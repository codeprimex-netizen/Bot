<?php

declare(strict_types=1);

namespace Tests\Fixtures\Channel;

use App\Enums\BspProvider;
use App\Enums\ChannelCapability;
use App\Enums\ChannelCapabilitySupport;
use App\Enums\ChannelMode;
use App\Enums\InboundEventKind;
use App\Enums\PresenceState;
use App\Enums\SessionLoginMethod;
use App\Enums\SessionStatus;
use App\Exceptions\Bridge\BridgeRequestFailedException;
use App\Exceptions\Bridge\BridgeUnreachableException;
use App\Models\Session;
use App\Services\Bridge\MediaPayload;
use App\Services\Bridge\NumberCheck;
use App\Services\Bridge\SentMessageDto;
use App\Services\Bridge\SessionInitDto;
use App\Services\Bridge\SessionStateDto;
use App\Services\Channel\ChannelCredentials;
use App\Services\Channel\ChannelDriver;
use App\Services\Channel\ChannelHealth;
use App\Services\Channel\Concerns\DerivesChannelPolicy;
use App\Services\Channel\InboundEvent;
use App\Services\Channel\OutboundContent;
use App\Services\Channel\RegistrationResult;
use App\Services\Channel\SendReceipt;
use App\Services\Channel\TemplateRef;
use Closure;
use Illuminate\Http\Request;
use Throwable;

