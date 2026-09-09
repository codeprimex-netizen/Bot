<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Enums\AuditChainDefect;
use App\Exceptions\Audit\AuditChainBusyException;
use App\Exceptions\Audit\AuditPayloadException;
use App\Models\AuditLog;
use App\Models\Builders\TenantScopedBuilder;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use App\Support\Audit\AuditPayloadNormalizer;
use App\Support\Audit\AuditPayloadRedactor;
use App\Support\Audit\CanonicalSerializer;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The hash-chained audit trail: `row_hash = SHA-256(prev_hash || canonical(entry))`
 * (Req 24.2, 24.5 / D1; Req 34.1 / NFR5; Correctness Property 17).
 *
 * ## Per-tenant chains, plus one platform chain — and why
 *
 * Entries are chained per `chain_key` (`tenant_id`, or `platform` when no tenant is
 * involved) rather than in one platform-wide chain. A single global chain is the
 * stronger construction in the abstract — it would also witness the *relative order*
 * of two different tenants' entries — and it was rejected for three concrete reasons:
 *
 * 1. **Verification must not require the bypass it audits.** Reading a global chain
 *    means reading across tenants, which means `TenantContext::asPlatform()` — and
 *    that emits `PlatformModeEntered`, which this very service records. Verifying the
 *    chain would therefore *append to the chain being verified*, and the fail-closed
 *    `TenantScope` would stop a tenant-scoped caller from ever verifying its own
 *    history. Per-tenant chains are verifiable inside the tenant's own scope, with no
 *    bypass in sight.
 * 2. **Right-to-delete and a global chain are incompatible.** Offboarding a tenant
 *    eventually purges its rows (Req 28.2 / D5). In a global chain that punches a
 *    permanent, unrepairable hole, and every verification afterwards reports tampering
 *    that never happened — which is how an integrity control ends up switched off for
 *    being noisy. With per-tenant chains a departing tenant takes *its own* chain with
 *    it and every other chain still verifies, untouched.
 * 3. **Append contention stays per tenant.** Chain positions are serialized, so one
 *    global chain would serialize every audited action on the platform behind a single
 *    lock: a busy tenant would throttle everyone else's admin actions, the opposite of
 *    the noisy-neighbour bound of Req 30.2 / NFR1.
 *
 * What is given up — cross-chain ordering — is recovered where it matters by
 * `created_at` (inside the hash, so it cannot be edited undetected) and by the
 * platform chain itself, which records every scope bypass and admin action in one
 * place.
 *
 * ## Concurrency: two writers cannot fork a chain
 *
 * Position `n+1` has exactly one valid predecessor, so an append is a
 * read-tip-then-insert race. It is closed twice:
 *
 * - a cache lock per chain (`audit:chain:{key}`) serializes appends in the common
 *   case, across processes and queue workers;
 * - `unique(chain_key, sequence)` makes the **database** the final arbiter: a writer
 *   that raced through anyway (no lock driver, a lock expiring under load, two cache
 *   stores) loses its insert and retries from the new tip.
 *
 * The lock is an optimization; the unique index is the guarantee. Neither drops an
 * entry silently — exhausting the retries raises `AuditChainBusyException`, because a
 * missing audit row is a missing record of a privileged action.
 *
 * ## What the chain does and does not prove
 *
 * It proves no row was **altered, moved, duplicated, or removed from the middle**:
 * every such tamper leaves a break that `verify()` finds and localizes. It cannot,
 * alone, prove that rows were not removed from the **end** (a shorter chain is still
 * internally consistent), nor stop an attacker with full write access from recomputing
 * every row. Those are the other two layers' job: the `REVOKE UPDATE, DELETE` grants
 * and trigger guards of `App\Support\Database\AppendOnlyTable`, which stop the
 * rewrite, and exported `AuditChainAnchor`s, which make truncation visible.
 */
