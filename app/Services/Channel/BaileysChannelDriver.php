<?php

declare(strict_types=1);

namespace App\Services\Channel;

use App\Enums\ChannelCapability;
use App\Enums\ChannelMode;
use App\Enums\InboundEventKind;
use App\Enums\PresenceState;
use App\Enums\SessionLoginMethod;
use App\Exceptions\Bridge\BridgeRequestFailedException;
use App\Exceptions\Bridge\BridgeUnreachableException;
use App\Exceptions\Channel\ModeCapabilityException;
use App\Exceptions\Channel\WebhookVerificationException;
use App\Exceptions\Reliability\IdempotencyKeyReuseException;
use App\Exceptions\Reliability\OperationInFlightException;
use App\Models\Session;
use App\Services\Bridge\BridgeClient;
use App\Services\Bridge\BridgeWire;
use App\Services\Bridge\HttpBridgeClient;
use App\Services\Bridge\MediaPayload;
use App\Services\Bridge\NumberCheck;
use App\Services\Bridge\SentMessageDto;
use App\Services\Bridge\SessionInitDto;
use App\Services\Bridge\SessionStateDto;
use App\Services\Channel\Concerns\DerivesChannelPolicy;
use App\Services\Reliability\IdempotencyOptions;
use App\Services\Reliability\IdempotencyStore;
use App\Services\Security\SigningSecretStore;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * The `BAILEYS` driver: the existing Node + Baileys bridge, seen through the
 * `ChannelDriver` contract — **an adapter, not a second implementation** (Req 8.1, 8.8 / A8;
 * design § Channel Mode 2.1: *"The current `HttpBridgeClient` (Baileys) becomes one driver
 * among several (`BaileysChannelDriver`) — no behavioural change for existing tenants"*).
 *
 * ```php
 * // Registered by ChannelServiceProvider; obtained only through the router, wrapped.
 * $driver = $router->driverFor($session);          // ModeGuardedChannelDriver(BaileysChannelDriver)
 *
 * $receipt = $driver->send($session, TextContent::to($jid, 'Your order shipped.', $key));
 * ```
 *
 * ## It depends on the *interface*, so the guard decorators cannot be lost
 *
 * The constructor takes `BridgeClient`, and `BridgeServiceProvider` binds that name to one
 * chain in one order:
 *
 * ```
 *   TenantScopedBridgeClient   ownership resolution + per-tenant auth-state paths
 *     └── GuardedBridgeClient  per-session circuit breaker + bounded inline retry
 *           └── HttpBridgeClient   JSON over HTTP to the Node + Baileys sidecar
 * ```
 *
 * A driver that constructed `HttpBridgeClient` itself would compile, pass its own tests, and
 * silently drop ownership scoping, the breaker and the retry budget for every send routed
 * through Channel Mode — a regression with no symptom until a tenant addressed another
 * tenant's session id. Taking the interface makes that impossible by construction rather
 * than by review: there is no `new HttpBridgeClient` anywhere in this class, and the only
 * bridge it can reach is the one the container composed.
 *
 * That is also why this class re-composes nothing. `wa.bridge.guard` already decides whether
 * the breaker is in the chain, and a second decision here would be a second, competing
 * answer to the same question.
 *
 * ## "Unchanged behaviour for existing tenants" is the acceptance criterion
 *
 * The eleven transport methods are **pure delegation**: same arguments, in the same order,
 * with no defaulting, no rewriting, no `try`. Three failure modes of a careless adapter are
 * therefore ruled out, and each is asserted directly by `BaileysChannelDriverTest`:
 *
 * | Careless adapter | What it would break |
 * |---|---|
 * | normalises a JID, trims a phone, or fills in `$opts` | the sidecar receives something the caller did not ask for, and `HttpBridgeClient`'s own validation is bypassed or duplicated |
 * | catches `BridgeRequestFailedException` and returns a falsy result | a refused send becomes a plausible success; `BridgeErrorClassifier` never sees the code, so the retry budget is wrong |
 * | re-wraps a failure in its own exception type | the classifier chain stops recognising it and a `409 session_not_connected` stops being retryable |
 *
 * Anything that *is* a decision lives in the five driver members below, none of which existed
 * before Channel Mode, so no pre-existing call path can reach them.
 *
 * ## `send()` — how content reaches the wire
 *
 * | Content | Wire call | `SendReceipt::$degraded` |
 * |---|---|---|
 * | `TextContent` (`SEND_SINGLE`, `✅` on Baileys) | `sendText()` | `false` |
 * | a rich variant of task 12.4 (reply buttons, list, product) — `INTERACTIVE`, `⚠️ if WA version supports` | `sendText()` with `plainText()`, i.e. design.md's numbered-menu rendering | **`true`** |
 *
 * The `degraded` flag is not a judgement this class makes case by case: it is
 * `! supportFor($content->capability())->isNative()`, read from the same matrix the gate
 * reads. So a `⚠️` cell degrades and a `✅` cell does not, and the two cannot drift.
 *
 * `sendMedia()` is deliberately **not** reached from `send()` today, and that is a statement
 * about `OutboundContent` rather than about Baileys. No variant of it can express a
 * `MediaPayload` — task 12.4's rich family is buttons, lists and product cards, all of which
 * degrade to text — so there is nothing for a media arm to dispatch on, and inventing the
 * accessor here would fix the shape of an interface before the task that owns it exists. The
 * media *transport* is fully available (`sendMedia()` below, delegated unchanged) and is what
 * the media send path already uses; when a media-carrying `OutboundContent` lands, it adds
 * one arm to `dispatch()` and changes nothing else.
 *
 * ## `sendTemplate()` — refused, and the matrix is why that is consistent
 *
 * `Template` on `BAILEYS` is `⚠️`, not `❌` (design § 2.3: *"⚠️ text-templated only"*), so
 * `ChannelRouter::assertSupported($session, Template)` **allows** it — and it must, because
 * the `⚠️` is real: a tenant can absolutely send templated *text* through Baileys. That is a
 * `send()` of a `TextContent` the caller rendered.
 *
 * What Baileys cannot do is the thing `sendTemplate()` means: submit a **pre-approved**
 * template, by `(name, language)`, against a provider's approval registry. There is no such
 * registry on the WhatsApp web protocol, so the honest answers are exactly two — refuse, or
 * render the body as text and return a receipt that says a template was sent. The second is
 * worse in a way that is hard to detect: `SendReceipt::$templateName` and
 * `channel_send_log` would record an approved-template send whose approval nobody ever
 * obtained, and a tenant reading their own send log could not tell which of their template
 * sends were real. `ChannelDriver::sendTemplate()`'s docblock settles it in the same
 * direction (*"refused with `ModeCapabilityException` … rather than silently rendered as
 * text"*), and `ModeCapabilityException`'s own docblock lists a driver refusing a method the
 * backend does not have as one of its sanctioned raisers.
 *
 * So the split is: **the `⚠️` is honoured by `send()`, and `sendTemplate()` refuses.** The
 * capability gate stays permissive (nothing about the matrix changes) and the operation that
 * would lie is the only thing refused.
 *
 * ## `parseWebhook()` — the bridge's HMAC, reusing the platform's one HMAC machinery
 *
 * The sidecar POSTs inbound messages and receipts to the platform and signs the **raw body**
 * with the per-session secret held by `SigningSecretStore` under scope
 * `bridge:session:{sessionId}` — the scope that store's own docblock already reserves for
 * *"per-session bridge webhook HMAC"*. Nothing about the signing scheme is re-invented here:
 * the algorithm, the `sha256=` presentation, constant-time comparison and the dual-secret
 * rotation window are all that store's, so rotating a session's webhook secret
 * (`wa:security:rotate-hmac`) keeps verifying payloads the sidecar signed with the previous
 * one.
 *
 * ### The scope comes from the credentials, never from the payload
 *
 * `parseWebhook()` is handed `ChannelCredentials`, and for Baileys those are
 * `ChannelCredentials::platform()` — the bridge's URL and token are platform config, not
 * tenant secrets (`ChannelMode::requiresTenantCredentials()` is false) — carrying one config
 * key, `session_id`. Use `credentialsFor()` to build them; task 8.3's controller has the
 * session in hand from `channel_webhook_routes.route_key` before it parses anything.
 *
 * Deriving the scope from the credentials rather than from `$payload['session_id']` is the
 * whole security content of this method. The payload-driven alternative verifies against
 * *whatever session the body names*, which means a body signed with tenant A's session
 * secret verifies successfully when POSTed to tenant B's route key — and the event that came
 * out would carry tenant B's id, because that is where `tenantId` comes from. One valid
 * signature from any tenant would then inject messages into any other tenant's conversation
 * history. So the caller's answer is the only answer, and the payload's claim is checked
 * *against* it (`WebhookVerificationException::wrongRecipient()`), exactly as the Cloud API
 * driver will check `phone_number_id` (Req 8.4, Property 23).
 *
 * ### The wire format, and what each event becomes
 *
 * | `event` | Canonical kind | Required fields |
 * |---|---|---|
 * | `message` | `MESSAGE` | `wa_message_id`, `from` |
 * | `delivered` | `DELIVERY_RECEIPT` | `wa_message_id` |
 * | `read` | `READ_RECEIPT` | `wa_message_id` |
 * | `failed` | `SEND_FAILURE` | `wa_message_id` (`error` becomes the redacted `failureReason`) |
 * | anything else | `UNSUPPORTED` | — |
 *
 * `UNSUPPORTED` is a successful parse, so a sidecar build newer than this release gets a
 * `200` and stops retrying rather than a 403 storm. A *verified* payload missing a field its
 * own kind requires is `malformedPayload()` (403) instead of an `InvalidArgumentException`
 * from `InboundEvent`'s constructor: both refuse, but only one of them is a 500 on a public
 * endpoint.
 *
 * For Baileys, `channelIdentity` is the **session id**. Every other mode puts a
 * provider-side number identifier there (`phone_number_id`, a partner's sender id); a
 * Baileys session has none, and the session is the thing the payload is addressed to.
 *
 * ## `send()` idempotency, `register()` and `healthCheck()`
 *
 * - **Idempotency** is `IdempotencyStore::once()` on `$content->idempotencyKey()`, scoped
 *   `channel.send:{tenantId}` — the tenant is in the *scope* because `idempotency_keys` is
 *   deliberately not tenant-scoped and a caller-chosen key could collide across tenants
 *   (that store's own rule). A replay returns the **original** receipt, rebuilt from the
 *   recorded row, and puts nothing new on the wire. The key is bound to a fingerprint of the
 *   send, so re-using one key for a different recipient or body is refused
 *   (`IdempotencyKeyReuseException`, 422) rather than answered with someone else's receipt.
 * - **`register()`** reports, never registers: a Baileys number pairs by QR or pairing code
 *   and there is no provider-side registration to perform (design § 2.5: *"A Baileys session
 *   still just pairs via QR"*). It answers `live()` once the session can actually send and
 *   `pending()` while it cannot, which is the same "report current state rather than reset
 *   it" idempotency `provisionSession()` has. Throwing instead would make a mode-independent
 *   `SessionManager::create` impossible for the one mode that is the default.
 * - **`healthCheck()`** is `BridgeClient::isReachable()` — the sidecar's own health route.
 *   There is no provider API to probe and no tenant credential to validate, so an unhealthy
 *   answer here means the bridge process is down, which is exactly what the connection
 *   screen's red light should say.
 *
 * `requiresAntiBan()` and `supports()` come from `DerivesChannelPolicy` and are not
 * overridden: Baileys is a web-protocol mode, so the answer is `true` and Req 8.8 requires
 * that no configuration be able to change it (`ModeGuardedChannelDriver` re-derives it
 * anyway).
 */
final readonly class BaileysChannelDriver implements ChannelDriver
{
    use DerivesChannelPolicy;

    /**
     * The header the sidecar presents its HMAC in.
     *
     * A constant rather than a config key, for the reason `HttpBridgeClient` hardcodes its
     * routes: the sidecar is part of this platform and its wire vocabulary is a shared
     * protocol, not an operator preference. `wa.security.hmac` still owns the algorithm and
     * the `sha256=` presentation, because those are platform-wide and shared with providers
     * we do not control.
     */
    public const string SIGNATURE_HEADER = 'X-Bridge-Signature';

    /**
     * The `ChannelCredentials::config()` key naming the session a webhook payload must be
     * about — see `credentialsFor()`.
     */
    public const string SESSION_CONFIG_KEY = 'session_id';

    /**
     * Prefix of the `SigningSecretStore` scope holding a session's webhook secret.
     *
     * The spelling is that store's own (`bridge:session:{sessionId}`), so the secret this
     * driver verifies with is the same row `wa:security:rotate-hmac` rotates and
     * `RotateSigningSecrets --scope=` names.
     */
    public const string WEBHOOK_SCOPE_PREFIX = 'bridge:session:';

    /**
     * Dedup namespace of a driver-level send. The tenant id is appended by `sendScope()`.
     */
    public const string SEND_SCOPE_PREFIX = 'channel.send:';

    public function __construct(
        private BridgeClient $bridge,
        private SigningSecretStore $secrets,
        private IdempotencyStore $idempotency,
    ) {}

    public function mode(): ChannelMode
    {
        return ChannelMode::Baileys;
    }

    /**
     * The credentials this driver expects for `$session` — platform config, plus the one key
     * `parseWebhook()` derives its HMAC scope from.
     *
     * `BAILEYS` has no `channel_credentials` row (Req 8.13 / A8: the default mode needs no
     * tenant onboarding), so `ChannelCredentialStore::for()` answers `null` for it and
     * something has to supply the shape the contract's credential-taking methods require.
     * `DefaultChannelRouter` says so explicitly: *"Substituting that platform config is the
     * Baileys driver's business (task 7.1, via `ChannelCredentials::platform()`), not the
     * router's."*
     *
     * The tenant id is the session's own, so a driver call made with these credentials is
     * attributed to exactly the tenant whose session it is about.
     */
    public static function credentialsFor(Session $session): ChannelCredentials
    {
        return ChannelCredentials::platform(
            tenantId: $session->tenant_id,
            mode: ChannelMode::Baileys,
            config: [self::SESSION_CONFIG_KEY => $session->id],
        );
    }

    /**
     * The `SigningSecretStore` scope holding one session's webhook HMAC secret.
     *
     * Public because task 8.3 registers the callback and has to hand the sidecar the secret
     * (`SigningSecretStore::currentSecret()`) for the same scope this verifies against — one
     * spelling, named once.
     */
    public static function webhookScope(string $sessionId): string
    {
        return self::WEBHOOK_SCOPE_PREFIX.$sessionId;
    }

    /*
    |--------------------------------------------------------------------------
    | Outbound
    |--------------------------------------------------------------------------
    */

    /**
     * Send one message, at most once per idempotency key.
     *
     * @throws BridgeRequestFailedException when the bridge refuses
     * @throws BridgeUnreachableException when the bridge never answered
     * @throws IdempotencyKeyReuseException when the key was first used for a different send
     * @throws OperationInFlightException when a duplicate is still in flight
     */
    public function send(Session $session, OutboundContent $content): SendReceipt
    {
        $capability = $content->capability();

        // Read from the matrix, not decided here: a `⚠️` cell is flattened to text and the
        // receipt says so, a `✅` cell is sent as-is. `❌` never arrives — the router refused
        // it before dispatch (Property 21).
        $degraded = ! $this->supportFor($capability)->isNative();

        $outcome = $this->idempotency->once(
            self::sendScope($session),
            $content->idempotencyKey(),
            fn (): array => $this->dispatch($session, $content, $degraded),
            IdempotencyOptions::default()
                ->forTenant($session->tenant_id)
                // So one key cannot answer two different sends with the first one's receipt.
                ->matching($this->sendFingerprint($session, $content))
                // A send runs inside a queued job, so its own backoff is a better place to
                // wait for a live holder than a blocked worker slot.
                ->failingFast(),
        );

        return $this->receipt($content, $capability, $outcome->payload());
    }

    /**
     * Refused: the WhatsApp web protocol has no approved-template registry.
     *
     * See the class docblock for why this refuses rather than rendering the template as
     * text, and why refusing is consistent with `Template` being `⚠️` rather than `❌`.
     *
     * @param  array<string, string|int|float>  $vars
     *
     * @throws ModeCapabilityException always
     */
    public function sendTemplate(Session $session, TemplateRef $template, array $vars): SendReceipt
    {
        throw ModeCapabilityException::for($this->mode(), ChannelCapability::Template);
    }

    /*
    |--------------------------------------------------------------------------
    | Inbound
    |--------------------------------------------------------------------------
    */

    /**
     * Verify a sidecar webhook and normalise it (Req 8.4 / A8; Req 10.1 / B1).
     *
     * Order is origin → recipient → shape, as `ChannelDriver::parseWebhook()` requires. The
     * body is decoded between the first two because the recipient claim is inside it;
     * decoding is not believing, and nothing read from it is acted on until both checks have
     * passed.
     *
     * @throws WebhookVerificationException when origin, recipient, or shape does not check out
     */
    public function parseWebhook(Request $request, ChannelCredentials $credentials): InboundEvent
    {
        // From the caller, never from the body — the class docblock sets out the cross-tenant
        // injection this ordering prevents. A missing key is a wiring defect in the caller
        // (task 8.3), so `requireConfig()`'s InvalidArgumentException is the right answer: it
        // is not something a request can provoke.
        $sessionId = $credentials->requireConfig(self::SESSION_CONFIG_KEY);

        $presented = trim((string) $request->header(self::SIGNATURE_HEADER, ''));

        if ($presented === '') {
            throw WebhookVerificationException::missingSignature($this->mode(), self::SIGNATURE_HEADER);
        }

        // Over the **raw** body: re-encoding a decoded array changes bytes, and the signature
        // is over bytes. Constant-time comparison and the rotation overlap window are
        // `SigningSecretStore`'s, and a scope with no secret verifies `false` rather than
        // throwing — an unverifiable payload must not be able to choose between 403 and 503.
        if (! $this->secrets->verify(self::webhookScope($sessionId), $request->getContent(), $presented)) {
            throw WebhookVerificationException::badSignature($this->mode(), self::SIGNATURE_HEADER);
        }

        $payload = $this->decode($request);
        $claimed = BridgeWire::stringOrNull($payload[self::SESSION_CONFIG_KEY] ?? null);

        if ($claimed === null) {
            throw WebhookVerificationException::malformedPayload(
                $this->mode(),
                'it names no session, so it cannot be matched against the route it was delivered on',
            );
        }

        if (! hash_equals($sessionId, $claimed)) {
            throw WebhookVerificationException::wrongRecipient($this->mode(), $sessionId, $claimed);
        }

        return $this->event($credentials, $sessionId, $payload);
    }

    /*
    |--------------------------------------------------------------------------
    | Credentials & onboarding
    |--------------------------------------------------------------------------
    */

    /**
     * Report what pairing has achieved; there is nothing to register with a provider.
     *
     * `live()` once the session can send, `pending()` while it cannot — and never an
     * exception, because a `SessionManager::create` that had to special-case the *default*
     * mode would be a mode-aware pipeline pretending to be mode-independent.
     *
     * The `providerNumberId` is the session id: the bridge addresses sessions by that opaque
     * ULID and by nothing else, so it is literally *"what every subsequent send addresses
     * itself with"*. No callback is reported — registering the sidecar's callback URL and
     * handing it the HMAC secret is task 8.3's, and claiming a `routeKey` this driver did not
     * create would put a URL on a panel that nothing answers.
     */
    public function register(Session $session, ChannelCredentials $credentials): RegistrationResult
    {
        if ($session->canSend()) {
            return RegistrationResult::live(
                $credentials,
                $session->id,
                detail: 'This number is paired with the WhatsApp Web bridge and can send.',
            );
        }

        return RegistrationResult::pending($credentials, sprintf(
            'A Baileys session is not registered with a provider: it becomes sendable once pairing '
            .'completes (currently %s).',
            $session->status->value,
        ), providerNumberId: $session->id);
    }

    /**
     * Whether the bridge process is answering.
     *
     * Reports rather than throws, as the contract requires — the caller wants the failure as
     * data to record and to display. `isReachable()` is the one transport method that already
     * answers a health question with a value, so there is nothing to catch here.
     */
    public function healthCheck(ChannelCredentials $credentials): ChannelHealth
    {
        $startedAt = hrtime(true);
        $reachable = $this->bridge->isReachable();
        $latencyMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);

        return $reachable
            ? ChannelHealth::healthy($credentials, 'The WhatsApp Web bridge is reachable.', $latencyMs)
            : ChannelHealth::unhealthy($credentials, sprintf(
                'The WhatsApp Web bridge is not answering at %s. Baileys needs no tenant credentials, '
                .'so this is a platform dependency rather than something to re-enter.',
                $this->bridgeUrl(),
            ), $latencyMs);
    }

    /*
    |--------------------------------------------------------------------------
    | Transport — delegated verbatim (§ "Unchanged behaviour for existing tenants")
    |--------------------------------------------------------------------------
    */

    public function provisionSession(
        string $sessionId,
        SessionLoginMethod $method,
        ?string $phone = null,
        ?string $authStateDir = null,
    ): SessionInitDto {
        return $this->bridge->provisionSession($sessionId, $method, $phone, $authStateDir);
    }

    public function startSession(string $sessionId): void
    {
        $this->bridge->startSession($sessionId);
    }

    public function stopSession(string $sessionId, bool $logout = false): void
    {
        $this->bridge->stopSession($sessionId, $logout);
    }

    public function sessionState(string $sessionId): SessionStateDto
    {
        return $this->bridge->sessionState($sessionId);
    }

    public function qr(string $sessionId): ?string
    {
        return $this->bridge->qr($sessionId);
    }

    public function pairingCode(string $sessionId, string $phone): string
    {
        return $this->bridge->pairingCode($sessionId, $phone);
    }

    /**
     * @param  array<string, mixed>  $opts
     */
    public function sendText(string $sessionId, string $jid, string $text, array $opts = []): SentMessageDto
    {
        return $this->bridge->sendText($sessionId, $jid, $text, $opts);
    }

    /**
     * @param  array<string, mixed>  $opts
     */
    public function sendMedia(string $sessionId, string $jid, MediaPayload $media, array $opts = []): SentMessageDto
    {
        return $this->bridge->sendMedia($sessionId, $jid, $media, $opts);
    }

    public function sendPresence(string $sessionId, string $jid, PresenceState $presence): void
    {
        $this->bridge->sendPresence($sessionId, $jid, $presence);
    }

    /**
     * @param  list<string>  $numbers
     * @return array<array-key, NumberCheck>
     */
    public function checkNumbers(string $sessionId, array $numbers): array
    {
        return $this->bridge->checkNumbers($sessionId, $numbers);
    }

    public function isReachable(): bool
    {
        return $this->bridge->isReachable();
    }

    /*
    |--------------------------------------------------------------------------
    | Sending internals
    |--------------------------------------------------------------------------
    */

    /**
     * The dedup namespace for `$session`'s tenant.
     *
     * The tenant is in the scope rather than only in the key because `idempotency_keys` is
     * not tenant-scoped: its `tenant_id` is attribution, and a caller-chosen key that
     * collided across tenants would replay one tenant's receipt to another. That is
     * `IdempotencyStore`'s stated rule, and this is the one line of this class it applies to.
     */
    private static function sendScope(Session $session): string
    {
        return self::SEND_SCOPE_PREFIX.$session->tenant_id;
    }

    /**
     * Put one message on the wire and return the replay material.
     *
     * Returns an array rather than a `SendReceipt` because the return value *is* what
     * `idempotency_keys.result` stores, and only JSON-encodable data can be replayed. The
     * receipt is rebuilt from it by `receipt()`, so a fresh send and a replay travel exactly
     * the same path out of `send()`.
     *
     * @return array<string, mixed>
     */
    private function dispatch(Session $session, OutboundContent $content, bool $degraded): array
    {
        // The one arm today, and the reason is in the class docblock: no `OutboundContent`
        // can carry a `MediaPayload` yet, so every variant — including task 12.4's rich
        // family — reaches the wire as its `plainText()` rendering.
        $sent = $this->bridge->sendText($session->id, $content->recipient(), $content->plainText());

        return [
            'wa_message_id' => $sent->waMessageId,
            'jid' => $sent->jid,
            'sent_at' => $sent->sentAt?->toIso8601String(),
            'degraded' => $degraded,
        ];
    }

    /**
     * The fingerprint an idempotency key is bound to.
     *
     * Message text is included as a **hash**: the fingerprint is what makes "same key,
     * different send" detectable, and Req 7.3 / A7 permits a content digest and never the
     * body. `IdempotencyKey::fingerprint()` hashes the whole structure again, so nothing
     * legible is stored either way — the digest is here so that nothing legible is *passed*.
     *
     * @return array<string, string>
     */
    private function sendFingerprint(Session $session, OutboundContent $content): array
    {
        return [
            'session' => $session->id,
            'capability' => $content->capability()->value,
            'recipient' => $content->recipient(),
            'content' => hash('sha256', $content->plainText()),
        ];
    }

    /**
     * Rebuild the driver-level receipt from what the wire said (or what the ledger replayed).
     *
     * @param  array<array-key, mixed>  $payload
     */
    private function receipt(OutboundContent $content, ChannelCapability $capability, array $payload): SendReceipt
    {
        $sentAt = BridgeWire::timestampOrNull($payload['sent_at'] ?? null);

        return SendReceipt::fromSentMessage(
            mode: $this->mode(),
            sent: new SentMessageDto(
                // A send acknowledged with no id is refused by `SendReceipt` itself, which is
                // the correct outcome: an unreconcilable "sent" is worse than a retry.
                waMessageId: BridgeWire::stringOrNull($payload['wa_message_id'] ?? null) ?? '',
                jid: BridgeWire::stringOrNull($payload['jid'] ?? null) ?? $content->recipient(),
                sentAt: $sentAt,
            ),
            idempotencyKey: $content->idempotencyKey(),
            capability: $capability,
            degraded: ($payload['degraded'] ?? false) === true,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Inbound internals
    |--------------------------------------------------------------------------
    */

    /**
     * The verified body as a string-keyed array.
     *
     * @return array<string, mixed>
     *
     * @throws WebhookVerificationException when the body is not a JSON object
     */
    private function decode(Request $request): array
    {
        $decoded = json_decode($request->getContent(), true);

        if (! is_array($decoded)) {
            throw WebhookVerificationException::malformedPayload(
                $this->mode(),
                'its body is not a JSON object',
            );
        }

        return BridgeWire::arrayOrEmpty($decoded);
    }

    /**
     * One verified payload as the canonical event.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws WebhookVerificationException when a payload of a known kind is missing a field that kind requires
     */
    private function event(ChannelCredentials $credentials, string $sessionId, array $payload): InboundEvent
    {
        $kind = self::kindOf(BridgeWire::stringOrNull($payload['event'] ?? null));

        if ($kind === null) {
            // A sidecar build newer than this release, or an event the platform does not act
            // on: a successful parse, so the sidecar gets a 200 and stops retrying.
            return InboundEvent::unsupported($this->mode(), $credentials->tenantId, $sessionId, $payload);
        }

        $providerMessageId = BridgeWire::stringOrNull($payload['wa_message_id'] ?? null);

        if ($providerMessageId === null) {
            throw WebhookVerificationException::malformedPayload(
                $this->mode(),
                'it carries no "wa_message_id", so nothing could ever be correlated with it',
            );
        }

        $occurredAt = self::timestampOf($payload['timestamp'] ?? null);

        if ($kind !== InboundEventKind::Message) {
            return InboundEvent::receipt(
                kind: $kind,
                mode: $this->mode(),
                tenantId: $credentials->tenantId,
                providerMessageId: $providerMessageId,
                occurredAt: $occurredAt,
                // Scrubbed even though these credentials hold no secret: the rule is that a
                // provider phrase is redacted on the way in, not that this mode happens to
                // have nothing to lose today.
                failureReason: $kind === InboundEventKind::SendFailure
                    ? $credentials->redact(BridgeWire::stringOrNull($payload['error'] ?? null) ?? 'The bridge reported a send failure.')
                    : null,
                channelIdentity: $sessionId,
                payload: $payload,
            );
        }

        $from = BridgeWire::stringOrNull($payload['from'] ?? null);

        if ($from === null) {
            throw WebhookVerificationException::malformedPayload(
                $this->mode(),
                'it is a message that names no sender',
            );
        }

        return InboundEvent::message(
            mode: $this->mode(),
            tenantId: $credentials->tenantId,
            providerMessageId: $providerMessageId,
            from: $from,
            text: BridgeWire::stringOrNull($payload['text'] ?? null),
            channelIdentity: $sessionId,
            occurredAt: $occurredAt,
            payload: $payload,
        );
    }

    /**
     * The sidecar's `event` value as a canonical kind, or null for one this release does not
     * act on.
     */
    private static function kindOf(?string $event): ?InboundEventKind
    {
        return match ($event) {
            'message' => InboundEventKind::Message,
            'delivered' => InboundEventKind::DeliveryReceipt,
            'read' => InboundEventKind::ReadReceipt,
            'failed' => InboundEventKind::SendFailure,
            default => null,
        };
    }

    /**
     * A payload timestamp as an instant, or null.
     *
     * Epoch seconds are what the protocol carries, so they are accepted first;
     * `BridgeWire::timestampOrNull()` reads the ISO-8601 form the sidecar's HTTP responses
     * use. An unreadable value is `null` rather than "now": a fabricated occurrence time
     * would be indistinguishable from a real one afterwards.
     */
    private static function timestampOf(mixed $value): ?CarbonImmutable
    {
        if (is_string($value) && preg_match('/^\d{1,14}$/', trim($value)) === 1) {
            // `BridgeWire` reads a numeric epoch and an ISO-8601 string, and already decides
            // between seconds and milliseconds. A JSON number arrives as an int and needs
            // nothing; a *quoted* epoch would otherwise take the string arm and be parsed as
            // a date, which is how `1736899200` becomes a year.
            $value = (int) trim($value);
        }

        return BridgeWire::timestampOrNull($value);
    }

    /**
     * The configured bridge base URL, for an operator-facing health detail.
     *
     * The URL is deployment config and not a secret (`wa.bridge.token` is, and is not read
     * here), so naming it is what makes an unhealthy answer actionable.
     */
    private function bridgeUrl(): string
    {
        $configured = config('wa.bridge.url');

        return is_string($configured) && trim($configured) !== ''
            ? trim($configured)
            : HttpBridgeClient::DEFAULT_URL;
    }
}
