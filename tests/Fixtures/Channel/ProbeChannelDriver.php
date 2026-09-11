<?php

declare(strict_types=1);

namespace Tests\Fixtures\Channel;

use App\Enums\ChannelCapability;
use App\Enums\ChannelMode;
use App\Enums\PresenceState;
use App\Enums\SessionLoginMethod;
use App\Models\Session;
use App\Services\Bridge\MediaPayload;
use App\Services\Bridge\NumberCheck;
use App\Services\Bridge\SentMessageDto;
use App\Services\Bridge\SessionInitDto;
use App\Services\Bridge\SessionStateDto;
use App\Services\Channel\ChannelCredentials;
use App\Services\Channel\ChannelDriver;
use App\Services\Channel\ChannelHealth;
use App\Services\Channel\InboundEvent;
use App\Services\Channel\OutboundContent;
use App\Services\Channel\RegistrationResult;
use App\Services\Channel\SendReceipt;
use App\Services\Channel\TemplateRef;
use Closure;
use Illuminate\Http\Request;
use Throwable;

/**
 * A `ChannelDriver` whose **credential probe** is arrangeable, wrapped around
 * `FakeChannelDriver` for everything else — the fixture task 7.6's tests need and
 * `FakeChannelDriver` deliberately does not provide.
 *
 * ```php
 * ProbeChannelDriver::reset();
 * ProbeChannelDriver::refuse(ChannelMode::CloudApi, 'The access token has expired.');
 *
 * $router = new DefaultChannelRouter(…, ProbeChannelDriver::registry(ChannelMode::CloudApi));
 *
 * $validator->save($tenant, ChannelMode::CloudApi, ['access_token' => 'bad']);   // throws
 * expect(ProbeChannelDriver::probed(ChannelMode::CloudApi))->toHaveCount(1);
 * ```
 *
 * ## Why a second fake rather than an extension of the first
 *
 * `FakeChannelDriver` has one knob for the whole backend — `unreachable()` — which makes
 * `healthCheck()` unhealthy *and* `register()` pending *and* `isReachable()` false. Task 7.6
 * has to tell four outcomes apart, because it does four different things about them:
 *
 * | Arrangement | `healthCheck()` | What the validator must do |
 * |---|---|---|
 * | (default) | `healthy()` | activate: stamp `verified_at`, drop the memos |
 * | `refuse()` | `unhealthy()` | retain the previous set, raise `rejected()`, invalidate only a first-save row |
 * | `failProbe()` | **throws** | change nothing at all, re-raise unchanged |
 * | `pendReenregistration()` / `failRegistration()` | healthy, then `register()` pends or throws | activate anyway / change nothing |
 *
 * Collapsing "the provider said no" into "the probe could not be made" is the exact mistake
 * the validator's three-outcome design exists to prevent, so a fixture that could not separate
 * them would leave the interesting half untested. `FakeChannelDriver` is `final` and belongs to
 * task 7.5, so this composes it rather than editing it: every non-probe method delegates, which
 * keeps sends, capability derivation and `mode()` honest — a test asserting *"this tenant can
 * still send after a rejected rotation"* is then asserting against the real fake's send path and
 * not against a bespoke one.
 *
 * ## Arranged and recorded **statically**, per mode
 *
 * The validator's whole job includes dropping the router's driver memo, so the router
 * constructs a *fresh* driver after every activation. An arrangement or a recording held on the
 * instance would therefore disappear at the moment the code under test did the right thing.
 * Everything is keyed by mode value in static state, read at call time, so:
 *
 * - an arrangement made before or after a construction applies either way;
 * - `probed()` accumulates across re-constructions, which is what makes "the driver was asked
 *   exactly once" assertable;
 * - `reset()` in a `beforeEach` is mandatory, exactly as for `FakeChannelDriver`.
 *
 * ## It records the material it was handed, values and all
 *
 * `probed()` returns the decrypted secret bag each probe was made with. That is the assertion
 * Req 8.6 turns on: *what the driver was asked about must be what is stored afterwards*, so a
 * merge that dropped a carried-forward key, or a write that stored something other than what
 * was validated, fails here rather than in production. It is safe only because this class is
 * unreachable outside `autoload-dev`; nothing in `app/` can obtain a bag this way.
 */
