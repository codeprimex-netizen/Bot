<?php

declare(strict_types=1);

namespace App\Services\Audit;

/**
 * Everything about one audit entry that is known *before* its position in the chain
 * is (Req 24.5 / D1).
 *
 * An append is "read the tip, then insert" — and if it loses that race it retries
 * from the new tip. Splitting the entry from its position is what makes the retry
 * free: redaction, normalization, and JSON encoding happen once, outside the chain
 * lock, and only `sequence`, `prev_hash`, and `row_hash` are recomputed per attempt.
 *
 * @internal to `HashChainAuditService`; callers use `AuditService::write()`.
 */
final readonly class AuditEntryDraft
{
    /**
     * @param  string  $payloadJson  the exact JSON stored in the `payload` column
     * @param  array<array-key, mixed>  $payload  that JSON decoded — what the hash covers, so the
     *                                            digest always describes what a verifier reads back
     */
    public function __construct(
        public string $chainKey,
        public ?string $tenantId,
        public string $action,
        public ?AuditSubject $subject,
        public AuditActor $actor,
        public AuditCorrelation $correlation,
        public string $payloadJson,
        public array $payload,
    ) {}
}
