<?php

declare(strict_types=1);

namespace App\Services\Channel;

use App\Enums\BspProvider;
use App\Enums\ChannelCapability;
use App\Enums\ChannelCapabilitySupport;
use App\Enums\ChannelMode;
use App\Enums\PresenceState;
use App\Enums\SessionLoginMethod;
use App\Enums\SessionStatus;
use App\Exceptions\Bridge\BridgeRequestFailedException;
use App\Exceptions\Bridge\BridgeUnreachableException;
use App\Exceptions\Channel\ChannelCredentialException;
use App\Exceptions\Channel\ChannelOperationException;
use App\Exceptions\Channel\ChannelRequestFailedException;
use App\Exceptions\Channel\ChannelTemplateException;
use App\Exceptions\Channel\ModeCapabilityException;
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
use App\Services\Channel\Bsp\Adapters\BaseBspAdapter;
use App\Services\Channel\Bsp\BspAdapter;
use App\Services\Channel\Bsp\BspAdapterRegistry;
use App\Services\Channel\Bsp\BspRefusal;
use App\Services\Channel\Bsp\BspRequest;
use App\Services\Channel\Bsp\BspWebhookVerifier;
use App\Services\Channel\Concerns\DedupesChannelSends;
use App\Services\Channel\Concerns\DerivesChannelPolicy;
use App\Services\Channel\Concerns\ResolvesOwnedSession;
use App\Services\Reliability\IdempotencyStore;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

/**
 * The `BSP_GATEWAY` driver: **one driver, eight partners** — Twilio, 360dialog, Gupshup, Vonage,
 * MessageBird, Infobip, WATI, Kaleyra — each behind a `Bsp\BspAdapter`, with a **per-provider
 * capability sub-matrix resolved at runtime** (Req 8.1, 8.2 / A8; design § Channel Mode 2.2 mode 4,
 * 2.3, 2.7).
 *
 * ```php
 * // Registered by ChannelServiceProvider; obtained only through the router, wrapped.
 * $driver = $router->driverFor($session);      // ModeGuardedChannelDriver(BspGatewayChannelDriver)
 *
 * $receipt = $driver->send($session, TextContent::to('919812345678', 'Your order shipped.', $key));
 *
 * // task 8.2, persisting a session's authoritative capability list (Req 8.2)
 * $capabilities = $driver->capabilitiesFor($credentials);   // the *partner's* real answer
 * ```
 *
 * It is the official-mode sibling of `CloudApiChannelDriver` and reuses everything that class
 * extracted for it — `Concerns\VerifiesProviderSignature` (through `Bsp\BspWebhookVerifier`),
 * `Concerns\DedupesChannelSends`, `Concerns\ResolvesOwnedSession`, `ProviderCallGuard`,
 * `ChannelRequestFailedException` (whose optional `?BspProvider $provider` exists for this mode),
 * `ChannelOperationException` and `ChannelTemplateException`. What is genuinely new here is the
 * *plurality*: eight endpoints, eight auth schemes, eight error vocabularies, eight webhook proofs,
 * and eight capability profiles.
 *
 * ## There is no provider `switch` in this class, and that is structural
 *
 * Every partner-specific fact is reached through `Bsp\BspAdapterRegistry::for($provider)`, which is
 * total by construction. A ninth partner is a new `BspProvider` case plus one adapter class; nothing
 * in this file mentions a partner by name, and nothing in it would change. See `Bsp\BspAdapter` for
 * why that is arithmetic rather than taste — six axes differ per partner, so a switch-based driver
 * would carry six eight-armed `match` expressions that have to stay aligned by hand.
 *
 * ## The per-provider sub-matrix, which is the point of the task
 *
 * design § 2.3 requires that *"the driver reports each provider's real `supports()` at runtime
 * rather than assuming one profile"*, and that the `BSP_GATEWAY` matrix row be a **ceiling**. Support
 * is therefore resolved in three layers, and every one of them can only narrow:
 *
 * | Layer | Source | Where |
 * |---|---|---|
 * | 1. mode ceiling | `ChannelCapability::supportOn(BSP_GATEWAY)` | the enum |
 * | 2. what design.md says about the named partner | `BspProvider::declaredSupport()` | the enum |
 * | 3. what the partner **account** allows | `channel_credentials.config`, read by the adapter | `Bsp\BspAdapter::refineSupport()` |
 *
 * `refineSupport()` below composes them, and **widening is impossible three times over**:
 *
 * 1. layer 2 already floors itself on the ceiling (`BspProvider::declaredSupport()`);
 * 2. this class does not consult an adapter at all for a capability the ceiling refuses;
 * 3. `DerivesChannelPolicy::supportFor()` combines the result through
 *    `ChannelCapabilitySupport::refinedBy()`, whose `Unsupported` arm is absorbing — and
 *    `ModeGuardedChannelDriver` then re-derives `supports()` as the *conjunction* of the matrix and
 *    whatever this driver said.
 *
 * That is Property 21's floor, and the failure it prevents is concrete: a partner that could widen
 * `GROUPS` would have a group-create dispatched to a gateway with no such API, mid-operation, after
 * the capability gate had already allowed it — which is the provider error a typed pre-dispatch
 * `ModeCapabilityException` exists to replace.
 *
 * ### The sub-matrix needs credentials, and `supports()` has no parameter for them
 *
 * `ChannelDriver::supports()` takes a capability and nothing else, and it must perform **no I/O** —
 * it is called on every send and once per capability at session create. So this driver is
 * *bindable*: `withCredentials()` returns a new instance carrying one credential set, and the bound
 * instance's `supports()` answers for that partner account. An **unbound** instance answers the mode
 * ceiling, which is the only honest answer when the partner is not yet known.
 *
 * Everything that needs the narrowed answer binds first: `send()`, `sendTemplateTo()` and
 * `sendMedia()` resolve credentials and then read the bound support, and task 8.2 calls
 * `capabilitiesFor($credentials)` or `subMatrixFor($credentials)`. Binding returns a new instance
 * rather than mutating, so the driver stays `readonly` and no request can leave one tenant's
 * credentials bound to a driver another tenant's job then resolves.
 *
 * ### Why the driver re-checks the capability that the router already gated
 *
 * `ChannelRouter::assertSupported()` gates on the **mode**, and for every other mode that is the
 * whole answer. Here it is not: a `MEDIA` send on a WATI session passes the mode gate (`⚠️ per
 * provider`) and must still be refused, because WATI has no send-by-link media route. This driver is
 * the only place that knows, so `send()` raises `ModeCapabilityException` itself — before any
 * request, with no side effect, which is exactly Req 8.3's requirement. Task 8.2 persisting the
 * *driver's* set rather than the mode's is what makes the router agree with this in a later phase.
 *
 * ## Credentials — §2.7's `BSP_GATEWAY` row
 *
 * design § 2.7 gives this mode *"provider (enum), endpoint/base URL, sender id/number"* as config and
 * *"provider **api key/secret**, webhook signing secret"* as secrets. The provider comes from
 * `ChannelCredentials::$provider` — which that class *guarantees* is non-null for this mode, for
 * exactly this reason — and the rest is per partner: each adapter names its own keys through
 * `requiredConfigKeys()` / `requiredSecretKeys()`, which is what `healthCheck()` reports instead of
 * probing when they are absent (so task 7.6 can keep the previous working set, Req 8.6, 8.13).
 *
 * Nothing is memoised. Credentials are resolved through `ChannelCredentialStore` on every call, which
 * is Req 8.5's *"decrypted only within the lifetime of the request or job"* plus one practical
 * consequence: a key the tenant has just rotated is picked up by the next send rather than by the
 * next worker restart.
 *
 * ## `parseWebhook()` — the recipient comes from the credentials, never from the payload
 *
 * The order is the contract's — origin, recipient, shape — and the middle step is the security
 * content of the method:
 *
 * 1. **Origin** is the adapter's (`Bsp\BspWebhookVerifier` carries the five schemes: hex HMAC,
 *    base64 HMAC over the body, base64 HMAC over a canonical URL string, a signed JWT with a body
 *    digest, and a shared-secret header).
 * 2. **Recipient** is *this* class's: the expected identity is
 *    `Bsp\BspAdapter::senderIdentity($credentials)` and the payload's claim is checked **against** it
 *    with `hash_equals()`. A partner signature proves who sent a body, not whose number it is about,
 *    and a partner account fronting several tenants' numbers can legitimately sign a payload for any
 *    of them — so a body signed with tenant A's secret POSTed to tenant B's route key would otherwise
 *    become an `InboundEvent` carrying **B's** tenant id (that is where `tenantId` comes from) and A's
 *    message content: one valid signature, and any tenant's conversation history is injectable
 *    (Req 8.4, Property 23). The refusal is `WebhookVerificationException::wrongRecipient()`.
 * 3. **Shape** is the adapter's again, and a verified payload this release does not act on is
 *    `InboundEvent::unsupported()` — a successful parse and a `200`, so the partner stops retrying.
 *
 * Four partners cannot supply a claim on every callback shape and say so
 * (`Bsp\BspAdapter::requiresRecipientClaim()`); each states in its own docblock what carries the
 * binding instead and what the residual risk is. Nothing is skipped silently.
 *
 * ### Partners batch, and a single `InboundEvent` cannot say so
 *
 * 360dialog forwards Meta's `entry[].changes[]` envelope and Infobip batches under `results[]`, so
 * one HTTP request can carry several logical events. `parseWebhookBatch()` is the real method and
 * `parseWebhook()` returns the first of what it found, exactly as `CloudApiChannelDriver` resolves
 * the same problem — and every event carries `_batch_size` / `_batch_index` so a caller holding one
 * can *detect* that there were more. **Task 8.3 must call `parseWebhookBatch()`.**
 *
 * ## `register()` and `healthCheck()` report, they do not raise
 *
 * A BSP number is provisioned in the partner's own console — §2.7 lists no credential this platform
 * could register a number with, and none of the eight exposes a route for it — so `register()`
 * *verifies* rather than registers: one read-only account probe, `live()` when the partner
 * recognised the credentials and `pending()` when it answered something this release cannot read as
 * a confirmation. Only a provider refusal or a transport failure is an exception, which is what the
 * contract reserves them for.
 *
 * `healthCheck()` is per **partner account** and never raises for an unhealthy answer — an incomplete
 * credential set is `unhealthy()` naming the missing keys, a partner refusal is `unhealthy()` with a
 * sentence rendered from the partner's *code* (never its prose, which quotes the request and on a
 * `401` the key it was sent), and only "we could not ask at all" propagates. That distinction is what
 * task 7.6 needs in order to retain a working credential set, and what task 7.6's per-partner health
 * display reads.
 *
 * ## `requiresAntiBan()` is `false`, and this class does not say so
 *
 * It comes from `Concerns\DerivesChannelPolicy` as `mode()->isWebProtocol()`, and is not overridden:
 * Req 8.8 requires the anti-ban gate be non-disableable on a web-protocol mode, and a driver that
 * *chose* its answer would be a way to choose wrong. An official partner route follows the partner's
 * template / 24-hour-window / rate rules instead (Property 24), which are tasks 8.1 and 8.4.
 *
 * ## The transport half: eleven inherited methods, honest answers
 *
 * | Method | On a BSP partner |
 * |---|---|
 * | `sendText`, `sendMedia` | **real** — the partner's own send route; media by link only (see `sendMedia()`) |
 * | `sessionState` | **real** — the account probe: recognised credentials are `CONNECTED`, otherwise `QR_PENDING` |
 * | `isReachable` | **real** — whether a partner host answers at all; see the method for what "a partner" means without credentials |
 * | `qr` | **`null`** — a documented answer: a partner number is provisioned, never paired |
 * | `checkNumbers` | all **`unknown`** — no partner here exposes an `onWhatsApp` probe, and answering `false` would record "not on WhatsApp" as a permanent fact |
 * | `provisionSession`, `startSession`, `stopSession`, `pairingCode`, `sendPresence` | `ChannelOperationException` (422) — a refusal, never a silent no-op |
 */