/**
 * A deterministic in-memory `ChannelDriver` for tests: **one per mode, configurable
 * downward, and it records everything** (design § Testing Strategy; Req 36.1 / NFR7;
 * Correctness Property 28).
 *
 * ```php
 * $router = new DefaultChannelRouter(
 *     app(TenantContext::class),
 *     app(ChannelCredentialStore::class),
 *     app(PlanGate::class),
 *     FakeChannelDriver::registry(...ChannelMode::cases()),
 * );
 *
 * $router->driverFor($session)->send($session, $content);
 *
 * expect(FakeChannelDriver::instance(ChannelMode::CloudApi)?->calls())
 *     ->toBe(['send:order-4417']);
 * ```
 *
 * ## Why it lives under `tests/` when design.md says "bound in the container"
 *
 * design.md's Testing Strategy lists this alongside `FakeBridgeClient` and the other doubles
 * as *"bound in the container"*, and task 7.5 repeats *"bound only in the testing
 * container"*. This codebase has already answered that question the other way, and
 * `FakeBridgeClient`'s docblock is where the argument is made:
 *
 * > **Why it lives here and not in `app/`.** It is *test-only, structurally*. `Tests\` is
 * > mapped by `autoload-dev`, so this class is not autoloadable in a production install at
 * > all — there is nothing for a config key to name by mistake, and nothing for the
 * > completeness scan […] to find reachable from `app/`.
 *
 * "Bound only in testing" is a *convention* enforced by whoever reviews the service
 * providers; `autoload-dev` is a property of the build. The stronger reading also satisfies
 * the requirement design.md is really after — Req 36.1's *"no `Fake*`/`Stub*` binding …
 * reachable from `app/`"*, which task 39.x enforces by scanning for exactly this class name
 * pattern under `app/`. A `FakeChannelDriver` in `app/Services/Channel/` would fail that
 * scan on the day it was written, whatever the provider did or did not bind.
 *
 * So: every fake on this platform (`FakeBridgeClient`, `FakeKms`, `FakeDomainProbe`,
 * `FakeRateCapGate`, `FakeProviderErrorClassifier`) lives under `tests/Fixtures/**` on the
 * `Tests\` namespace, and this one follows them. Tests wire it explicitly — into a router's
 * registry via `registry()`, or by `app()->instance()` — and nothing else can.
 *
 * ## Configurable **downward only**
 *
 * A test must be able to say *"this driver is a partner that does not do media"* without
 * inventing a mode. It must **not** be able to say *"this driver is a Cloud API that does
 * groups"*, because `ModeGuardedChannelDriver` exists precisely to make that impossible in
 * production — and a fake that could do it would let a test pass for a reason production
 * cannot reproduce, which is the one failure mode a test double has that a bug does not.
 *
 * The narrowing therefore goes through the same machinery the real drivers use, and the
 * enforcement is not this class's to remember:
 *
 * | Arrangement | Effect |
 * |---|---|
 * | `restrict(Media)` | `supportFor(Media)` becomes `Unsupported` wherever the mode allowed it |
 * | `conditional(Interactive)` | a `✅` cell becomes `⚠️` — the shape task 7.4 resolves per partner |
 * | `claimSupport(Groups)` on `CLOUD_API` | **nothing.** The ceiling is `❌`, and `ChannelCapabilitySupport::refinedBy()` is absorbing on `Unsupported` |
 *
 * `claimSupport()` exists *so that the last row can be asserted*: a fake that had no way to
 * try to widen a cell would leave "widening is impossible" untested. `FakeChannelDriverTest`
 * asks for every refused capability on every mode and asserts none of them opens.
 *
 * ## Deterministic, in the four ways that bite
 *
 * | | How |
 * |---|---|
 * | provider message ids | a pure function of the input: `FAKE-{idempotencyKey}` for a send, `FAKE-TPL-{name}:{language}` for a template. No counter, so a test's expectation does not depend on how many sends ran before it, in this test or another |
 * | randomness | none. No `rand()`, no `Str::random()`, no `Str::ulid()` on a send path |
 * | clocks | this class never calls `now()`. `SendReceipt::$acceptedAt` is left null and `ChannelHealth`/`RegistrationResult` take their own timestamps, so a test that needs to assert on one freezes the clock (`travelTo`) and gets exactly what it froze |
 * | ordering | `calls()` is append-only in call order; nothing is keyed by iteration order of a map |
 *
 * ## It records what was asked, so a test can assert what was *not*
 *
 * Properties 21, 22 and 24 are all statements about a call **not** happening: an unsupported
 * capability is refused *before any driver call* (task 7.7), a message reaches exactly one
 * driver (6.5), a failover chain attempts each mode at most once (8.5). None of those can be
 * asserted by looking at a return value — the observable is the absence of a call. So every
 * driver and transport method appends to `calls()`, and construction is counted statically:
 *
 * | Observable | Read with |
 * |---|---|
 * | which operations reached this instance, in order | `calls()`, `called('send')`, `callCount('send')` |
 * | what was sent | `sends()`, `receipts()` |
 * | how many instances a mode ever got | `built($mode)` |
 * | the instance a registry handed out | `instance($mode)` |
 *
 * The build counter is static and separate from equality for the reason task 6.3's router
 * test needs it: *a router that resolved a fresh driver on every call would still return an
 * equal object, so counting equality proves nothing and counting constructions proves
 * everything.*
 *
 * ## Failure injection, classified the way the real thing is
 *
 * Task 8.5's failover tests need a send that fails **retryably** and one that fails
 * **permanently**, and the distinction has to be the platform's own — `BridgeErrorClassifier`
 * reads `BridgeRequestFailedException::$bridgeCode` and `$status`, `PlatformErrorClassifier`
 * handles the rest. So the two helpers raise the real exceptions rather than a bespoke one:
 *
 * | Arrangement | Raises | `ErrorClass` | Fate |
 * |---|---|---|---|
 * | `failSendRetryably()` | `BridgeUnreachableException::transportFailed()` | `BRIDGE` | retried ×5, jittered — a failover chain advances |
 * | `failSendPermanently()` | `BridgeRequestFailedException::refused(…, 401, session_logged_out)` | `AUTH` | fails fast — no advance, no retry |
 * | `failSendWith($e)` | `$e`, unchanged | whatever the chain says | for a bespoke case |
 *
 * A failing send is recorded in `calls()` **before** it throws, so a test can still tell
 * "fenced off by a breaker" from "attempted and refused" — the same ordering choice
 * `FakeBridgeClient::record()` makes and for the same reason.
 *
 * ## What it deliberately does not do
 *
 * No capability *refusal*, no anti-ban answer of its own, no mode-match check, no ownership
 * check. Those belong to `ChannelRouter::assertSupported()`, `DerivesChannelPolicy` and
 * `ModeGuardedChannelDriver`, are tested against those classes directly, and a fake that
 * re-implemented them would let a test pass because the *fake* enforced a guarantee the real
 * graph had lost.
 */
