<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Models\QuotaHold;

/**
 * What one resume sweep did — the value `QuotaParkingLot::resumeDue()` returns and the
 * scheduled command prints (Req 20.3 / C3).
 *
 * Every claimed hold lands in exactly one bucket, and the buckets are the four answers the
 * re-check can give, plus the two ways a sweep can decline to act:
 *
 * | Bucket | Meaning | Next |
 * |---|---|---|
 * | `resumed` | the allowance covers it and the owner has the work back | done |
 * | `deferred` | still exhausted for the current period | re-checked when that period rolls |
 * | `blocked` | the refusal is no longer transient (a downgrade, a limit set to 0) | waits for a plan change or top-up — **never** auto-resumed |
 * | `failed` | the hand-back threw, or its resumer is not registered | stays parked, retried with backoff |
 * | `contended` | another worker claimed it first | that worker's problem, not ours |
 * | `orphaned` | the owning tenant is gone | hold cancelled |
 *
 * The invariant a test can assert: `claimed === resumed + deferred + blocked + failed +
 * contended + orphaned`, and nothing leaves a sweep un-accounted for — which is how "no
 * queued work is dropped" stays checkable rather than asserted.
 */
final class QuotaResumeReport
{
    private int $claimed = 0;

    private int $resumed = 0;

    private int $deferred = 0;

    private int $blocked = 0;

    private int $failed = 0;

    private int $contended = 0;

    private int $orphaned = 0;

    private int $pruned = 0;

    private int $pulledForward = 0;

    /**
     * @var list<string>
     */
    private array $resumedHolds = [];

    public function recordClaimAttempt(): void
    {
        $this->claimed++;
    }

    public function recordResumed(QuotaHold $hold): void
    {
        $this->resumed++;
        $this->resumedHolds[] = $hold->id;
    }

    public function recordDeferred(): void
    {
        $this->deferred++;
    }

    public function recordBlocked(): void
    {
        $this->blocked++;
    }

    public function recordFailed(): void
    {
        $this->failed++;
    }

    public function recordContended(): void
    {
        $this->contended++;
    }

    public function recordOrphaned(): void
    {
        $this->orphaned++;
    }

    public function recordPruned(int $rows): void
    {
        $this->pruned += max(0, $rows);
    }

    public function recordPulledForward(int $rows): void
    {
        $this->pulledForward += max(0, $rows);
    }

    public function claimed(): int
    {
        return $this->claimed;
    }

    public function resumed(): int
    {
        return $this->resumed;
    }

    public function deferred(): int
    {
        return $this->deferred;
    }

    public function blocked(): int
    {
        return $this->blocked;
    }

    public function failed(): int
    {
        return $this->failed;
    }

    public function contended(): int
    {
        return $this->contended;
    }

    public function orphaned(): int
    {
        return $this->orphaned;
    }

    public function pruned(): int
    {
        return $this->pruned;
    }

    public function pulledForward(): int
    {
        return $this->pulledForward;
    }

    /**
     * The holds handed back, so a caller (or a test) can name them rather than count them.
     *
     * @return list<string>
     */
    public function resumedHolds(): array
    {
        return $this->resumedHolds;
    }

    /**
     * Whether the sweep found nothing at all to do — the normal case, once a minute.
     */
    public function isEmpty(): bool
    {
        return $this->claimed === 0 && $this->pruned === 0 && $this->pulledForward === 0;
    }

    /**
     * Log / command-output shape.
     *
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'claimed' => $this->claimed,
            'resumed' => $this->resumed,
            'deferred' => $this->deferred,
            'blocked' => $this->blocked,
            'failed' => $this->failed,
            'contended' => $this->contended,
            'orphaned' => $this->orphaned,
            'pruned' => $this->pruned,
            'pulled_forward' => $this->pulledForward,
        ];
    }
}
