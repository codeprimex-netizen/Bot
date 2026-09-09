<?php

declare(strict_types=1);

namespace App\Exceptions\Tenancy;

use App\Services\Tenancy\QuotaVerdict;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * A metered action was refused because the tenant's plan allowance does not cover it —
 * **429**, with `Retry-After` when the allowance comes back on its own
 * (Req 3.4 / A3; Req 20.3 / C3).
 *
 * Raised by `QuotaGuard::authorize()`, the throwing form of `verdict()`, for callers
 * whose natural failure mode is an exception: a panel action, an API request, a service
 * that has no queue job to release. The **send pipeline does not use it** — Algorithm 3
 * branches on the verdict itself, because a deferred send has to `release()` the job
 * rather than raise.
 *
 * ## Never a drop
 *
 * Req 3.4: an exhausted quota must *"defer or block the send (never drop it)"*. Both
 * halves reach a client through this one exception, and `isDeferrable()` is the
 * difference:
 *
 * - **deferrable** (`PERIOD_EXHAUSTED`) — the period will roll. `Retry-After` carries
 *   the exact wait, so a client, a job, and the panel countdown all agree.
 * - **not deferrable** (not priced, metered to zero, no plan, larger than a whole
 *   period, a gauge at capacity) — waiting will not help; the tenant has to upgrade,
 *   delete something, or ask for less. There is no `Retry-After`, because inventing one
 *   would promise a reset that is never coming.
 *
 * The public sentence is the verdict's own `explanation()`: it is the tenant's own plan
 * and its own usage, so there is nothing to redact, and Req 3.4's "notify the tenant"
 * needs something actually sayable.
 *
 * ## What task 2.4 extends
 *
 * This is the exception half of task 2.4, present now because `QuotaGuard::authorize()`
 * would otherwise have nothing to throw. Task 2.4 still owns everything *around* it and
 * should not re-create it:
 *
 * - a `QUOTA_PAUSED` state for work parked by a deferrable refusal (campaigns, Req 20.3),
 *   and the tenant notification Req 3.4 asks for;
 * - the scheduled period-reset command that resumes that work when
 *   `retryAfterSeconds()` has elapsed — the natural place to key off
 *   `$verdict->reason->isTransient()`;
 * - the `RateLimited`-style backoff for the queue, if it wants one on top of
 *   `release()`.
 */
final class QuotaExceededException extends RuntimeException implements HttpExceptionInterface
{
    public const int STATUS = 429;

    /**
     * Stable machine-readable code for API clients and the panel.
     */
    public const string ERROR_CODE = 'quota_exceeded';

    /**
     * The fallback sentence, for the (impossible-by-construction) case of a refusal with
     * no verdict to explain itself.
     */
    public const string PUBLIC_MESSAGE = 'This action exceeds your plan allowance.';

    private function __construct(
        public readonly string $tenantId,
        public readonly QuotaVerdict $verdict,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * Build the refusal a non-allowing verdict describes.
     */
    public static function from(string $tenantId, QuotaVerdict $verdict): self
    {
        return new self($tenantId, $verdict, sprintf(
            'Tenant %s was refused %d unit(s) of %s (%s): %s used of %s in period %s.',
            $tenantId,
            $verdict->units,
            $verdict->kind->value,
            $verdict->reason->value,
            $verdict->used,
            $verdict->isUnlimited() ? 'unlimited' : (string) $verdict->limit,
            $verdict->periodKey,
        ));
    }

    public function getStatusCode(): int
    {
        return self::STATUS;
    }

    /**
     * `Retry-After`, and only when there is a real reset to point at.
     *
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        $seconds = $this->retryAfterSeconds();

        return $seconds === null ? [] : ['Retry-After' => (string) $seconds];
    }

    /**
     * Whether the allowance returns on its own — the defer half of Req 3.4.
     */
    public function isDeferrable(): bool
    {
        return $this->verdict->isDeferred();
    }

    /**
     * Seconds until the request would fit, or null when no wait would help.
     */
    public function retryAfterSeconds(): ?int
    {
        return $this->isDeferrable() ? $this->verdict->secondsUntilPeriodReset() : null;
    }

    /**
     * Whether the refusal is about pricing rather than usage — the upgrade-CTA case.
     */
    public function suggestsUpgrade(): bool
    {
        return $this->verdict->reason->isPricing();
    }

    /**
     * The only sentence a client is shown.
     */
    public function publicMessage(): string
    {
        $explanation = $this->verdict->explanation();

        return $explanation === '' ? self::PUBLIC_MESSAGE : $explanation;
    }

    public function errorCode(): string
    {
        return self::ERROR_CODE;
    }
}