final class FakeChannelDriver implements ChannelDriver
{
    use DerivesChannelPolicy;

    /**
     * Prefix of every generated provider message id, so an assertion never depends on
     * randomness or on ordering.
     */
    public const string MESSAGE_ID_PREFIX = 'FAKE-';

    public const string TEMPLATE_ID_PREFIX = 'FAKE-TPL-';

    /**
     * The number the fake claims to own, for `register()` and for a webhook recipient check.
     */
    public const string PROVIDER_NUMBER_ID = 'fake-number-id';

    /**
     * Who a `sendTemplate()` receipt says it addressed.
     *
     * A constant rather than the session's `phone`, because a `SendReceipt` requires a
     * non-empty recipient and a factory-made session may have none — a fake that could
     * construct an invalid receipt would fail in the fixture rather than in the code under
     * test.
     */
    public const string TEMPLATE_RECIPIENT = '15550001111@s.whatsapp.net';

    /**
     * Constructions per mode value, since the last `reset()`.
     *
     * @var array<string, int>
     */
    private static array $built = [];

    /**
     * The most recent instance per mode value — what `registry()` handed the router.
     *
     * @var array<string, self>
     */
    private static array $instances = [];

    /**
     * Capability refinements applied to every instance of a mode, so a test can arrange them
     * *before* a lazy registry constructs one.
     *
     * @var array<string, array<string, ChannelCapabilitySupport>>
     */
    private static array $profiles = [];

    /**
     * @var array<string, ChannelCapabilitySupport>
     */
    private array $refinements = [];

    /**
     * @var list<string>
     */
    private array $calls = [];

    /**
     * @var list<array{recipient: string, capability: string, text: string, idempotency_key: string, degraded: bool}>
     */
    private array $sends = [];

    /**
     * @var list<SendReceipt>
     */
    private array $receipts = [];

    private ?Throwable $sendError = null;

    private bool $reachable = true;

    public function __construct(private readonly ChannelMode $driverMode)
    {
        self::$built[$driverMode->value] = self::built($driverMode) + 1;
        self::$instances[$driverMode->value] = $this;
        $this->refinements = self::$profiles[$driverMode->value] ?? [];
    }

    /**
     * A registry of the shape `DefaultChannelRouter` takes: mode value → a closure per mode.
     *
     * The closure constructs a **fresh** instance each time it is called, which is what makes
     * `built()` a measurement of the router's memoisation rather than of this fixture.
     *
     * @return array<string, Closure(): ChannelDriver>
     */
    public static function registry(ChannelMode ...$modes): array
    {
        $registry = [];

        foreach ($modes === [] ? ChannelMode::cases() : $modes as $mode) {
            $registry[$mode->value] = static fn (): ChannelDriver => new self($mode);
        }

        return $registry;
    }

    /**
     * How many instances have been constructed for `$mode` since `reset()`.
     */
    public static function built(ChannelMode $mode): int
    {
        return self::$built[$mode->value] ?? 0;
    }

    /**
     * The latest instance constructed for `$mode`, or null when none has been.
     *
     * How a test reaches the driver a router built for itself — `ModeGuardedChannelDriver`
     * hides the concrete type, and `inner()` would only reach it after a resolution.
     */
    public static function instance(ChannelMode $mode): ?self
    {
        return self::$instances[$mode->value] ?? null;
    }

    /**
     * Forget every construction, instance and profile.
     *
     * Static state is per-process, so a test that arranges a profile must reset it or leak
     * into the next one — `beforeEach(fn () => FakeChannelDriver::reset())` is the idiom.
     */
    public static function reset(): void
    {
        self::$built = [];
        self::$instances = [];
        self::$profiles = [];
    }

    /**
     * Narrow a capability for **every** instance of `$mode`, including ones a lazy registry
     * has not built yet.
     *
     * The static half of `restrict()`, and the only way to configure a driver the router will
     * construct on its own later.
     */
    public static function profile(ChannelMode $mode, ChannelCapability $capability, ChannelCapabilitySupport $support): void
    {
        self::$profiles[$mode->value][$capability->value] = $support;

        self::instance($mode)?->refine($capability, $support);
    }

