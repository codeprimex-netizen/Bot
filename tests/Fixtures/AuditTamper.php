<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Models\AuditLog;
use App\Support\Database\AppendOnlyTable;
use Illuminate\Support\Facades\DB;

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
