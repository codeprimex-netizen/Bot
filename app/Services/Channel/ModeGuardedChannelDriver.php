<?php

declare(strict_types=1);

namespace App\Services\Channel;

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
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Holds any `ChannelDriver` to what its own mode says — the decorator that makes the two
 * derived answers of the contract *structural* instead of conventional
 * (Req 8.3, 8.4, 8.8 / A8; Properties 21, 22, 24).
 *
 * ```php
 * // task 6.3: what ChannelRouter::driverFor() is expected to hand back
 * return new ModeGuardedChannelDriver($this->drivers[$session->channel_mode->value]);
 * ```
 *
 * ## Why a decorator, and not a base class or a code review
 *
 * `Concerns\DerivesChannelPolicy` already writes the right answers once, and every driver in
 * tasks 7.1–7.5 is expected to use it. But a trait is not a guarantee: a class method wins
 * over a trait method in PHP — even a `final` one — so the trait is a convenience for a
 * driver written in good faith, not a constraint on one written in haste. An abstract base
 * class could carry `final` methods, but an interface cannot require anything to extend it,
 * so that would be the same convention with more ceremony.
 *
 * A decorator can be a constraint, because it does not ask the wrapped driver for the answer
 * at all. This is the pattern `TenantScopedBridgeClient` already uses one layer down: the
 * container binds it as the outermost decorator, so *"there is no code path that reaches the
 * wire without it, and none that could be fixed by a check inside the sidecar"*
 * (`BridgeClient`, **The bridge never learns about tenants**). The same argument, applied to
 * mode policy.
 *
 * ## The four things it guarantees
 *
 * | Guarantee | Mechanism | Why it matters |
 * |---|---|---|
 * | anti-ban cannot be switched off | `requiresAntiBan()` returns `mode()->isWebProtocol()`, the wrapped answer is never consulted | Req 8.8 — the gate must not be disableable; a `BAILEYS` send without the warm-up ramp gets a tenant's number banned |
 * | a refusal cannot be widened | `supports()` is the **conjunction** of the matrix and the driver | Property 21 — no per-provider config turns `❌ GROUPS` into a dispatch |
 * | a session is never sent through the wrong driver | `send()`, `sendTemplate()`, `register()` refuse a `$session` whose `channel_mode` is not `mode()` | Property 22 — exactly one driver per message, with the right credentials, rate rules, and ban-risk profile |
 * | credentials are never used cross-mode | `parseWebhook()`, `register()`, `healthCheck()` refuse `ChannelCredentials` for another mode | Property 23 — a Cloud API token handed to the BSP driver is a silent authentication failure at best |
 *
 * Note the shape of the capability guarantee: a logical **AND** with
 * `ChannelCapability::supportedOn($mode)`. Narrowing is preserved — a driver may still refuse
 * something its mode allows, which is exactly how task 7.4 resolves a partner that lacks a
 * capability — while widening is arithmetically impossible, because no value of the driver's
 * answer makes `false && x` true. That is the whole of *"7.4 may refine, but only downward"*,
 * and it needs no cooperation from 7.4.
 *
 * ## What it does *not* do
 *
 * It is not a gate. It does not check quota, opt-out, the 24-hour window, or anti-ban — it
 * only makes sure the answers those gates read are the true ones. It does not throw
 * `ModeCapabilityException` either: refusing an unsupported capability *before dispatch* is
 * `ChannelRouter::assertSupported()`'s job (task 6.3), and duplicating it here would mean an
 * operation could be refused with two different exceptions depending on which layer noticed.
 *
 * Every transport method delegates verbatim. Wrapping is therefore invisible to task 7.1's
 * *"unchanged behaviour for existing tenants"*: `BaileysChannelDriver` wraps
 * `HttpBridgeClient`, this wraps that, and a `sendText()` still puts one message on the wire
 * exactly as it did before Channel Mode existed.
 */
