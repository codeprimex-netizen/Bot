<?php

declare(strict_types=1);

namespace App\Exceptions\Security;

use App\Services\Abuse\FraudVerdict;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * A signup or OTP attempt was refused by the anti-fraud heuristics
 * (design § Abuse / anti-fraud, row 1: *"email+phone OTP, device/IP heuristics,
 * velocity limits, trial quota caps"*; Req 32.7 / NFR3).
 *
 * ## Every block is bounded, and says so
 *
 * `429` with a `Retry-After` header taken from the verdict, because a *permanent*
 * anti-fraud block is a bug, not a stricter policy: signup traffic arrives through
 * carrier NAT, corporate egress, and university networks, so any threshold keyed on an
 * address will eventually hit a legitimate user who shares one. The heuristics
 * therefore count inside a window and refuse only for the remainder of it — and the
 * remainder is in the response, so the caller (and the panel screens of tasks
 * 24.1/24.2) can say "try again in 12 minutes" instead of "denied".
 *
 * ## What the message may contain
 *
 * The signals and the wait — never the email, the phone number, or the device
 * fingerprint. Identities reach the abuse layer only as keyed digests
 * (`IdentityDigest`), and the `abuse_events` row written alongside this exception
 * stores the same digest, so an operator can correlate attempts without the trail
 * carrying the identity itself.
 */
final class AntiFraudBlockedException extends SecurityException implements HttpExceptionInterface
{
    /**
     * Too many requests: the caller is being rate-limited by policy, not rejected.
     */
    public const int STATUS = 429;

    public const string ERROR_CODE = 'anti_fraud_blocked';

    private function __construct(string $message, public readonly FraudVerdict $verdict)
    {
        parent::__construct($message);
    }

    public static function from(FraudVerdict $verdict): self
    {
        return new self(sprintf(
            'The attempt was refused by the anti-fraud heuristics (signals: %s) for another %d second(s).',
            $verdict->signalList() === '' ? 'none' : $verdict->signalList(),
            $verdict->retryAfterSeconds(),
        ), $verdict);
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
        return ['Retry-After' => (string) $this->verdict->retryAfterSeconds()];
    }

    /**
     * Retryable *after the window*, which is what `Retry-After` carries. There is no
     * state to clear and no appeal to make: the counter decays.
     */
    public function isRetryable(): bool
    {
        return true;
    }

    /**
     * The sentence a prospective customer sees. It states the wait, because a
     * refusal a legitimate user cannot act on is indistinguishable from an outage.
     */
    public function publicMessage(): string
    {
        return $this->verdict->explanation();
    }
}
