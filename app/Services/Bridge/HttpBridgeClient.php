<?php

declare(strict_types=1);

namespace App\Services\Bridge;

use App\Enums\PresenceState;
use App\Enums\SessionLoginMethod;
use App\Exceptions\Bridge\BridgeRequestFailedException;
use App\Exceptions\Bridge\BridgeUnreachableException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;
use Throwable;

/**
 * The wire: JSON over HTTP to the Node + Baileys **WA Bridge** sidecar, authenticated with a
 * bearer token from `wa.bridge` (engine Req 35; ROADMAP § "WA Bridge").
 *
 * The sidecar is deliberately thin — ROADMAP describes it as ~600 lines that hold the
 * protocol socket and nothing else: no business logic, no database, no decisions. This class
 * is the other half of that contract, and it is thin for the same reason: it turns a typed PHP
 * call into one HTTP request and one decoded response, and has an opinion about nothing else.
 *
 * ## The wire protocol
 *
 * `{wa.bridge.url}/{wa.bridge.prefix}` is the base; every session-addressed route embeds the
 * opaque session ULID, and nothing else identifies anything. The sidecar has no tenant
 * concept and no route that could reveal one.
 *
 * | Operation | Route |
 * |---|---|
 * | `provisionSession` | `POST   /sessions` |
 * | `startSession` | `POST   /sessions/{id}/start` |
 * | `stopSession` | `POST   /sessions/{id}/stop` |
 * | `sessionState` | `GET    /sessions/{id}` |
 * | `qr` | `GET    /sessions/{id}/qr` |
 * | `pairingCode` | `POST   /sessions/{id}/pairing-code` |
 * | `sendText` | `POST   /sessions/{id}/messages/text` |
 * | `sendMedia` | `POST   /sessions/{id}/messages/media` |
 * | `sendPresence` | `POST   /sessions/{id}/presence` |
 * | `checkNumbers` | `POST   /sessions/{id}/numbers/check` |
 * | `isReachable` | `GET    /health` |
 *
 * A refusal is `{"code": "session_not_connected", "message": "…"}` with a non-2xx status. The
 * `code` vocabulary is `BridgeRequestFailedException`'s constants; the `message` is read for
 * nothing, because it is prose written by another process and would end up in our logs.
 *
 * ## Why the HTTP client's own retries are off
 *
 * `retry()` is deliberately unused, exactly as `HttpOutboxTransport` explains for the outbox:
 * the attempt budget belongs to one layer, and here that layer is `GuardedBridgeClient`
 * (bounded inline attempts) composed with `RetryPolicy` in the calling job (the real budget).
 * A transport that retried internally would multiply the two, hide attempts from the job's
 * `attempts()` counter, and — for a send — turn one duplicate-risk window into three.
 *
 * Timeouts are bounded for the same reason. A hanging bridge read is a stalled worker, and the
 * queue lease has to outlive the whole attempt.
 *
 * ## Fail closed, in one direction only
 *
 * Every exit that is not a decoded response is an exception, and the transport-failure one is
 * `BridgeUnreachableException` — never a `null`, never an empty `SentMessageDto`. A send whose
 * transport failed is a send whose outcome is *unknown*, and the one thing it must never look
 * like is a success. The single exception is `isReachable()`, whose question is "is it up?" and
 * whose honest answer to a dead bridge is `false`.
 */
