<?php

declare(strict_types=1);

namespace App\Exceptions\Security;

use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * A session is under the per-session kill-switch and may not be used
 * (design § Abuse / anti-fraud: *"kill-switch for offending sessions"*; design
 * § Admin Panel row 26; Req 32.7 / NFR3).
 *
 * ## Why a typed refusal rather than a silent no-op
 *
 * A killed session is a *deliberate operator decision* about WhatsApp ToS or ban
 * risk, and the work that hits it must stop visibly: a caller that quietly did
 * nothing would leave a campaign appearing to send, a flow appearing to answer, and
 * an operator with no way to tell "kill-switch honoured" from "kill-switch never
 * checked". The refusal is also recorded — `SessionKillSwitch::assertUsable()` writes
 * an `abuse_events` row for the attempt — so the trail shows both the flip and every
 * attempt that hit it.
 *
 * Non-retryable: waiting does not help, because only a release (or the expiry of a
 * time-bounded automatic kill) lifts it.
 */
final class SessionKilledException extends SecurityException implements HttpExceptionInterface
{
    /**
     * Forbidden: the caller is authenticated and the operation is refused anyway.
     */
    public const int STATUS = 403;

    public const string PUBLIC_MESSAGE = 'This session has been suspended by the platform. Contact support to have it reviewed.';

    public const string ERROR_CODE = 'session_killed';

    private function __construct(string $message, public readonly string $sessionKey)
    {
        parent::__construct($message);
    }

    /**
     * @param  string|null  $reason  operator-supplied, already free of message content
     */
    public static function forSession(string $sessionKey, ?string $reason = null): self
    {
        return new self(sprintf(
            'Session %s is under a kill-switch and cannot be used%s. Release it (SessionKillSwitch::release) '
            .'after review; an automatic kill lifts itself when it expires.',
            self::fingerprint($sessionKey),
            $reason === null || $reason === '' ? '' : ': '.self::redact($reason),
        ), $sessionKey);
    }

    public function getStatusCode(): int
    {
        return self::STATUS;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return [];
    }

    public function isRetryable(): bool
    {
        return false;
    }

    public function publicMessage(): string
    {
        return self::PUBLIC_MESSAGE;
    }
}
