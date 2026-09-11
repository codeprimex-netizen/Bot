<?php

declare(strict_types=1);

namespace Tests\Fixtures\Bridge;

use App\Enums\PresenceState;
use App\Enums\SessionLoginMethod;
use App\Enums\SessionStatus;
use App\Exceptions\Bridge\BridgeRequestFailedException;
use App\Exceptions\Bridge\BridgeUnreachableException;
use App\Services\Bridge\BridgeClient;
use App\Services\Bridge\MediaPayload;
use App\Services\Bridge\NumberCheck;
use App\Services\Bridge\SentMessageDto;
use App\Services\Bridge\SessionInitDto;
use App\Services\Bridge\SessionStateDto;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

/**
 * An in-memory `BridgeClient` for tests (design.md § Testing Strategy; Req 36.2 / NFR7;
 * Property 28).
 *
 * ## Why it lives here and not in `app/`
 *
 * It is **test-only, structurally**. `Tests\` is mapped by `autoload-dev`, so this class is not
 * autoloadable in a production install at all — there is nothing for a config key to name by
 * mistake, and nothing for the completeness scan of task 39.4 to find reachable from `app/`.
 * `BridgeServiceProvider` binds only the real chain; a test binds this one explicitly:
 *
 * ```php
 * $bridge = FakeBridgeClient::bind();          // replaces the container's BridgeClient
 * $bridge->connect($session->id);
 *
 * app(BridgeClient::class)->sendText($session->id, '91…@s.whatsapp.net', 'Hi');
 *
 * expect($bridge->sentTo($session->id))->toHaveCount(1);
 * ```
 *
 * ## Why it models the *state machine* rather than the answers
 *
 * It keeps a per-session status and refuses operations that status forbids — a send to a session
 * that was never connected raises `session_not_connected`, exactly as the sidecar would. A fake
 * that acknowledged every send would make every fail-closed test pass vacuously, which is the
 * one thing those tests exist to prevent.
 *
 * It is also **deterministic**: message ids are sequential (`FAKE-{n}`), never random, so a test
 * can assert on them.
 *
 * ## What it does *not* do
 *
 * No ownership check and no breaker. Both belong to the decorators
 * (`TenantScopedBridgeClient`, `GuardedBridgeClient`) and are tested against those directly; a
 * fake that re-implemented them would let a test pass because the *fake* enforced isolation
 * while the real chain did not.
 *
 * | Call | Simulates |
 * |---|---|
 * | `connect($id)` / `status($id, $status)` | a session reaching a connection state |
 * | `unreachable()` / `reachable()` | the sidecar process being down |
 * | `refuseWith($code, $status)` | the sidecar answering a refusal, for one call or until reset |
 * | `showQr($id, $png)` | a pairing QR being available |
 * | `answerNumbers([...])` | the protocol's `onWhatsApp` answers |
 * | `sentTo($id)` / `presences($id)` / `calls($op)` | what the platform actually asked for |
 */
final class FakeBridgeClient implements BridgeClient
{
    /**
     * Prefix of every generated message id, so an assertion never depends on randomness.
     */
    public const string MESSAGE_ID_PREFIX = 'FAKE-';

    /**
     * @var array<string, SessionStatus>
     */
    private array $statuses = [];

    /**
     * @var array<string, string>
     */
    private array $qrCodes = [];

    /**
     * @var array<string, string>
     */
    private array $pairingCodes = [];

    /**
     * @var array<string, string>
     */
    private array $authStateDirs = [];

    /**
     * `array-key` rather than `string` because PHP coerces an all-digit key to an integer —
     * a lookup by the original string coerces identically, so it makes no difference to a
     * caller, only to the declared type.
     *
     * @var array<array-key, bool|null>
     */
    private array $numberAnswers = [];

    /**
     * Every text/media send, in order, keyed by session id.
     *
     * @var array<string, list<array{jid: string, text: string|null, media: MediaPayload|null, opts: array<string, mixed>, wa_message_id: string}>>
     */
    private array $sends = [];

    /**
     * @var array<string, list<PresenceState>>
     */
    private array $presences = [];

    /**
     * @var array<string, int>
     */
    private array $calls = [];

    private bool $reachable = true;

    /**
     * An arbitrary failure the client library itself raises, rather than a bridge refusal.
     */
    private ?Throwable $error = null;

    /**
     * A refusal to answer with: `[code, status, remaining]`, where a null `remaining` means
     * "until reset".
     *
     * @var array{code: string|null, status: int, remaining: int|null}|null
     */
    private ?array $refusal = null;

    private int $nextMessageId = 1;