final readonly class HttpBridgeClient implements BridgeClient
{
    /**
     * Seconds to wait for the whole request, and for the connection alone, when config says
     * nothing usable.
     */
    public const int DEFAULT_TIMEOUT = 15;

    public const int DEFAULT_CONNECT_TIMEOUT = 5;

    public const string DEFAULT_URL = 'http://127.0.0.1:3000';

    /**
     * Health probes get their own, much shorter budget: the whole point of asking is to find
     * out quickly, and a probe that waits fifteen seconds to say "down" is a probe that has
     * already stalled the dashboard it feeds.
     */
    private const int HEALTH_TIMEOUT = 3;

    public function __construct(private HttpFactory $http) {}

    /*
    |--------------------------------------------------------------------------
    | Session lifecycle
    |--------------------------------------------------------------------------
    */

    public function provisionSession(
        string $sessionId,
        SessionLoginMethod $method,
        ?string $phone = null,
        ?string $authStateDir = null,
    ): SessionInitDto {
        $operation = 'session.provision';

        if ($method->requiresPhone() && $this->digits($phone) === null) {
            // Refused here rather than by the bridge: the bridge would answer 422 after a
            // round trip, and a pairing code cannot be issued for a number nobody named.
            throw new InvalidArgumentException(sprintf(
                'Login method [%s] needs the session phone number in E.164 digits before pairing can start.',
                $method->value,
            ));
        }

        $body = [
            'session_id' => $sessionId,
            'login_method' => $method->value,
        ];

        if (($digits = $this->digits($phone)) !== null) {
            $body['phone'] = $digits;
        }

        if ($authStateDir !== null && trim($authStateDir) !== '') {
            $body['auth_state_dir'] = trim($authStateDir);
        }

        $payload = $this->decode($operation, $this->send($operation, 'post', 'sessions', $body));

        return SessionInitDto::fromPayload($operation, $sessionId, $payload);
    }

    public function startSession(string $sessionId): void
    {
        $this->send('session.start', 'post', $this->sessionPath($sessionId, 'start'));
    }

    public function stopSession(string $sessionId, bool $logout = false): void
    {
        $this->send('session.stop', 'post', $this->sessionPath($sessionId, 'stop'), ['logout' => $logout]);
    }

    public function sessionState(string $sessionId): SessionStateDto
    {
        $operation = 'session.state';
        $payload = $this->decode($operation, $this->send($operation, 'get', $this->sessionPath($sessionId)));

        return SessionStateDto::fromPayload($operation, $sessionId, $payload);
    }

    public function qr(string $sessionId): ?string
    {
        $operation = 'session.qr';
        $payload = $this->decode($operation, $this->send($operation, 'get', $this->sessionPath($sessionId, 'qr')));

        // A 200 with no `qr` is the bridge saying "this session is not showing one" — the only
        // null in this contract that is an answer rather than a failure.
        return BridgeWire::stringOrNull($payload['qr'] ?? null);
    }

    public function pairingCode(string $sessionId, string $phone): string
    {
        $operation = 'session.pairing_code';
        $digits = $this->digits($phone);

        if ($digits === null) {
            throw new InvalidArgumentException(
                'A pairing code is issued for a number: $phone must be E.164 digits.'
            );
        }

        $payload = $this->decode($operation, $this->send(
            $operation,
            'post',
            $this->sessionPath($sessionId, 'pairing-code'),
            ['phone' => $digits],
        ));

        $code = BridgeWire::stringOrNull($payload['pairing_code'] ?? null);

        if ($code === null) {
            // An empty code would be shown to a tenant as something to type into their phone.
            throw BridgeUnreachableException::malformedResponse(
                $operation,
                'the pairing-code response carried no "pairing_code"',
            );
        }

        return $code;
    }

    /*
    |--------------------------------------------------------------------------
    | Messaging
    |--------------------------------------------------------------------------
    */

    public function sendText(string $sessionId, string $jid, string $text, array $opts = []): SentMessageDto
    {
        $operation = 'message.text';

        $payload = $this->decode($operation, $this->send(
            $operation,
            'post',
            $this->sessionPath($sessionId, 'messages/text'),
            $this->messageBody($jid, ['text' => $text], $opts),
        ));

        return SentMessageDto::fromPayload($operation, $jid, $payload);
    }

    public function sendMedia(string $sessionId, string $jid, MediaPayload $media, array $opts = []): SentMessageDto
    {
        $operation = 'message.media';

        $payload = $this->decode($operation, $this->send(
            $operation,
            'post',
            $this->sessionPath($sessionId, 'messages/media'),
            $this->messageBody($jid, ['media' => $media->toRequest()], $opts),
        ));

        return SentMessageDto::fromPayload($operation, $jid, $payload);
    }

    public function sendPresence(string $sessionId, string $jid, PresenceState $presence): void
    {
        $this->send('presence.update', 'post', $this->sessionPath($sessionId, 'presence'), [
            'jid' => $this->assertJid($jid),
            'presence' => $presence->value,
        ]);
    }

    public function checkNumbers(string $sessionId, array $numbers): array
    {
        $operation = 'numbers.check';

        // De-duplicated, but keyed by the caller's own spelling: the answer map has to be
        // usable without the caller re-normalising anything.
        $asked = [];

        foreach ($numbers as $number) {
            $digits = $this->digits($number);

            if ($digits !== null) {
                $asked[$number] = $digits;
            }
        }

        if ($asked === []) {
            // No round trip for an empty question. Not a failure: the honest answer to "which
            // of these zero numbers are on WhatsApp?" is an empty map.
            return [];
        }

        $payload = $this->decode($operation, $this->send(
            $operation,
            'post',
            $this->sessionPath($sessionId, 'numbers/check'),
            ['numbers' => array_values(array_unique(array_values($asked)))],
        ));

        return $this->readNumberChecks($asked, $payload);
    }

    /*
    |--------------------------------------------------------------------------
    | Transport health
    |--------------------------------------------------------------------------
    */

    public function isReachable(): bool
    {
        try {
            return $this->request(self::HEALTH_TIMEOUT)->get($this->url('health'))->successful();
        } catch (Throwable) {
            // The question is "is the bridge up?", and every way of failing to ask is a "no".
            // This is the one method in the contract that reports a failure as a value.
            return false;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Requests
    |--------------------------------------------------------------------------
    */

    /**
     * Issue one request and hand back the response, or raise.
     *
     * @param  'delete'|'get'|'patch'|'post'  $method
     * @param  array<string, mixed>  $body
     *
     * @throws BridgeRequestFailedException when the bridge answered with a refusal
     * @throws BridgeUnreachableException when the bridge could not be reached at all
     */
    private function send(string $operation, string $method, string $path, array $body = []): Response
    {
        $url = $this->url($path);

        try {
            $request = $this->request($this->timeout());

            $response = match ($method) {
                'get' => $request->get($url),
                'delete' => $request->delete($url, $body),
                'patch' => $request->patch($url, $body),
                'post' => $request->post($url, $body),
            };
        } catch (Throwable) {
            // Connection refused, DNS failure, read timeout — and deliberately nothing from
            // the client exception is carried over: it can quote the bridge URL and the
            // bearer token in a request dump.
            throw BridgeUnreachableException::transportFailed($operation);
        }

        if ($response->failed()) {
            throw BridgeRequestFailedException::refused(
                $operation,
                $response->status(),
                $this->errorCode($response),
                $this->retryAfter($response),
            );
        }

        return $response;
    }

    /**
     * A configured pending request: JSON both ways, bearer token, bounded timeouts, no
     * internal retries.
     */
    private function request(int $timeout): PendingRequest
    {
        $request = $this->http
            ->asJson()
            ->acceptJson()
            ->connectTimeout($this->connectTimeout())
            ->timeout($timeout);

        $token = $this->token();

        // An unset token is not substituted with a placeholder: the bridge answers 401, which
        // classifies as AUTH and fails fast, which is exactly the right outcome for a
        // deployment that has not been configured yet. Sending a fake token would produce the
        // same 401 while making the cause harder to see.
        return $token === null ? $request : $request->withToken($token);
    }

    /**
     * The absolute URL for a bridge path.
     */
    private function url(string $path): string
    {
        $base = rtrim($this->baseUrl(), '/');
        $prefix = trim($this->prefix(), '/');
        $segment = ltrim($path, '/');

        return $prefix === ''
            ? $base.'/'.$segment
            : $base.'/'.$prefix.'/'.$segment;
    }

    /**
     * A session-addressed path, with the id percent-encoded.
     *
     * The id is a ULID by the time it reaches here (`TenantScopedBridgeClient` refuses anything
     * else before the lookup), but it is encoded anyway: this class is also constructible
     * directly, and a path built by concatenation is exactly how a `../` reaches a route it
     * should not.
     */
    private function sessionPath(string $sessionId, string $suffix = ''): string
    {
        $path = 'sessions/'.rawurlencode($sessionId);

        return $suffix === '' ? $path : $path.'/'.ltrim($suffix, '/');
    }

    /**
     * A message request body: the recipient, the content, and the caller's protocol options.
     *
     * `$opts` is nested under its own key rather than merged into the body, so a caller cannot
     * overwrite `jid`, `text`, or `media` through it — an option map that could rewrite the
     * recipient would make the send pipeline's opt-out and quota decisions unenforceable.
     *
     * @param  array<string, mixed>  $content
     * @param  array<string, mixed>  $opts
     * @return array<string, mixed>
     */
    private function messageBody(string $jid, array $content, array $opts): array
    {
        $body = ['jid' => $this->assertJid($jid)] + $content;

        if ($opts !== []) {
            $body['options'] = $opts;
        }

        return $body;
    }

    /*
    |--------------------------------------------------------------------------
    | Responses
    |--------------------------------------------------------------------------
    */

    /**
     * The response body as a string-keyed array.
     *
     * @return array<string, mixed>
     *
     * @throws BridgeUnreachableException when the body is not a JSON object
     */
    private function decode(string $operation, Response $response): array
    {
        $decoded = $response->json();

        if (! is_array($decoded)) {
            // HTML from a reverse proxy, a truncated body, a bare string: the bridge answered
            // 2xx with something that is not a result, so the outcome is unknown and this is a
            // transport failure rather than a result to be read.
            throw BridgeUnreachableException::malformedResponse(
                $operation,
                'a 2xx response whose body is not a JSON object',
            );
        }

        return BridgeWire::arrayOrEmpty($decoded);
    }

    /**
     * Line up the bridge's answers with the numbers that were asked.
     *
     * Every asked number gets an entry, and a number the bridge said nothing about comes back
     * `unknown` rather than missing — a caller iterating its own input must not have to
     * distinguish "absent from the map" from "not on WhatsApp".
     *
     * @param  array<array-key, string>  $asked  caller spelling => E.164 digits
     * @param  array<string, mixed>  $payload
     * @return array<array-key, NumberCheck>
     */
    private function readNumberChecks(array $asked, array $payload): array
    {
        $byDigits = [];

        foreach (BridgeWire::listOrEmpty($payload['numbers'] ?? null) as $row) {
            $entry = BridgeWire::arrayOrEmpty($row);
            $digits = $this->digits(BridgeWire::stringOrNull($entry['number'] ?? null));

            if ($digits !== null) {
                $byDigits[$digits] = $entry;
            }
        }

        $checks = [];

        foreach ($asked as $spelling => $digits) {
            // PHP has already coerced an all-digit key to an integer on the way into $asked, so
            // the spelling is re-stringified for the DTO. The array key stays as PHP made it —
            // a lookup by the original string coerces identically, so callers are unaffected.
            $number = (string) $spelling;

            $checks[$spelling] = array_key_exists($digits, $byDigits)
                ? NumberCheck::fromPayload($number, $byDigits[$digits])
                : new NumberCheck($number, null);
        }

        return $checks;
    }

    /**
     * The bridge's machine-readable error code, when it sent one.
     *
     * Only `code` is read. `message` is prose from another process and is never carried into a
     * PHP exception — see `BridgeRequestFailedException::refused()`.
     */
    private function errorCode(Response $response): ?string
    {
        $decoded = $response->json();

        if (! is_array($decoded)) {
            return null;
        }

        return BridgeWire::stringOrNull(BridgeWire::arrayOrEmpty($decoded)['code'] ?? null);
    }

    /**
     * A `Retry-After` the bridge named, in its numeric form only.
     *
     * The HTTP-date form is ignored rather than parsed, for the reason `RetryPolicy` gives: a
     * clock skew would turn a two-second wait into a two-hour one.
     */
    private function retryAfter(Response $response): ?int
    {
        $header = trim((string) $response->header('Retry-After'));

        return preg_match('/^\d+$/', $header) === 1 ? (int) $header : null;
    }

    /*
    |--------------------------------------------------------------------------
    | Input shapes
    |--------------------------------------------------------------------------
    */

    /**
     * A phone number reduced to its E.164 digits, or null when there is nothing usable.
     *
     * Separators, spaces and a leading `+` are stripped; anything left that is not digits, or a
     * result outside E.164's 8–15 digit range, is `null` rather than a "best effort" — sending
     * to a mangled number is how one tenant's typo becomes a message to a stranger.
     */
    private function digits(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = (string) preg_replace('/\D+/', '', $value);

        return preg_match('/^\d{8,15}$/', $digits) === 1 ? $digits : null;
    }

    /**
     * A recipient JID, validated rather than trusted.
     *
     * The JID goes straight into a request body the sidecar addresses a human being with, so a
     * value carrying whitespace, a control character, or a newline is refused here. Refusing is
     * safe: every JID the platform holds comes from the protocol itself
     * (`NumberCheck::$jid`, an inbound event, a group listing).
     */
    private function assertJid(string $jid): string
    {
        $trimmed = trim($jid);

        if (preg_match('/^[A-Za-z0-9._:@-]{5,128}$/', $trimmed) !== 1 || ! str_contains($trimmed, '@')) {
            throw new InvalidArgumentException(
                'A recipient JID must be a fully-qualified protocol address such as '
                .'"9198…@s.whatsapp.net" or "…@g.us".'
            );
        }

        return $trimmed;
    }

    /*
    |--------------------------------------------------------------------------
    | Config
    |--------------------------------------------------------------------------
    */

    private function baseUrl(): string
    {
        $configured = config('wa.bridge.url');

        return is_string($configured) && trim($configured) !== '' ? trim($configured) : self::DEFAULT_URL;
    }

    private function prefix(): string
    {
        $configured = config('wa.bridge.prefix');

        return is_string($configured) ? trim($configured) : '';
    }

    private function token(): ?string
    {
        $configured = config('wa.bridge.token');

        return is_string($configured) && trim($configured) !== '' ? trim($configured) : null;
    }

    private function timeout(): int
    {
        return $this->positiveConfig('wa.bridge.timeout', self::DEFAULT_TIMEOUT);
    }

    private function connectTimeout(): int
    {
        return $this->positiveConfig('wa.bridge.connect_timeout', self::DEFAULT_CONNECT_TIMEOUT);
    }

    /**
     * A positive integer from config, falling back to the compiled-in default — so a deleted or
     * nonsensical key degrades to a working timeout rather than to none.
     */
    private function positiveConfig(string $key, int $default): int
    {
        $value = config($key);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
    }
}
