<?php

declare(strict_types=1);

namespace Tests\Fixtures\Channel;

use App\Enums\ChannelCapability;
use App\Enums\ChannelMode;
use App\Enums\PresenceState;
use App\Enums\SessionLoginMethod;
use App\Enums\SessionStatus;
use App\Models\Session;
use App\Services\Bridge\MediaPayload;
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
use Illuminate\Http\Request;

/**
 * A `ChannelDriver` for the **router's** tests: one per mode, deterministic, and it counts
 * how many times it was constructed (design.md § Testing Strategy; Req 36.1 / NFR7).
 *
 * ## Why this exists next to `FakeChannelDriver`
 *
 * It does not, yet: `App\Services\Channel\FakeChannelDriver` is **task 7.5** and is not
 * written. Task 6.3 needs *something* registered per mode to assert that routing is
 * exactly-one, that every driver leaves the router wrapped, and that resolution is memoised —
 * so this is the smallest honest driver that makes those assertions possible, and it lives in
 * `tests/` where `autoload-dev` keeps it structurally unreachable from a production install
 * (the argument `FakeBridgeClient`'s docblock makes).
 *
 * When 7.5 lands, the router tests can move onto it; what they must **not** do is depend on a
 * fake that answers capability or anti-ban questions of its own, which is why this one uses
 * `DerivesChannelPolicy` exactly as tasks 7.1–7.5 are expected to. A fake that lied about
 * `requiresAntiBan()` would make the decorator-wrapping assertions pass for the wrong reason.
 *
 * ## What it records
 *
 * | Call | Read with |
 * |---|---|
 * | construction | `RouterProbeDriver::built($mode)` — the memoisation assertion |
 * | any driver or transport method | `calls()` |
 *
 * The build counter is static because memoisation is about *instances*: a router that resolved
 * a fresh driver on every call would still return an equal object, so counting equality proves
 * nothing and counting constructions proves everything.
 */
final class RouterProbeDriver implements ChannelDriver
{
    use DerivesChannelPolicy;

    /**
     * Constructions per mode value, since the last `reset()`.
     *
     * @var array<string, int>
     */
    private static array $built = [];

    /**
     * @var list<string>
     */
    private array $calls = [];

    public function __construct(private readonly ChannelMode $driverMode)
    {
        self::$built[$driverMode->value] = self::built($driverMode) + 1;
    }

    /**
     * A factory of the shape `DefaultChannelRouter` takes: mode value → a closure per mode.
     *
     * @param  ChannelMode  ...$modes  the modes to register a driver for
     * @return array<string, \Closure(): ChannelDriver>
     */
    public static function registry(ChannelMode ...$modes): array
    {
        $registry = [];

        foreach ($modes as $mode) {
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

    public static function reset(): void
    {
        self::$built = [];
    }

    /**
     * @return list<string>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    public function mode(): ChannelMode
    {
        return $this->driverMode;
    }

    /*
    |--------------------------------------------------------------------------
    | Driver operations
    |--------------------------------------------------------------------------
    */

    public function send(Session $session, OutboundContent $content): SendReceipt
    {
        $this->calls[] = 'send:'.$content->idempotencyKey();

        return new SendReceipt(
            mode: $this->driverMode,
            providerMessageId: 'wamid.'.$content->idempotencyKey(),
            recipient: $content->recipient(),
            idempotencyKey: $content->idempotencyKey(),
            capability: $content->capability(),
        );
    }

    /**
     * @param  array<string, string|int|float>  $vars
     */
    public function sendTemplate(Session $session, TemplateRef $template, array $vars): SendReceipt
    {
        $this->calls[] = 'sendTemplate:'.$template->key();

        return new SendReceipt(
            mode: $this->driverMode,
            providerMessageId: 'wamid.tpl',
            recipient: '15550001111@s.whatsapp.net',
            idempotencyKey: 'tpl-'.$template->key(),
            capability: ChannelCapability::Template,
            templateName: $template->name,
        );
    }

    public function parseWebhook(Request $request, ChannelCredentials $credentials): InboundEvent
    {
        $this->calls[] = 'parseWebhook';

        return InboundEvent::unsupported($this->driverMode, $credentials->tenantId);
    }

    public function register(Session $session, ChannelCredentials $credentials): RegistrationResult
    {
        $this->calls[] = 'register';

        return RegistrationResult::live($credentials, 'probe-number-id');
    }

    public function healthCheck(ChannelCredentials $credentials): ChannelHealth
    {
        $this->calls[] = 'healthCheck';

        return ChannelHealth::healthy($credentials);
    }

    /*
    |--------------------------------------------------------------------------
    | Transport — recorded, deterministic, and never reached by the router
    |--------------------------------------------------------------------------
    */

    public function provisionSession(
        string $sessionId,
        SessionLoginMethod $method,
        ?string $phone = null,
        ?string $authStateDir = null,
    ): SessionInitDto {
        $this->calls[] = 'provisionSession:'.$sessionId;

        return new SessionInitDto(sessionId: $sessionId, status: SessionStatus::QrPending);
    }

    public function startSession(string $sessionId): void
    {
        $this->calls[] = 'startSession:'.$sessionId;
    }

    public function stopSession(string $sessionId, bool $logout = false): void
    {
        $this->calls[] = 'stopSession:'.$sessionId;
    }

    public function sessionState(string $sessionId): SessionStateDto
    {
        $this->calls[] = 'sessionState:'.$sessionId;

        return new SessionStateDto(sessionId: $sessionId, status: SessionStatus::Connected);
    }

    public function qr(string $sessionId): ?string
    {
        $this->calls[] = 'qr:'.$sessionId;

        return null;
    }

    public function pairingCode(string $sessionId, string $phone): string
    {
        $this->calls[] = 'pairingCode:'.$sessionId;

        return 'PROBE123';
    }

    /**
     * @param  array<string, mixed>  $opts
     */
    public function sendText(string $sessionId, string $jid, string $text, array $opts = []): SentMessageDto
    {
        $this->calls[] = 'sendText:'.$sessionId;

        return new SentMessageDto(waMessageId: 'wamid.text', jid: $jid);
    }

    /**
     * @param  array<string, mixed>  $opts
     */
    public function sendMedia(string $sessionId, string $jid, MediaPayload $media, array $opts = []): SentMessageDto
    {
        $this->calls[] = 'sendMedia:'.$sessionId;

        return new SentMessageDto(waMessageId: 'wamid.media', jid: $jid);
    }

    public function sendPresence(string $sessionId, string $jid, PresenceState $presence): void
    {
        $this->calls[] = 'sendPresence:'.$sessionId.':'.$presence->value;
    }

    /**
     * @param  list<string>  $numbers
     * @return array<array-key, \App\Services\Bridge\NumberCheck>
     */
    public function checkNumbers(string $sessionId, array $numbers): array
    {
        $this->calls[] = 'checkNumbers:'.count($numbers);

        return [];
    }

    public function isReachable(): bool
    {
        $this->calls[] = 'isReachable';

        return true;
    }
}
