<?php

declare(strict_types=1);

namespace App\Services\Audit;

/**
 * The result of walking one audit chain (Req 24.5 / D1, Correctness Property 17).
 *
 * Returned rather than thrown: a tampered audit log is a *finding to report*, not an
 * exception to bubble. The Admin audit viewer (task 30.6) renders it, the nightly
 * integrity job alerts on it, and neither wants a 500.
 *
 * `firstFinding()` is the answer to the question that actually gets asked — "where
 * does the history stop being trustworthy?" — because everything after the first
 * break is suspect regardless of whether it verifies.
 */
final readonly class AuditChainVerification
{
    /**
     * @param  int  $entriesChecked  rows walked
     * @param  list<AuditChainFinding>  $findings  every break, in chain order
     * @param  int  $tipSequence  the last position present, `0` for an empty chain
     * @param  string  $tipHash  the last `row_hash` present, the genesis hash when empty
     */
    public function __construct(
        public string $chainKey,
        public int $entriesChecked,
        public array $findings,
        public int $tipSequence,
        public string $tipHash,
    ) {}

    /**
     * Whether the chain is intact end to end.
     */
    public function isIntact(): bool
    {
        return $this->findings === [];
    }

    /**
     * The first break — the point history became untrustworthy.
     */
    public function firstFinding(): ?AuditChainFinding
    {
        return $this->findings[0] ?? null;
    }

    /**
     * The position of the first break, or `null` when intact.
     */
    public function firstBrokenSequence(): ?int
    {
        return $this->firstFinding()?->sequence;
    }

    /**
     * A one-line summary for logs and alerts.
     */
    public function summary(): string
    {
        if ($this->isIntact()) {
            return sprintf(
                'Audit chain [%s] is intact: %d entr%s verified, tip at position %d.',
                $this->chainKey,
                $this->entriesChecked,
                $this->entriesChecked === 1 ? 'y' : 'ies',
                $this->tipSequence,
            );
        }

        return sprintf(
            'Audit chain [%s] FAILED verification: %d finding(s) over %d entr%s. First break — %s',
            $this->chainKey,
            count($this->findings),
            $this->entriesChecked,
            $this->entriesChecked === 1 ? 'y' : 'ies',
            $this->firstFinding()?->describe() ?? '',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'chain_key' => $this->chainKey,
            'intact' => $this->isIntact(),
            'entries_checked' => $this->entriesChecked,
            'tip_sequence' => $this->tipSequence,
            'tip_hash' => $this->tipHash,
            'findings' => array_map(
                static fn (AuditChainFinding $finding): array => $finding->toArray(),
                $this->findings,
            ),
        ];
    }
}