final class ProbeChannelDriver implements ChannelDriver
{
    /**
     * Arranged probe outcomes, keyed by mode value.
     *
     * @var array<string, string>
     */
    private static array $refusals = [];

    /**
     * @var array<string, Throwable>
     */
    private static array $probeFailures = [];

    /**
     * @var array<string, string>
     */
    private static array $pendingRegistrations = [];

    /**
     * @var array<string, Throwable>
     */
    private static array $registrationFailures = [];

    /**
     * The secret bag of every probe, in call order, keyed by mode value.
     *
     * @var array<string, list<array<string, string|null>>>
     */
    private static array $probed = [];

    /**
     * @var array<string, list<array<string, string|null>>>
     */
    private static array $registered = [];

    private readonly FakeChannelDriver $inner;

    public function __construct(private readonly ChannelMode $driverMode)
    {
        $this->inner = new FakeChannelDriver($driverMode);
    }

    /**
     * A registry of the shape `DefaultChannelRouter` takes, building a fresh driver per call.
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
     * Forget every arrangement and every recording — and `FakeChannelDriver`'s too, since this
     * fixture constructs those.
     */
    public static function reset(): void
    {
        self::$refusals = [];
        self::$probeFailures = [];
        self::$pendingRegistrations = [];
        self::$registrationFailures = [];
        self::$probed = [];
        self::$registered = [];

        FakeChannelDriver::reset();
    }

    /*
    |--------------------------------------------------------------------------
    | Arranging
    |--------------------------------------------------------------------------
    */

    /**
     * The provider refuses these credentials, with a reason — a revoked token, a number that
     * is not on the account.
     */
    public static function refuse(ChannelMode $mode, string $detail = 'The access token has expired.'): void
    {
        self::$refusals[$mode->value] = $detail;
    }

    /**
     * The provider accepts them again.
     */
    public static function accept(ChannelMode $mode): void
    {
        unset(self::$refusals[$mode->value], self::$probeFailures[$mode->value]);
    }

    /**
     * The probe itself cannot be performed — an unreachable endpoint, an open circuit. Not a
     * verdict about the credentials, and the validator must not treat it as one.
     */
    public static function failProbe(ChannelMode $mode, Throwable $failure): void
    {
        self::$probeFailures[$mode->value] = $failure;
    }

    /**
     * The credentials are good and the provider has not finished verifying the number.
     */
    public static function pendRegistration(ChannelMode $mode, string $detail = 'Awaiting verification.'): void
    {
        self::$pendingRegistrations[$mode->value] = $detail;
    }

    /**
     * Registration itself fails.
     */
    public static function failRegistration(ChannelMode $mode, Throwable $failure): void
    {
        self::$registrationFailures[$mode->value] = $failure;
    }

    /*
    |--------------------------------------------------------------------------
    | Asserting
    |--------------------------------------------------------------------------
    */

    /**
     * The secret bag of every `healthCheck()` on `$mode`, in call order.
     *
     * @return list<array<string, string|null>>
     */
    public static function probed(ChannelMode $mode): array
    {
        return self::$probed[$mode->value] ?? [];
    }

    /**
     * The secret bag of every `register()` on `$mode`, in call order.
     *
     * @return list<array<string, string|null>>
     */
    public static function registered(ChannelMode $mode): array
    {
        return self::$registered[$mode->value] ?? [];
    }

    /*
    |--------------------------------------------------------------------------
    | The arrangeable half
    |--------------------------------------------------------------------------
    */

    public function healthCheck(ChannelCredentials $credentials): ChannelHealth
    {
        self::$probed[$this->driverMode->value][] = self::bagOf($credentials);

        $failure = self::$probeFailures[$this->driverMode->value] ?? null;

        if ($failure !== null) {
            throw $failure;
        }

        $refusal = self::$refusals[$this->driverMode->value] ?? null;

        return $refusal === null
            ? ChannelHealth::healthy($credentials, 'The probe backend accepted these credentials.', 12)
            : ChannelHealth::unhealthy($credentials, $refusal, 12);
    }

