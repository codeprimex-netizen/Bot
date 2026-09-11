<?php

declare(strict_types=1);

namespace App\Services\Channel;

use App\Enums\ChannelCapability;
use App\Enums\ChannelMode;
use App\Enums\InboundEventKind;
use App\Enums\PresenceState;
use App\Enums\SessionLoginMethod;
use App\Enums\SessionStatus;
use App\Exceptions\Bridge\BridgeRequestFailedException;
use App\Exceptions\Bridge\BridgeUnreachableException;
use App\Exceptions\Channel\ChannelCredentialException;
use App\Exceptions\Channel\ChannelOperationException;
use App\Exceptions\Channel\ChannelRequestFailedException;
use App\Exceptions\Channel\ChannelTemplateException;
use App\Exceptions\Channel\WebhookVerificationException;
use App\Exceptions\Reliability\IdempotencyKeyReuseException;
use App\Exceptions\Reliability\OperationInFlightException;
use App\Models\CloudApiTemplate;
use App\Models\Session;
use App\Services\Bridge\BridgeWire;
use App\Services\Bridge\MediaPayload;
use App\Services\Bridge\NumberCheck;
use App\Services\Bridge\SentMessageDto;
use App\Services\Bridge\SessionInitDto;
use App\Services\Bridge\SessionStateDto;
use App\Services\Channel\Concerns\DedupesChannelSends;
use App\Services\Channel\Concerns\DerivesChannelPolicy;
use App\Services\Channel\Concerns\ResolvesOwnedSession;
use App\Services\Channel\Concerns\VerifiesProviderSignature;
use App\Services\Reliability\IdempotencyStore;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * The `ON_PREMISE` driver: the **legacy self-hosted WhatsApp Business API client** — a container
 * the tenant runs, with its own `/v1/...` REST surface — behind the `ChannelDriver` contract
 * (Req 8.9 / A8; design § Channel Mode 2.2 mode 3, 2.3, 2.7).
 *
 * ```php
 * // Registered by ChannelServiceProvider; obtained only through the router, wrapped.
 * $driver = $router->driverFor($session);   // ModeGuardedChannelDriver(OnPremiseChannelDriver)
 *
 * $receipt = $driver->send($session, TextContent::to('919812345678', 'Your order shipped.', $key));
 * ```
 *
 * @deprecated Meta has sunset the On-Premise API. New sessions should use
 * `App\Services\Channel\CloudApiChannelDriver`; see `OnPremiseDeprecation::migrationPath()` for
 * the ordered `ON_PREMISE → CLOUD_API` path. This driver is **maintained, not degraded** —
 * design § 2.2 says the mode is *"supported for tenants who still run it"*, and a platform that
 * broke a working backend to make a point would harm the tenants Req 8.9 exists to protect.
 *
 * ## The interesting thing about this mode: Baileys' *policy*, Cloud API's *shape*
 *
 * It is the one mode where the two axes of design § 2.3 come apart, and conflating them is the
 * mistake this class is written to make impossible:
 *
 * | Axis | `ON_PREMISE` answers like… | Because |
 * |---|---|---|
 * | which pacing rules apply | **`BAILEYS`** — `requiresAntiBan()` is `true` | the client rides the WhatsApp **web** protocol underneath, so the warm-up / rate ramp applies (Req 8.8, Property 24) |
 * | which features exist | **`CLOUD_API`** — templates `✅`, groups/welcome/extraction/tagging/channels `❌` | it is an official Business API client, not a WhatsApp Web session |
 * | what the wire looks like | **`CLOUD_API`** — an HTTP provider API with a JSON error envelope | one `POST /v1/messages`, guarded, classified, deduplicated |
 * | who hosts it | **nobody else** — the tenant's own container, at a base URL the tenant supplies | which is why `base_url` is a *credential* here and platform config for every other mode |
 *
 * `requiresAntiBan()` comes from `Concerns\DerivesChannelPolicy` → `ChannelMode::isWebProtocol()`
 * and is **not overridden here**. Req 8.8 requires the gate be non-disableable, so there is
 * deliberately no config key, no constructor flag and no per-tenant setting that could turn it
 * off — and `ModeGuardedChannelDriver` re-derives the answer from `mode()` whatever this class
 * returned, so even a future edit to this file cannot change it.
 *
 * ## Authentication is the distinguishing feature, and where the token lives is the decision
 *
 * Cloud API's credential *is* the bearer token: long-lived, stored, read on every call. The
 * On-Premise client works the other way round — what is stored is a **username and password**,
 * and the bearer token is minted by `POST /v1/users/login` under HTTP Basic auth and stamped
 * with an `expires_after`:
 *
 * ```json
 * {"users": [{"token": "eyJhbGciOi…", "expires_after": "2024-05-01 15:29:26+00:00"}]}
 * ```
 *
 * Two constraints pull in opposite directions, and both are hard:
 *
 * - **Req 8.5**: decrypted credentials are held *"only within the lifetime of the request or job
 *   that uses them"*. So the token must not be cached in a worker's process memory, in Redis, or
 *   on a `singleton()`.
 * - **A token that lapses mid-campaign must be re-obtained**, not turned into a run of auth
 *   failures. `ErrorClass::Auth` is **structurally** zero attempts (`ErrorClass::disposition()`,
 *   which no configuration can override), so an expired token discovered on send 3,000 of 9,000
 *   would *lose* the remaining 6,000 rather than defer them.
 *
 * The resolution is three mechanisms, each doing one thing:
 *
 * | Mechanism | What it guarantees |
 * |---|---|
 * | `OnPremiseTokenCache`, bound **`scoped()`** | one login per request or job — and the holder is discarded when that unit of work ends, so nothing outlives it |
 * | `OnPremiseTokenCache::EXPIRY_SKEW_SECONDS` | a token inside its last minute is treated as **already** expired, so a send never starts on a token that will lapse in flight |
 * | `withFreshToken()` below | a refusal that looks like an expired token discards the cached copy and replays the call **exactly once** with a brand-new one |
 *
 * The last row is why `OnPremiseErrorClassifier` may safely map `1005` to `AUTH`: only a refusal
 * that survived a freshly-minted token is ever classified, and a brand-new token being refused
 * *is* an auth failure. The replay is bounded at one because a second one would be a login loop
 * against a container that has decided to say no.
 *
 * The stored material is still resolved through `ChannelCredentialStore` on **every** call, never
 * memoised — 7.2's rule, unchanged — so a rotated password is picked up by the next send. And
 * because the cache key is derived from the password itself (`OnPremiseTokenCache::keyFor()`), a
 * rotation cannot be answered with a token minted from the value it replaced.
 *
 * A deployment that mints its own long-lived token out of band may store it as `access_token`
 * instead, which design § 2.7 allows (*"client API user/password/token"*). Then no login is
 * performed, `withFreshToken()` has nothing to refresh, and a `1005` on it is a genuine
 * credential failure the tenant must fix — which is the honest outcome, and the reason
 * `canReauthenticate()` exists rather than the replay being unconditional.
 *
 * ## Credentials — §2.7's row, key by key
 *
 * design § 2.7 gives `ON_PREMISE`: *"on-prem base URL, phone number"* as config and *"client API
 * user/password/token, webhook secret"* as secrets.
 *
 * | Key | Kind | Required | Read by |
 * |---|---|---|---|
 * | `base_url` | config | yes | every request — it *is* the container's address |
 * | `phone_number` | config | yes | `parseWebhook()`'s recipient identity, and every event's `channelIdentity` |
 * | `template_namespace` | config | no | `sendTemplate()`, for a client build that requires the Message Template Namespace |
 * | `media_provider` | config | no | `sendMedia()`, for a media **link** (see that method) |
 * | `username` | secret | yes, unless `access_token` | `POST /v1/users/login`, as the Basic auth user |
 * | `password` | secret | yes, unless `access_token` | the same login |
 * | `access_token` | secret | no | used verbatim in place of a login — the out-of-band escape hatch above |
 * | `webhook_secret` | secret | yes | `parseWebhook()`'s HMAC verification |
 *
 * Every one of `password`, `access_token` and `webhook_secret` is matched by
 * `App\Support\Pii\PiiKeyRules::SECRET_PATTERN`, so a caller who logs the decrypted bag still
 * gets `[redacted]`. `username` is not, and is not meant to be: it is an identifier, and naming
 * it in a *"which key is missing"* message is what makes that message actionable.
 *
 * ### `base_url` is tenant-supplied, which no other mode's host is
 *
 * `wa.channel.cloud_api.base_url` is platform config precisely so that *"a tenant-supplied host
 * would be a way to point a tenant's own access token at somebody else's server"*. Here the host
 * has to come from the tenant — the container is theirs — so the same risk is real and is bounded
 * by `containerUrl()` instead:
 *
 * - the scheme must be `http` or `https`, so a `file://` or `gopher://` base cannot be smuggled in;
 * - a URL carrying **userinfo** (`https://user:pass@host/`) is refused, because that is how a
 *   credential ends up in a log line that records the URL;
 * - a **private, loopback or link-local IP literal** is refused unless
 *   `wa.channel.on_premise.allow_private_hosts` is on. The default is off: on a hosted platform
 *   the container is reachable over the internet, and the value of the exception is that
 *   `http://169.254.169.254/` — the cloud metadata endpoint — is not a place a tenant may aim the
 *   platform's HTTP client. A self-hosted deployment whose container is in the same VPC turns the
 *   flag on deliberately.
 *
 * What this does **not** do is resolve DNS and check the answer: a name that resolves to a private
 * address today can resolve elsewhere between the check and the connection, so DNS-level egress
 * control belongs to the network and not to a driver pretending to provide it. The flag and the
 * literal check are what a driver can honestly guarantee, and the residual case is stated rather
 * than hidden.
 *
 * ## `send()` — the matrix decides, and one arm dispatches
 *
 * The shape is 7.2's, member for member: resolve credentials, read `degraded` from the same
 * matrix the gate reads (`! supportFor($capability)->isNative()`, never a hand-written answer),
 * and claim the idempotency key through `Concerns\DedupesChannelSends` — the same
 * `channel.send:{tenantId}` scope, the same fingerprint, the same lease semantics.
 *
 * There is one dispatch arm for the reason 7.2 gives: no variant of `OutboundContent` can yet
 * express media or an interactive payload (task 12.4 owns that family), so a second arm would fix
 * the shape of an interface before the task that owns it exists. Both wire shapes are
 * nevertheless complete — media through `sendMedia()`, interactive through
 * `OnPremiseMessage::buttons()` / `::list()` — so 12.4 adds one `match` arm and nothing else.
 *
 * `SEND_BULK` is `⚠️ template + tier`: the client has no bulk route, a campaign is N calls to
 * `POST /v1/messages`, and on **this** mode the pacing is the anti-ban ramp (task 8.1) *and* the
 * client's own `1015`, which the classifier defers.
 *
 * ## `parseWebhook()` — and the protocol gap it has to work around
 *
 * The order is the contract's — origin → recipient → shape — and both of the first two need a
 * note, because the legacy client is weaker than Meta at each.
 *
 * ### Origin: the client signs nothing, so the platform requires that a proxy does
 *
 * The On-Premise client posts its callbacks as a plain `POST` with **no signature header of any
 * kind**; the deployment guide's answer is mutual TLS or a secret embedded in the URL. Neither is
 * verifiable inside `parseWebhook()`, and design § 2.7 nevertheless lists a *"webhook secret"* for
 * this mode — so the platform's convention is that the tenant puts an HMAC in front of the
 * container (its reverse proxy, or the sidecar that already terminates TLS for it) and stores the
 * shared secret as `webhook_secret`.
 *
 * The header is `X-Wa-Signature-256`, the digest is `HMAC` over the **raw** body under that
 * secret, and all of it is `Concerns\VerifiesProviderSignature` — the same
 * `wa.security.hmac.algorithm` / `wa.security.hmac.prefix` scheme every other signed webhook on
 * this platform uses, so there is no second HMAC scheme to keep true. An unsigned payload is
 * refused (`missingSignature()`), which **fails closed**: Req 8.4 admits no unverified inbound
 * event, and a mode that accepted one because its provider is old would be the one hole in the
 * platform's inbound story.
 *
 * ### Recipient: the payload names no business number, so the credentials are the only source
 *
 * A Cloud API webhook states its own recipient in `value.metadata.phone_number_id`, and 7.2's
 * whole security argument is that the *credentials'* answer is trusted and the payload's is
 * checked against it. The On-Premise callback body has **no such field at all**: `messages[].from`
 * and `statuses[].recipient_id` are both **customer** identities, and nothing in the envelope
 * names the business number.
 *
 * That makes the rule *"the recipient comes from the credentials, never from the payload"*
 * trivially true here — `channelIdentity` is `phone_number`, always, and there is no payload field
 * that could override it even by mistake. But it also removes the positive check, so two things
 * are done instead:
 *
 * 1. **The signature is the origin *and* the tenant binding.** `webhook_secret` is per credential
 *    row, so a body signed with tenant A's secret and POSTed to tenant B's route key fails
 *    verification under B's secret. `OnPremiseChannelDriverTest` asserts exactly that.
 * 2. **A recipient claim is refused when it is present and wrong.** When the body carries
 *    `metadata.phone_number` or `metadata.display_phone_number` — which a proxy can add, and which
 *    a tenant fronting two containers with one secret should be told to add — it is compared,
 *    digits to digits, against `phone_number`, and a mismatch is
 *    `WebhookVerificationException::wrongRecipient()`. Its absence changes nothing, so the check
 *    can never be a regression; its presence closes the one case a shared secret leaves open.
 *
 * The gap itself — that design § 2.3's *"Inbound webhooks ✅ (on-prem callback)"* cell does not say
 * what authenticates it — is reported to the consolidation pass rather than papered over.
 *
 * ### The client batches, and a single `InboundEvent` cannot say so
 *
 * `{"contacts": […], "messages": […], "statuses": […]}` — several logical events per HTTP request,
 * exactly as Meta does it, and `InboundEvent`'s own docblock names this. So the resolution is
 * 7.2's, deliberately identical so that task 8.3 has one thing to remember rather than two:
 * `parseWebhookBatch()` is the real method and returns every event in document order;
 * `parseWebhook()` returns the first; and every event carries `_batch_size` / `_batch_index` so a
 * caller holding one can *detect* that it was one of several. **Task 8.3 must call
 * `parseWebhookBatch()`** — calling `parseWebhook()` on a batched body acknowledges with a `200`
 * and silently drops every event but the first.
 *
 * ### What each field becomes
 *
 * | Payload | Canonical kind | Notes |
 * |---|---|---|
 * | `messages[]` | `MESSAGE` | `from`, `id`, `timestamp`; `text.body`, or an interactive reply's title |
 * | `statuses[].status = sent` / `delivered` | `DELIVERY_RECEIPT` | one kind for both, per `InboundEventKind`'s table; the raw string is kept in the payload for task 9.5 |
 * | `statuses[].status = read` | `READ_RECEIPT` | |
 * | `statuses[].status = failed` | `SEND_FAILURE` | `errors[0]` becomes the redacted `failureReason` |
 * | a top-level `errors[]` with no messages or statuses | `UNSUPPORTED` | the client reporting its own trouble: a verified `200`, not a 403 storm |
 * | anything else verified | `UNSUPPORTED` | including a shape a newer client build introduces |
 *
 * Status monotonicity is **not** enforced here, for `InboundEventKind`'s stated reason: this
 * driver reports what the client said, and task 9.5 applies the never-downgrades rule when it
 * writes the message row (Req 20.6 / C3, Property 9). `occurredAt` is the client's own
 * `timestamp`, never `now()` — a fabricated occurrence time is indistinguishable from a real one
 * afterwards.
 *
 * ## `register()` and `healthCheck()` — state as data, never as a raise
 *
 * 7.2's split exactly. Both read `GET /v1/health`, whose `gateway_status` is the client's own word
 * for what this platform calls a session state:
 *
 * | `gateway_status` | `register()` | `sessionState()` |
 * |---|---|---|
 * | `connected` | `live()` | `CONNECTED` |
 * | `connecting`, `uninitialized`, `stale`, `disconnected` | `pending()`, naming the status | `QR_PENDING` — awaiting the container |
 * | `unregistered` | `pending()` — the number was never registered | `QR_PENDING` |
 *
 * `register()` is **read-only and idempotent**, and deliberately does *not* call
 * `POST /v1/account`. Registering a number on the legacy client is a two-step act with an SMS or
 * voice code and a two-step-verification PIN, and a driver that re-ran it during a session restart
 * could throw away a working registration — the failure `provisionSession()`'s idempotency exists
 * to prevent, one layer up. It also claims no callback URL: the `route_key` is minted by task 8.3,
 * and reporting a URL this driver did not register would put a link on a panel that nothing
 * answers (7.1's reasoning, unchanged). `pointWebhookAt()` is the seam 8.3 calls once it *has* a
 * route key — a real, idempotent `PATCH /v1/settings/application`, which is legitimate here in a
 * way it is not on Cloud API because the container is the tenant's own.
 *
 * `healthCheck()` reports as data: incomplete credentials are `unhealthy()` naming the keys (never
 * probed — which is what task 7.6 needs in order to keep the previous working set), a refusal is
 * `unhealthy()` with a sentence rendered from the client's **code**, and only a genuine transport
 * failure propagates. A `gateway_status` of `unregistered` is `unhealthy()` because the
 * credentials genuinely cannot send; `connecting` is **healthy**, because a reconnecting gateway is
 * weather and 7.6 must not retire a working credential set over it.
 *
 * ## The transport half: eleven inherited methods, and two the legacy client does better
 *
 * | Method | On the On-Premise client |
 * |---|---|
 * | `sendText`, `sendMedia` | **real** — `POST /v1/messages`; inline bytes are uploaded to `/v1/media` first |
 * | `sessionState` | **real** — `GET /v1/health`, per the table above |
 * | `checkNumbers` | **real** — `POST /v1/contacts`, which Cloud API has no equivalent for. 7.2's own docblock names this as *"one of the few things it could do that Cloud API cannot"* |
 * | `isReachable` | **real**, with a caveat — the host is a tenant credential, so this answers *"is the acting tenant's container serving?"*; see the method |
 * | `qr` | **`null`** — a documented answer: an officially-registered number never shows one |
 * | `provisionSession`, `startSession`, `stopSession`, `pairingCode`, `sendPresence` | `ChannelOperationException` (422) — a refusal, never a silent no-op |
 *
 * `stopSession()` is the one to be careful about, for 7.2's reason amplified: the client *does*
 * expose `DELETE /v1/account`, and calling it here would make "stop this session" surrender the
 * number's registration — which on this mode is the single step of the migration path that cannot
 * be undone (`OnPremiseDeprecation::migrationPath()`, step 4). So it refuses, and deregistration
 * stays a deliberate, audited tenant action.
 *
 * ## Deprecation is a deliverable, not a comment
 *
 * `OnPremiseDeprecation` owns it, and this class is three of its four surfaces: the notice
 * prefixes every `ChannelHealth::$detail` and every `RegistrationResult::$detail`, and `login()`
 * emits one `warning` per unit of work — once, because the token cache makes a login happen once,
 * so a 9,000-send campaign warns once and a deployment with no On-Premise tenant never warns at
 * all. The fourth is the mode picker, which reads `ChannelMode::isDeprecated()` and
 * `OnPremiseDeprecation::migrationPath()`.
 */
final readonly class OnPremiseChannelDriver implements ChannelDriver
{
    use DedupesChannelSends;
    use DerivesChannelPolicy;
    use ResolvesOwnedSession;
    use VerifiesProviderSignature;

    /**
     * The header the platform reads an inbound HMAC from.
     *
     * A platform constant rather than a config key, for the reason `CloudApiChannelDriver`
     * hardcodes `X-Hub-Signature-256`: a header name is protocol vocabulary, not an operator
     * preference. Unlike Meta's, this one is **the platform's** vocabulary rather than the
     * provider's, because the legacy client signs nothing — see the class docblock.
     */
    public const string SIGNATURE_HEADER = 'X-Wa-Signature-256';

    /**
     * `ChannelCredentials::config()` keys — §2.7's *"on-prem base URL, phone number"*, plus the
     * two optional client settings the legacy API needs and §2.7 does not list.
     */
    public const string BASE_URL_CONFIG_KEY = 'base_url';

    public const string PHONE_NUMBER_CONFIG_KEY = 'phone_number';

    public const string TEMPLATE_NAMESPACE_CONFIG_KEY = 'template_namespace';

    public const string MEDIA_PROVIDER_CONFIG_KEY = 'media_provider';

    /**
     * `ChannelCredentials::secret()` keys — §2.7's *"client API user/password/token, webhook
     * secret"*.
     */
    public const string USERNAME_SECRET_KEY = 'username';

    public const string PASSWORD_SECRET_KEY = 'password';

    public const string ACCESS_TOKEN_SECRET_KEY = 'access_token';

    public const string WEBHOOK_SECRET_KEY = 'webhook_secret';

    /**
     * The API version segment every route carries.
     *
     * A constant, not config and not a tenant credential: `v1` is the only version the legacy
     * client has ever served, and a configurable one would be a knob whose every value but this
     * produces a 404.
     */
    public const string API_PREFIX = 'v1';

    /**
     * The routes this driver uses, spelled once.
     */
    public const string LOGIN_PATH = 'users/login';

    public const string MESSAGES_PATH = 'messages';

    public const string MEDIA_PATH = 'media';

    public const string HEALTH_PATH = 'health';

    public const string CONTACTS_PATH = 'contacts';

    public const string SETTINGS_PATH = 'settings/application';

    /**
     * What the client calls a gateway that is connected to WhatsApp and able to send.
     */
    public const string CONNECTED_STATUS = 'connected';

    /**
     * The one gateway state that means these credentials can never send until the tenant finishes
     * onboarding — as opposed to the transient states, which are weather.
     */
    public const string UNREGISTERED_STATUS = 'unregistered';

    /**
     * Keys added to every event's payload so a caller holding **one** event can tell that the
     * client batched several into the request.
     *
     * The same literals `CloudApiChannelDriver::BATCH_SIZE_KEY` / `::BATCH_INDEX_KEY` declare, on
     * purpose: task 8.3 reads them without branching on the mode. The duplication is *guarded* the
     * way `DedupesChannelSends` guards its scope literal — `OnPremiseChannelDriverTest` asserts the
     * two classes agree, so the day one of them moves the suite says so.
     */
    public const string BATCH_SIZE_KEY = '_batch_size';

    public const string BATCH_INDEX_KEY = '_batch_index';

    /**
     * Reserved `$vars` keys carrying the two things `ChannelDriver::sendTemplate()`'s signature has
     * no parameter for — 7.2's convention, deliberately identical. See `sendTemplate()`, and prefer
     * `sendTemplateTo()`.
     */
    public const string TEMPLATE_RECIPIENT_VAR = '_to';

    public const string TEMPLATE_KEY_VAR = '_key';

    /**
     * Timeouts, with compiled-in fallbacks so a deleted config key degrades to a working driver.
     *
     * More generous than Cloud API's, and for a reason rather than by accident: the container is a
     * single self-hosted process, often on modest hardware, and its `POST /v1/messages` blocks
     * until the WhatsApp gateway has accepted the message. `graph.facebook.com` does not.
     */
    public const int DEFAULT_TIMEOUT = 20;

    public const int DEFAULT_CONNECT_TIMEOUT = 5;

    /**
     * Health probes get a shorter budget than sends: the point of asking is to find out quickly,
     * and a probe that waits twenty seconds to say "down" has already stalled the screen it feeds
     * (`HttpBridgeClient::HEALTH_TIMEOUT`'s reasoning).
     */
    private const int HEALTH_TIMEOUT = 5;

    public function __construct(
        private HttpFactory $http,
        private ChannelCredentialStore $credentials,
        private IdempotencyStore $idempotency,
        private ProviderCallGuard $guard,
        private OnPremiseTokenCache $tokens,
        private TenantContext $tenants,
    ) {}

    public function mode(): ChannelMode
    {
        return ChannelMode::OnPremise;
    }

    /*
    |--------------------------------------------------------------------------
    | Outbound
    |--------------------------------------------------------------------------
    */

    /**
     * Send one message through the tenant's On-Premise client, at most once per idempotency key.
     *
     * @throws ChannelCredentialException when the tenant has no usable `ON_PREMISE` credentials
     * @throws ChannelRequestFailedException when the client refuses the send
     * @throws BridgeUnreachableException when the container never answered, so the outcome is unknown
     * @throws IdempotencyKeyReuseException when the key was first used for a different send
     * @throws OperationInFlightException when a duplicate is still in flight
     */
    public function send(Session $session, OutboundContent $content): SendReceipt
    {
        $capability = $content->capability();
        $credentials = $this->credentialsFor($session);

        // Read from the matrix, not decided here — `❌` never arrives, because the router refused
        // it before dispatch (Property 21).
        $degraded = ! $this->supportFor($capability)->isNative();

        $outcome = $this->idempotency->once(
            self::sendScope($session),
            $content->idempotencyKey(),
            // The one dispatch arm; see the class docblock for why there is not yet a second.
            fn (): array => $this->dispatch(
                $credentials,
                OnPremiseMessage::text($content->recipient(), $content->plainText()),
                'on_premise.message.text',
                $degraded,
            ),
            $this->sendOptions($session, $this->sendFingerprint(
                $session,
                $capability,
                $content->recipient(),
                $content->plainText(),
            )),
        );

        return $this->receipt($content->idempotencyKey(), $capability, $outcome->payload());
    }

    /**
     * Send a pre-approved template, at most once per idempotency key.
     *
     * ## The reserved `$vars` keys, and the contract gap behind them
     *
     * Deliberately the **same** convention `CloudApiChannelDriver::sendTemplate()` established, for
     * the same reason and with the same reserved names: design § 2.4 declares
     * `sendTemplate(Session, TemplateRef, array $vars)`, and that signature is missing *who* the
     * template goes to and the idempotency key it is deduplicated on. Neither can be derived — the
     * `Session` is the *sender's* number, and `SendReceipt` rightly refuses to exist without a
     * recipient and a key.
     *
     * So `_to` and `_key` are read from `$vars`, and `sendTemplateTo()` is the honest signature that
     * does the work. The names cannot collide with a real placeholder: the legacy client numbers its
     * body parameters `1`, `2`, … and names the rest `[a-z0-9_]` **not** starting with an
     * underscore.
     *
     * Two drivers using one convention is the point. Task 8.4 owns template sending end to end and
     * should replace both with a `TemplateContent implements OutboundContent` — it already answers
     * `capability()`, `recipient()`, `idempotencyKey()` and `plainText()`, which is exactly the four
     * facts missing here — and then this convention can be deleted from both drivers at once.
     *
     * `$vars` is typed `array-key` rather than the contract's `string`, for 7.2's reason: PHP
     * silently coerces the array key `'1'` to the integer `1`, so `['1' => 'Asha']` is an
     * `array<int, string>` by the time any callee sees it and **no positional template can satisfy
     * the declared type**. A parameter type may only be widened, so this is legal; task 8.4 should
     * widen the interface to match.
     *
     * @param  array<array-key, string|int|float>  $vars  placeholder values, plus `_to` and `_key`
     *
     * @throws InvalidArgumentException when `_to` or `_key` is absent
     * @throws ChannelTemplateException when the template is unknown, not approved, or mis-filled
     * @throws ChannelCredentialException when the tenant has no usable `ON_PREMISE` credentials
     * @throws ChannelRequestFailedException when the client refuses the send
     * @throws BridgeUnreachableException when the container never answered
     */
    public function sendTemplate(Session $session, TemplateRef $template, array $vars): SendReceipt
    {
        $recipient = $this->reservedVar($vars, self::TEMPLATE_RECIPIENT_VAR, 'the recipient');
        $idempotencyKey = $this->reservedVar($vars, self::TEMPLATE_KEY_VAR, 'the idempotency key');

        unset($vars[self::TEMPLATE_RECIPIENT_VAR], $vars[self::TEMPLATE_KEY_VAR]);

        return $this->sendTemplateTo($session, $template, $vars, $recipient, $idempotencyKey);
    }

    /**
     * `sendTemplate()` with the two parameters the contract's signature lacks — the method task 8.4
     * should call.
     *
     * The order is: credentials → **re-read the template row** → check the variables → build the
     * client's component payload → POST. Everything before the POST is a local refusal, because
     * `ChannelDriver::sendTemplate()` requires that a mis-filled template be *"a local failure and
     * not a provider rejection charged to the tenant's quality rating"* — and on this mode the
     * rejection would be charged by the tenant's own container, which makes it no less real.
     *
     * The row is re-read on every send (`CloudApiTemplate::sendable()`), never taken from the
     * `TemplateRef`: that type carries only `(name, language, credentialId)` precisely so a template
     * that was paused five minutes ago cannot still look sendable.
     *
     * **The 24-hour-window rule is task 8.4's and is deliberately absent**, exactly as it is from
     * 7.2 — and it matters more here, because `ON_PREMISE` is the mode where design.md's two
     * documents disagree about whether the window applies at all
     * (`ChannelMode::enforcesSessionWindow()` resolves that, conservatively, and this driver reports
     * both axes rather than choosing). Nothing here consults a conversation's last-inbound
     * timestamp, and nothing here should: a window check that lived in two places would disagree the
     * day one of them was fixed.
     *
     * @param  array<array-key, string|int|float>  $vars  placeholder values, keyed as the template numbers them
     *
     * @throws ChannelTemplateException when the template is unknown, not approved, or mis-filled
     * @throws ChannelCredentialException when the tenant has no usable `ON_PREMISE` credentials
     * @throws ChannelRequestFailedException when the client refuses the send
     * @throws BridgeUnreachableException when the container never answered
     */
    public function sendTemplateTo(
        Session $session,
        TemplateRef $template,
        array $vars,
        string $recipient,
        string $idempotencyKey,
    ): SendReceipt {
        $credentials = $this->credentialsFor($session);
        $row = $this->sendableTemplate($credentials, $template);
        $components = $this->templateComponents($template, $row, $vars);
        $namespace = BridgeWire::stringOrNull($credentials->config(self::TEMPLATE_NAMESPACE_CONFIG_KEY));

        $outcome = $this->idempotency->once(
            self::sendScope($session),
            $idempotencyKey,
            fn (): array => $this->dispatch(
                $credentials,
                OnPremiseMessage::template($recipient, $row->name, $row->language, $components, $namespace),
                'on_premise.message.template',
                degraded: false,
                templateName: $row->name,
            ),
            $this->sendOptions($session, $this->sendFingerprint(
                $session,
                ChannelCapability::Template,
                $recipient,
                $row->body,
                // So one key cannot answer a template send with a free-form send's receipt, or one
                // template's with another's.
                discriminator: $template->key().'|'.json_encode($vars),
            )),
        );

        return $this->receipt($idempotencyKey, ChannelCapability::Template, $outcome->payload());
    }

    /*
    |--------------------------------------------------------------------------
    | Inbound
    |--------------------------------------------------------------------------
    */

    /**
     * Verify an On-Premise callback POST and normalise **the first** event it carries.
     *
     * The contract's method, and the reason `parseWebhookBatch()` exists directly below it: the
     * client batches, and one `InboundEvent` cannot express several. Task 8.3 must call the batch
     * method — the class docblock sets out what is dropped if it does not, and every returned event
     * carries `_batch_size` / `_batch_index` so the loss is detectable rather than silent.
     *
     * @throws WebhookVerificationException when origin, recipient, or shape does not check out
     * @throws ChannelCredentialException when the tenant stored no webhook secret to verify against
     */
    public function parseWebhook(Request $request, ChannelCredentials $credentials): InboundEvent
    {
        $events = $this->parseWebhookBatch($request, $credentials);

        // `parseWebhookBatch()` never returns an empty list — a verified body with nothing to act on
        // yields exactly one `UNSUPPORTED` event — so this index is always present. The fallback is
        // not defensive programming; it is what makes the guarantee readable.
        return $events[0] ?? InboundEvent::unsupported(
            $this->mode(),
            $credentials->tenantId,
            $credentials->requireConfig(self::PHONE_NUMBER_CONFIG_KEY),
        );
    }

    /**
     * Verify an On-Premise callback POST once and normalise **every** event it carries, in document
     * order.
     *
     * ```php
     * // task 8.3's controller, after resolving (tenant, session, driver) from the route key
     * foreach ($driver->parseWebhookBatch($request, $credentials) as $event) {
     *     if ($event->isActionable()) { $this->dispatch($event); }
     * }
     * return response()->noContent();   // one 200 for the whole batch
     * ```
     *
     * Order of checks is the contract's — origin → recipient → shape. See the class docblock for why
     * the recipient step is a *conditional* refusal on this mode rather than an unconditional
     * comparison, and why that is not a weakening.
     *
     * Never empty: a verified body this release does not act on — the client reporting its own
     * `errors[]`, or a shape a newer build introduces — yields one `UNSUPPORTED` event, which is a
     * successful parse and a `200` so the client stops retrying it.
     *
     * @return non-empty-list<InboundEvent>
     *
     * @throws WebhookVerificationException when origin, recipient, or shape does not check out
     * @throws ChannelCredentialException when the tenant stored no webhook secret to verify against
     */
    public function parseWebhookBatch(Request $request, ChannelCredentials $credentials): array
    {
        // From the caller, never from the body. A missing key is a wiring defect in task 8.3's
        // controller, so `requireConfig()`'s InvalidArgumentException is the right answer: it is not
        // something a request can provoke.
        $phoneNumber = $credentials->requireConfig(self::PHONE_NUMBER_CONFIG_KEY);

        // 1. Origin. Over the raw bytes, in constant time, under the tenant's webhook secret.
        $this->assertSignature($request, self::SIGNATURE_HEADER, $this->webhookSecret($credentials));

        $payload = $this->decodeWebhook($request);

        // 2. Recipient. The credentials' answer is the only answer; a claim in the body is checked
        // *against* it and never read in place of it (Req 8.4, Property 23).
        $this->assertRecipient($payload, $phoneNumber);

        $events = [];

        foreach (BridgeWire::listOrEmpty($payload['messages'] ?? null) as $message) {
            $events[] = $this->messageEvent(
                BridgeWire::arrayOrEmpty($message),
                BridgeWire::listOrEmpty($payload['contacts'] ?? null),
                $credentials,
                $phoneNumber,
            );
        }

        foreach (BridgeWire::listOrEmpty($payload['statuses'] ?? null) as $status) {
            $events[] = $this->statusEvent(BridgeWire::arrayOrEmpty($status), $credentials, $phoneNumber);
        }

        if ($events === []) {
            // A verified body with neither messages nor statuses: the client's own `errors[]`
            // notification, a `contacts`-only body, or a shape this release does not model. A `200`
            // rather than a 403 — refusing it would turn every client feature into a retry storm.
            return [InboundEvent::unsupported($this->mode(), $credentials->tenantId, $phoneNumber, $payload)];
        }

        return $this->stamped($events);
    }

    /*
    |--------------------------------------------------------------------------
    | Credentials & onboarding
    |--------------------------------------------------------------------------
    */

    /**
     * Report what the tenant's container says about this number, without changing it.
     *
     * One call — `GET /v1/health` — and the `gateway_status` mapping in the class docblock. Reports
     * state, does not throw for a state: `live()` when the gateway is `connected`, `pending()` for
     * every other status including `unregistered`. Task 9.1 keeps a `pending()` session out of
     * `SENDABLE`, which is exactly right — its first send would be refused by the client.
     *
     * **Read-only and idempotent**, and deliberately not `POST /v1/account`: see the class docblock
     * for why a driver must never re-run the legacy client's registration. A registration that
     * *failed at the provider* is still an exception, as the contract requires — that is the guarded
     * call's `ChannelRequestFailedException`.
     *
     * No callback URL is claimed. `pointWebhookAt()` is the seam task 8.3 calls once it has minted a
     * `route_key`; reporting a URL this driver did not register would put a link on a panel that
     * nothing answers.
     *
     * The `detail` carries the deprecation notice, so the one place an operator reads a session's
     * registration record says what to do about this mode (Req 8.9).
     *
     * @throws ChannelRequestFailedException when the client refuses the probe
     * @throws BridgeUnreachableException when the container never answered
     */
    public function register(Session $session, ChannelCredentials $credentials): RegistrationResult
    {
        $phoneNumber = $credentials->requireConfig(self::PHONE_NUMBER_CONFIG_KEY);
        $status = $this->gatewayStatus($this->authorisedGet('on_premise.register', $credentials, self::HEALTH_PATH));

        if ($status !== self::CONNECTED_STATUS) {
            return RegistrationResult::pending($credentials, OnPremiseDeprecation::prefix(sprintf(
                $status === self::UNREGISTERED_STATUS
                    ? 'This number is not registered on the On-Premise client (gateway %s). Complete '
                        .'registration on the container before the session can send.'
                    : 'The On-Premise client reports its gateway as %s. The session becomes sendable '
                        .'once it reports "'.self::CONNECTED_STATUS.'".',
                $status ?? 'unknown',
            )), providerNumberId: $phoneNumber);
        }

        return RegistrationResult::live(
            $credentials,
            // The tenant's own number, from config: unlike Cloud API there is no provider-assigned
            // id to echo back, because the container *is* the registration.
            $phoneNumber,
            detail: OnPremiseDeprecation::prefix(
                'The On-Premise client reports its gateway connected, so this number can send. No '
                .'callback URL is claimed here: the route key is minted when the webhook route is '
                .'created, and the container is pointed at it then.'
            ),
        );
    }

    /**
     * Point the container's webhook at `$callbackUrl` — `PATCH /v1/settings/application`.
     *
     * The seam task 8.3 calls once it has minted a `route_key` and built the URL with
     * `App\Services\Url\UrlBuilder::webhook()`. Separate from `register()` for two reasons: the route
     * key does not exist when a session is registered, and this is the one **write** in this class
     * that changes the tenant's container — so it is an explicit act with its own call site rather
     * than a side effect of a status probe.
     *
     * Idempotent: setting the same URL twice is the same PATCH. The URL is required to be **HTTPS**,
     * because the platform builds it from the canonical base over HTTPS (Req 9.2 / A9) and a driver
     * that accepted an `http://` one would let a misconfiguration send verified customer content
     * across the internet in the clear.
     *
     * @throws InvalidArgumentException when the URL is not an absolute HTTPS URL
     * @throws ChannelRequestFailedException when the client refuses the settings write
     * @throws BridgeUnreachableException when the container never answered
     */
    public function pointWebhookAt(ChannelCredentials $credentials, string $callbackUrl): void
    {
        $url = trim($callbackUrl);

        if (! str_starts_with(strtolower($url), 'https://') || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException(
                'An On-Premise callback URL must be an absolute https:// URL built from the platform\'s '
                .'canonical base: verified customer messages travel over it, and the shared secret that '
                .'authenticates them is in a header.'
            );
        }

        $this->withFreshToken(
            'on_premise.settings.webhook',
            $credentials,
            fn (string $token): array => $this->guarded(
                'on_premise.settings.webhook',
                $credentials,
                fn (): Response => $this->request($this->timeout())
                    ->withToken($token)
                    ->patch($this->containerUrl($credentials, self::SETTINGS_PATH), [
                        'webhooks' => ['url' => $url],
                    ]),
            ),
        );
    }

    /**
     * Probe whether `$credentials` actually work, and report it as **data**.
     *
     * What task 7.6 calls before activating a credential set on save or rotate, and what the
     * connection screen's light reads. Four answers, and only one of them is an exception:
     *
     * | Situation | Answer |
     * |---|---|
     * | a required key is missing | `unhealthy()`, naming the keys — so 7.6 keeps the previous working set |
     * | the client refused the login or the probe | `unhealthy()`, with a sentence rendered from its **code** |
     * | the gateway is `unregistered` | `unhealthy()` — the credentials are right and cannot send until onboarding finishes |
     * | the container could not be reached at all | `BridgeUnreachableException` propagates |
     *
     * A gateway that is `connecting`, `stale` or `disconnected` is **healthy**: those are transient,
     * and a credential set retired because a container was reconnecting is a working number taken
     * offline by a health check — the exact failure 7.6 exists to prevent.
     *
     * The last row is the contract's own division: *"'we could not ask' and 'the provider said no'
     * lead to different operator actions"*. No `detail` ever quotes the client's prose — its `401`
     * body echoes the Basic auth it was sent — and `ChannelHealth` passes even the rendered sentence
     * through `ChannelCredentials::redact()` on the way in.
     *
     * Read-only: it logs in and reads one route, and changes nothing about a credential set that is
     * about to be rejected.
     */
    public function healthCheck(ChannelCredentials $credentials): ChannelHealth
    {
        $missing = $this->missingCredentialKeys($credentials);

        if ($missing !== []) {
            return ChannelHealth::unhealthy($credentials, OnPremiseDeprecation::prefix(sprintf(
                'These On-Premise credentials are incomplete: %s. The client refuses every request '
                .'without them, so they are refused here rather than probed.',
                implode(', ', $missing),
            )));
        }

        $startedAt = hrtime(true);

        try {
            $payload = $this->authorisedGet('on_premise.health', $credentials, self::HEALTH_PATH, self::HEALTH_TIMEOUT);
        } catch (ChannelRequestFailedException $refused) {
            return ChannelHealth::unhealthy(
                $credentials,
                OnPremiseDeprecation::prefix($this->healthDetailFor($refused)),
                $this->elapsedMs($startedAt),
            );
        }

        $latencyMs = $this->elapsedMs($startedAt);
        $status = $this->gatewayStatus($payload);

        if ($status === null) {
            // A 200 whose body is not a health report: the client answered, and the answer does not
            // establish that these credentials can send. Reported rather than raised, because 7.6's
            // decision is the same either way.
            return ChannelHealth::unhealthy(
                $credentials,
                OnPremiseDeprecation::prefix(
                    'The On-Premise client answered the credential probe without reporting a gateway '
                    .'status, so these credentials could not be confirmed.'
                ),
                $latencyMs,
            );
        }

        if ($status === self::UNREGISTERED_STATUS) {
            return ChannelHealth::unhealthy(
                $credentials,
                OnPremiseDeprecation::prefix(
                    'The client accepted these credentials, and its gateway is unregistered: this '
                    .'number has not completed registration on the container, so it cannot send.'
                ),
                $latencyMs,
            );
        }

        return ChannelHealth::healthy($credentials, OnPremiseDeprecation::prefix(sprintf(
            'The On-Premise client accepted these credentials; its gateway reports "%s".',
            $status,
        )), $latencyMs);
    }

    /*
    |--------------------------------------------------------------------------
    | Transport — the eleven inherited methods (see the class docblock's table)
    |--------------------------------------------------------------------------
    */

    /**
     * Refused: a number on the legacy client is **registered**, not paired.
     *
     * There is no QR to scan and no socket to provision — design § 2.5 gives the official modes
     * `driver->register(...)` and leaves QR pairing to Baileys, so task 9.1 branches by mode and
     * never reaches this. A no-op returning a plausible `SessionInitDto` would mark the session
     * provisioned without the container having been asked anything.
     *
     * Registration itself is `POST /v1/account` plus `POST /v1/account/verify` with an SMS or voice
     * code and a two-step-verification PIN — a tenant onboarding act, not a transport call, and one a
     * retried provision must never re-run.
     *
     * @throws ChannelOperationException always
     */
    public function provisionSession(
        string $sessionId,
        SessionLoginMethod $method,
        ?string $phone = null,
        ?string $authStateDir = null,
    ): SessionInitDto {
        throw ChannelOperationException::unsupported($this->mode(), 'provisionSession',
            'a number on the On-Premise client is registered against the container with an SMS or '
            .'voice code and a two-step PIN rather than paired — use register(), and read its state '
            .'with sessionState()'
        );
    }

    /**
     * Refused: there is no socket this platform opens.
     *
     * The container holds the protocol connection and manages it itself; nothing on the platform's
     * side is started or stopped. Refusing rather than no-opping is what stops a session-restart
     * sweep from reporting success for a number it never touched.
     *
     * @throws ChannelOperationException always
     */
    public function startSession(string $sessionId): void
    {
        throw ChannelOperationException::unsupported($this->mode(), 'startSession',
            'the On-Premise container owns its own gateway connection — the session is sendable while '
            .'that gateway reports connected, which sessionState() and healthCheck() report'
        );
    }

    /**
     * Refused, and deliberately **not** mapped onto the client's `DELETE /v1/account`.
     *
     * The client does expose deregistration, and calling it here would make an ordinary "stop this
     * session" surrender the number's registration — which on this mode is step 4 of the migration
     * path, the step that cannot be undone without a fresh SMS or voice verification
     * (`OnPremiseDeprecation::migrationPath()`). Deregistration stays a deliberate, audited tenant
     * action, which is the mode-switch task's (8.6).
     *
     * @throws ChannelOperationException always
     */
    public function stopSession(string $sessionId, bool $logout = false): void
    {
        throw ChannelOperationException::unsupported($this->mode(), 'stopSession',
            'an On-Premise number has no session this platform closes, and deregistering it is the one '
            .'irreversible step of the migration to CLOUD_API rather than a side effect of stopping one'
        );
    }

    /**
     * The container's live view of this number, as a session state.
     *
     * `GET /v1/health`: a `connected` gateway is `CONNECTED`, and every other status is `QR_PENDING`
     * — *awaiting the backend*, which is the same meaning that status carries for a Baileys session
     * awaiting a scan. It is a **report**, not the truth: `sessions_wa.status` remains the state of
     * record, and `SessionStatus::canTransitionTo()` may refuse what this says
     * (`SessionStateDto`'s own docblock).
     *
     * `phone` is the tenant's configured number rather than anything the container echoed: the
     * legacy `/v1/health` route describes the gateway and not the account, so a number read from
     * config is the only honest answer and it is the one `sessions_wa.phone` already holds.
     *
     * @throws ChannelRequestFailedException when the client refuses the lookup
     * @throws BridgeUnreachableException when the container never answered
     */
    public function sessionState(string $sessionId): SessionStateDto
    {
        $session = $this->ownedSession($sessionId);
        $credentials = $this->credentialsFor($session);

        $status = $this->gatewayStatus(
            $this->authorisedGet('on_premise.session.state', $credentials, self::HEALTH_PATH),
        );

        $connected = $status === self::CONNECTED_STATUS;

        return new SessionStateDto(
            sessionId: $session->id,
            status: $connected ? SessionStatus::Connected : SessionStatus::QrPending,
            phone: self::digitsOrNull(BridgeWire::stringOrNull($credentials->config(self::PHONE_NUMBER_CONFIG_KEY))),
            pushName: null,
            disconnectReason: $connected ? null : $status,
        );
    }

    /**
     * `null`, always — and that is an answer rather than a failure.
     *
     * `BridgeClient::qr()` already documents `null` as *"this session is not showing one"*, which is
     * permanently true of a number registered against a Business API client instead of paired. A
     * connection screen that asks every driver for a QR therefore renders nothing for this mode
     * without having to know which mode it is looking at.
     */
    public function qr(string $sessionId): ?string
    {
        return null;
    }

    /**
     * Refused: pairing codes belong to the WhatsApp Web protocol.
     *
     * The one transport method whose return type (`string`) has no honest empty value — an empty or
     * invented code would be shown to a tenant as something to type into their phone.
     *
     * @throws ChannelOperationException always
     */
    public function pairingCode(string $sessionId, string $phone): string
    {
        throw ChannelOperationException::unsupported($this->mode(), 'pairingCode',
            'pairing codes are a WhatsApp Web login method; an On-Premise number is verified against '
            .'the container with a one-time SMS or voice code during registration'
        );
    }

    /**
     * One text message on the wire — the transport primitive, not the driver-level send.
     *
     * Not idempotent, exactly as `BridgeClient::sendText()` is not: *"it puts one message on the wire
     * each time it is called"*. Deduplication is `send()`'s, above.
     *
     * `$opts` is read through a documented allowlist rather than merged into the body, because the
     * client **rejects** unknown fields (`1010`): passing a caller's map through would turn a typo
     * into a refusal counted against the number.
     *
     * | `$opts` key | Client field |
     * |---|---|
     * | `preview_url` / `link_preview` (bool) | `text.preview_url` |
     * | `reply_to` (message id) | `context.message_id` |
     *
     * @param  array<string, mixed>  $opts
     *
     * @throws ChannelRequestFailedException when the client refuses the send
     * @throws BridgeUnreachableException when the container never answered
     */
    public function sendText(string $sessionId, string $jid, string $text, array $opts = []): SentMessageDto
    {
        $session = $this->ownedSession($sessionId);
        $credentials = $this->credentialsFor($session);

        return $this->postMessage(
            'on_premise.message.text',
            $credentials,
            OnPremiseMessage::text(
                $jid,
                $text,
                previewUrl: BridgeWire::boolOrNull($opts['preview_url'] ?? $opts['link_preview'] ?? null) ?? false,
                replyTo: BridgeWire::stringOrNull($opts['reply_to'] ?? null),
            ),
        );
    }

    /**
     * One media message on the wire.
     *
     * The legacy client has **no inline-bytes field** and fetches a `link` only through a media
     * provider the operator configured on the container. So:
     *
     * - a `MediaPayload::fromBytes()` is uploaded to `POST /v1/media` first and sent by id;
     * - a `MediaPayload::fromUrl()` is sent by `link`, with the tenant's configured
     *   `media_provider` named beside it when there is one.
     *
     * The third case — a link with no configured provider — is sent as a bare `link` and refused by
     * the client with `1009`, which surfaces as a typed `ChannelRequestFailedException` the panel
     * shows. That is deliberate rather than a gap: the alternative would be for the platform to fetch
     * the tenant's URL itself and re-upload it, which turns every media send into a
     * platform-initiated request to a tenant-supplied address — the SSRF surface `containerUrl()`
     * exists to bound, reintroduced on a hot path. A configuration gap reported as one is the better
     * trade, and `healthCheck()` is where a tenant is told which optional keys are unset.
     *
     * @param  array<string, mixed>  $opts  accepted for contract compatibility; the client's media
     *                                      message has no option Baileys' `$opts` maps onto, and
     *                                      passing unknown fields through would be a `1010`
     *
     * @throws ChannelRequestFailedException when the client refuses the upload or the send
     * @throws BridgeUnreachableException when the container never answered
     */
    public function sendMedia(string $sessionId, string $jid, MediaPayload $media, array $opts = []): SentMessageDto
    {
        $session = $this->ownedSession($sessionId);
        $credentials = $this->credentialsFor($session);

        $uploadedId = $media->base64 === null ? null : $this->uploadMedia($credentials, $media);

        return $this->postMessage(
            'on_premise.message.media',
            $credentials,
            OnPremiseMessage::media(
                $jid,
                $media,
                $uploadedId,
                BridgeWire::stringOrNull($credentials->config(self::MEDIA_PROVIDER_CONFIG_KEY)),
            ),
        );
    }

    /**
     * Refused: the Business API client has no presence channel.
     *
     * There is no "typing…" a registered business number can broadcast, so a silent no-op would leave
     * a caller believing a presence indicator was sent. A caller that wants humane pacing on this
     * mode already has one — `requiresAntiBan()` is `true` here, so the anti-ban engine's own pacing
     * applies (Property 24), which is a better answer than a presence update the protocol never
     * sends.
     *
     * @throws ChannelOperationException always
     */
    public function sendPresence(string $sessionId, string $jid, PresenceState $presence): void
    {
        throw ChannelOperationException::unsupported($this->mode(), 'sendPresence',
            'the WhatsApp Business API client has no presence channel — pacing on this mode comes from '
            .'the anti-ban engine, which applies here because ON_PREMISE is a web-protocol mode'
        );
    }

    /**
     * Which of `$numbers` are on WhatsApp — **really asked**, which is this mode's one advantage
     * over Cloud API.
     *
     * `POST /v1/contacts` with `blocking=wait` is the contacts endpoint
     * `CloudApiChannelDriver::checkNumbers()` has to answer `unknown` for, and its own docblock names
     * the gap: *"the Cloud API has no contacts/`onWhatsApp` endpoint (the On-Premise API did, which
     * is one of the few things it could do that Cloud API cannot)"*. It did, and this is it.
     *
     * The client answers `{"contacts": [{"input": "…", "status": "valid"|"invalid"|"processing", "wa_id": "…"}]}`,
     * and the three statuses map onto `NumberCheck`'s three-valued `exists` without losing the middle
     * one:
     *
     * | `status` | `exists` |
     * |---|---|
     * | `valid` | `true` |
     * | `invalid`, `failed` | `false` |
     * | `processing`, or a status this release does not know | `null` — *unknown* |
     *
     * `processing` becoming `null` rather than `false` is the row that matters. `NumberCheck`'s own
     * docblock states the consequence of collapsing it: *"a rate limit [would] be recorded as a
     * permanent fact about somebody's phone number"* — and here it would be an *in-progress lookup*
     * recorded as one, with every later campaign skipping a real customer for ever.
     *
     * `force_check` is `false`, so the client answers from its own cache where it can. A forced check
     * is a WhatsApp-side lookup per number, and the anti-ban engine exists because that kind of
     * traffic is what gets a number limited.
     *
     * @param  list<string>  $numbers
     * @return array<array-key, NumberCheck>
     *
     * @throws ChannelRequestFailedException when the client refuses the lookup
     * @throws BridgeUnreachableException when the container never answered
     */
    public function checkNumbers(string $sessionId, array $numbers): array
    {
        $session = $this->ownedSession($sessionId);

        if ($numbers === []) {
            // Nothing to ask, and asking anyway is a request the client charges against its own
            // rate. Ownership was still resolved above, so a caller cannot learn whether a session
            // id exists from whether this method answers.
            return [];
        }

        $credentials = $this->credentialsFor($session);
        $operation = 'on_premise.contacts.check';

        $payload = $this->withFreshToken(
            $operation,
            $credentials,
            fn (string $token): array => $this->guarded(
                $operation,
                $credentials,
                fn (): Response => $this->request($this->timeout())
                    ->withToken($token)
                    ->post($this->containerUrl($credentials, self::CONTACTS_PATH), [
                        'blocking' => 'wait',
                        'contacts' => array_values(array_map(
                            static fn (string $number): string => trim($number),
                            $numbers,
                        )),
                        'force_check' => false,
                    ]),
            ),
        );

        return $this->numberChecks($numbers, $payload);
    }

    /**
     * Whether the **acting tenant's** container is answering at all.
     *
     * The one method in the contract whose meaning genuinely changes on this mode, and the reason is
     * in design § 2.7: the base URL is a *tenant credential*, so there is no single host to probe.
     * `BridgeClient::isReachable()` asks *"is the dependency up?"*, and for `ON_PREMISE` the
     * dependency is one container per tenant — so this answers about the tenant currently bound to
     * `TenantContext`.
     *
     * Credential-free apart from the base URL, deliberately: **any** HTTP answer counts as reachable,
     * including a `401`. An unauthenticated `GET /v1/health` is *supposed* to be refused, and a
     * refusal proves the container is serving — `CloudApiChannelDriver::isReachable()`'s rule, and
     * `HttpBridgeClient`'s before it.
     *
     * `false` when there is no bound tenant, no credential set, or no usable base URL: those are all
     * *"we could not ask"*, and this is the one method in the contract that reports a failure as a
     * value rather than an exception, so every way of failing to ask is a `false`.
     */
    public function isReachable(): bool
    {
        $tenant = $this->tenants->current();

        if ($tenant === null) {
            return false;
        }

        try {
            $credentials = $this->credentials->for($tenant, ChannelMode::OnPremise);

            if ($credentials === null) {
                return false;
            }

            $this->request(self::HEALTH_TIMEOUT)->get($this->containerUrl($credentials, self::HEALTH_PATH));

            return true;
        } catch (Throwable) {
            // Every way of failing to ask is a "no" — including a malformed base URL and a
            // cross-tenant refusal from the store, neither of which this method may surface as an
            // exception.
            return false;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Credentials
    |--------------------------------------------------------------------------
    */

    /**
     * The tenant's decrypted `ON_PREMISE` credentials for `$session`, or a refusal.
     *
     * Resolved through `ChannelCredentialStore` on **every** call rather than memoised, which is
     * Req 8.5 plus one practical consequence: a password the tenant has just rotated is picked up by
     * the next send instead of by the next worker restart — and because `OnPremiseTokenCache`'s key
     * is derived from that password, the bearer token is re-minted with it rather than carried over.
     *
     * `$session->tenant` rather than the acting tenant, and that difference is load-bearing: the
     * store refuses a lookup for a tenant other than the bound one (`CrossTenantAccessException`), so
     * asking about the session's owner is what turns a foreign session into a 403 instead of a send
     * authenticated with the caller's own container credentials.
     *
     * @throws ChannelCredentialException when the tenant has no usable credential set (Req 8.13)
     */
    private function credentialsFor(Session $session): ChannelCredentials
    {
        $credentials = $this->credentials->for($session->tenant, ChannelMode::OnPremise);

        if ($credentials === null || ! $credentials->isComplete()) {
            // Refused, never rerouted onto BAILEYS — see `ChannelCredentialException` for why a
            // silent reroute would send a brand's official traffic from a number it never registered.
            throw ChannelCredentialException::missing(ChannelMode::OnPremise, $session->tenant_id);
        }

        return $credentials;
    }

    /**
     * The tenant's inbound webhook secret, or a refusal naming the configuration defect.
     *
     * A route whose secret is absent cannot verify **anything**, so the payload is refused either
     * way. It is refused as a *credential* problem rather than as a bad signature because the two
     * send an operator to different places: one is a tenant field left blank, the other is a rotated
     * secret or a forgery.
     *
     * @throws ChannelCredentialException when no webhook secret is stored
     */
    private function webhookSecret(ChannelCredentials $credentials): string
    {
        $secret = $credentials->secret(self::WEBHOOK_SECRET_KEY);

        if ($secret === null) {
            throw ChannelCredentialException::missing($this->mode(), $credentials->tenantId);
        }

        return $secret;
    }

    /**
     * Which of §2.7's required keys are absent — names only, never values.
     *
     * What `healthCheck()` reports instead of probing, so task 7.6 can tell a tenant which field to
     * fill in.
     *
     * `username` and `password` are reported as one pair, and only when no `access_token` is stored:
     * either the tenant lets the platform log in, or it supplies a token minted out of band, and
     * naming both halves of the alternative it did not choose would be noise. `webhook_secret` is
     * required even though a *send* works without it, for `CloudApiChannelDriver`'s reason: without
     * it no inbound message can ever be verified, and a connection that can only talk is not
     * connected.
     *
     * `template_namespace` and `media_provider` are **not** required. Each is needed only by a
     * client build or a media shape that may not apply, and a health check that refused a working
     * credential set over an unused optional setting would be the failure 7.6 exists to prevent.
     *
     * @return list<string>
     */
    private function missingCredentialKeys(ChannelCredentials $credentials): array
    {
        $missing = [];

        foreach ([self::BASE_URL_CONFIG_KEY, self::PHONE_NUMBER_CONFIG_KEY] as $key) {
            $value = $credentials->config($key);

            if ((! is_string($value) && ! is_int($value)) || trim((string) $value) === '') {
                $missing[] = $key;
            }
        }

        if (! $credentials->hasSecret(self::ACCESS_TOKEN_SECRET_KEY)) {
            foreach ([self::USERNAME_SECRET_KEY, self::PASSWORD_SECRET_KEY] as $key) {
                if (! $credentials->hasSecret($key)) {
                    $missing[] = $key;
                }
            }
        }

        if (! $credentials->hasSecret(self::WEBHOOK_SECRET_KEY)) {
            $missing[] = self::WEBHOOK_SECRET_KEY;
        }

        return $missing;
    }

    /*
    |--------------------------------------------------------------------------
    | Authentication — the bearer token, its expiry, and the one replay
    |--------------------------------------------------------------------------
    */

    /**
     * Run one authorised call, and if the client says the bearer token is no good, mint a new one and
     * run it **exactly once** more.
     *
     * The mechanism the class docblock's third row describes, and the reason
     * `OnPremiseErrorClassifier` may map `1005` to `AUTH` without that costing a campaign. Three
     * properties, each deliberate:
     *
     * 1. **Outside the guard, not inside it.** `ProviderCallGuard`'s inline retry decides from the
     *    retry matrix, and `AUTH` is structurally zero attempts — so a retry *there* would never
     *    happen, and making it happen would mean weakening `AUTH` for every mode. Re-authentication
     *    is not a retry of a failed call; it is a *different* call with a different credential, which
     *    is why it lives one layer out.
     * 2. **Exactly one replay.** A second would be a login loop against a container that has decided
     *    to say no — the worst shape of failure, because it looks like a slow send rather than a
     *    refused one.
     * 3. **Only when a login is possible.** A deployment storing a long-lived `access_token` has
     *    nothing to refresh (`canReauthenticate()`), so its refusal passes straight through as the
     *    credential failure it is.
     *
     * The cached token is discarded *before* the replay, so the second attempt cannot be made with
     * the value that was just refused.
     *
     * @param  callable(string): array<string, mixed>  $call  performs the request with a bearer token
     * @return array<string, mixed>
     *
     * @throws ChannelRequestFailedException when the client refuses, and a fresh token did not help
     * @throws BridgeUnreachableException when the container never answered
     */
    private function withFreshToken(string $operation, ChannelCredentials $credentials, callable $call): array
    {
        try {
            return $call($this->bearer($credentials));
        } catch (ChannelRequestFailedException $refused) {
            if (! OnPremiseErrorClassifier::looksLikeExpiredToken($refused) || ! self::canReauthenticate($credentials)) {
                throw $refused;
            }

            $this->tokens->forget(self::tokenKey($credentials));

            // One replay, with a token minted after the refusal. If this one is refused too, the
            // credentials really are wrong and the exception is the honest answer.
            return $call($this->bearer($credentials));
        }
    }

    /**
     * The bearer token to authenticate with — a stored one, or one minted for this unit of work.
     *
     * @throws ChannelRequestFailedException when the client refuses the login
     * @throws BridgeUnreachableException when the container never answered
     */
    private function bearer(ChannelCredentials $credentials): string
    {
        $stored = $credentials->secret(self::ACCESS_TOKEN_SECRET_KEY);

        if ($stored !== null) {
            // The out-of-band escape hatch of design § 2.7's *"client API user/password/token"*. Not
            // cached, because there is nothing to cache: it is read from the credential bag on every
            // call, exactly as Cloud API reads its own.
            return $stored;
        }

        return $this->tokens->remember(
            self::tokenKey($credentials),
            fn (): OnPremiseAccessToken => $this->login($credentials),
        )->value();
    }

    /**
     * `POST /v1/users/login` under HTTP Basic auth, and the deprecation warning an operator sees.
     *
     * The client answers `{"users": [{"token": "…", "expires_after": "2024-05-01 15:29:26+00:00"}]}`.
     * The expiry is parsed by `OnPremiseTokenCache::mint()`, which owns the fallback for a value it
     * cannot read — see that method for why the fallback is *short* rather than absent.
     *
     * **This is where the deprecation warning is logged**, and the choice is load-bearing rather than
     * incidental: a login happens exactly once per request or job (`OnPremiseTokenCache` is what makes
     * that true), so a 9,000-recipient campaign produces one warning instead of 9,000, and a
     * deployment with no On-Premise tenant produces none at all. A warning nobody can drown out is
     * worth more than a warning on every send. See `OnPremiseDeprecation` for the other three
     * surfaces.
     *
     * A `401` here is a genuinely wrong username or password and is **not** re-attempted: there is no
     * fresher credential to try, and `withFreshToken()` deliberately never wraps this call.
     *
     * @throws ChannelRequestFailedException when the client refuses the login
     * @throws BridgeUnreachableException when the container never answered, or answered unreadably
     */
    private function login(ChannelCredentials $credentials): OnPremiseAccessToken
    {
        $operation = 'on_premise.login';

        Log::warning(OnPremiseDeprecation::CODE, OnPremiseDeprecation::logContext($credentials->tenantId));

        $username = $credentials->requireSecret(self::USERNAME_SECRET_KEY);
        $password = $credentials->requireSecret(self::PASSWORD_SECRET_KEY);

        $payload = $this->guarded($operation, $credentials, fn (): Response => $this
            ->request($this->timeout())
            ->withBasicAuth($username, $password)
            ->post($this->containerUrl($credentials, self::LOGIN_PATH)));

        $user = BridgeWire::arrayOrEmpty(BridgeWire::listOrEmpty($payload['users'] ?? null)[0] ?? null);
        $token = BridgeWire::stringOrNull($user['token'] ?? null);

        if ($token === null) {
            // A 2xx login with no token: the client answered and did not authenticate us, so every
            // call built on this would be a 401. Treated as a transport failure — the outcome is
            // unknown and the work must be kept — rather than as a credential refusal, which would
            // fail fast and lose the send.
            throw BridgeUnreachableException::malformedResponse(
                $operation,
                'a 2xx login response carrying no "users[0].token"',
            );
        }

        return OnPremiseTokenCache::mint($token, BridgeWire::stringOrNull($user['expires_after'] ?? null));
    }

    /**
     * Whether a fresh token can be obtained for these credentials at all.
     *
     * False for the stored-`access_token` deployment: there is no login to perform, so a refusal is
     * the tenant's to fix and pretending otherwise would double every failed request.
     */
    private static function canReauthenticate(ChannelCredentials $credentials): bool
    {
        return $credentials->hasSecret(self::USERNAME_SECRET_KEY)
            && $credentials->hasSecret(self::PASSWORD_SECRET_KEY);
    }

    /**
     * The token cache key for one credential set — built here so there is one spelling.
     *
     * The password is part of it, which is what makes a rotation produce a fresh login rather than a
     * carried-over token. See `OnPremiseTokenCache::keyFor()`.
     */
    private static function tokenKey(ChannelCredentials $credentials): string
    {
        return OnPremiseTokenCache::keyFor(
            $credentials,
            self::USERNAME_SECRET_KEY,
            self::PASSWORD_SECRET_KEY,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Sending internals
    |--------------------------------------------------------------------------
    */

    /**
     * Put one message on the wire and return the replay material.
     *
     * An array rather than a `SendReceipt` because the return value **is** what
     * `idempotency_keys.result` stores, and only JSON-encodable data can be replayed. The receipt is
     * rebuilt from it by `receipt()`, so a fresh send and a replay leave `send()` by exactly the same
     * path.
     *
     * @return array<string, mixed>
     */
    private function dispatch(
        ChannelCredentials $credentials,
        OnPremiseMessage $message,
        string $operation,
        bool $degraded,
        ?string $templateName = null,
    ): array {
        $sent = $this->postMessage($operation, $credentials, $message);

        return [
            'provider_message_id' => $sent->waMessageId,
            'recipient' => $sent->jid,
            'degraded' => $degraded,
            'template' => $templateName,
        ];
    }

    /**
     * `POST /v1/messages`, guarded, and read into the transport's own DTO.
     *
     * The client answers `{"messages": [{"id": "gBEGkYiEB1VXAglK1ZEqA1YKPrU"}]}` — a message id and,
     * unlike Cloud API, **nothing about the contact**. So the recorded recipient is the digits the
     * body addressed rather than a `wa_id` the provider resolved: there is no better answer available,
     * and inventing one would put a number on the delivery board that nothing sent to.
     *
     * There is deliberately no accepted-at timestamp: the client sends none, and
     * `SentMessageDto::$sentAt` of `null` is honest where `now()` would be a fabricated fact.
     *
     * @throws ChannelRequestFailedException when the client refuses the send
     * @throws BridgeUnreachableException when the container never answered, or answered unreadably
     */
    private function postMessage(
        string $operation,
        ChannelCredentials $credentials,
        OnPremiseMessage $message,
    ): SentMessageDto {
        $url = $this->containerUrl($credentials, self::MESSAGES_PATH);
        $body = $message->body();

        $payload = $this->withFreshToken(
            $operation,
            $credentials,
            fn (string $token): array => $this->guarded(
                $operation,
                $credentials,
                fn (): Response => $this->request($this->timeout())->withToken($token)->post($url, $body),
            ),
        );

        $messages = BridgeWire::listOrEmpty($payload['messages'] ?? null);
        $providerMessageId = BridgeWire::stringOrNull(BridgeWire::arrayOrEmpty($messages[0] ?? null)['id'] ?? null);

        if ($providerMessageId === null) {
            // A 2xx with no message id: nothing could ever be reconciled with this send, and an
            // unreconcilable "sent" is worse than a retry. Treated as a transport failure, which is
            // what `SentMessageDto` would conclude anyway.
            throw BridgeUnreachableException::malformedResponse(
                $operation,
                'a 2xx message response carrying no "messages[0].id"',
            );
        }

        return new SentMessageDto(
            waMessageId: $providerMessageId,
            jid: $message->recipient(),
        );
    }

    /**
     * Upload inline bytes to `POST /v1/media` and return the client's media id.
     *
     * The legacy client takes the **raw bytes as the request body** with the media's own MIME type as
     * `Content-Type` — not multipart, which is where Cloud API differs — and answers
     * `{"media": [{"id": "…"}]}`. The MIME type is the sniffed one from the payload, never one guessed
     * from a filename, which `MediaPayload` already argues is how an executable arrives as an image.
     *
     * @throws ChannelRequestFailedException when the client refuses the upload
     * @throws BridgeUnreachableException when the container never answered, or returned no id
     */
    private function uploadMedia(ChannelCredentials $credentials, MediaPayload $media): string
    {
        $operation = 'on_premise.media.upload';
        $bytes = base64_decode((string) $media->base64, true);

        if ($bytes === false || $bytes === '') {
            throw new InvalidArgumentException(
                'An On-Premise media upload needs decodable bytes: the payload\'s base64 could not be '
                .'decoded, and uploading nothing would produce a media id that delivers an empty '
                .'attachment.'
            );
        }

        $url = $this->containerUrl($credentials, self::MEDIA_PATH);
        $mimeType = $media->mimeType;

        $payload = $this->withFreshToken(
            $operation,
            $credentials,
            fn (string $token): array => $this->guarded(
                $operation,
                $credentials,
                fn (): Response => $this->http
                    ->acceptJson()
                    ->connectTimeout($this->connectTimeout())
                    ->timeout($this->timeout())
                    ->withToken($token)
                    ->withBody($bytes, $mimeType)
                    ->post($url),
            ),
        );

        $id = BridgeWire::stringOrNull(
            BridgeWire::arrayOrEmpty(BridgeWire::listOrEmpty($payload['media'] ?? null)[0] ?? null)['id'] ?? null,
        );

        if ($id === null) {
            throw BridgeUnreachableException::malformedResponse(
                $operation,
                'a 2xx media-upload response carrying no "media[0].id"',
            );
        }

        return $id;
    }

    /**
     * Rebuild the driver-level receipt from what the client said, or from what the ledger replayed.
     *
     * @param  array<array-key, mixed>  $payload
     */
    private function receipt(string $idempotencyKey, ChannelCapability $capability, array $payload): SendReceipt
    {
        return new SendReceipt(
            mode: $this->mode(),
            // A send acknowledged with no id is refused by `SendReceipt` itself, which is the correct
            // outcome for the reason `postMessage()` gives.
            providerMessageId: BridgeWire::stringOrNull($payload['provider_message_id'] ?? null) ?? '',
            recipient: BridgeWire::stringOrNull($payload['recipient'] ?? null) ?? '',
            idempotencyKey: $idempotencyKey,
            capability: $capability,
            degraded: ($payload['degraded'] ?? false) === true,
            templateName: BridgeWire::stringOrNull($payload['template'] ?? null),
        );
    }

    /**
     * One reserved `$vars` entry, or a refusal naming it — see `sendTemplate()`.
     *
     * @param  array<array-key, string|int|float>  $vars
     *
     * @throws InvalidArgumentException when the key is absent or empty
     */
    private function reservedVar(array $vars, string $key, string $what): string
    {
        $value = trim((string) ($vars[$key] ?? ''));

        if ($value === '') {
            throw new InvalidArgumentException(sprintf(
                'An On-Premise template send needs %s in $vars[%s]: ChannelDriver::sendTemplate() has no '
                .'parameter for it, and a receipt without it could never be reconciled. Prefer '
                .'sendTemplateTo(), which takes both explicitly.',
                $what,
                $key,
            ));
        }

        return $value;
    }

    /*
    |--------------------------------------------------------------------------
    | Template internals
    |--------------------------------------------------------------------------
    */

    /**
     * The approved template row this send must use, re-read from the registry.
     *
     * `cloud_api_templates` is the platform's **approved-template registry**, keyed on
     * `credential_id` rather than on a mode — which is what lets this mode share it without a second
     * table. Its name is Cloud API's for historical reasons and is reported to the consolidation pass
     * as a naming defect rather than worked around with a duplicate model.
     *
     * Three refusals, all before any request and all `ChannelTemplateException` (422): the reference
     * names another provider account, no row exists for `(account, name, language)`, or the row exists
     * and is not `APPROVED`. The middle two are distinguished by a second lookup **without** the
     * approved filter, because *"submit this template"* and *"wait for review"* are different
     * instructions and a panel that could not tell them apart would give the wrong one half the time.
     *
     * @throws ChannelTemplateException when the template cannot be sent
     */
    private function sendableTemplate(ChannelCredentials $credentials, TemplateRef $template): CloudApiTemplate
    {
        $credentialId = $credentials->credentialId;

        if ($credentialId === null) {
            // `ChannelCredentials::fromModel()` always carries the row id, so this is a
            // programmer-built credential set being used for a template send — and template approval
            // is meaningless without the account it was approved under.
            throw new InvalidArgumentException(
                'An On-Premise template send needs credentials resolved from a stored credential row: '
                .'template approval is per provider account, and the template registry is keyed on it.'
            );
        }

        if ($template->credentialId !== null && $template->credentialId !== $credentialId) {
            throw ChannelTemplateException::wrongAccount($this->mode(), $template->key());
        }

        $row = CloudApiTemplate::sendable($credentialId, $template->name, $template->language);

        if ($row instanceof CloudApiTemplate) {
            return $row;
        }

        $unapproved = CloudApiTemplate::query()
            ->forCredential($credentialId)
            ->where('name', $template->name)
            ->where('language', $template->language)
            ->first();

        throw $unapproved instanceof CloudApiTemplate
            ? ChannelTemplateException::notApproved($this->mode(), $template->key(), $unapproved->status)
            : ChannelTemplateException::notRegistered($this->mode(), $template->key());
    }

    /**
     * The client's `template.components` for `$vars`, checked against the template's own placeholders.
     *
     * The placeholders come from the stored `body`, which holds the tenant's copy with `{{1}}`-style
     * markers — read from the mirrored body rather than from `$vars` for the obvious reason: the point
     * of the check is to catch a `$vars` that does not match. Both directions are refused, and surplus
     * is refused as firmly as missing: a surplus key is nearly always a renamed or removed
     * placeholder, and the send would otherwise go out with a stale value silently occupying the wrong
     * slot.
     *
     * Only the **body** component is built. A header, a footer, or a button URL suffix can also carry
     * parameters, and their definitions live in `cloud_api_templates.components` — *"in the provider's
     * shape"*, populated by **task 8.4's** template sync, which is the task that will know what is in
     * there. Building a header component from a column no sync has ever written would be inventing a
     * schema; a body-only payload is correct for every template whose header is static, which is
     * every template this platform can currently create.
     *
     * The legacy client's older `localizable_params` shape is deliberately not emitted: builds from
     * 2.27 onward take `components`, which is the same structure Cloud API uses, and supporting two
     * payload shapes would mean guessing the container's version from nothing.
     *
     * @param  array<array-key, string|int|float>  $vars
     * @return list<array<string, mixed>>
     *
     * @throws ChannelTemplateException when the variables do not fill the placeholders exactly
     */
    private function templateComponents(TemplateRef $template, CloudApiTemplate $row, array $vars): array
    {
        $placeholders = self::placeholdersIn($row->body);
        $supplied = array_map(static fn (int|string $key): string => (string) $key, array_keys($vars));

        $missing = array_values(array_diff($placeholders, $supplied));
        $surplus = array_values(array_diff($supplied, $placeholders));

        if ($missing !== [] || $surplus !== []) {
            throw ChannelTemplateException::variableMismatch($this->mode(), $template->key(), $missing, $surplus);
        }

        if ($placeholders === []) {
            return [];
        }

        $parameters = [];

        foreach ($placeholders as $placeholder) {
            $parameters[] = [
                'type' => 'text',
                // In placeholder order, because body parameters are **positional**: the first
                // parameter fills `{{1}}` whatever its key was called, so the order this list is
                // built in *is* the mapping.
                'text' => (string) $vars[$placeholder],
            ];
        }

        return [['type' => 'body', 'parameters' => $parameters]];
    }

    /**
     * The `{{n}}` placeholder keys of a template body, in ascending positional order.
     *
     * Ascending numerically rather than in the order they appear in the copy: parameters are
     * positional, so a body that mentions `{{2}}` before `{{1}}` — which a translation legitimately
     * does — must still send `{{1}}`'s value first.
     *
     * @return list<string>
     */
    private static function placeholdersIn(string $body): array
    {
        preg_match_all('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', $body, $matches);

        $found = array_values(array_unique($matches[1]));

        usort($found, static function (string $a, string $b): int {
            $numeric = ctype_digit($a) && ctype_digit($b);

            return $numeric ? (int) $a <=> (int) $b : strcmp($a, $b);
        });

        return $found;
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
    private function decodeWebhook(Request $request): array
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
     * Refuse the payload if it names a business number these credentials do not own.
     *
     * The **conditional** half of the recipient check, and the class docblock explains at length why
     * it can only be conditional on this mode: the legacy client's callback body names no business
     * number at all, so there is usually nothing to compare. When something *is* there — added by the
     * proxy that already signs the payload, or by a client build that grew the field — it is compared,
     * and a mismatch is refused.
     *
     * Compared as **digits**, because the two sides are spelled differently by convention: the
     * credential holds the E.164 number a tenant typed (`+91 98…`, `919812345678`) and a proxy adds
     * whatever its own configuration says. Comparing the raw strings would refuse a correct payload
     * over a plus sign, and a check that fires on correct traffic is a check an operator turns off.
     *
     * `hash_equals()` rather than `===` for `CloudApiChannelDriver::findNumber()`'s reason: the value
     * identifies a tenant's number, and using one comparison discipline everywhere means nobody has to
     * decide per call site which one this is.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws WebhookVerificationException when the payload names another number
     */
    private function assertRecipient(array $payload, string $phoneNumber): void
    {
        $metadata = BridgeWire::arrayOrEmpty($payload['metadata'] ?? null);

        $claimed = BridgeWire::stringOrNull($metadata['phone_number'] ?? null)
            ?? BridgeWire::stringOrNull($metadata['display_phone_number'] ?? null);

        if ($claimed === null) {
            // Nothing claimed, so nothing to refuse. The signature is the origin proof *and* the
            // tenant binding here — `webhook_secret` is per credential row.
            return;
        }

        $expected = self::digitsOrNull($phoneNumber) ?? $phoneNumber;
        $presented = self::digitsOrNull($claimed) ?? $claimed;

        if (! hash_equals($expected, $presented)) {
            throw WebhookVerificationException::wrongRecipient($this->mode(), $expected, $presented);
        }
    }

    /**
     * One `messages[]` element as a canonical message event.
     *
     * `text` is the body when there is one, and an interactive reply's own title when the customer
     * pressed a button or picked a row — so the conversation engine sees what the customer *said*
     * either way, and the reply **id** (which is what a flow correlates on) stays available through
     * `InboundEvent::payload()`. A media or location message has no text at all, which is `null`
     * rather than an invented caption.
     *
     * @param  array<string, mixed>  $message
     * @param  list<mixed>  $contacts
     *
     * @throws WebhookVerificationException when the message names no id or no sender
     */
    private function messageEvent(
        array $message,
        array $contacts,
        ChannelCredentials $credentials,
        string $phoneNumber,
    ): InboundEvent {
        $providerMessageId = BridgeWire::stringOrNull($message['id'] ?? null);

        if ($providerMessageId === null) {
            throw WebhookVerificationException::malformedPayload(
                $this->mode(),
                'it carries a message with no "id", so nothing could ever be correlated with it',
            );
        }

        $from = BridgeWire::stringOrNull($message['from'] ?? null);

        if ($from === null) {
            throw WebhookVerificationException::malformedPayload(
                $this->mode(),
                'it carries a message that names no sender',
            );
        }

        return InboundEvent::message(
            mode: $this->mode(),
            // From the credentials, never from the payload.
            tenantId: $credentials->tenantId,
            providerMessageId: $providerMessageId,
            from: $from,
            text: self::textOf($message),
            // Likewise: the legacy callback names no business number, so this is the only source
            // there is — which is the rule 7.1 and 7.2 established, holding here by construction.
            channelIdentity: $phoneNumber,
            occurredAt: self::timestampOf($message['timestamp'] ?? null),
            payload: ['message' => $message, 'contacts' => $contacts],
        );
    }

    /**
     * One `statuses[]` element as a canonical receipt.
     *
     * The raw `status` string is preserved in the payload because `sent` and `delivered` collapse into
     * one canonical kind (`InboundEventKind`'s own table), and task 9.5 needs the distinction to apply
     * the never-downgrades rule (Req 20.6 / C3, Property 9). Monotonicity is *not* enforced here — see
     * the class docblock.
     *
     * @param  array<string, mixed>  $status
     *
     * @throws WebhookVerificationException when the status names no message id
     */
    private function statusEvent(
        array $status,
        ChannelCredentials $credentials,
        string $phoneNumber,
    ): InboundEvent {
        $providerMessageId = BridgeWire::stringOrNull($status['id'] ?? null);

        if ($providerMessageId === null) {
            throw WebhookVerificationException::malformedPayload(
                $this->mode(),
                'it carries a status with no "id", so it says nothing about which message it is about',
            );
        }

        $kind = self::kindOf(BridgeWire::stringOrNull($status['status'] ?? null));
        $payload = ['status' => $status];

        if ($kind === null) {
            // A status this release does not act on (`deleted`, and whatever a newer client build
            // adds): verified, acknowledged, acted on by nobody — not a 403 on every client upgrade.
            return InboundEvent::unsupported($this->mode(), $credentials->tenantId, $phoneNumber, $payload);
        }

        return InboundEvent::receipt(
            kind: $kind,
            mode: $this->mode(),
            tenantId: $credentials->tenantId,
            providerMessageId: $providerMessageId,
            occurredAt: self::timestampOf($status['timestamp'] ?? null),
            failureReason: $kind === InboundEventKind::SendFailure
                ? $credentials->redact(self::failureReasonOf($status))
                : null,
            channelIdentity: $phoneNumber,
            payload: $payload,
        );
    }

    /**
     * Stamp each event with the batch shape, so a caller holding one can tell there were more.
     *
     * Deliberately identical to `CloudApiChannelDriver::stamped()`, keys included: task 8.3 reads
     * `_batch_size` and `_batch_index` without branching on the mode, and two spellings of the same
     * idea would be one spelling task 8.3 forgot.
     *
     * @param  list<InboundEvent>  $events
     * @return non-empty-list<InboundEvent>
     */
    private function stamped(array $events): array
    {
        $size = count($events);
        $stamped = [];

        foreach ($events as $index => $event) {
            $stamped[] = new InboundEvent(
                kind: $event->kind,
                mode: $event->mode,
                tenantId: $event->tenantId,
                providerMessageId: $event->providerMessageId,
                from: $event->from,
                channelIdentity: $event->channelIdentity,
                text: $event->text,
                occurredAt: $event->occurredAt,
                failureReason: $event->failureReason,
                payload: [
                    self::BATCH_SIZE_KEY => $size,
                    self::BATCH_INDEX_KEY => $index,
                    ...$event->payload(),
                ],
            );
        }

        /** @var non-empty-list<InboundEvent> $stamped */
        return $stamped;
    }

    /**
     * A client timestamp as an instant, or null.
     *
     * The legacy client sends epoch **seconds as a quoted string** (`"1518694235"`), which is the one
     * shape `BridgeWire::timestampOrNull()` cannot read on its own: it takes the string arm and parses
     * the digits as a date, so `1518694235` becomes a year. Converted to an int first, exactly as
     * `BaileysChannelDriver` and `CloudApiChannelDriver` do for the same reason — and `null` rather
     * than `now()` for anything unreadable, because a fabricated occurrence time is
     * indistinguishable from a real one afterwards.
     */
    private static function timestampOf(mixed $value): ?CarbonImmutable
    {
        if (is_string($value) && preg_match('/^\d{1,14}$/', trim($value)) === 1) {
            $value = (int) trim($value);
        }

        return BridgeWire::timestampOrNull($value);
    }

    /**
     * The client's `statuses[].status` as a canonical kind, or null for one this release does not act
     * on.
     *
     * `sent` and `delivered` are both `DELIVERY_RECEIPT`, which is `InboundEventKind`'s own mapping.
     */
    private static function kindOf(?string $status): ?InboundEventKind
    {
        return match ($status) {
            'sent', 'delivered' => InboundEventKind::DeliveryReceipt,
            'read' => InboundEventKind::ReadReceipt,
            'failed' => InboundEventKind::SendFailure,
            default => null,
        };
    }

    /**
     * A failed status's reason, from the client's error **title** and code.
     *
     * `title` is a short fixed phrase (*"Message Undeliverable"*); `details` is the field that quotes
     * the request, so it is not read. The code is included because it is what an operator matches
     * against `OnPremiseErrorClassifier`, and the whole string still goes through
     * `ChannelCredentials::redact()` before it reaches the event.
     *
     * @param  array<string, mixed>  $status
     */
    private static function failureReasonOf(array $status): string
    {
        $error = BridgeWire::arrayOrEmpty(BridgeWire::listOrEmpty($status['errors'] ?? null)[0] ?? null);
        $title = BridgeWire::stringOrNull($error['title'] ?? null);
        $code = BridgeWire::stringOrNull($error['code'] ?? null);

        if ($title === null && $code === null) {
            return 'The On-Premise client reported this message as failed without naming a reason.';
        }

        return sprintf(
            '%s%s',
            $title ?? 'The On-Premise client reported this message as failed',
            $code === null ? '' : sprintf(' (code %s)', $code),
        );
    }

    /**
     * What the customer said, for the message shapes that carry words.
     *
     * @param  array<string, mixed>  $message
     */
    private static function textOf(array $message): ?string
    {
        $type = BridgeWire::stringOrNull($message['type'] ?? null);

        if ($type === 'interactive') {
            $interactive = BridgeWire::arrayOrEmpty($message['interactive'] ?? null);

            foreach (['button_reply', 'list_reply'] as $shape) {
                $reply = BridgeWire::arrayOrEmpty($interactive[$shape] ?? null);
                $title = BridgeWire::stringOrNull($reply['title'] ?? null);

                if ($title !== null) {
                    return $title;
                }
            }

            return null;
        }

        if ($type === 'button') {
            // A template quick-reply button: reported as its own type, with the pressed label in
            // `button.text`.
            return BridgeWire::stringOrNull(BridgeWire::arrayOrEmpty($message['button'] ?? null)['text'] ?? null);
        }

        return BridgeWire::stringOrNull(BridgeWire::arrayOrEmpty($message['text'] ?? null)['body'] ?? null);
    }

    /**
     * The client's `POST /v1/contacts` answer as one `NumberCheck` per number asked.
     *
     * Keyed on the **caller's** spelling, not the client's echo: a caller that asked about
     * `+91 98123 45678` looks the answer up by that string, and `NumberCheck::$number` carries what it
     * asked. A number the client did not mention at all is `unknown` rather than absent, so a caller
     * iterating its own list never has to handle a missing key.
     *
     * @param  list<string>  $numbers
     * @param  array<string, mixed>  $payload
     * @return array<array-key, NumberCheck>
     */
    private function numberChecks(array $numbers, array $payload): array
    {
        $byInput = [];

        foreach (BridgeWire::listOrEmpty($payload['contacts'] ?? null) as $entry) {
            $contact = BridgeWire::arrayOrEmpty($entry);
            $input = BridgeWire::stringOrNull($contact['input'] ?? null);

            if ($input !== null) {
                $byInput[$input] = $contact;
            }
        }

        $checks = [];

        foreach ($numbers as $number) {
            $key = (string) $number;
            $contact = $byInput[$key] ?? $byInput[trim($key)] ?? null;
            $status = $contact === null ? null : BridgeWire::stringOrNull($contact['status'] ?? null);

            $checks[$key] = new NumberCheck($key, match ($status) {
                'valid' => true,
                'invalid', 'failed' => false,
                // `processing`, an unknown status, or no entry at all: see the table on
                // `checkNumbers()` for why this must not collapse to `false`.
                default => null,
            });
        }

        return $checks;
    }

    /*
    |--------------------------------------------------------------------------
    | The container wire
    |--------------------------------------------------------------------------
    */

    /**
     * One guarded, authorised `GET`, decoded — with the single re-authentication if the token lapsed.
     *
     * @return array<string, mixed>
     *
     * @throws ChannelRequestFailedException when the client refuses
     * @throws BridgeUnreachableException when the container never answered, or answered unreadably
     */
    private function authorisedGet(
        string $operation,
        ChannelCredentials $credentials,
        string $path,
        ?int $timeout = null,
    ): array {
        $url = $this->containerUrl($credentials, $path);
        $budget = $timeout ?? $this->timeout();

        return $this->withFreshToken(
            $operation,
            $credentials,
            fn (string $token): array => $this->guarded(
                $operation,
                $credentials,
                fn (): Response => $this->request($budget)->withToken($token)->get($url),
            ),
        );
    }

    /**
     * Run one container request through the breaker and the bounded inline retry, then read it.
     *
     * The failure conversion is here rather than in `ProviderCallGuard` because it is the only
     * provider-specific part: a refusal becomes `ChannelRequestFailedException` carrying the client's
     * error envelope (which `OnPremiseErrorClassifier` reads), and anything else becomes
     * `BridgeUnreachableException` — deliberately **not** the client exception, which can carry the
     * request in a dump, including the Basic auth header or the bearer token.
     *
     * @param  callable(): Response  $call
     * @return array<string, mixed>
     *
     * @throws ChannelRequestFailedException when the client refuses
     * @throws BridgeUnreachableException when the container never answered, or answered unreadably
     */
    private function guarded(string $operation, ChannelCredentials $credentials, callable $call): array
    {
        $response = $this->guard->run(
            $operation,
            ProviderCallGuard::breakerName($this->mode(), $credentials->tenantId),
            function () use ($operation, $call): Response {
                $response = $call();

                if ($response->failed()) {
                    // Raised **inside** the guarded call so the breaker counts a refusal as a failure
                    // of the dependency, and so the retry decision is made on the typed exception
                    // rather than on a status code the matrix cannot key on.
                    throw $this->refusalFor($operation, $response);
                }

                return $response;
            },
            fn (Throwable $e): Throwable => $this->failureFor($operation, $e),
        );

        if ($response->status() === 204 || trim($response->body()) === '') {
            // `PATCH /v1/settings/application` answers `200` with an empty body, and some builds
            // answer `204`. An empty success is a success with nothing to read — not a malformed
            // response, which is what `json()` returning null would otherwise be taken for.
            return [];
        }

        $decoded = $response->json();

        if (! is_array($decoded)) {
            // HTML from a proxy, a truncated body, a bare string: the client answered 2xx with
            // something that is not a result, so the outcome is unknown and this is a transport
            // failure rather than a result to read (`HttpBridgeClient::decode()`'s reasoning).
            throw BridgeUnreachableException::malformedResponse(
                $operation,
                'a 2xx response whose body is not a JSON object',
            );
        }

        return BridgeWire::arrayOrEmpty($decoded);
    }

    /**
     * The client's error envelope, read into the platform's typed refusal.
     *
     * `{"errors": [{"code": 1005, "title": "Access denied", "details": "…"}]}` — a **list**, unlike
     * Meta's single `error` object, and the first entry is the one carried. `details` is not: it quotes
     * the request that produced it, which on a `401` is the `Authorization` header.
     *
     * `title` is not carried either, even though it is a fixed phrase: `ChannelRequestFailedException`
     * normalises everything it interpolates to `NORMALISED_CODE_PATTERN`, so a phrase with spaces
     * would be dropped on the way in. It reaches a tenant through `healthDetailFor()` instead, which
     * renders the platform's own sentence from the code.
     */
    private function refusalFor(string $operation, Response $response): ChannelRequestFailedException
    {
        $decoded = $response->json();
        $error = is_array($decoded)
            ? BridgeWire::arrayOrEmpty(
                BridgeWire::listOrEmpty(BridgeWire::arrayOrEmpty($decoded)['errors'] ?? null)[0] ?? null,
            )
            : [];

        return ChannelRequestFailedException::refused(
            mode: $this->mode(),
            operation: $operation,
            status: $response->status(),
            errorCode: BridgeWire::stringOrNull($error['code'] ?? null),
            errorType: BridgeWire::stringOrNull($error['href'] ?? null),
            retryAfterSeconds: self::retryAfter($response),
        );
    }

    /**
     * What a failure of a container call surfaces as.
     *
     * A refusal or an already-typed transport failure passes through unchanged — it carries what the
     * classifier and the caller need. Anything else (a `ConnectionException`, a client library
     * throwing its own type) becomes a transport failure, which fails **closed**: a send whose
     * transport failed may or may not have happened, and the one answer that is definitely wrong is
     * "sent".
     */
    private function failureFor(string $operation, Throwable $e): Throwable
    {
        return $e instanceof ChannelRequestFailedException
            || $e instanceof BridgeUnreachableException
            || $e instanceof BridgeRequestFailedException
            ? $e
            : BridgeUnreachableException::transportFailed($operation);
    }

    /**
     * A configured pending request: JSON both ways, bounded timeouts, **no internal retries**.
     *
     * `retry()` is deliberately unused, exactly as `HttpBridgeClient` explains: the attempt budget
     * belongs to one layer, and here that is `ProviderCallGuard` composed with `RetryPolicy` in the
     * calling job. A transport that retried internally would multiply the two and turn one
     * duplicate-risk window into three.
     *
     * The client comes from the injected factory, so `Http::fake()` intercepts it in tests and the
     * platform's own timeouts apply.
     */
    private function request(int $timeout): PendingRequest
    {
        return $this->http
            ->asJson()
            ->acceptJson()
            ->connectTimeout($this->connectTimeout())
            ->timeout($timeout);
    }

    /**
     * The absolute URL for one route on the tenant's container: `{base_url}/v1/{path}`.
     *
     * The **only** place a tenant-supplied host becomes a URL this platform requests, and therefore
     * the only place the three checks the class docblock sets out can live: scheme, userinfo, and
     * private-address literal. A second URL builder would be a second place for one of them to be
     * forgotten.
     *
     * @throws InvalidArgumentException when the stored base URL is absent or not usable
     */
    private function containerUrl(ChannelCredentials $credentials, string $path): string
    {
        $base = rtrim(trim($credentials->requireConfig(self::BASE_URL_CONFIG_KEY)), '/');
        $parts = parse_url($base);

        if ($parts === false || ! is_array($parts)) {
            throw new InvalidArgumentException(self::baseUrlRefusal('it could not be parsed as a URL'));
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new InvalidArgumentException(self::baseUrlRefusal(
                'it must be an absolute http:// or https:// URL naming a host',
            ));
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            // Refused rather than stripped: a URL carrying userinfo is how a credential ends up in
            // every log line that records the URL, and silently removing it would hide the mistake
            // from the tenant who made it.
            throw new InvalidArgumentException(self::baseUrlRefusal(
                'it carries a username or password in the URL, which would be written into every log '
                .'line that records the request — put the credentials in the username and password '
                .'fields instead',
            ));
        }

        if (self::isBlockedHost($host) && ! self::allowsPrivateHosts()) {
            throw new InvalidArgumentException(self::baseUrlRefusal(sprintf(
                'it names a loopback, private, or link-local address, which the platform will not '
                .'request on a tenant\'s behalf — %s is where a cloud metadata endpoint lives. A '
                .'deployment whose container really is on a private network sets '
                .'wa.channel.on_premise.allow_private_hosts',
                '169.254.169.254',
            )));
        }

        return $base.'/'.self::API_PREFIX.'/'.ltrim($path, '/');
    }

    /**
     * The one sentence every `base_url` refusal is phrased with.
     *
     * The offending value is deliberately **not** quoted: it is tenant input, it reaches logs, and a
     * base URL is exactly the field somebody pastes a token into by mistake. The key name is enough
     * to act on, which is `ChannelCredentials::requireConfig()`'s rule as well.
     */
    private static function baseUrlRefusal(string $because): string
    {
        return sprintf(
            'The [%s] credential for this tenant is not a usable On-Premise base URL: %s. The value is '
            .'not quoted here because it is tenant input and would reach a log line.',
            self::BASE_URL_CONFIG_KEY,
            $because,
        );
    }

    /**
     * Whether `$host` is an IP literal the platform refuses to request on a tenant's behalf.
     *
     * **IP literals and the loopback names only.** No DNS resolution, and that is a deliberate
     * boundary rather than an oversight: a name that resolves to a private address now can resolve
     * elsewhere between this check and the connection, so resolution-time enforcement belongs to the
     * network's egress policy and a driver that pretended to provide it would be selling a guarantee
     * it cannot keep. What is checked here is what can be decided from the string alone, which is
     * where the cloud-metadata and localhost cases actually live.
     */
    private static function isBlockedHost(string $host): bool
    {
        $candidate = strtolower(trim($host, " \t\n\r\0\x0B[]"));

        if ($candidate === 'localhost' || str_ends_with($candidate, '.localhost')) {
            return true;
        }

        if (filter_var($candidate, FILTER_VALIDATE_IP) === false) {
            // A hostname. Not decidable here — see the docblock.
            return false;
        }

        return filter_var(
            $candidate,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false;
    }

    /**
     * Whether this deployment allows a container on a private or loopback address.
     *
     * Default **false**, and the default is the security control: on a hosted platform the tenant's
     * container is reachable over the internet, so a private address in a `base_url` is either a
     * mistake or an attempt to aim the platform's HTTP client at something inside its own network. A
     * self-hosted deployment whose container is genuinely in the same VPC turns it on, deliberately
     * and in one place.
     */
    private static function allowsPrivateHosts(): bool
    {
        return config('wa.channel.on_premise.allow_private_hosts') === true;
    }

    /**
     * A numeric `Retry-After`, in its numeric form only.
     *
     * The HTTP-date form is ignored rather than parsed, for the reason `RetryPolicy` gives: a clock
     * skew would turn a two-second wait into a two-hour one.
     */
    private static function retryAfter(Response $response): ?int
    {
        $header = trim((string) $response->header('Retry-After'));

        return preg_match('/^\d+$/', $header) === 1 ? (int) $header : null;
    }

    private function timeout(): int
    {
        return self::positiveConfig('wa.channel.on_premise.timeout', self::DEFAULT_TIMEOUT);
    }

    private function connectTimeout(): int
    {
        return self::positiveConfig('wa.channel.on_premise.connect_timeout', self::DEFAULT_CONNECT_TIMEOUT);
    }

    /**
     * A positive integer from config, falling back to the compiled-in default — so a deleted or
     * nonsensical key degrades to a working timeout rather than to none.
     */
    private static function positiveConfig(string $key, int $default): int
    {
        $value = config($key);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
    }

    /*
    |--------------------------------------------------------------------------
    | Small readers
    |--------------------------------------------------------------------------
    */

    /**
     * The client's `health.gateway_status`, or null when the body is not a health report.
     *
     * The route answers `{"health": {"gateway_status": "connected", "…": …}}`, and a build that reports
     * the status at the top level is tolerated: the field is what matters, and a nesting change
     * between container versions should not turn a healthy number into an unknown one.
     *
     * Lower-cased, because the value is compared against constants and one build spells it
     * `CONNECTED`.
     *
     * @param  array<string, mixed>  $payload
     */
    private function gatewayStatus(array $payload): ?string
    {
        $health = BridgeWire::arrayOrEmpty($payload['health'] ?? null);

        $status = BridgeWire::stringOrNull($health['gateway_status'] ?? null)
            ?? BridgeWire::stringOrNull($payload['gateway_status'] ?? null);

        return $status === null ? null : strtolower(trim($status));
    }

    /**
     * A tenant-facing sentence for a credential probe the client refused.
     *
     * Rendered from the client's **code**, never from its prose — its `401` body echoes the Basic auth
     * it was sent. `ChannelHealth` runs even this through `ChannelCredentials::redact()`, so the belt
     * and the braces are both on.
     *
     * Kept short on purpose: it is prefixed with the deprecation notice, and both together have to fit
     * inside `ChannelCredentials::MAX_DETAIL_LENGTH` or the part a tenant needs is what gets
     * truncated.
     */
    private function healthDetailFor(ChannelRequestFailedException $refused): string
    {
        $reason = match (true) {
            $refused->hasErrorCode(...OnPremiseErrorClassifier::AUTH_CODES),
            $refused->status === 401 => 'The client rejected the username and password.',
            $refused->hasErrorCode(...OnPremiseErrorClassifier::PERMISSION_CODES) => 'The client accepted the login and this account may not use it.',
            $refused->hasErrorCode(...OnPremiseErrorClassifier::RATE_LIMIT_CODES) => 'The client is rate-limiting requests, so the credentials could not be confirmed right now.',
            $refused->status === 404 => 'The base URL does not serve the client\'s v1 API.',
            default => 'The On-Premise client refused the credential probe.',
        };

        return sprintf(
            '%s (HTTP %d%s). The client\'s own message is not repeated: it quotes the request, '
            .'including the credentials it was sent.',
            $reason,
            $refused->status,
            $refused->errorCode === null ? '' : ', code '.$refused->errorCode,
        );
    }

    /**
     * Milliseconds since an `hrtime(true)` reading.
     */
    private static function elapsedMs(float|int $startedAt): int
    {
        return (int) ((hrtime(true) - $startedAt) / 1_000_000);
    }

    /**
     * A human-formatted number reduced to E.164 digits, or null.
     *
     * `HttpBridgeClient::digits()`'s rule: anything that is not 8–15 digits after separators are
     * stripped is `null` rather than a best effort.
     */
    private static function digitsOrNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = (string) preg_replace('/\D+/', '', $value);

        return preg_match('/^\d{8,15}$/', $digits) === 1 ? $digits : null;
    }
}
