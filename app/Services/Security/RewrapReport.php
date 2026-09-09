<?php

declare(strict_types=1);

namespace App\Services\Security;

/**
 * What one master-key re-wrap sweep did, per store (Req 32.6 / NFR3).
 *
 * Three numbers per table, and the third is the one an operator is on the hook for:
 *
 * | Bucket | Meaning | Next |
 * |---|---|---|
 * | `rewrapped` | rows now sealed under the active master key | done |
 * | `failed` | rows that would not open, and were therefore left byte-for-byte untouched | **needs a human, while the old master key still exists** |
 * | `pending` | rows still sealed under an older key after this sweep | picked up by the next run |
 *
 * A non-zero `failed` is the alert. It means the master key those rows were sealed under
 * is no longer resolvable, so the sweep cannot move them — and retiring the old key from
 * the key store at that point makes the loss permanent. `pending` is normal: sweeps are
 * batched on purpose so a rotation cannot turn into an unbounded run of KMS calls.
 */
final class RewrapReport
{
    /**
     * @var array<string, array{rewrapped: int, failed: int, pending: int}>
     */
    private array $stores = [];

    /**
     * Record one store's contribution.
     */
    public function record(string $label, int $rewrapped, int $failed, int $pending): void
    {
        $this->stores[$label] = [
            'rewrapped' => max(0, $rewrapped),
            'failed' => max(0, $failed),
            'pending' => max(0, $pending),
        ];
    }

    public function rewrapped(): int
    {
        return $this->sum('rewrapped');
    }

    /**
     * Rows that could not be opened. Non-zero means the rotation is **not** complete and
     * the previous master key must stay available.
     */
    public function failed(): int
    {
        return $this->sum('failed');
    }

    /**
     * Rows still sealed under an older master key when the sweep stopped.
     */
    public function pending(): int
    {
        return $this->sum('pending');
    }

    /**
     * Whether every store was already fully sealed under the active key — the steady
     * state, and the one case the scheduled command stays quiet about.
     */
    public function isEmpty(): bool
    {
        return $this->rewrapped() === 0 && $this->failed() === 0 && $this->pending() === 0;
    }

    /**
     * Whether the rotation is finished: everything is under the active key and nothing
     * refused to move. Only then is it safe to retire the previous master key.
     */
    public function isComplete(): bool
    {
        return $this->failed() === 0 && $this->pending() === 0;
    }

    /**
     * @return array<string, array{rewrapped: int, failed: int, pending: int}>
     */
    public function perStore(): array
    {
        return $this->stores;
    }

    /**
     * Audit/report payload. Counts and labels only — no key ids, no material.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'rewrapped' => $this->rewrapped(),
            'failed' => $this->failed(),
            'pending' => $this->pending(),
            'stores' => $this->stores,
        ];
    }

    /**
     * @param  'rewrapped'|'failed'|'pending'  $bucket
     */
    private function sum(string $bucket): int
    {
        $total = 0;

        foreach ($this->stores as $counts) {
            $total += $counts[$bucket];
        }

        return $total;
    }
}
