<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Models\AuditLog;
use App\Support\Audit\CanonicalSerializer;
use App\Support\Database\AppendOnlyTable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * An attacker with direct database access — the adversary Correctness Property 17
 * exists for.
 *
 * Tamper-detection tests have to *actually tamper*, which means getting past the
 * append-only guards first. That is not a hole in the design; it is the threat model.
 * The layers are ordered deliberately:
 *
 * - grants and triggers (`AppendOnlyTable`) stop the application, and a careless
 *   operator, from rewriting history at all — tested in `AuditLogAppendOnlyTest`;
 * - the hash chain makes a rewrite that got past them **evident** — tested here.
 *
 * So every method below drops the trigger guards, mutates the table with raw SQL, and
 * puts the guards back. If the chain were verified *before* tampering it must pass,
 * and after tampering it must fail and point at the first broken link.
 */
final class AuditTamper
{
    /**
     * Run raw SQL against `audit_logs` with the database guards lifted.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function raw(callable $callback): mixed
    {
        return AppendOnlyTable::withoutGuards(self::table(), $callback);
    }

    /**
     * Rewrite columns of the row at one chain position.
     *
     * @param  array<string, mixed>  $values
     */
    public static function mutate(string $chainKey, int $sequence, array $values): void
    {
        self::raw(function () use ($chainKey, $sequence, $values): void {
            DB::table(self::table())
                ->where('chain_key', $chainKey)
                ->where('sequence', $sequence)
                ->update($values);
        });
    }

    /**
     * Remove the row at one chain position.
     */
    public static function remove(string $chainKey, int $sequence): void
    {
        self::raw(function () use ($chainKey, $sequence): void {
            DB::table(self::table())
                ->where('chain_key', $chainKey)
                ->where('sequence', $sequence)
                ->delete();
        });
    }

    /**
     * Swap two rows' positions, leaving every other column untouched — the "reorder"
     * tamper. Done via a parking position because `unique(chain_key, sequence)` (quite
     * rightly) refuses the intermediate state.
     */
    public static function swap(string $chainKey, int $first, int $second): void
    {
        self::raw(function () use ($chainKey, $first, $second): void {
            $parking = PHP_INT_MAX;

            self::move($chainKey, $first, $parking);
            self::move($chainKey, $second, $first);
            self::move($chainKey, $parking, $second);
        });
    }

    /**
     * Insert an extra row at the end of a chain, copying an existing row's shape so it
     * looks plausible — the "insertion" tamper.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function insertAtTail(string $chainKey, array $overrides = []): void
    {
        self::raw(function () use ($chainKey, $overrides): void {
            $last = DB::table(self::table())
                ->where('chain_key', $chainKey)
                ->orderByDesc('sequence')
                ->first();

            $row = array_merge((array) $last, [
                'id' => (string) \Illuminate\Support\Str::ulid(),
                'sequence' => (is_object($last) ? (int) $last->sequence : 0) + 1,
                'action' => 'forged.entry',
                'row_hash' => hash('sha256', 'forged'.microtime()),
            ], $overrides);

            DB::table(self::table())->insert($row);
        });
    }

    /**
     * Rewrite a row **and repair its own hash** — the attacker who has read
     * `HashChainAuditService` and knows how a row hash is computed.
     *
     * This is the tamper that decides whether the log is really a *chain*. A row whose
     * hash is recomputed in isolation is internally perfect; only the fact that the
     * *next* row committed to the old hash makes the rewrite visible. If this method
     * could produce a chain that verifies clean, `prev_hash` would not be inside the
     * hash and Property 17 would be false.
     *
     * @param  array<string, mixed>  $values
     */
    public static function mutateAndRehash(string $chainKey, int $sequence, array $values): void
    {
        self::mutate($chainKey, $sequence, $values);
        self::mutate($chainKey, $sequence, ['row_hash' => self::rowHashAt($chainKey, $sequence)]);
    }

    /**
     * The hash the row at this position *would* carry if it had always looked the way
     * it looks now: `SHA-256(prev_hash || canonical(entry))`.
     *
     * Deliberately built from the raw database row rather than from the model, so it
     * reproduces the verifier's arithmetic from the stored bytes the way an outside
     * attacker would have to.
     */
    public static function rowHashAt(string $chainKey, int $sequence): string
    {
        // A read needs no guards lifted: the append-only triggers are BEFORE UPDATE /
        // BEFORE DELETE, and `SELECT` was never the thing being prevented.
        $found = DB::table(self::table())
            ->where('chain_key', $chainKey)
            ->where('sequence', $sequence)
            ->first();

        if ($found === null) {
            throw new RuntimeException(sprintf('No audit row at chain [%s] position %d.', $chainKey, $sequence));
        }

        /** @var array<array-key, mixed> $row */
        $row = (array) $found;
        $payload = json_decode(self::text($row, 'payload') ?? '', true);

        // Mirrors HashChainAuditService::recordOf(): every stored column except
        // `row_hash`, with the payload as the value its JSON decodes to.
        $record = [
            'id' => self::text($row, 'id'),
            'chain_key' => self::text($row, 'chain_key'),
            'sequence' => self::number($row, 'sequence'),
            'tenant_id' => self::text($row, 'tenant_id'),
            'action' => self::text($row, 'action'),
            'subject_type' => self::text($row, 'subject_type'),
            'subject_id' => self::text($row, 'subject_id'),
            'payload' => is_array($payload) ? $payload : [],
            'actor_type' => self::text($row, 'actor_type'),
            'actor_id' => self::text($row, 'actor_id'),
            'actor_label' => self::text($row, 'actor_label'),
            'request_id' => self::text($row, 'request_id'),
            'trace_id' => self::text($row, 'trace_id'),
            'ip_address' => self::text($row, 'ip_address'),
            'user_agent' => self::text($row, 'user_agent'),
            'created_at' => Carbon::parse(self::text($row, 'created_at') ?? '')->format('Y-m-d H:i:s.u'),
        ];

        return hash('sha256', (self::text($row, 'prev_hash') ?? '').(new CanonicalSerializer)->encode($record));
    }

    /**
     * Drop the last `$count` rows of a chain — the one tamper a hash chain cannot see
     * on its own, because what is left is a shorter chain that verifies perfectly.
     * `AuditChainAnchor` exists for exactly this, and the property test says so.
     */
    public static function truncateTail(string $chainKey, int $count): void
    {
        self::raw(function () use ($chainKey, $count): void {
            // Selected then deleted by key: `DELETE ... ORDER BY ... LIMIT` is not
            // portable to SQLite, which is what this suite runs on.
            $doomed = DB::table(self::table())
                ->where('chain_key', $chainKey)
                ->orderByDesc('sequence')
                ->limit($count)
                ->pluck('sequence')
                ->all();

            DB::table(self::table())
                ->where('chain_key', $chainKey)
                ->whereIn('sequence', $doomed)
                ->delete();
        });
    }

    /**
     * @param  array<array-key, mixed>  $row
     */
    private static function text(array $row, string $column): ?string
    {
        $value = $row[$column] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * @param  array<array-key, mixed>  $row
     */
    private static function number(array $row, string $column): int
    {
        $value = $row[$column] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    private static function move(string $chainKey, int $from, int $to): void
    {
        DB::table(self::table())
            ->where('chain_key', $chainKey)
            ->where('sequence', $from)
            ->update(['sequence' => $to]);
    }

    private static function table(): string
    {
        return (new AuditLog)->getTable();
    }
}
