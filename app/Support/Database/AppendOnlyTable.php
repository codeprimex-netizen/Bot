<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Makes a table refuse `UPDATE` and `DELETE` **in the database**, on both engines
 * the platform runs on (Req 24.2 / D1; Req 34.1 / NFR5).
 *
 * ## Why this exists at all
 *
 * Req 34.1 asks for append-only tables enforced by *grants*: the application role
 * holds `INSERT`/`SELECT` and nothing else, so an attacker who owns the application
 * still cannot rewrite history. Grants are the right production control, but they
 * are administered **outside** the schema — `GRANT`/`REVOKE` needs privileges a
 * migration user should not have, they live per environment, and they do not exist
 * at all on SQLite, which is what the test suite runs on. Enforcing the invariant
 * *only* through grants would therefore leave it untested and unenforced everywhere
 * except production.
 *
 * So the rule is installed twice, at two different trust levels:
 *
 * | Layer                                     | Stops                                   | Where |
 * |-------------------------------------------|-----------------------------------------|-------|
 * | `REVOKE UPDATE, DELETE` (see `revokeStatements()`) | the application role, at the privilege level | MySQL production/staging, ops-run |
 * | `BEFORE UPDATE` / `BEFORE DELETE` triggers (this class) | *every* connection, including a DBA's, migrations, and raw SQL | MySQL + SQLite, installed by the migration |
 * | `App\Models\Concerns\AppendOnly`          | Eloquent writes, with a typed message   | every environment |
 *
 * The trigger layer is the one that makes the invariant testable: `pest` runs on
 * SQLite and still proves that a raw `UPDATE` against `audit_logs` fails.
 *
 * ## What it deliberately does not stop
 *
 * - `DROP`/`TRUNCATE`/`ALTER` — DDL, not DML, so no trigger sees it. That is a
 *   grant question (the application role must hold neither), and the reason
 *   `revokeStatements()` is part of the deployment runbook rather than optional.
 * - Cascaded deletes, *inconsistently* — SQLite fires triggers for a foreign-key
 *   cascade, MySQL does not. A guard that holds on one engine and not the other is
 *   worse than no guard, which is why `audit_logs` carries no cascading foreign key at
 *   all: its rows outlive the tenant they describe, and purging them is an explicit
 *   privileged retention action (Req 28.2 / D5) rather than a side effect of deleting
 *   another row. See the `audit_logs` migration for the full reasoning.
 * - An operator who drops the triggers first. Tamper-*evidence* is the hash chain's
 *   job, not this class's; the two are layered on purpose, and the property test for
 *   Correctness Property 17 tampers via `withoutGuards()` precisely to prove the
 *   chain still notices.
 */
final class AppendOnlyTable
{
    /**
     * Error text surfaced by the database when the guard fires. Kept identical on
     * both drivers so callers and tests can match one string.
     */
    public const string MESSAGE = 'append-only table: UPDATE and DELETE are not permitted';

    /**
     * Install the `BEFORE UPDATE` / `BEFORE DELETE` guards for a table.
     *
     * Silently does nothing on a driver with no trigger support, so an exotic test
     * connection cannot break a migration; the model-level guard still applies there.
     */
    public static function guard(string $table, ?Connection $connection = null): void
    {
        $connection ??= DB::connection();

        foreach (self::guardStatements($table, $connection->getDriverName()) as $statement) {
            $connection->unprepared($statement);
        }
    }

    /**
     * Remove the guards.
     *
     * Two legitimate callers, and no others: a migration that has to reshape the
     * table (expand-contract, Req 35.2 / NFR6), and the tamper-detection tests,
     * which must be able to act as an attacker with direct database access.
     */
    public static function unguard(string $table, ?Connection $connection = null): void
    {
        $connection ??= DB::connection();

        foreach (self::triggerNames($table) as $trigger) {
            $connection->unprepared(sprintf('DROP TRIGGER IF EXISTS %s', $trigger));
        }
    }