final class HashChainAuditService implements AuditService
{
    /**
     * The stored timestamp format: microsecond precision, hashed as this exact string
     * so a verifier reproduces it byte for byte from the stored value.
     */
    private const string TIMESTAMP_FORMAT = 'Y-m-d H:i:s.u';

    /**
     * How many times an append re-reads the tip after losing the position race.
     */
    private const int MAX_APPEND_ATTEMPTS = 5;

    /**
     * Rows per query while walking a chain: bounded memory, bounded round-trips.
     */
    private const int VERIFY_CHUNK = 1000;

    public function __construct(
        private readonly TenantContext $context,
        private readonly ConnectionInterface $connection,
        private readonly CacheFactory $cache,
        private readonly CanonicalSerializer $serializer,
        private readonly AuditPayloadNormalizer $normalizer,
        private readonly AuditPayloadRedactor $redactor,
        private readonly int $lockSeconds = 5,
    ) {}

    public function write(
        string $action,
        array $payload = [],
        Model|AuditSubject|string|null $subject = null,
        ?AuditActor $actor = null,
        Tenant|string|null $tenant = null,
    ): AuditLog {
        return $this->append(
            $this->draft($action, $payload, $subject, $actor, $this->resolveTenantId($tenant, $subject)),
        );
    }

    public function writeForPlatform(
        string $action,
        array $payload = [],
        Model|AuditSubject|string|null $subject = null,
        ?AuditActor $actor = null,
    ): AuditLog {
        return $this->append($this->draft($action, $payload, $subject, $actor, null));
    }

    public function verify(Tenant|string|null $tenant = null, ?AuditChainAnchor $anchor = null): AuditChainVerification
    {
        return $this->verifyChain($this->chainKeyFor($tenant), $anchor);
    }

    public function verifyChain(string $chainKey, ?AuditChainAnchor $anchor = null): AuditChainVerification
    {
        /** @var list<AuditChainFinding> $findings */
        $findings = [];
        $expectedSequence = 1;
        $expectedPrevHash = AuditLog::GENESIS_HASH;
        $checked = 0;
        $tipSequence = 0;
        $tipHash = AuditLog::GENESIS_HASH;

        while (true) {
            $entries = $this->chainQuery($chainKey)
                ->where('sequence', '>', $tipSequence)
                ->orderBy('sequence')
                ->limit(self::VERIFY_CHUNK)
                ->get();

            foreach ($entries as $entry) {
                $checked++;
                $tipSequence = $entry->sequence;
                $tipHash = $entry->row_hash;

                foreach ($this->findingsFor($entry, $chainKey, $expectedSequence, $expectedPrevHash) as $finding) {
                    $findings[] = $finding;
                }

                // Advance from what is *stored*, so a single altered row is reported
                // once instead of cascading into every row after it.
                $expectedPrevHash = $entry->row_hash;
                $expectedSequence = $entry->sequence + 1;
            }

            if ($entries->count() < self::VERIFY_CHUNK) {
                break;
            }
        }

        foreach ($this->anchorFindings($chainKey, $anchor, $tipSequence, $tipHash) as $finding) {
            $findings[] = $finding;
        }

        return new AuditChainVerification($chainKey, $checked, $findings, $tipSequence, $tipHash);
    }

    public function anchor(Tenant|string|null $tenant = null): AuditChainAnchor
    {
        $chainKey = $this->chainKeyFor($tenant);
        $tip = $this->tip($chainKey);

        return new AuditChainAnchor(
            $chainKey,
            $tip->sequence ?? 0,
            $tip->row_hash ?? AuditLog::GENESIS_HASH,
            Carbon::now(),
        );
    }

    public function chainKeyFor(Tenant|string|null $tenant): string
    {
        return AuditLog::chainKeyFor($this->tenantIdOf($tenant));
    }

    /*
    |--------------------------------------------------------------------------
    | Appending
    |--------------------------------------------------------------------------
    */