    public function register(Session $session, ChannelCredentials $credentials): RegistrationResult
    {
        self::$registered[$this->driverMode->value][] = self::bagOf($credentials);

        $failure = self::$registrationFailures[$this->driverMode->value] ?? null;

        if ($failure !== null) {
            throw $failure;
        }

        $pending = self::$pendingRegistrations[$this->driverMode->value] ?? null;

        return $pending === null
            ? RegistrationResult::live($credentials, FakeChannelDriver::PROVIDER_NUMBER_ID)
            : RegistrationResult::pending($credentials, $pending);
    }

    /*
    |--------------------------------------------------------------------------
    | Everything else — delegated, so the send path under test is the real fake's
    |--------------------------------------------------------------------------
    */

    public function mode(): ChannelMode
    {
        return $this->inner->mode();
    }

    public function supports(ChannelCapability $capability): bool
    {
        return $this->inner->supports($capability);
    }

    public function requiresAntiBan(): bool
    {
        return $this->inner->requiresAntiBan();
    }

    public function send(Session $session, OutboundContent $content): SendReceipt
    {
        return $this->inner->send($session, $content);
    }

    /**
     * @param  array<string, string|int|float>  $vars
     */
    public function sendTemplate(Session $session, TemplateRef $template, array $vars): SendReceipt
    {
        return $this->inner->sendTemplate($session, $template, $vars);
    }

    public function parseWebhook(Request $request, ChannelCredentials $credentials): InboundEvent
    {
        return $this->inner->parseWebhook($request, $credentials);
    }

    public function provisionSession(
        string $sessionId,
        SessionLoginMethod $method,
        ?string $phone = null,
        ?string $authStateDir = null,
    ): SessionInitDto {
        return $this->inner->provisionSession($sessionId, $method, $phone, $authStateDir);
    }

    public function startSession(string $sessionId): void
    {
        $this->inner->startSession($sessionId);
    }

    public function stopSession(string $sessionId, bool $logout = false): void
    {
        $this->inner->stopSession($sessionId, $logout);
    }

    public function sessionState(string $sessionId): SessionStateDto
    {
        return $this->inner->sessionState($sessionId);
    }

    public function qr(string $sessionId): ?string
    {
        return $this->inner->qr($sessionId);
    }

    public function pairingCode(string $sessionId, string $phone): string
    {
        return $this->inner->pairingCode($sessionId, $phone);
    }

    /**
     * @param  array<string, mixed>  $opts
     */
    public function sendText(string $sessionId, string $jid, string $text, array $opts = []): SentMessageDto
    {
        return $this->inner->sendText($sessionId, $jid, $text, $opts);
    }

    /**
     * @param  array<string, mixed>  $opts
     */
    public function sendMedia(string $sessionId, string $jid, MediaPayload $media, array $opts = []): SentMessageDto
    {
        return $this->inner->sendMedia($sessionId, $jid, $media, $opts);
    }

    public function sendPresence(string $sessionId, string $jid, PresenceState $presence): void
    {
        $this->inner->sendPresence($sessionId, $jid, $presence);
    }

    /**
     * @param  list<string>  $numbers
     * @return array<array-key, NumberCheck>
     */
    public function checkNumbers(string $sessionId, array $numbers): array
    {
        return $this->inner->checkNumbers($sessionId, $numbers);
    }

    public function isReachable(): bool
    {
        return $this->inner->isReachable();
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * The decrypted bag a probe was handed — values included, which is the point.
     *
     * @return array<string, string|null>
     */
    private static function bagOf(ChannelCredentials $credentials): array
    {
        $bag = [];

        foreach ($credentials->secretKeys() as $key) {
            $bag[$key] = $credentials->secret($key);
        }

        return $bag;
    }
}