    /*
    |--------------------------------------------------------------------------
    | Arranging
    |--------------------------------------------------------------------------
    */

    /**
     * Refuse `$capabilities` on this instance — *"a partner that does not do media"*.
     */
    public function restrict(ChannelCapability ...$capabilities): self
    {
        foreach ($capabilities as $capability) {
            $this->refine($capability, ChannelCapabilitySupport::Unsupported);
        }

        return $this;
    }

    /**
     * Attach a condition to `$capabilities` — a `✅` cell this backend actually restricts,
     * which is the shape task 7.4 resolves from a partner's live config.
     */
    public function conditional(ChannelCapability ...$capabilities): self
    {
        foreach ($capabilities as $capability) {
            $this->refine($capability, ChannelCapabilitySupport::Conditional);
        }

        return $this;
    }

    /**
     * Claim `$capabilities` natively — which **cannot** open a cell the mode refuses.
     *
     * Here so that the impossibility is assertable: the refinement is passed through
     * `ChannelCapabilitySupport::refinedBy()`, whose `Unsupported` arm is absorbing, so this
     * is a no-op on exactly the cells Property 21 is about and a genuine widening (`⚠️` → `✅`)
     * on the ones a real per-provider layer is allowed to resolve.
     */
    public function claimSupport(ChannelCapability ...$capabilities): self
    {
        foreach ($capabilities as $capability) {
            $this->refine($capability, ChannelCapabilitySupport::Native);
        }

        return $this;
    }

    /**
     * Every `send()` and `sendTemplate()` fails in a way the platform classifies **retryable**
     * (`ErrorClass::Bridge`) — so a failover chain advances and a queued job is retried.
     */
    public function failSendRetryably(): self
    {
        return $this->failSendWith(BridgeUnreachableException::transportFailed('message.text'));
    }

    /**
     * Every `send()` and `sendTemplate()` fails in a way the platform classifies **fail-fast**
     * (`ErrorClass::Auth`) — so nothing is retried and nothing advances.
     */
    public function failSendPermanently(): self
    {
        return $this->failSendWith(BridgeRequestFailedException::refused(
            'message.text',
            401,
            BridgeRequestFailedException::CODE_SESSION_LOGGED_OUT,
        ));
    }

    /**
     * Every `send()` and `sendTemplate()` throws `$error`, unchanged.
     */
    public function failSendWith(Throwable $error): self
    {
        $this->sendError = $error;

        return $this;
    }

    public function sendSuccessfully(): self
    {
        $this->sendError = null;

        return $this;
    }

    /**
     * The backend is down: `healthCheck()` reports unhealthy and `isReachable()` is false.
     */
    public function unreachable(): self
    {
        $this->reachable = false;

        return $this;
    }

    public function reachable(): self
    {
        $this->reachable = true;

        return $this;
    }

    /*
    |--------------------------------------------------------------------------
    | Asserting
    |--------------------------------------------------------------------------
    */

    /**
     * Every operation this instance was asked for, in call order.
     *
     * @return list<string>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    /**
     * Whether `$operation` was reached at all — the *"the driver was never invoked"*
     * observable Properties 21, 22 and 24 are stated in terms of.
     */
    public function called(string $operation): bool
    {
        return $this->callCount($operation) > 0;
    }

    /**
     * How often `$operation` was reached. Matches on the operation name, so
     * `callCount('send')` counts `send:order-4417` too.
     */
    public function callCount(string $operation): int
    {
        return count(array_filter(
            $this->calls,
            static fn (string $call): bool => $call === $operation || str_starts_with($call, $operation.':'),
        ));
    }

    /**
     * Everything `send()` was given, in order.
     *
     * @return list<array{recipient: string, capability: string, text: string, idempotency_key: string, degraded: bool}>
     */
    public function sends(): array
    {
        return $this->sends;
    }

    /**
     * Every receipt this driver returned, in order.
     *
     * @return list<SendReceipt>
     */
    public function receipts(): array
    {
        return $this->receipts;
    }

    /*
    |--------------------------------------------------------------------------
    | What this backend is
    |--------------------------------------------------------------------------
    */

    public function mode(): ChannelMode
    {
        return $this->driverMode;
    }