final readonly class BspGatewayChannelDriver implements ChannelDriver
{
    use DedupesChannelSends;
    use DerivesChannelPolicy;
    use ResolvesOwnedSession;

    /**
     * Keys added to every event's payload so a caller holding **one** event can tell that the partner
     * batched several into the request — `CloudApiChannelDriver`'s convention, and task 8.3 reads it.
     */
    public const string BATCH_SIZE_KEY = '_batch_size';

    public const string BATCH_INDEX_KEY = '_batch_index';

    /**
     * Reserved `$vars` keys carrying the two things `ChannelDriver::sendTemplate()`'s signature has no
     * parameter for — see `sendTemplate()`, and prefer `sendTemplateTo()`.
     *
     * The same names 7.2 reserved, deliberately: a caller that has learned the convention for one
     * official mode must not have to learn a second one, and no template author can collide with them
     * (Meta's positional parameters are digits and its named ones may not begin with an underscore).
     */
    public const string TEMPLATE_RECIPIENT_VAR = '_to';

    public const string TEMPLATE_KEY_VAR = '_key';

    /**
     * Request budgets, used when `wa.channel.bsp` says nothing usable — so a deleted config key
     * degrades to a working driver rather than to an unbounded read.
     */
    public const int DEFAULT_TIMEOUT = 15;

    public const int DEFAULT_CONNECT_TIMEOUT = 5;

    /**
     * How many partner hosts an **unbound** `isReachable()` probes before answering.
     *
     * Two, and the number is a compromise both ways: one would read a single partner's outage as the
     * whole partner network being down, and eight would let a screen that asked a yes/no question wait
     * out eight connect timeouts. See `isReachable()`.
     */
    public const int MAX_REACHABILITY_PROBES = 2;

    /**
     * The suffix of a WhatsApp **group** identity, which no partner route can address.
     *
     * Named here rather than borrowed from another mode's message builder so the refusal in
     * `recipientDigits()` does not depend on a Cloud-API class staying where it is.
     */
    public const string GROUP_JID_SUFFIX = '@g.us';

    /**
     * A health probe's budget: shorter than a send's, because the point of asking is to find out
     * quickly and a probe that waits fifteen seconds to say "down" has already stalled the screen it
     * feeds (`HttpBridgeClient::HEALTH_TIMEOUT`'s reasoning).
     */
    private const int HEALTH_TIMEOUT = 5;

    /**
     * @param  ChannelCredentials|null  $bound  the credential set this instance's `supports()` answers
     *                                          for; `null` on the instance the container builds, and
     *                                          set only by `withCredentials()`
     */
    public function __construct(
        private HttpFactory $http,
        private ChannelCredentialStore $credentials,
        private IdempotencyStore $idempotency,
        private ProviderCallGuard $guard,
        private BspAdapterRegistry $adapters,
        private BspWebhookVerifier $verifier,
        private ?ChannelCredentials $bound = null,
    ) {}

    public function mode(): ChannelMode
    {
        return ChannelMode::BspGateway;
    }

    /*
    |--------------------------------------------------------------------------
    | The per-provider capability sub-matrix
    |--------------------------------------------------------------------------
    */

    /**
     * This driver, bound to one partner account — the instance whose `supports()` is that partner's.
     *
     * A new instance rather than a mutation, so the driver stays `readonly` and immutable: a bound
     * copy cannot leak a tenant's credentials into a driver another tenant's job later resolves from
     * the container, which is the risk a settable property would carry inside a long-lived worker.
     *
     * @throws InvalidArgumentException when the credentials are not this mode's
     */
    public function withCredentials(ChannelCredentials $credentials): self
    {
        if ($credentials->mode !== $this->mode()) {
            throw new InvalidArgumentException(sprintf(
                'BspGatewayChannelDriver cannot be bound to [%s] credentials: the capability sub-matrix '
                .'it would resolve belongs to a BSP partner, and this credential set names none.',
                $credentials->mode->value,
            ));
        }

        return new self(
            $this->http,
            $this->credentials,
            $this->idempotency,
            $this->guard,
            $this->adapters,
            $this->verifier,
            $credentials,
        );
    }

    /**
     * The partner this instance is bound to, or `null` when it is unbound.
     *
     * `ChannelCredentials` guarantees a non-null provider for this mode, so a bound instance always
     * has one — the null here means *"not bound"*, never *"bound to a partnerless BSP credential
     * set"*.
     */
    public function provider(): ?BspProvider
    {
        return $this->bound?->provider;
    }

    /**
     * The whole resolved sub-matrix for one partner account, capability by capability.
     *
     * What task 8.2 persists as a session's authoritative capability list (Req 8.2): it is a pure
     * function of `(mode, provider, credentials.config)` with no I/O, so it can be computed once at
     * session create and stored, and recomputing it later yields the same answer unless the tenant
     * changed its partner configuration — which is a credential write and therefore already an event
     * task 8.2 re-runs the handshake on.
     *
     * Keyed by `ChannelCapability::value` rather than by the enum, so it round-trips through the JSON
     * column that stores it.
     *
     * @return array<string, ChannelCapabilitySupport>
     */
    public function subMatrixFor(ChannelCredentials $credentials): array
    {
        $bound = $this->withCredentials($credentials);
        $matrix = [];

        foreach (ChannelCapability::cases() as $capability) {
            $matrix[$capability->value] = $bound->supportFor($capability);
        }

        return $matrix;
    }

    /**
     * Every capability one partner account may attempt, in declaration order.
     *
     * @return list<ChannelCapability>
     */
    public function capabilitiesFor(ChannelCredentials $credentials): array
    {
        return $this->withCredentials($credentials)->capabilities();
    }

    /**
     * Layers 2 and 3 of the sub-matrix, composed under the ceiling — see the class docblock.
     *
     * Three things about the body are load-bearing:
     *
     * - an **unbound** instance returns the ceiling unchanged, because the partner is unknown and the
     *   mode row is the only honest answer;
     * - an already-refused ceiling short-circuits, so no adapter is ever *asked* about a capability the
     *   route does not have — an adapter cannot even try to widen one;
     * - the partner's own layer (`BspProvider::declaredSupport()`) is combined with the account layer
     *   through `refinedBy()`, and the trait combines *that* with the ceiling through `refinedBy()`
     *   again.
     *
     * No I/O: the credentials are already in memory and `config` is a decoded array on them, which is
     * what makes this safe to call on every send and once per capability at session create.
     */
    protected function refineSupport(
        ChannelCapability $capability,
        ChannelCapabilitySupport $ceiling,
    ): ChannelCapabilitySupport {
        $credentials = $this->bound;
        $provider = $credentials?->provider;

        if ($credentials === null || $provider === null) {
            return $ceiling;
        }

        if (! $ceiling->isSupported()) {
            // The route refuses this capability. Nothing below is consulted, so no partner
            // configuration participates in a decision that has already been made (Property 21).
            return $ceiling;
        }

        $declared = $provider->declaredSupport($capability);

        return $declared->refinedBy(
            $this->adapters->for($provider)->refineSupport($capability, $declared, $credentials),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Outbound
    |--------------------------------------------------------------------------
    */

    /**
     * Send one message through this session's partner, at most once per idempotency key.
     *
     * @throws ChannelCredentialException when the tenant has no usable `BSP_GATEWAY` credentials
     * @throws ModeCapabilityException when this **partner** does not support the content's capability
     * @throws ChannelRequestFailedException when the partner refuses the send
     * @throws BridgeUnreachableException when the partner never answered, so the outcome is unknown
     * @throws IdempotencyKeyReuseException when the key was first used for a different send
     * @throws OperationInFlightException when a duplicate is still in flight
     */
    public function send(Session $session, OutboundContent $content): SendReceipt
    {
        $capability = $content->capability();
        $credentials = $this->credentialsFor($session);
        $adapter = $this->adapterFor($credentials);
        $support = $this->assertPartnerSupports($credentials, $capability);
        $recipient = $this->recipientDigits($content->recipient());

        $outcome = $this->idempotency->once(
            self::sendScope($session),
            $content->idempotencyKey(),
            fn (): array => $this->dispatch(
                $credentials,
                $adapter,
                $adapter->textMessage($credentials, $recipient, $content->plainText()),
                'message.text',
                $recipient,
                // Read from the resolved sub-matrix, not decided here: a `⚠️` cell degrades and a `✅`
                // cell does not, exactly as 7.1 and 7.2 compute it — so the flag and the gate cannot
                // drift apart.
                degraded: ! $support->isNative(),
            ),
            $this->sendOptions($session, $this->sendFingerprint(
                $session,
                $capability,
                $recipient,
                $content->plainText(),
                // So one key cannot answer a Twilio send with a Gupshup send's receipt when a tenant
                // has moved a session between partners.
                discriminator: $credentials->provider?->value,
            )),
        );

        return $this->receipt($content->idempotencyKey(), $capability, $credentials, $outcome->payload());
    }

    /**
     * Send a pre-approved template, at most once per idempotency key.
     *
     * ## The reserved `$vars` keys, and the contract gap behind them
     *
     * design § 2.4 declares `sendTemplate(Session, TemplateRef, array $vars)`, and that signature is
     * missing two things every template send needs: *who* it goes to, and the key it is deduplicated
     * on. Neither can be derived — the `Session` is the *sender's* number, and `SendReceipt` refuses
     * to exist without a recipient and a key. So both are read from `$vars` under `_to` and `_key`,
     * and `sendTemplateTo()` is the honest signature.
     *
     * This is 7.2's convention verbatim, and the gap is reported to task 8.4 in the same terms: the
     * right fix is a `TemplateContent implements OutboundContent`, after which this convention can be
     * deleted from both drivers at once.
     *
     * @param  array<array-key, string|int|float>  $vars  placeholder values, plus `_to` and `_key`
     *
     * @throws InvalidArgumentException when `_to` or `_key` is absent
     * @throws ChannelTemplateException when the template is unknown, not approved, or mis-filled
     * @throws ChannelCredentialException when the tenant has no usable `BSP_GATEWAY` credentials
     * @throws ModeCapabilityException when this partner account has no template registry
     * @throws ChannelRequestFailedException when the partner refuses the send
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
     * The order is: credentials → capability → **re-read the template row** → check the variables →
     * build the partner's body → POST. Everything before the POST is a local refusal, because
     * `ChannelDriver::sendTemplate()` requires that a mis-filled template be *"a local failure and not
     * a provider rejection charged to the tenant's quality rating"* — which on a partner route is
     * charged to the number's quality rating at Meta just the same.
     *
     * @param  array<array-key, string|int|float>  $vars  placeholder values, keyed as the template numbers them
     *
     * @throws ChannelTemplateException when the template is unknown, not approved, or mis-filled
     * @throws ChannelCredentialException when the tenant has no usable `BSP_GATEWAY` credentials
     * @throws ModeCapabilityException when this partner account has no template registry
     * @throws ChannelRequestFailedException when the partner refuses the send
     */
    public function sendTemplateTo(
        Session $session,
        TemplateRef $template,
        array $vars,
        string $recipient,
        string $idempotencyKey,
    ): SendReceipt {
        $credentials = $this->credentialsFor($session);
        $adapter = $this->adapterFor($credentials);
        $this->assertPartnerSupports($credentials, ChannelCapability::Template);

        $row = $this->sendableTemplate($credentials, $template);
        $parameters = $this->templateParameters($template, $row, $vars);
        $digits = $this->recipientDigits($recipient);

        $outcome = $this->idempotency->once(
            self::sendScope($session),
            $idempotencyKey,
            fn (): array => $this->dispatch(
                $credentials,
                $adapter,
                $adapter->templateMessage($credentials, $digits, $row, $parameters),
                'message.template',
                $digits,
                degraded: false,
                templateName: $row->name,
            ),
            $this->sendOptions($session, $this->sendFingerprint(
                $session,
                ChannelCapability::Template,
                $digits,
                $row->body,
                discriminator: $credentials->provider?->value.'|'.$template->key().'|'.json_encode($vars),
            )),
        );

        return $this->receipt($idempotencyKey, ChannelCapability::Template, $credentials, $outcome->payload());
    }

    /*
    |--------------------------------------------------------------------------
    | Inbound
    |--------------------------------------------------------------------------
    */

    /**
     * Verify a partner webhook and normalise **the first** event it carries.
     *
     * The contract's method, and the reason `parseWebhookBatch()` exists directly below it: 360dialog
     * and Infobip batch, and one `InboundEvent` cannot express several. Task 8.3 must call the batch
     * method — every returned event carries `_batch_size` / `_batch_index` so the loss is detectable
     * rather than silent.
     *
     * @throws WebhookVerificationException when origin, recipient, or shape does not check out
     * @throws ChannelCredentialException when the tenant stored no webhook secret to verify against
     */
    public function parseWebhook(Request $request, ChannelCredentials $credentials): InboundEvent
    {
        return $this->parseWebhookBatch($request, $credentials)[0];
    }

    /**
     * Verify a partner webhook once and normalise **every** event it carries, in document order.
     *
     * ```php
     * // task 8.3's controller, after resolving (tenant, session, driver) from the route key
     * foreach ($driver->parseWebhookBatch($request, $credentials) as $event) {
     *     if ($event->isActionable()) { $this->dispatch($event); }
     * }
     * return response()->noContent();   // one 200 for the whole batch
     * ```
     *
     * Order of checks is the contract's — origin, recipient, shape — and never empty: a verified body
     * this release does not act on yields one `UNSUPPORTED` event, which is a successful parse and a
     * `200` so the partner stops retrying it.
     *
     * @return non-empty-list<InboundEvent>
     *
     * @throws WebhookVerificationException when origin, recipient, or shape does not check out
     * @throws ChannelCredentialException when the tenant stored no webhook secret to verify against
     */
    public function parseWebhookBatch(Request $request, ChannelCredentials $credentials): array
    {
        $adapter = $this->adapterFor($credentials);

        // From the caller's credentials, never from the body. A missing sender is a wiring or
        // configuration defect rather than something a request can provoke, which is why it is an
        // InvalidArgumentException from the credential bag and not a 403.
        $expected = $adapter->senderIdentity($credentials);

        // 1. Origin — the partner's own proof, over the raw bytes or its canonical equivalent.
        $adapter->verifyWebhook($request, $credentials, $this->verifier);

        $payload = $adapter->decodeWebhook($request);

        // 2. Recipient — the credentials' answer is the only answer; the payload's claim is checked
        // *against* it (Req 8.4, Property 23).
        $claimed = $adapter->recipientIn($payload, $request);

        if ($claimed !== null) {
            if (! hash_equals($expected, $claimed)) {
                throw WebhookVerificationException::wrongRecipient($this->mode(), $expected, $claimed);
            }
        } elseif ($adapter->requiresRecipientClaim()) {
            throw WebhookVerificationException::malformedPayload(
                $this->mode(),
                'it names no sender identity, so it cannot be matched against the credentials the route '
                .'it arrived on belongs to',
            );
        }

        // 3. Shape.
        $events = $adapter->eventsIn($payload, $request, $credentials, $expected);

        if ($events === []) {
            return [InboundEvent::unsupported($this->mode(), $credentials->tenantId, $expected, $payload)];
        }

        return $this->stamped($events);
    }

    /*
    |--------------------------------------------------------------------------
    | Credentials & onboarding
    |--------------------------------------------------------------------------
    */

    /**
     * Verify that this session's partner recognises these credentials, and report what it said.
     *
     * **Verifies rather than registers, and that is not a shortcut.** A BSP number is provisioned in
     * the partner's own console or by the partner's onboarding team; §2.7 lists no credential this
     * platform could register a number with, and none of the eight partners exposes a route for it.
     * So the honest act here is the read-only account probe: `live()` when the partner recognised the
     * credentials — which is what "this number can send now" reduces to on a partner route — and
     * `pending()` when it answered something this release cannot read as a confirmation.
     *
     * Idempotent and read-only, for `provisionSession()`'s reason one layer up: a retried
     * registration must not be able to throw away a working one.
     *
     * No callback URL is claimed. A partner's callback is configured in its console against the
     * platform's `route_key`, which task 8.3 mints — reporting a `callbackUrl` this driver did not
     * register would put a URL on a panel that nothing answers (7.1's and 7.2's reasoning, unchanged).
     *
     * @throws ChannelRequestFailedException when the partner refuses the probe
     * @throws BridgeUnreachableException when the partner never answered
     */
    public function register(Session $session, ChannelCredentials $credentials): RegistrationResult
    {
        $adapter = $this->adapterFor($credentials);
        $missing = self::missingCredentialKeys($adapter, $credentials);

        if ($missing !== []) {
            return RegistrationResult::pending($credentials, sprintf(
                'These %s credentials are incomplete: %s. The partner is not contacted until they are '
                .'filled in, because every request without them is refused.',
                $adapter->provider()->label(),
                implode(', ', $missing),
            ));
        }

        $payload = $this->guarded('register', $credentials, $adapter, $adapter->accountProbe($credentials));
        $detail = $adapter->accountDetailIn($payload);

        if ($detail === null) {
            return RegistrationResult::pending($credentials, sprintf(
                '%s answered the account probe without confirming these credentials, so this number is '
                .'not marked sendable yet.',
                $adapter->provider()->label(),
            ));
        }

        return RegistrationResult::live(
            $credentials,
            // The partner's own sender identity, which is what every later send addresses itself with.
            $adapter->senderIdentity($credentials),
            detail: $detail.' No callback URL is claimed here: a partner callback is configured in the '
                .'partner\'s console against the platform\'s route key, which is minted when the '
                .'webhook route is created.',
        );
    }

    /**
     * Probe whether `$credentials` actually work with their partner, and report it as **data**.
     *
     * What task 7.6 calls before activating a credential set on save or rotate, and what task 7.6's
     * per-partner health display reads. Four answers, and only one of them is an exception:
     *
     * | Situation | Answer |
     * |---|---|
     * | a required key is missing | `unhealthy()`, naming the keys — so 7.6 keeps the previous working set |
     * | the partner refused (bad key, unknown account) | `unhealthy()`, with a sentence rendered from its **code** |
     * | the partner answered something unreadable | `unhealthy()` — it did not establish that a send would work |
     * | the partner could not be reached at all | `BridgeUnreachableException` propagates |
     *
     * The last row is the contract's own division: *"we could not ask"* and *"the provider said no"*
     * lead to different operator actions. The refusal sentence never quotes the partner's prose — most
     * of the eight echo an `Authorization` fragment on a `401` — and `ChannelHealth` passes even the
     * rendered sentence through `ChannelCredentials::redact()` on the way in.
     */
    public function healthCheck(ChannelCredentials $credentials): ChannelHealth
    {
        $adapter = $this->adapterFor($credentials);
        $missing = self::missingCredentialKeys($adapter, $credentials);

        if ($missing !== []) {
            return ChannelHealth::unhealthy($credentials, sprintf(
                'These %s credentials are incomplete: %s. The partner rejects every request without '
                .'them, so they are refused here rather than probed.',
                $adapter->provider()->label(),
                implode(', ', $missing),
            ));
        }

        $startedAt = hrtime(true);

        try {
            $payload = $this->guarded(
                'health',
                $credentials,
                $adapter,
                $adapter->accountProbe($credentials),
                self::HEALTH_TIMEOUT,
            );
        } catch (ChannelRequestFailedException $refused) {
            return ChannelHealth::unhealthy(
                $credentials,
                self::healthDetailFor($adapter, $refused),
                self::elapsedMs($startedAt),
            );
        }

        $latencyMs = self::elapsedMs($startedAt);
        $detail = $adapter->accountDetailIn($payload);

        return $detail === null
            ? ChannelHealth::unhealthy($credentials, sprintf(
                '%s answered the credential probe without describing the account, so these credentials '
                .'could not be confirmed.',
                $adapter->provider()->label(),
            ), $latencyMs)
            : ChannelHealth::healthy($credentials, $detail, $latencyMs);
    }

    /*
    |--------------------------------------------------------------------------
    | Transport — the eleven inherited methods (see the class docblock's table)
    |--------------------------------------------------------------------------
    */

    /**
     * Refused: a partner number is **provisioned**, not paired.
     *
     * There is no QR to scan and no socket to provision — design § 2.5 gives the official modes
     * `driver->register(...)` and leaves QR pairing to Baileys, so task 9.1 branches by mode and never
     * reaches this. A no-op returning a plausible `SessionInitDto` would mark the session provisioned
     * without the partner having been asked anything.
     *
     * @throws ChannelOperationException always
     */
    public function provisionSession(
        string $sessionId,
        SessionLoginMethod $method,
        ?string $phone = null,
        ?string $authStateDir = null,
    ): SessionInitDto {
        throw ChannelOperationException::unsupported($this->mode(), 'provisionSession', sprintf(
            'a BSP number is provisioned in the partner\'s own console and reached with an API key '
            .'rather than paired by QR or pairing code — use register(), and read its state with '
            .'sessionState()'
        ));
    }

    /**
     * Refused: there is no socket to open.
     *
     * @throws ChannelOperationException always
     */
    public function startSession(string $sessionId): void
    {
        throw ChannelOperationException::unsupported($this->mode(), 'startSession', sprintf(
            'a partner-hosted number holds no connection this platform opens — it is sendable while its '
            .'API key is valid, which healthCheck() reports'
        ));
    }

    /**
     * Refused, and deliberately **not** mapped onto a partner's number-release route.
     *
     * Several of the eight can release or re-point a sender, and calling one here would make an
     * ordinary "stop this session" surrender the tenant's number at its partner — destructive, slow to
     * reverse through a partner's support queue, and triggered by a routine operation. That stays a
     * deliberate, audited tenant action, which is the mode-switch task's (8.6).
     *
     * @throws ChannelOperationException always
     */
    public function stopSession(string $sessionId, bool $logout = false): void
    {
        throw ChannelOperationException::unsupported($this->mode(), 'stopSession', sprintf(
            'a partner-hosted number has no session to close, and releasing it at the partner is an '
            .'audited tenant action rather than a side effect of stopping one'
        ));
    }

    /**
     * The partner's live view of this number, as a session state.
     *
     * The account probe: credentials the partner recognises are `CONNECTED`, and anything else is
     * `QR_PENDING` — *awaiting provider-side confirmation*, the same meaning that status carries for a
     * Baileys session awaiting a scan. A **report**, not the truth: `sessions_wa.status` remains the
     * state of record and `SessionStatus::canTransitionTo()` may refuse what this says.
     *
     * @throws ChannelRequestFailedException when the partner refuses the probe
     * @throws BridgeUnreachableException when the partner never answered
     */
    public function sessionState(string $sessionId): SessionStateDto
    {
        $session = $this->ownedSession($sessionId);
        $credentials = $this->credentialsFor($session);
        $adapter = $this->adapterFor($credentials);

        $payload = $this->guarded(
            'session.state',
            $credentials,
            $adapter,
            $adapter->accountProbe($credentials),
            self::HEALTH_TIMEOUT,
        );

        $recognised = $adapter->accountDetailIn($payload) !== null;

        return new SessionStateDto(
            sessionId: $session->id,
            status: $recognised ? SessionStatus::Connected : SessionStatus::QrPending,
            phone: self::digitsOrNull($credentials->config(BaseBspAdapter::SENDER_CONFIG_KEY)),
            pushName: null,
            disconnectReason: $recognised ? null : 'provider_account_unconfirmed',
        );
    }

    /**
     * `null`, always — and that is an answer rather than a failure.
     *
     * `BridgeClient::qr()` already documents `null` as *"this session is not showing one"*, which is
     * permanently true of a number reached through a partner API key. A connection screen that asks
     * every driver for a QR therefore renders nothing for this mode without knowing which mode it is
     * looking at.
     */
    public function qr(string $sessionId): ?string
    {
        return null;
    }

    /**
     * Refused: pairing codes belong to the WhatsApp Web protocol.
     *
     * The one transport method whose return type has no honest empty value — an invented code would be
     * shown to a tenant as something to type into a phone.
     *
     * @throws ChannelOperationException always
     */
    public function pairingCode(string $sessionId, string $phone): string
    {
        throw ChannelOperationException::unsupported($this->mode(), 'pairingCode', sprintf(
            'pairing codes are a WhatsApp Web login method; a BSP number is claimed at the partner '
            .'during onboarding'
        ));
    }

    /**
     * One text message on the wire — the transport primitive, not the driver-level send.
     *
     * Not idempotent, exactly as `BridgeClient::sendText()` is not: *"it puts one message on the wire
     * each time it is called"*. Deduplication is `send()`'s.
     *
     * `$opts` is deliberately ignored rather than merged into the partner's body: the eight partners
     * accept eight different option sets and several reject an unknown field outright, so passing a
     * caller's map through would turn a typo into a 4xx counted against the number's quality rating —
     * the argument `CloudApiChannelDriver::sendText()` makes about Meta, multiplied by eight.
     *
     * @param  array<string, mixed>  $opts  accepted for contract compatibility; see above
     *
     * @throws ChannelRequestFailedException when the partner refuses the send
     * @throws BridgeUnreachableException when the partner never answered
     */
    public function sendText(string $sessionId, string $jid, string $text, array $opts = []): SentMessageDto
    {
        $session = $this->ownedSession($sessionId);
        $credentials = $this->credentialsFor($session);
        $adapter = $this->adapterFor($credentials);
        $recipient = $this->recipientDigits($jid);

        return $this->postMessage(
            'message.text',
            $credentials,
            $adapter,
            $adapter->textMessage($credentials, $recipient, $text),
            $recipient,
        );
    }

    /**
     * One media message on the wire — **by link only**.
     *
     * Every one of the eight partners fetches media from a URL, and **none** of them shares an upload
     * route: Twilio takes `MediaUrl`, Infobip `content.mediaUrl`, Gupshup `originalUrl`, and so on. So
     * an inline-bytes payload is refused here rather than each adapter inventing an upload — and this
     * is also the platform's normal path, since `MediaPayload::fromUrl()` exists precisely so
     * *"nothing large crosses the PHP boundary"*.
     *
     * The capability is checked against the **partner** first, which is what stops a media send on a
     * WATI session — WATI has no send-by-link route at all — from reaching the wire (see
     * `Bsp\Adapters\WatiAdapter`).
     *
     * @param  array<string, mixed>  $opts  accepted for contract compatibility; see `sendText()`
     *
     * @throws ModeCapabilityException when this partner does not support media
     * @throws ChannelOperationException when the payload carries inline bytes rather than a URL
     * @throws ChannelRequestFailedException when the partner refuses the send
     */
    public function sendMedia(string $sessionId, string $jid, MediaPayload $media, array $opts = []): SentMessageDto
    {
        $session = $this->ownedSession($sessionId);
        $credentials = $this->credentialsFor($session);
        $adapter = $this->adapterFor($credentials);
        $this->assertPartnerSupports($credentials, ChannelCapability::Media);

        if ($media->url === null) {
            throw ChannelOperationException::unsupported($this->mode(), 'sendMedia', sprintf(
                'a BSP partner fetches media from a URL and none of them shares an upload route, so an '
                .'inline-bytes payload has nowhere to go — store the media and send it with '
                .'MediaPayload::fromUrl()'
            ));
        }

        $recipient = $this->recipientDigits($jid);

        return $this->postMessage(
            'message.media',
            $credentials,
            $adapter,
            $adapter->mediaMessage($credentials, $recipient, $media),
            $recipient,
        );
    }

    /**
     * Refused: a partner route has no presence channel.
     *
     * There is no "typing…" a business number can broadcast through a BSP, so a silent no-op would
     * leave a caller believing a presence indicator was sent.
     *
     * @throws ChannelOperationException always
     */
    public function sendPresence(string $sessionId, string $jid, PresenceState $presence): void
    {
        throw ChannelOperationException::unsupported($this->mode(), 'sendPresence', sprintf(
            'the official partner routes have no presence channel — a business number cannot broadcast '
            .'"typing" or "online"'
        ));
    }

    /**
     * Every asked number, answered `unknown`.
     *
     * No partner here exposes an `onWhatsApp` / contacts probe: they front Meta's Business Platform,
     * which has none. `NumberCheck` exists with a three-valued `exists` for exactly this, and its
     * docblock states the consequence of the alternative — answering `false` would record *"not on
     * WhatsApp"* as a permanent fact about somebody's phone number, and every later campaign would
     * skip a real customer for ever.
     *
     * The caller's spelling is preserved as the key, and no request is made: there is nothing to ask.
     *
     * @param  list<string>  $numbers
     * @return array<array-key, NumberCheck>
     */
    public function checkNumbers(string $sessionId, array $numbers): array
    {
        // Ownership is still resolved, even though nothing is sent: a caller must not be able to learn
        // whether a session id exists by whether this method answers.
        $this->ownedSession($sessionId);

        $checks = [];

        foreach ($numbers as $number) {
            $checks[$number] = new NumberCheck((string) $number, null);
        }

        return $checks;
    }

    /**
     * Whether a partner API is answering at all.
     *
     * Credential-free, like every `isReachable()`: it asks *"is the dependency up?"*, and **any** HTTP
     * answer counts — an unauthenticated partner request is *supposed* to be refused, and a refusal
     * proves the host is serving. Only a transport failure is a `false`, which is the rule
     * `HttpBridgeClient` applies to its own health route.
     *
     * ## What "the dependency" means when there are eight of them
     *
     * A **bound** instance (`withCredentials()`) probes its own partner's host, which is the precise
     * answer. An **unbound** one has no partner to name, so it probes the first
     * `MAX_REACHABILITY_PROBES` partner hosts in `BspProvider` order and answers `true` if any of them
     * responds. That is deliberately a claim about *this platform's egress to the partner network*
     * rather than about any tenant's account — which is all a credential-free probe can honestly
     * assert, and `healthCheck()` is the per-account answer.
     *
     * The bound is why it is not all eight: eight sequential connect timeouts would stall the screen
     * that asked a yes/no question, and one host would let a single partner's outage read as the whole
     * network being down.
     */
    public function isReachable(): bool
    {
        $adapters = $this->bound !== null && $this->bound->provider !== null
            ? [$this->adapters->for($this->bound->provider)]
            : array_slice($this->adapters->all(), 0, max(1, self::MAX_REACHABILITY_PROBES));

        foreach ($adapters as $adapter) {
            try {
                $this->request(self::HEALTH_TIMEOUT, [])->get(
                    $this->bound === null ? $adapter->defaultBaseUrl() : $adapter->baseUrl($this->bound),
                );

                return true;
            } catch (Throwable) {
                // Every way of failing to ask is a "no" for this host; the next host still gets a turn.
                continue;
            }
        }

        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Credentials
    |--------------------------------------------------------------------------
    */

    /**
     * The tenant's decrypted `BSP_GATEWAY` credentials for `$session`, or a refusal.
     *
     * Resolved through `ChannelCredentialStore` on **every** call rather than memoised, which is
     * Req 8.5 plus one practical consequence: a key the tenant has just rotated is picked up by the
     * next send instead of by the next worker restart.
     *
     * `$session->tenant` rather than the acting tenant, and that difference is load-bearing: the store
     * refuses a lookup for a tenant other than the bound one (`CrossTenantAccessException`), so asking
     * about the session's owner is what turns a foreign session into a 403 instead of a send
     * authenticated with the caller's own API key.
     *
     * The provider argument is deliberately omitted, so the store answers with the tenant's **active**
     * `BSP_GATEWAY` credential row whichever partner that is. A tenant that has configured two
     * partners has two rows and exactly one active per `(tenant, mode, provider)` triple; which one a
     * *session* uses is task 8.6's to settle, and guessing a partner here would send a session's
     * traffic through a partner it was never configured for.
     *
     * @throws ChannelCredentialException when the tenant has no usable credential set (Req 8.13)
     */
    private function credentialsFor(Session $session): ChannelCredentials
    {
        $credentials = $this->credentials->for($session->tenant, $this->mode());

        if ($credentials === null || ! $credentials->isComplete()) {
            // Refused, never rerouted onto BAILEYS — see `ChannelCredentialException` for why a silent
            // reroute would send a brand's official traffic from a number it never registered.
            throw ChannelCredentialException::missing($this->mode(), $session->tenant_id);
        }

        return $credentials;
    }

    /**
     * The adapter for this credential set's partner.
     *
     * `ChannelCredentials` guarantees a non-null provider for `BSP_GATEWAY`, and
     * `BspAdapterRegistry::for()` is total, so this cannot fail for a credential set that exists — the
     * one narrow case it guards is a caller handing over another mode's credentials, which would
     * otherwise reach an adapter that reads keys it does not have.
     *
     * @throws InvalidArgumentException when the credentials are not this mode's
     */
    private function adapterFor(ChannelCredentials $credentials): BspAdapter
    {
        $provider = $credentials->provider;

        if ($credentials->mode !== $this->mode() || $provider === null) {
            throw new InvalidArgumentException(sprintf(
                'BspGatewayChannelDriver was handed [%s] credentials that name no BSP provider, so no '
                .'partner adapter can be resolved for them.',
                $credentials->mode->value,
            ));
        }

        return $this->adapters->for($provider);
    }

    /**
     * Refuse the operation unless **this partner account** supports `$capability`, and return the
     * resolved cell.
     *
     * The check the router cannot make: `ChannelRouter::assertSupported()` gates on the mode, whose
     * `BSP_GATEWAY` row is a ceiling, and only the partner's resolved sub-matrix knows that (say) WATI
     * has no media route. Raised **before** any request and with no side effect, which is Req 8.3's
     * requirement and Property 21's shape.
     *
     * @throws ModeCapabilityException when the partner refuses the capability
     */
    private function assertPartnerSupports(
        ChannelCredentials $credentials,
        ChannelCapability $capability,
    ): ChannelCapabilitySupport {
        $support = $this->withCredentials($credentials)->supportFor($capability);

        if (! $support->isSupported()) {
            throw ModeCapabilityException::for($this->mode(), $capability);
        }

        return $support;
    }

    /**
     * Which of a partner's required keys are absent — names only, never values.
     *
     * What `healthCheck()` and `register()` report instead of probing, so task 7.6 can tell a tenant
     * which field to fill in (Req 8.6, 8.13). The key *names* are partner vocabulary and are safe to
     * print; `ChannelCredentials` makes the same argument about `requireConfig()`'s message.
     *
     * @return list<string>
     */
    private static function missingCredentialKeys(BspAdapter $adapter, ChannelCredentials $credentials): array
    {
        $missing = [];

        foreach ($adapter->requiredConfigKeys() as $key) {
            $value = $credentials->config($key);

            if (! is_string($value) && ! is_int($value)) {
                $missing[] = $key;
            } elseif (trim((string) $value) === '') {
                $missing[] = $key;
            }
        }

        foreach ($adapter->requiredSecretKeys() as $key) {
            if (! $credentials->hasSecret($key)) {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    /*
    |--------------------------------------------------------------------------
    | Template internals
    |--------------------------------------------------------------------------
    */

    /**
     * The approved template row this send must use, re-read from the registry.
     *
     * **`cloud_api_templates` is reused rather than a parallel BSP registry invented**, and the key is
     * `credential_id`: a BSP credential row is one `(tenant, BSP_GATEWAY, provider)` triple, so a
     * tenant's Twilio templates and its Gupshup templates are rows against two different credential
     * ids and cannot be sent through each other's account. That is what `CloudApiTemplate::sendable()`
     * already enforces in SQL, and it is the right key for the same reason it is on Cloud API: a
     * template's approval belongs to the provider account it was approved under. The partner's own
     * handle for the row lives in `provider_template_id` (Twilio's `ContentSid`, Gupshup's template
     * id), which task 8.4's sync populates and each adapter reads.
     *
     * Three refusals, all before any partner call and all `ChannelTemplateException` (422): the
     * reference names another provider account, no row exists for `(account, name, language)`, or the
     * row exists and is not `APPROVED`. The last two are distinguished by a second lookup without the
     * approved filter, because *"submit this template"* and *"wait for review"* are different
     * instructions.
     *
     * @throws ChannelTemplateException when the template cannot be sent
     */
    private function sendableTemplate(ChannelCredentials $credentials, TemplateRef $template): CloudApiTemplate
    {
        $credentialId = $credentials->credentialId;

        if ($credentialId === null) {
            throw new InvalidArgumentException(
                'A BSP template send needs credentials resolved from a stored credential row: template '
                .'approval is per partner account, and cloud_api_templates is keyed on it.'
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
     * `$vars` as a positional parameter list, checked against the template's own placeholders.
     *
     * The placeholders come from the stored `body` — the tenant's copy with `{{1}}`-style markers —
     * rather than from `$vars`, for the obvious reason: the point of the check is to catch a `$vars`
     * that does not match. Both directions are refused, and surplus as firmly as missing: a surplus key
     * is nearly always a renamed or removed placeholder, and the send would otherwise go out with a
     * stale value in the wrong slot.
     *
     * Ascending numeric order, not order of appearance, because every partner here takes template
     * parameters **positionally** — so a body that mentions `{{2}}` before `{{1}}` (which a translation
     * legitimately does) must still send `{{1}}`'s value first.
     *
     * @param  array<array-key, string|int|float>  $vars
     * @return list<string>
     *
     * @throws ChannelTemplateException when the variables do not fill the placeholders exactly
     */
    private function templateParameters(TemplateRef $template, CloudApiTemplate $row, array $vars): array
    {
        $placeholders = self::placeholdersIn($row->body);
        $supplied = array_map(static fn (int|string $key): string => (string) $key, array_keys($vars));

        $missing = array_values(array_diff($placeholders, $supplied));
        $surplus = array_values(array_diff($supplied, $placeholders));

        if ($missing !== [] || $surplus !== []) {
            throw ChannelTemplateException::variableMismatch($this->mode(), $template->key(), $missing, $surplus);
        }

        return array_map(
            static fn (string $placeholder): string => (string) $vars[$placeholder],
            $placeholders,
        );
    }

    /**
     * The `{{n}}` placeholder keys of a template body, in ascending positional order.
     *
     * @return list<string>
     */
    private static function placeholdersIn(string $body): array
    {
        preg_match_all('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', $body, $matches);

        $found = array_values(array_unique($matches[1]));

        usort($found, static function (string $a, string $b): int {
            return ctype_digit($a) && ctype_digit($b) ? (int) $a <=> (int) $b : strcmp($a, $b);
        });

        return $found;
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
     * rebuilt from it by `receipt()`, so a fresh send and a replay leave `send()` by the same path.
     *
     * @return array<string, mixed>
     */
    private function dispatch(
        ChannelCredentials $credentials,
        BspAdapter $adapter,
        BspRequest $request,
        string $operation,
        string $recipient,
        bool $degraded,
        ?string $templateName = null,
    ): array {
        $sent = $this->postMessage($operation, $credentials, $adapter, $request, $recipient);

        return [
            'provider_message_id' => $sent->waMessageId,
            'recipient' => $sent->jid,
            'degraded' => $degraded,
            'template' => $templateName,
            // Recorded so a replay rebuilds a receipt naming the partner that actually carried the
            // message, which is what `channel_send_log` records per provider and what makes a failover
            // across two partners distinguishable from a retry on one (`SendReceipt`).
            'provider' => $credentials->provider?->value,
        ];
    }

    /**
     * One guarded send, read into the transport's own DTO.
     *
     * A partner that echoed the address it resolved is preferred as the recorded recipient, for
     * `CloudApiChannelDriver::postMessage()`'s reason: it is the identity the backend actually
     * addressed, and a partner that normalised a number differently reconciles receipts against its
     * own spelling.
     *
     * There is deliberately no accepted-at timestamp: the partners that send one disagree on its
     * format and most send none, and `SentMessageDto::$sentAt` of `null` is honest where `now()` would
     * be a fabricated fact on the delivery board.
     *
     * @throws ChannelRequestFailedException when the partner refuses the send
     * @throws BridgeUnreachableException when the partner never answered, or answered unreadably
     */
    private function postMessage(
        string $operation,
        ChannelCredentials $credentials,
        BspAdapter $adapter,
        BspRequest $request,
        string $recipient,
    ): SentMessageDto {
        $payload = $this->guarded($operation, $credentials, $adapter, $request);
        $result = $adapter->readSendResult($payload);

        if ($result === null) {
            // A 2xx with no partner message id: nothing could ever be reconciled with this send, and an
            // unreconcilable "sent" is worse than a retry.
            throw BridgeUnreachableException::malformedResponse(
                self::label($credentials, $operation),
                'a 2xx send response carrying no partner message id',
            );
        }

        return new SentMessageDto(
            waMessageId: $result->providerMessageId,
            jid: $result->recipient ?? $recipient,
        );
    }

    /**
     * Rebuild the driver-level receipt from what the partner said, or from what the ledger replayed.
     *
     * @param  array<array-key, mixed>  $payload
     */
    private function receipt(
        string $idempotencyKey,
        ChannelCapability $capability,
        ChannelCredentials $credentials,
        array $payload,
    ): SendReceipt {
        // From the replayed payload when it named a partner, so a replay of a send made before a
        // tenant switched partners reports the partner that actually carried it — and from the live
        // credentials otherwise, which is what a fresh send and a pre-`provider` ledger row both need.
        $recorded = BridgeWire::stringOrNull($payload['provider'] ?? null);
        $provider = $recorded === null ? null : BspProvider::tryFromKey($recorded);

        return new SendReceipt(
            mode: $this->mode(),
            // A send acknowledged with no id is refused by `SendReceipt` itself, which is the correct
            // outcome for the reason `postMessage()` gives.
            providerMessageId: BridgeWire::stringOrNull($payload['provider_message_id'] ?? null) ?? '',
            recipient: BridgeWire::stringOrNull($payload['recipient'] ?? null) ?? '',
            idempotencyKey: $idempotencyKey,
            capability: $capability,
            degraded: ($payload['degraded'] ?? false) === true,
            provider: $provider ?? $credentials->provider,
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
                'A BSP template send needs %s in $vars[%s]: ChannelDriver::sendTemplate() has no '
                .'parameter for it, and a receipt without it could never be reconciled. Prefer '
                .'sendTemplateTo(), which takes both explicitly.',
                $what,
                $key,
            ));
        }

        return $value;
    }

    /**
     * A recipient reduced to E.164 digits, or a refusal.
     *
     * `OutboundContent::recipient()` is documented as carrying either a fully-qualified WhatsApp
     * identity or an E.164 number, and every partner here wants the number — so both spellings are
     * normalised in one place, before an adapter sees one. A **group** JID is refused rather than
     * reduced: no partner route has group messaging (`GROUPS` is `❌` on this mode), and a group id
     * stripped of its domain is a plausible-looking number that belongs to somebody.
     *
     * @throws InvalidArgumentException when the value is not an addressable number
     */
    private function recipientDigits(string $recipient): string
    {
        $value = trim($recipient);

        if (str_ends_with($value, self::GROUP_JID_SUFFIX)) {
            throw new InvalidArgumentException(
                'A BSP partner route cannot address a WhatsApp group: no partner fronting Meta\'s '
                .'Business Platform has group messaging, and a group id reduced to digits is a '
                .'plausible-looking number belonging to somebody else.'
            );
        }

        $digits = self::digitsOrNull($value);

        if ($digits === null) {
            throw new InvalidArgumentException(sprintf(
                'A BSP send needs an E.164 recipient of 8 to 15 digits; got [%s]. Sending to a mangled '
                .'number is how one tenant\'s typo becomes a message to a stranger.',
                mb_strimwidth($value, 0, 40, '…'),
            ));
        }

        return $digits;
    }

    /*
    |--------------------------------------------------------------------------
    | Inbound internals
    |--------------------------------------------------------------------------
    */

    /**
     * Stamp each event with the batch shape, so a caller holding one can tell there were more.
     *
     * `CloudApiChannelDriver::stamped()`'s answer to the same problem: the information is not lost, it
     * is carried where a consumer can find it, and task 8.3 is expected to read it — or, better, to
     * call `parseWebhookBatch()`.
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

    /*
    |--------------------------------------------------------------------------
    | The partner wire
    |--------------------------------------------------------------------------
    */

    /**
     * Run one partner request through the breaker and the bounded inline retry, then read it.
     *
     * The failure conversion is here rather than in `ProviderCallGuard` because it is the only
     * partner-specific part: a refusal becomes `ChannelRequestFailedException` carrying the partner's
     * own code **and the partner itself** (`$provider`, which is what
     * `BspGatewayErrorClassifier` disambiguates eight code vocabularies with), and anything else
     * becomes `BridgeUnreachableException` — deliberately **not** the HTTP client's exception, which
     * can carry the request in a dump, including the API key header.
     *
     * The refusal is raised **inside** the guarded call so the breaker counts a partner refusal as a
     * failure of the dependency, and so the retry decision is made on the typed exception rather than
     * on a status code the matrix cannot key on.
     *
     * `refusalIn()` is consulted on **every** response, not only a failing one, because two of the
     * eight refuse with a success status (`Bsp\Adapters\WatiAdapter`, `Bsp\Adapters\GupshupAdapter`) —
     * and reading a `200` as acceptance there would record an unsent message as sent.
     *
     * @return array<string, mixed>
     *
     * @throws ChannelRequestFailedException when the partner refuses
     * @throws BridgeUnreachableException when the partner never answered, or answered unreadably
     */
    private function guarded(
        string $operation,
        ChannelCredentials $credentials,
        BspAdapter $adapter,
        BspRequest $request,
        ?int $timeout = null,
    ): array {
        $label = self::label($credentials, $operation);

        return $this->guard->run(
            $label,
            ProviderCallGuard::breakerName($this->mode(), $credentials->tenantId),
            function () use ($label, $credentials, $adapter, $request, $timeout): array {
                $response = $this->execute($request, $timeout ?? $this->timeout());
                $decoded = $response->json();
                $payload = is_array($decoded) ? BridgeWire::arrayOrEmpty($decoded) : [];

                $refusal = $adapter->refusalIn($payload, $response->status(), self::retryAfter($response));

                if ($refusal !== null) {
                    throw $this->refusalFor($label, $credentials, $response->status(), $refusal);
                }

                if ($response->failed()) {
                    // The partner refused in a shape its adapter does not recognise — an HTML error page
                    // from a proxy, a body a later release added. Still a typed refusal, carrying the
                    // status the classifier can read.
                    throw $this->refusalFor($label, $credentials, $response->status(), new BspRefusal(
                        retryAfterSeconds: self::retryAfter($response),
                    ));
                }

                if (! is_array($decoded)) {
                    // A 2xx whose body is not a result: the partner answered, and the answer establishes
                    // nothing, so the outcome is unknown and this is a transport failure rather than a
                    // result to read (`HttpBridgeClient::decode()`'s reasoning).
                    throw BridgeUnreachableException::malformedResponse(
                        $label,
                        'a 2xx response whose body is not a JSON object',
                    );
                }

                return $payload;
            },
            fn (Throwable $e): Throwable => self::failureFor($label, $e),
        );
    }

    /**
     * Perform one described request.
     *
     * The body encoding comes from the `BspRequest` — form for Twilio, Gupshup and Kaleyra, JSON for
     * the rest — and the headers carry the partner's auth, built by its adapter. The client comes from
     * the injected factory, so `Http::fake()` intercepts it in tests and the platform's timeouts apply.
     */
    private function execute(BspRequest $request, int $timeout): Response
    {
        $pending = $this->request($timeout, $request->headers);
        $pending = $request->isForm() ? $pending->asForm() : $pending->asJson();

        if ($request->method === 'GET') {
            return $pending->get($request->url, $request->query);
        }

        $url = $request->query === []
            ? $request->url
            : $request->url.'?'.http_build_query($request->query);

        return $pending->post($url, $request->body());
    }

    /**
     * A configured pending request: bounded timeouts, the partner's headers, **no internal retries**.
     *
     * `retry()` is deliberately unused, exactly as `HttpBridgeClient` explains: the attempt budget
     * belongs to one layer, and here that is `ProviderCallGuard` composed with `RetryPolicy`. A
     * transport that retried internally would multiply the two and turn one duplicate-risk window into
     * three.
     *
     * @param  array<string, string>  $headers
     */
    private function request(int $timeout, array $headers): PendingRequest
    {
        return $this->http
            ->withHeaders($headers)
            ->acceptJson()
            ->connectTimeout($this->connectTimeout())
            ->timeout($timeout);
    }

    /**
     * A partner's refusal as the platform's typed exception, carrying the partner.
     *
     * `$provider` is the field that lets one classifier hold eight code vocabularies apart: Twilio's
     * `20003` and MessageBird's `2` both mean "the key was rejected", and neither means what the other
     * partner's number means.
     */
    private function refusalFor(
        string $operation,
        ChannelCredentials $credentials,
        int $status,
        BspRefusal $refusal,
    ): ChannelRequestFailedException {
        return ChannelRequestFailedException::refused(
            mode: $this->mode(),
            operation: $operation,
            status: $status,
            errorCode: $refusal->code,
            errorType: $refusal->type,
            retryAfterSeconds: $refusal->retryAfterSeconds,
            traceId: $refusal->traceId,
            provider: $credentials->provider,
        );
    }

    /**
     * What a failure of a partner call surfaces as.
     *
     * A refusal or an already-typed transport failure passes through unchanged — it carries what the
     * classifier and the caller need. Anything else (a `ConnectionException`, an adapter's
     * `InvalidArgumentException`, a client library throwing its own type) becomes a transport failure,
     * which fails **closed**: a send whose transport failed may or may not have happened, and the one
     * answer that is definitely wrong is "sent".
     */
    private static function failureFor(string $operation, Throwable $e): Throwable
    {
        return $e instanceof ChannelRequestFailedException
            || $e instanceof BridgeUnreachableException
            || $e instanceof BridgeRequestFailedException
            ? $e
            : BridgeUnreachableException::transportFailed($operation);
    }

    /**
     * A tenant-facing sentence for a credential probe the partner refused.
     *
     * Rendered from the partner's **code** through its own classifier, never from its prose — most of
     * the eight echo an `Authorization` fragment on a `401`. `ChannelHealth` runs even this through
     * `ChannelCredentials::redact()`, so both the belt and the braces are on.
     *
     * The `ErrorClass` is reused as the vocabulary rather than a per-partner sentence table, which is
     * the whole reason each adapter already maps its codes: an operator reading *"the partner rejected
     * the credentials"* needs the same sentence whichever of the eight said it.
     */
    private static function healthDetailFor(BspAdapter $adapter, ChannelRequestFailedException $refused): string
    {
        $reason = match ($adapter->classify($refused)?->value) {
            'AUTH' => 'The partner rejected the API credentials — they have expired, been revoked, or were never valid for this account.',
            'PERMISSION' => 'The credentials are valid but not permitted to send from this number, or the account is suspended or unfunded.',
            'RATE_LIMIT' => 'The partner is rate-limiting this account, so the credentials could not be confirmed right now.',
            default => $refused->status === 404
                ? 'The partner does not know this account or sender.'
                : 'The partner refused the credential probe.',
        };

        return sprintf(
            '%s (%s, HTTP %d%s). The partner\'s own message is not repeated here: it quotes the request, '
            .'including part of the key it was sent.',
            $reason,
            $adapter->provider()->label(),
            $refused->status,
            $refused->errorCode === null ? '' : ', code '.$refused->errorCode,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Small readers
    |--------------------------------------------------------------------------
    */

    /**
     * The operation label a refusal and a breaker name carry: `bsp.{provider}.{operation}`.
     *
     * Namespaced by partner so an operator reading a log can tell a Twilio refusal from a Gupshup one
     * without decoding an exception, and built from the enum's own slug rather than a free string —
     * the same discipline `ProviderCallGuard::breakerName()` applies to the mode segment.
     */
    private static function label(ChannelCredentials $credentials, string $operation): string
    {
        return sprintf('bsp.%s.%s', $credentials->provider?->webhookSlug() ?? 'unknown', $operation);
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
        return self::positiveConfig('wa.channel.bsp.timeout', self::DEFAULT_TIMEOUT);
    }

    private function connectTimeout(): int
    {
        return self::positiveConfig('wa.channel.bsp.connect_timeout', self::DEFAULT_CONNECT_TIMEOUT);
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

    /**
     * A value reduced to E.164 digits, or null.
     *
     * `HttpBridgeClient::digits()`'s rule: anything that is not 8–15 digits after separators are
     * stripped is `null` rather than a best effort.
     */
    private static function digitsOrNull(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $digits = (string) preg_replace('/\D+/', '', (string) $value);

        return preg_match('/^\d{8,15}$/', $digits) === 1 ? $digits : null;
    }

    /**
     * Milliseconds since an `hrtime(true)` reading.
     */
    private static function elapsedMs(float|int $startedAt): int
    {
        return (int) ((hrtime(true) - $startedAt) / 1_000_000);
    }
}
