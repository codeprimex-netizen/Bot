<?php

declare(strict_types=1);

namespace App\Services\Audit;

use Illuminate\Support\Carbon;

/**
 * A witnessed chain tip: "at this time, chain X was N entries long and ended in
 * this hash" (Req 24.5 / D1).
 *
 * ## Why an anchor is needed at all
 *
 * A hash chain proves that nothing *inside* it changed. It cannot, on its own, prove
 * that nothing was cut off the *end* of it: an attacker who deletes the last three
 * rows leaves a chain that is internally perfect, just shorter. Every hash-chained
 * log has this property, and pretending otherwise would be the one dishonest claim
 * in this subsystem.
 *
 * An anchor closes that gap by being **stored somewhere the attacker does not
 * control**: printed into a shift report, pushed to a monitoring system, mailed to
 * the compliance mailbox, or written to append-only object storage by the nightly
 * job. `AuditService::verify()` takes the last one and reports `TailTruncated` if the
 * chain no longer reaches it.
 *
 * Anchors are cheap (three fields) and comparable, so exporting one per chain per day
 * is enough to bound the amount of history an attacker with database access could
 * quietly discard to a single day.
 */
final readonly class AuditChainAnchor
{
    /**
     * @param  int  $sequence  the tip's position, `0` for a chain with no entries
     * @param  string  $rowHash  the tip's `row_hash`, or the genesis hash when empty
     */
    public function __construct(
        public string $chainKey,
        public int $sequence,
        public string $rowHash,
        public ?Carbon $capturedAt = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->sequence === 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'chain_key' => $this->chainKey,
            'sequence' => $this->sequence,
            'row_hash' => $this->rowHash,
            'captured_at' => $this->capturedAt?->toIso8601String(),
        ];
    }

    /**
     * Rebuild an anchor exported earlier (from JSON, a report, or a config value).
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $capturedAt = $data['captured_at'] ?? null;

        return new self(
            (string) ($data['chain_key'] ?? ''),
            (int) ($data['sequence'] ?? 0),
            (string) ($data['row_hash'] ?? ''),
            is_string($capturedAt) && $capturedAt !== '' ? Carbon::parse($capturedAt) : null,
        );
    }
}
