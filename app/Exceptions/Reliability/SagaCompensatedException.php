<?php

declare(strict_types=1);

namespace App\Exceptions\Reliability;

use App\Services\Reliability\SagaOutcome;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * A saga did not complete (Req 15.5 / B6, Req 31.5 / NFR2, Algorithm 8; design.md
 * §"Error Handling" → `ResilienceException` → `SagaCompensatedException`).
 *
 * `SagaOrchestrator::run()` **returns** a `SagaOutcome` rather than throwing — a
 * compensated saga is a legitimate, fully-handled result, and a caller that has a
 * fallback wants to read it, not catch it. This exception exists for the callers that do
 * not: a queue job or an HTTP action whose only sensible response to "the order did not
 * go through" is to fail loudly, with the outcome attached. Get one from
 * `SagaOutcome::toException()` or `SagaOutcome::throwUnlessCompleted()`.
 *
 * ## Two different failures, and why the distinction is on the exception
 *
 * | `outcome->needsAttention()` | Meaning | Retry the saga? |
 * |---|---|---|
 * | `false` | every completed step was compensated; the saga is `FAILED` and clean | yes — safe and idempotent, nothing was left behind |
 * | `true` | a **compensation** itself failed; the saga is still `COMPENSATING` and owes work | yes, and it must be — the retry resumes the unwind |
 *
 * The design's taxonomy calls this "409/internal; all steps compensated, safe to retry
 * saga", which is the first row. The second row is the case the pseudocode does not
 * consider at all and it must not be conflated with a clean unwind: a saga that could
 * not undo its own reservation has an orphaned side effect *right now*, so it is the one
 * an operator has to look at. `isRetryable()` is true in both rows — re-running is
 * always the right move — but `needsAttention()` is what decides whether a human is
 * paged.
 *
 * ## Redaction
 *
 * The message names the saga id, its type and the failing step — all internal
 * identifiers, no customer data. The saga's `state` (which holds the order, amounts and
 * customer) is never quoted, and the public body is a fixed sentence that names nothing.
 */
final class SagaCompensatedException extends RuntimeException implements HttpExceptionInterface
{
    /**
     * A conflict with the state of the resource rather than a bad request or a bug here:
     * the work was attempted, refused by a dependency, and rolled back.
     */
    public const int STATUS = 409;

    public const string ERROR_CODE = 'saga_compensated';

    /**
     * The only sentence a client is shown: no saga id, no step name, no state.
     */
    public const string PUBLIC_MESSAGE = 'This operation could not be completed and has been rolled back. Please try again.';

    private function __construct(
        public readonly SagaOutcome $outcome,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Wrap a non-completed outcome, keeping the original forward failure as `previous`
     * so the stack trace still points at what actually broke.
     */
    public static function from(SagaOutcome $outcome): self
    {
        return new self($outcome, $outcome->summary(), $outcome->cause);
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

    /**
     * Whether the unwind is incomplete — a compensation failed, so a side effect is
     * outstanding and the saga is still `COMPENSATING`. This is the alert condition.
     */
    public function needsAttention(): bool
    {
        return $this->outcome->needsAttention();
    }

    /**
     * Re-running the saga is always safe: forward actions and compensations are both
     * keyed idempotently, so a retry either resumes the unwind or does nothing.
     */
    public function isRetryable(): bool
    {
        return true;
    }

    /**
     * The step whose forward action failed, when there was one.
     */
    public function failedStep(): ?string
    {
        return $this->outcome->failedStep;
    }

    public function publicMessage(): string
    {
        return self::PUBLIC_MESSAGE;
    }

    public function errorCode(): string
    {
        return self::ERROR_CODE;
    }
}