    /**
     * The arranged refinement, floored by the mode's own cell.
     *
     * Returning the ceiling when nothing was arranged is the no-op refinement
     * `DerivesChannelPolicy` documents; `supportFor()` combines the two through
     * `refinedBy()`, which is where "downward only" is actually enforced.
     */
    protected function refineSupport(
        ChannelCapability $capability,
        ChannelCapabilitySupport $ceiling,
    ): ChannelCapabilitySupport {
        return $this->refinements[$capability->value] ?? $ceiling;
    }

    /*
    |--------------------------------------------------------------------------
    | Driver operations
    |--------------------------------------------------------------------------
    */

    public function send(Session $session, OutboundContent $content): SendReceipt
    {
        $this->record('send:'.$content->idempotencyKey());

        // `⚠️` degrades, `✅` does not — read from the same matrix (and the same arranged
        // refinements) the gate reads, so a restricted capability degrades here too.
        $degraded = ! $this->supportFor($content->capability())->isNative();

        $this->sends[] = [
            'recipient' => $content->recipient(),
            'capability' => $content->capability()->value,
            'text' => $content->plainText(),
            'idempotency_key' => $content->idempotencyKey(),
            'degraded' => $degraded,
        ];

        return $this->receipts[] = new SendReceipt(
            mode: $this->driverMode,
            // A pure function of the input: no counter, no clock, no randomness.
            providerMessageId: self::MESSAGE_ID_PREFIX.$content->idempotencyKey(),
            recipient: $content->recipient(),
            idempotencyKey: $content->idempotencyKey(),
            capability: $content->capability(),
            degraded: $degraded,
            provider: $this->driverMode->usesProvider() ? BspProvider::Twilio : null,
        );
    }

    /**
     * @param  array<string, string|int|float>  $vars
     */
    public function sendTemplate(Session $session, TemplateRef $template, array $vars): SendReceipt
    {
        $this->record('sendTemplate:'.$template->key());

        return $this->receipts[] = new SendReceipt(
            mode: $this->driverMode,
            providerMessageId: self::TEMPLATE_ID_PREFIX.$template->key(),
            recipient: self::TEMPLATE_RECIPIENT,
            idempotencyKey: 'tpl-'.$template->key(),
            capability: ChannelCapability::Template,
            provider: $this->driverMode->usesProvider() ? BspProvider::Twilio : null,
            templateName: $template->name,
        );
    }

    /**
     * Whatever the request says it is, once — no signature is checked.
     *
     * A fake cannot usefully verify a signature: it holds no secret, so any answer it gave
     * would be its own invention. Verification is each real driver's, tested against that
     * driver. What this *does* model is the normalisation, so a test of task 8.3's routing can
     * assert which driver parsed a payload and what came out.
     */
    public function parseWebhook(Request $request, ChannelCredentials $credentials): InboundEvent
    {
        $this->record('parseWebhook');

        /** @var array<array-key, mixed> $payload */
        $payload = $request->json()->all();

        $messageId = $payload['wa_message_id'] ?? null;
        $from = $payload['from'] ?? null;

        if (! is_string($messageId) || $messageId === '' || ! is_string($from) || $from === '') {
            return InboundEvent::unsupported(
                $this->driverMode,
                $credentials->tenantId,
                self::PROVIDER_NUMBER_ID,
                $payload,
            );
        }

        return new InboundEvent(
            kind: InboundEventKind::Message,
            mode: $this->driverMode,
            tenantId: $credentials->tenantId,
            providerMessageId: $messageId,
            from: $from,
            channelIdentity: self::PROVIDER_NUMBER_ID,
            text: is_string($payload['text'] ?? null) ? $payload['text'] : null,
            payload: $payload,
        );
    }

    public function register(Session $session, ChannelCredentials $credentials): RegistrationResult
    {
        $this->record('register');

        return $this->reachable
            ? RegistrationResult::live($credentials, self::PROVIDER_NUMBER_ID)
            : RegistrationResult::pending($credentials, 'The fake backend is arranged unreachable.');
    }

    public function healthCheck(ChannelCredentials $credentials): ChannelHealth
    {
        $this->record('healthCheck');

        return $this->reachable
            ? ChannelHealth::healthy($credentials)
            : ChannelHealth::unhealthy($credentials, 'The fake backend is arranged unreachable.');
    }