    /**
     * Everything that does not depend on the chain position — done once, before the
     * lock, so a retry costs one SELECT and one INSERT.
     *
     * @param  array<array-key, mixed>  $payload
     */
    private function draft(
        string $action,
        array $payload,
        Model|AuditSubject|string|null $subject,
        ?AuditActor $actor,
        ?string $tenantId,
    ): AuditEntryDraft {
        $prepared = $this->preparePayload($payload);

        return new AuditEntryDraft(
            AuditLog::chainKeyFor($tenantId),
            $tenantId,
            $action,
            AuditSubject::from($subject),
            $actor ?? AuditActor::fromAuth($this->context->actingAsPlatform()),
            AuditCorrelation::capture(),
            $prepared['json'],
            $prepared['value'],
        );
    }

    private function append(AuditEntryDraft $draft): AuditLog
    {
        $lock = $this->lockFor($draft->chainKey);

        try {
            for ($attempt = 1; $attempt <= self::MAX_APPEND_ATTEMPTS; $attempt++) {
                $tip = $this->tip($draft->chainKey);
                $row = $this->buildRow($draft, $tip->sequence ?? 0, $tip->row_hash ?? AuditLog::GENESIS_HASH);

                try {
                    $this->connection->table($this->table())->insert($row);
                } catch (UniqueConstraintViolationException) {
                    // Another writer took this position (or, on `row_hash`, this exact
                    // entry already exists). Re-read the tip and chain onto it.
                    continue;
                }

                return $this->hydrate($row, $draft);
            }
        } finally {
            $lock?->release();
        }

        throw AuditChainBusyException::positionContended($draft->chainKey, self::MAX_APPEND_ATTEMPTS);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRow(AuditEntryDraft $draft, int $tipSequence, string $prevHash): array
    {
        $record = [
            'id' => (string) Str::ulid(),
            'chain_key' => $draft->chainKey,
            'sequence' => $tipSequence + 1,
            'tenant_id' => $draft->tenantId,
            'action' => $draft->action,
            'subject_type' => $draft->subject?->type,
            'subject_id' => $draft->subject?->id,
            'payload' => $draft->payload,
            'actor_type' => $draft->actor->type->value,
            'actor_id' => $draft->actor->id,
            'actor_label' => $draft->actor->label,
            'request_id' => $draft->correlation->requestId,
            'trace_id' => $draft->correlation->traceId,
            'ip_address' => $draft->correlation->ipAddress,
            'user_agent' => $draft->correlation->userAgent,
            'created_at' => Carbon::now()->format(self::TIMESTAMP_FORMAT),
        ];

        // The stored row differs from the hashed record in exactly one way: the payload
        // is stored as its JSON text, and hashed as the value that text decodes to.
        $row = $record;
        $row['payload'] = $draft->payloadJson;
        $row['prev_hash'] = $prevHash;
        $row['row_hash'] = $this->hashOf($prevHash, $record);

        return $row;
    }

    /**
     * Redact, normalize, and round-trip the payload through JSON.
     *
     * The round trip is deliberate: the hash must cover what a verifier later *reads
     * back*, so the payload is encoded, decoded, and only then hashed. Anything the
     * JSON layer normalizes (float precision, integer-like keys) is therefore already
     * normalized when the row hash is computed, and verification can never fail for a
     * reason that has nothing to do with tampering.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array{json: string, value: array<array-key, mixed>}
     */
    private function preparePayload(array $payload): array
    {
        $prepared = $this->redactor->redact($this->normalizer->normalize($payload));

        $json = json_encode($prepared, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw AuditPayloadException::notEncodable(json_last_error_msg());
        }

        $decoded = json_decode($json, true);

        return ['json' => $json, 'value' => is_array($decoded) ? $decoded : []];
    }

    /*
    |--------------------------------------------------------------------------
    | Hashing
    |--------------------------------------------------------------------------
    */

    /**
     * `H(prev_hash || canonical(entry))`.
     *
     * `prev_hash` is the *prefix* rather than a field of the record, and that is what
     * makes this a chain: the same entry appended at a different position, or after a
     * different predecessor, hashes differently.
     *
     * Every stored column except `row_hash` itself is inside `canonical(entry)` — not
     * just the `payload` column. Property 17 is written as
     * `H(prev_hash || canonical(payload))`; hashing the whole entry is the strictly
     * stronger reading, and the necessary one: if `action`, `actor_id`, `sequence`, or
     * `created_at` sat outside the hash they could be rewritten without breaking a
     * single link — and "who did what, and when" is most of what an audit trail is for.
     *
     * @param  array<string, mixed>  $record
     */
    private function hashOf(string $prevHash, array $record): string
    {
        return hash('sha256', $prevHash.$this->serializer->encode($record));
    }

    /**
     * The hashed record rebuilt from a stored row — the verifier's side of the mirror.
     *
     * @return array<string, mixed>
     */
    private function recordOf(AuditLog $entry): array
    {
        return [
            'id' => $entry->id,
            'chain_key' => $entry->chain_key,
            'sequence' => $entry->sequence,
            'tenant_id' => $entry->tenant_id,
            'action' => $entry->action,
            'subject_type' => $entry->subject_type,
            'subject_id' => $entry->subject_id,
            'payload' => $entry->payload,
            'actor_type' => $entry->actor_type->value,
            'actor_id' => $entry->actor_id,
            'actor_label' => $entry->actor_label,
            'request_id' => $entry->request_id,
            'trace_id' => $entry->trace_id,
            'ip_address' => $entry->ip_address,
            'user_agent' => $entry->user_agent,
            'created_at' => $entry->created_at->format(self::TIMESTAMP_FORMAT),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Verifying
    |--------------------------------------------------------------------------
    */

    /**
     * Everything wrong with one row, at its position in the walk.
     *
     * @return list<AuditChainFinding>
     */
    private function findingsFor(
        AuditLog $entry,
        string $chainKey,
        int $expectedSequence,
        string $expectedPrevHash,
    ): array {
        $findings = [];

        if ($entry->sequence !== $expectedSequence) {
            // A row is missing or has been moved. The link check is skipped for this
            // row: the gap already explains why it cannot point at its predecessor, and
            // one finding per tamper is what keeps the report readable.
            $findings[] = new AuditChainFinding(
                AuditChainDefect::SequenceGap,
                $chainKey,
                $expectedSequence,
                $entry->id,
                sprintf('entry at position %d', $expectedSequence),
                sprintf('position %d', $entry->sequence),
            );
        } elseif (! hash_equals($expectedPrevHash, $entry->prev_hash)) {
            $findings[] = new AuditChainFinding(
                $expectedSequence === 1 ? AuditChainDefect::GenesisMismatch : AuditChainDefect::PrevHashMismatch,
                $chainKey,
                $entry->sequence,
                $entry->id,
                $expectedPrevHash,
                $entry->prev_hash,
            );
        }

        $recomputed = $this->hashOf($entry->prev_hash, $this->recordOf($entry));

        if (! hash_equals($entry->row_hash, $recomputed)) {
            $findings[] = new AuditChainFinding(
                AuditChainDefect::RowHashMismatch,
                $chainKey,
                $entry->sequence,
                $entry->id,
                $recomputed,
                $entry->row_hash,
            );
        }

        if (! $entry->hasCoherentChainKey()) {
            $findings[] = new AuditChainFinding(
                AuditChainDefect::ChainKeyMismatch,
                $chainKey,
                $entry->sequence,
                $entry->id,
                AuditLog::chainKeyFor($entry->tenant_id),
                $entry->chain_key,
            );
        }

        return $findings;
    }

    /**
     * @return list<AuditChainFinding>
     */
    private function anchorFindings(
        string $chainKey,
        ?AuditChainAnchor $anchor,
        int $tipSequence,
        string $tipHash,
    ): array {
        if ($anchor === null || $anchor->isEmpty()) {
            return [];
        }

        if ($anchor->sequence > $tipSequence) {
            return [new AuditChainFinding(
                AuditChainDefect::TailTruncated,
                $chainKey,
                $anchor->sequence,
                null,
                sprintf('at least %d entries (witnessed anchor)', $anchor->sequence),
                sprintf('%d entries', $tipSequence),
            )];
        }

        $atAnchor = $anchor->sequence === $tipSequence
            ? $tipHash
            : $this->chainQuery($chainKey)->where('sequence', $anchor->sequence)->value('row_hash');

        if (is_string($atAnchor) && ! hash_equals($anchor->rowHash, $atAnchor)) {
            return [new AuditChainFinding(
                AuditChainDefect::AnchorMismatch,
                $chainKey,
                $anchor->sequence,
                null,
                $anchor->rowHash,
                $atAnchor,
            )];
        }

        return [];
    }

    /*
    |--------------------------------------------------------------------------
    | Chain access
    |--------------------------------------------------------------------------
    */

    /**
     * Read one chain by key.
     *
     * `withoutTenantScope()` is the sanctioned, greppable bypass of task 0.3, and it is
     * the right tool here for a reason worth stating: the chain key is supplied by the
     * caller, so the constraint has not been dropped — it has moved from the ambient
     * context into an argument. That matters because appending must behave identically
     * whether a tenant is bound, nothing is bound (console, queue, webhook intake), or
     * platform mode is open — and in the last case the entry being written is *about*
     * that bypass, so it cannot depend on it.
     *
     * @return TenantScopedBuilder<AuditLog>
     */
    private function chainQuery(string $chainKey): TenantScopedBuilder
    {
        return AuditLog::withoutTenantScope()->where('chain_key', $chainKey);
    }

    private function tip(string $chainKey): ?AuditLog
    {
        return $this->chainQuery($chainKey)->orderByDesc('sequence')->first();
    }

    /**
     * The just-inserted row as a model, without a round-trip to read it back.
     *
     * @param  array<string, mixed>  $row
     */
    private function hydrate(array $row, AuditEntryDraft $draft): AuditLog
    {
        $entry = new AuditLog;
        $entry->setRawAttributes([...$row, 'payload' => $draft->payloadJson], sync: true);
        $entry->exists = true;

        return $entry;
    }

    /*
    |--------------------------------------------------------------------------
    | Locking
    |--------------------------------------------------------------------------
    */

    /**
     * Take the chain's write lock, or `null` when the cache store has no lock support.
     *
     * A store without locks is not a reason to refuse an audit write:
     * `unique(chain_key, sequence)` still prevents a fork and the retry loop still
     * converges. The lock only removes the retries.
     */
    private function lockFor(string $chainKey): ?Lock
    {
        $repository = $this->cache->store();
        $store = $repository instanceof CacheRepository ? $repository->getStore() : null;

        if (! $store instanceof LockProvider) {
            return null;
        }

        // TTL comfortably above the wait window, so a crashed writer's lock expires
        // rather than wedging the chain.
        $lock = $store->lock('audit:chain:'.$chainKey, max($this->lockSeconds * 2, 10));

        try {
            $lock->block($this->lockSeconds);
        } catch (LockTimeoutException) {
            throw AuditChainBusyException::lockTimedOut($chainKey, $this->lockSeconds);
        }

        return $lock;
    }

    /*
    |--------------------------------------------------------------------------
    | Resolution helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Which chain an entry belongs to: an explicit tenant, then the subject's tenant,
     * then the acting tenant, and otherwise the platform chain.
     */
    private function resolveTenantId(
        Tenant|string|null $tenant,
        Model|AuditSubject|string|null $subject,
    ): ?string {
        return $this->tenantIdOf($tenant)
            ?? AuditSubject::tenantIdOf($subject)
            ?? $this->context->currentId();
    }

    private function tenantIdOf(Tenant|string|null $tenant): ?string
    {
        if ($tenant instanceof Tenant) {
            return $tenant->id;
        }

        return $tenant === '' ? null : $tenant;
    }

    private function table(): string
    {
        return (new AuditLog)->getTable();
    }
}