final readonly class ModeGuardedChannelDriver implements ChannelDriver
{
    public function __construct(private ChannelDriver $inner) {}

    /**
     * The driver this wraps — for a test, and for a caller that genuinely needs the concrete
     * type (a per-provider adapter lookup). Reaching for it to escape a guarantee above is
     * the one use it does not have.
     */
    public function inner(): ChannelDriver
    {
        return $this->inner;
    }

    /*
    |--------------------------------------------------------------------------
    | The guaranteed answers
    |--------------------------------------------------------------------------
    */

    public function mode(): ChannelMode
    {
        return $this->inner->mode();
    }

    /**
     * The matrix **and** the driver — so a driver may narrow, and cannot widen.
     */
    public function supports(ChannelCapability $capability): bool
    {
        return $capability->supportedOn($this->mode()) && $this->inner->supports($capability);
    }

    /**
     * Derived from the mode, whatever the wrapped driver answers (Req 8.8, Property 24).
     */
    public function requiresAntiBan(): bool
    {
        return $this->mode()->isWebProtocol();
    }

    /*
    |--------------------------------------------------------------------------
    | Driver operations
    |--------------------------------------------------------------------------
    */

    public function send(Session $session, OutboundContent $content): SendReceipt
    {
        $this->assertSessionOnThisMode($session, 'send');

        return $this->inner->send($session, $content);
    }

    /**
     * @param  array<string, string|int|float>  $vars
     */
    public function sendTemplate(Session $session, TemplateRef $template, array $vars): SendReceipt
    {
        $this->assertSessionOnThisMode($session, 'sendTemplate');

        return $this->inner->sendTemplate($session, $template, $vars);
    }

    public function parseWebhook(Request $request, ChannelCredentials $credentials): InboundEvent
    {
        $this->assertCredentialsOnThisMode($credentials, 'parseWebhook');

        return $this->inner->parseWebhook($request, $credentials);
    }

    public function register(Session $session, ChannelCredentials $credentials): RegistrationResult
    {
        $this->assertSessionOnThisMode($session, 'register');
        $this->assertCredentialsOnThisMode($credentials, 'register');

        return $this->inner->register($session, $credentials);
    }

    public function healthCheck(ChannelCredentials $credentials): ChannelHealth
    {
        $this->assertCredentialsOnThisMode($credentials, 'healthCheck');

        return $this->inner->healthCheck($credentials);
    }

    /*
    |--------------------------------------------------------------------------
    | Transport — delegated verbatim
    |--------------------------------------------------------------------------
    */

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
    | The refusals
    |--------------------------------------------------------------------------
    */

    /**
     * A message belongs to exactly one driver: the one for its session's mode (Property 22).
     *
     * An `InvalidArgumentException` rather than a domain exception, because this is a
     * programming error in the routing layer and not a condition a tenant can produce or an
     * operator can fix. It is also not `ModeCapabilityException`: nothing about the
     * *capability* is wrong — the message was simply handed to the wrong backend.
     *
     * @throws InvalidArgumentException when the session runs on another mode
     */
    private function assertSessionOnThisMode(Session $session, string $operation): void
    {
        if ($session->channel_mode !== $this->mode()) {
            throw new InvalidArgumentException(sprintf(
                'Refusing %s(): this session runs on [%s] and this driver is [%s]. A message is '
                .'dispatched to exactly one driver — the one for its session\'s mode — because the '
                .'others hold different credentials, rate rules, and ban-risk profiles.',
                $operation,
                $session->channel_mode->value,
                $this->mode()->value,
            ));
        }
    }

    /**
     * Credentials are per `(tenant, mode)`, and a driver may only be handed its own
     * (Property 23).
     *
     * The failure this prevents is quiet: a Cloud API access token presented to a BSP partner
     * is a `401` that looks like an expired token, and task 7.6 would mark the tenant's
     * *working* credentials invalid on the strength of it.
     *
     * @throws InvalidArgumentException when the credentials belong to another mode
     */
    private function assertCredentialsOnThisMode(ChannelCredentials $credentials, string $operation): void
    {
        if ($credentials->mode !== $this->mode()) {
            throw new InvalidArgumentException(sprintf(
                'Refusing %s(): these credentials are for [%s] and this driver is [%s]. Presenting one '
                .'backend\'s secrets to another is an authentication failure that reads as an expired '
                .'token.',
                $operation,
                $credentials->mode->value,
                $this->mode()->value,
            ));
        }
    }
}