    /**
     * Bind this fake as the container's `BridgeClient`, replacing the whole real chain.
     *
     * Replacing the *whole* chain — ownership decorator included — is deliberate. A test about
     * the send pipeline should not have its assertions perturbed by a breaker state left over
     * from an earlier test, and the ownership and breaker behaviours have their own tests
     * against their own classes. A test that wants the real decorators around this fake
     * composes them itself.
     */
    public static function bind(): self
    {
        $bridge = new self;

        app()->instance(BridgeClient::class, $bridge);

        return $bridge;
    }

    /*
    |--------------------------------------------------------------------------
    | Arranging
    |--------------------------------------------------------------------------
    */

    public function status(string $sessionId, SessionStatus $status): self
    {
        $this->statuses[$sessionId] = $status;

        return $this;
    }

    /**
     * Bring a session online — the state a send needs.
     */
    public function connect(string $sessionId): self
    {
        return $this->status($sessionId, SessionStatus::Connected);
    }

    public function showQr(string $sessionId, string $png = 'data:image/png;base64,ZmFrZS1xcg=='): self
    {
        $this->qrCodes[$sessionId] = $png;

        return $this->status($sessionId, SessionStatus::QrPending);
    }

    public function withPairingCode(string $sessionId, string $code = 'ABCD1234'): self
    {
        $this->pairingCodes[$sessionId] = $code;

        return $this;
    }

    /**
     * The protocol's `onWhatsApp` answers: number => true (on WhatsApp), false (not), null
     * (declined to answer). A number that is not listed comes back unknown.
     *
     * @param  array<array-key, bool|null>  $answers
     */
    public function answerNumbers(array $answers): self
    {
        foreach ($answers as $number => $exists) {
            $this->numberAnswers[$number] = $exists;
        }

        return $this;
    }

    /**
     * The sidecar process is down: every operation raises a transport failure.
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

    /**
     * The sidecar answers a refusal.
     *
     * @param  int|null  $times  how many calls it applies to; null means every call until reset
     */
    public function refuseWith(?string $code, int $status = 409, ?int $times = null): self
    {
        $this->refusal = ['code' => $code, 'status' => $status, 'remaining' => $times];

        return $this;
    }

    public function stopRefusing(): self
    {
        $this->refusal = null;

        return $this;
    }

    /**
     * Every operation throws `$error` — for the case a *client library* fails in its own way
     * rather than the sidecar refusing.
     *
     * The guarded decorator has to turn that into a typed transport failure without letting the
     * original message (which can quote a URL and a bearer token) escape, and this is how a test
     * arranges it without needing a second implementation of the whole interface.
     */
    public function failWith(Throwable $error): self
    {
        $this->error = $error;

        return $this;
    }

    /*
    |--------------------------------------------------------------------------
    | Asserting
    |--------------------------------------------------------------------------
    */

    /**
     * Everything sent on one session, in order.
     *
     * @return list<array{jid: string, text: string|null, media: MediaPayload|null, opts: array<string, mixed>, wa_message_id: string}>
     */
    public function sentTo(string $sessionId): array
    {
        return $this->sends[$sessionId] ?? [];
    }

    /**
     * @return list<PresenceState>
     */
    public function presences(string $sessionId): array
    {
        return $this->presences[$sessionId] ?? [];
    }

    /**
     * How often one operation was reached — proves a breaker or an ownership check fenced it off.
     */
    public function calls(string $operation): int
    {
        return $this->calls[$operation] ?? 0;
    }

    /**
     * Every operation this fake was asked for, in order of first call.
     *
     * @return array<string, int>
     */
    public function callLog(): array
    {
        return $this->calls;
    }

    /**
     * The auth-state directory the platform told the bridge to use for a session — how a test
     * proves the path was derived per tenant rather than taken from the caller.
     */
    public function authStateDir(string $sessionId): ?string
    {
        return $this->authStateDirs[$sessionId] ?? null;
    }

    /*
    |--------------------------------------------------------------------------
    | BridgeClient
    |--------------------------------------------------------------------------
    */

    public function provisionSession(
        string $sessionId,
        SessionLoginMethod $method,
        ?string $phone = null,
        ?string $authStateDir = null,
    ): SessionInitDto {
        $this->record('session.provision');

        if ($authStateDir !== null) {
            $this->authStateDirs[$sessionId] = $authStateDir;
        }

        $status = $this->statuses[$sessionId] ?? $method->initialStatus();
        $this->statuses[$sessionId] = $status;

        if ($method === SessionLoginMethod::PairingCode && ! isset($this->pairingCodes[$sessionId])) {
            $this->pairingCodes[$sessionId] = 'PAIR'.str_pad((string) $this->nextMessageId, 4, '0', STR_PAD_LEFT);
        }

        return new SessionInitDto(
            sessionId: $sessionId,
            status: $status,
            qr: $method === SessionLoginMethod::Qr ? ($this->qrCodes[$sessionId] ?? null) : null,
            pairingCode: $method === SessionLoginMethod::PairingCode ? $this->pairingCodes[$sessionId] : null,
        );
    }