    /**
     * Run `$callback` with the database guards lifted, then put them back — even if
     * the callback throws.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function withoutGuards(string $table, callable $callback, ?Connection $connection = null): mixed
    {
        self::unguard($table, $connection);

        try {
            return $callback();
        } finally {
            self::guard($table, $connection);
        }
    }

    /**
     * The exact privilege statements an operator runs for this table, so the grant
     * half of Req 34.1 is *executable documentation* rather than prose that drifts.
     *
     * ```sql
     * -- once, after the migration, as an admin user:
     * GRANT SELECT, INSERT ON `wacb`.`audit_logs` TO 'wacb_app'@'%';
     * REVOKE UPDATE, DELETE ON `wacb`.`audit_logs` FROM 'wacb_app'@'%';
     * REVOKE DROP, ALTER, INDEX, TRIGGER ON `wacb`.`audit_logs` FROM 'wacb_app'@'%';
     * FLUSH PRIVILEGES;
     * ```
     *
     * `REVOKE DROP, ALTER, TRIGGER` matters as much as the first two: without it the
     * application role could drop the trigger guard, or drop the table outright, and
     * the `UPDATE`/`DELETE` revocation would be theatre. The migration user is a
     * *separate* role that does hold those privileges.
     *
     * @return list<string>
     */
    public static function revokeStatements(string $table, string $role, string $database, string $host = '%'): array
    {
        $target = sprintf('`%s`.`%s`', $database, $table);
        $grantee = sprintf("'%s'@'%s'", $role, $host);

        return [
            sprintf('GRANT SELECT, INSERT ON %s TO %s;', $target, $grantee),
            sprintf('REVOKE UPDATE, DELETE ON %s FROM %s;', $target, $grantee),
            sprintf('REVOKE DROP, ALTER, INDEX, TRIGGER ON %s FROM %s;', $target, $grantee),
            'FLUSH PRIVILEGES;',
        ];
    }

    /**
     * The trigger DDL for one driver.
     *
     * Public so the MySQL statements — which the test suite's SQLite connection will
     * never execute — are still reviewable and assertable, rather than being a string
     * nobody sees until a production migration runs. An unrecognised driver yields no
     * statements: the model-level guard still applies there.
     *
     * @return list<string>
     */
    public static function guardStatements(string $table, string $driver): array
    {
        return match ($driver) {
            // SIGNAL raises SQLSTATE 45000 ("unhandled user-defined exception"),
            // surfacing as a QueryException. A single-statement body needs no
            // BEGIN/END, so no DELIMITER dance is required through PDO.
            'mysql', 'mariadb' => [
                sprintf(
                    "CREATE TRIGGER %s BEFORE UPDATE ON `%s` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '%s'",
                    self::triggerName($table, 'update'),
                    $table,
                    self::MESSAGE,
                ),
                sprintf(
                    "CREATE TRIGGER %s BEFORE DELETE ON `%s` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '%s'",
                    self::triggerName($table, 'delete'),
                    $table,
                    self::MESSAGE,
                ),
            ],
            'sqlite' => [
                sprintf(
                    'CREATE TRIGGER %s BEFORE UPDATE ON "%s" BEGIN SELECT RAISE(ABORT, %s); END',
                    self::triggerName($table, 'update'),
                    $table,
                    "'".self::MESSAGE."'",
                ),
                sprintf(
                    'CREATE TRIGGER %s BEFORE DELETE ON "%s" BEGIN SELECT RAISE(ABORT, %s); END',
                    self::triggerName($table, 'delete'),
                    $table,
                    "'".self::MESSAGE."'",
                ),
            ],
            default => [],
        };
    }

    /**
     * @return list<string>
     */
    private static function triggerNames(string $table): array
    {
        return [self::triggerName($table, 'update'), self::triggerName($table, 'delete')];
    }

    private static function triggerName(string $table, string $operation): string
    {
        return sprintf('%s_no_%s', $table, $operation);
    }
}