    /*
    |--------------------------------------------------------------------------
    | Transport — recorded, deterministic
    |--------------------------------------------------------------------------
    */

    public function provisionSession(
        string $sessionId,
        SessionLoginMethod $method,
        ?string $phone = null,
        ?string $authStateDir = null,
    ): SessionInitDto {
        $this->record('provisionSession:'.$sessionId);

        return new SessionInitDto(sessionId: $sessionId, status: $method->initialStatus());
    }

    public function startSession(string $sessionId): void
    {
        $this->record('startSession:'.$sessionId);
    }

    public function stopSession(string $sessionId, bool $logout = false): void
    {
        $this->record('stopSession:'.$sessionId);
    }

    public function sessionState(string $sessionId): SessionStateDto
    {
        $this->record('sessionState:'.$sessionId);

        return new SessionStateDto(
            sessionId: $sessionId,
            status: $this->reachable ? SessionStatus::Connected : SessionStatus::Closed,
        );
    }

    public function qr(string $sessionId): ?string
    {
        $this->record('qr:'.$sessionId);

        return null;
    }

    public function pairingCode(string $sessionId, string $phone): string
    {
        $this->record('pairingCode:'.$sessionId);

        // Derived from the number, so two tests asking for two numbers get two codes and the
        // same number always gets the same one.
        return 'FAKE'.substr($phone, -4);
    }

    /**
     * @param  array<string, mixed>  $opts
     */
    public function sendText(string $sessionId, string $jid, string $text, array $opts = []): SentMessageDto
    {
        $this->record('sendText:'.$sessionId);

        return new SentMessageDto(
            waMessageId: self::MESSAGE_ID_PREFIX.substr(hash('sha256', $sessionId.'|'.$jid.'|'.$text), 0, 12),
            jid: $jid,
        );
    }

    /**
     * @param  array<string, mixed>  $opts
     */
    public function sendMedia(string $sessionId, string $jid, MediaPayload $media, array $opts = []): SentMessageDto
    {
        $this->record('sendMedia:'.$sessionId);

        return new SentMessageDto(
            waMessageId: self::MESSAGE_ID_PREFIX.substr(hash('sha256', $sessionId.'|'.$jid.'|'.$media->kind->value), 0, 12),
            jid: $jid,
        );
    }

    public function sendPresence(string $sessionId, string $jid, PresenceState $presence): void
    {
        $this->record('sendPresence:'.$sessionId.':'.$presence->value);
    }

    /**
     * @param  list<string>  $numbers
     * @return array<array-key, NumberCheck>
     */
    public function checkNumbers(string $sessionId, array $numbers): array
    {
        $this->record('checkNumbers:'.count($numbers));

        $checks = [];

        foreach ($numbers as $number) {
            $digits = (string) preg_replace('/\D+/', '', $number);

            $checks[$number] = new NumberCheck(
                number: $number,
                exists: true,
                jid: $digits.'@s.whatsapp.net',
            );
        }

        return $checks;
    }

    public function isReachable(): bool
    {
        $this->record('isReachable');

        return $this->reachable;
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Record the call, then apply an arranged send failure.
     *
     * Recorded *first*, so a test that arranges a failure can still prove the call reached the
     * driver — otherwise "fenced off before dispatch" and "attempted and refused" look the
     * same, and the first of those is what Property 21 asserts.
     *
     * @throws Throwable the arranged failure, on a send path only
     */
    private function record(string $call): void
    {
        $this->calls[] = $call;

        // The driver-level sends only. `sendText:`/`sendMedia:`/`sendPresence:` are transport
        // and are left working on purpose: a test that arranges a *driver* failure is usually
        // asserting what the layer above did about it, and a prefix match on `send` would have
        // silently broken the transport too.
        if ($this->sendError !== null && (str_starts_with($call, 'send:') || str_starts_with($call, 'sendTemplate:'))) {
            throw $this->sendError;
        }
    }

    private function refine(ChannelCapability $capability, ChannelCapabilitySupport $support): void
    {
        $this->refinements[$capability->value] = $support;
    }
}