    public function startSession(string $sessionId): void
    {
        $this->record('session.start');

        $this->statuses[$sessionId] = SessionStatus::Connected;
    }

    public function stopSession(string $sessionId, bool $logout = false): void
    {
        $this->record('session.stop');

        $this->statuses[$sessionId] = $logout ? SessionStatus::LoggedOut : SessionStatus::Closed;
    }

    public function sessionState(string $sessionId): SessionStateDto
    {
        $this->record('session.state');

        return new SessionStateDto(
            sessionId: $sessionId,
            status: $this->statuses[$sessionId] ?? SessionStatus::Initializing,
            lastSeenAt: CarbonImmutable::now(),
        );
    }

    public function qr(string $sessionId): ?string
    {
        $this->record('session.qr');

        return $this->qrCodes[$sessionId] ?? null;
    }

    public function pairingCode(string $sessionId, string $phone): string
    {
        $this->record('session.pairing_code');

        return $this->pairingCodes[$sessionId] ??= 'PAIR'.substr($phone, -4);
    }

    public function sendText(string $sessionId, string $jid, string $text, array $opts = []): SentMessageDto
    {
        $this->record('message.text');
        $this->assertSendable($sessionId);

        return $this->recordSend($sessionId, $jid, $text, null, $opts);
    }

    public function sendMedia(string $sessionId, string $jid, MediaPayload $media, array $opts = []): SentMessageDto
    {
        $this->record('message.media');
        $this->assertSendable($sessionId);

        return $this->recordSend($sessionId, $jid, null, $media, $opts);
    }

    public function sendPresence(string $sessionId, string $jid, PresenceState $presence): void
    {
        $this->record('presence.update');

        $this->presences[$sessionId][] = $presence;
    }

    public function checkNumbers(string $sessionId, array $numbers): array
    {
        $this->record('numbers.check');

        $checks = [];

        foreach ($numbers as $number) {
            $exists = $this->numberAnswers[$number] ?? null;

            $checks[$number] = new NumberCheck(
                number: $number,
                exists: $exists,
                jid: $exists === true ? preg_replace('/\D+/', '', $number).'@s.whatsapp.net' : null,
            );
        }

        return $checks;
    }

    public function isReachable(): bool
    {
        $this->calls['health'] = ($this->calls['health'] ?? 0) + 1;

        return $this->reachable;
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Count the call, then apply whichever arranged failure is in force.
     *
     * Counting *first* is deliberate: a test that arranges a refusal usually wants to prove the
     * call reached the bridge at all, and a fake that counted only successes could not tell
     * "fenced off by a breaker" from "attempted and refused".
     */
    private function record(string $operation): void
    {
        $this->calls[$operation] = ($this->calls[$operation] ?? 0) + 1;

        if ($this->error !== null) {
            throw $this->error;
        }

        if (! $this->reachable) {
            throw BridgeUnreachableException::transportFailed($operation);
        }

        if ($this->refusal === null) {
            return;
        }

        $remaining = $this->refusal['remaining'];

        if ($remaining !== null) {
            if ($remaining <= 0) {
                $this->refusal = null;

                return;
            }

            $this->refusal['remaining'] = $remaining - 1;
        }

        throw BridgeRequestFailedException::refused(
            $operation,
            $this->refusal['status'],
            $this->refusal['code'],
        );
    }

    /**
     * Refuse a send on a session the fake does not hold a live, unthrottled socket for — the
     * same `session_not_connected` the sidecar would answer with.
     */
    private function assertSendable(string $sessionId): void
    {
        $status = $this->statuses[$sessionId] ?? SessionStatus::Initializing;

        if ($status->canSend()) {
            return;
        }

        throw BridgeRequestFailedException::refused(
            'message.send',
            409,
            $status === SessionStatus::LoggedOut
                ? BridgeRequestFailedException::CODE_SESSION_LOGGED_OUT
                : BridgeRequestFailedException::CODE_SESSION_NOT_CONNECTED,
        );
    }

    /**
     * @param  array<string, mixed>  $opts
     */
    private function recordSend(
        string $sessionId,
        string $jid,
        ?string $text,
        ?MediaPayload $media,
        array $opts,
    ): SentMessageDto {
        $waMessageId = self::MESSAGE_ID_PREFIX.$this->nextMessageId++;

        $this->sends[$sessionId][] = [
            'jid' => $jid,
            'text' => $text,
            'media' => $media,
            'opts' => $opts,
            'wa_message_id' => $waMessageId,
        ];

        return new SentMessageDto($waMessageId, $jid, CarbonImmutable::now());
    }

    /**
     * A session id shaped like the real thing, for tests that need one without a database row.
     */
    public static function sessionId(): string
    {
        return Str::ulid()->toBase32();
    }
}
