<?php

declare(strict_types=1);

use App\Exceptions\Audit\AppendOnlyViolationException;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Support\Database\AppendOnlyTable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\Audit;

/*
|--------------------------------------------------------------------------
| Append-only enforcement (Req 24.2 / D1; Req 34.1 / NFR5)
|--------------------------------------------------------------------------
| Req 34.1 asks for `audit_logs` to be append-only "no UPDATE/DELETE grant to the app
| role". Grants are administered outside the schema and do not exist on SQLite, which
| is what this suite runs on — so the rule is installed at three levels and each is
| tested on its own terms:
|
|   1. model events        → typed AppendOnlyViolationException  (this file)
|   2. query builder       → same, for the mass paths events never see
|   3. database triggers   → QueryException, even for saveQuietly() and raw SQL
|
| and the MySQL grant statements are asserted to say what the runbook claims they say.
*/

it('refuses to update an entry through the model', function (): void {
    $tenant = Tenant::factory()->create();
    $entry = Audit::service()->write('a', ['v' => 1], tenant: $tenant);

    expect(fn () => $entry->update(['action' => 'rewritten']))
        ->toThrow(AppendOnlyViolationException::class, 'append-only');

    $entry->action = 'rewritten';

    expect(fn () => $entry->save())->toThrow(AppendOnlyViolationException::class);
    expect(DB::table('audit_logs')->where('id', $entry->id)->value('action'))->toBe('a');
});

it('refuses to delete an entry through the model', function (): void {
    $tenant = Tenant::factory()->create();
    $entry = Audit::service()->write('a', tenant: $tenant);

    expect(fn () => $entry->delete())->toThrow(AppendOnlyViolationException::class, 'append-only');
    expect(DB::table('audit_logs')->count())->toBe(1);
});

it('refuses the mass update, delete, upsert, and increment paths that fire no events', function (): void {
    $tenant = Tenant::factory()->create();
    Audit::chain(2, $tenant);

    $query = fn () => AuditLog::withoutTenantScope();

    expect(fn () => $query()->update(['action' => 'rewritten']))->toThrow(AppendOnlyViolationException::class)
        ->and(fn () => $query()->delete())->toThrow(AppendOnlyViolationException::class)
        ->and(fn () => $query()->forceDelete())->toThrow(AppendOnlyViolationException::class)
        ->and(fn () => $query()->increment('sequence'))->toThrow(AppendOnlyViolationException::class)
        ->and(fn () => $query()->decrement('sequence'))->toThrow(AppendOnlyViolationException::class)
        ->and(fn () => $query()->upsert([['id' => 'x']], 'id'))->toThrow(AppendOnlyViolationException::class);

    expect(DB::table('audit_logs')->count())->toBe(2);
});

it('refuses to create an entry outside AuditService, where it would have no valid hash', function (): void {
    $tenant = Tenant::factory()->create();

    expect(fn () => AuditLog::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'action' => 'forged']))
        ->toThrow(AppendOnlyViolationException::class, 'AuditService::write()');

    expect(DB::table('audit_logs')->count())->toBe(0);
});

it('blocks a raw UPDATE and a raw DELETE in the database itself', function (): void {
    $tenant = Tenant::factory()->create();
    $entry = Audit::service()->write('a', tenant: $tenant);

    // The layer that holds when nothing goes through Eloquent: a SQL console, a
    // forgotten script, or `saveQuietly()`.
    expect(fn () => DB::table('audit_logs')->where('id', $entry->id)->update(['action' => 'rewritten']))
        ->toThrow(QueryException::class, AppendOnlyTable::MESSAGE)
        ->and(fn () => DB::table('audit_logs')->where('id', $entry->id)->delete())
        ->toThrow(QueryException::class, AppendOnlyTable::MESSAGE);

    expect(DB::table('audit_logs')->where('action', 'a')->count())->toBe(1);
});

it('blocks saveQuietly() and deleteQuietly(), which fire no model events at all', function (): void {
    $tenant = Tenant::factory()->create();
    $entry = Audit::service()->write('a', tenant: $tenant);

    $entry->action = 'rewritten';

    // "Quietly" skips the event hooks — but an instance write is still performed through
    // the model's builder, which is why the builder guard exists as its own layer.
    expect(fn () => $entry->saveQuietly())->toThrow(AppendOnlyViolationException::class)
        ->and(fn () => $entry->deleteQuietly())->toThrow(AppendOnlyViolationException::class);

    expect(DB::table('audit_logs')->where('id', $entry->id)->value('action'))->toBe('a');
});

it('still allows inserts and reads — append-only, not read-only', function (): void {
    $tenant = Tenant::factory()->create();
    Audit::chain(3, $tenant);

    expect(AuditLog::withoutTenantScope()->where('chain_key', $tenant->id)->count())->toBe(3);
});

it('installs both guards on the table and can lift them for maintenance', function (): void {
    $triggers = fn (): array => collect(DB::select(
        "select name from sqlite_master where type = 'trigger' and tbl_name = 'audit_logs' order by name"
    ))->pluck('name')->all();

    expect($triggers())->toBe(['audit_logs_no_delete', 'audit_logs_no_update']);

    AppendOnlyTable::withoutGuards('audit_logs', function () use ($triggers): void {
        expect($triggers())->toBe([]);
    });

    // Put back even if the maintenance callback threw.
    expect($triggers())->toBe(['audit_logs_no_delete', 'audit_logs_no_update']);

    try {
        AppendOnlyTable::withoutGuards('audit_logs', function (): void {
            throw new RuntimeException('maintenance failed');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect($triggers())->toBe(['audit_logs_no_delete', 'audit_logs_no_update']);
});

it('documents the production grant statements it cannot run itself', function (): void {
    $statements = AppendOnlyTable::revokeStatements('audit_logs', 'wacb_app', 'wacb');

    expect($statements)->toHaveCount(4)
        ->and($statements[0])->toBe("GRANT SELECT, INSERT ON `wacb`.`audit_logs` TO 'wacb_app'@'%';")
        ->and($statements[1])->toBe("REVOKE UPDATE, DELETE ON `wacb`.`audit_logs` FROM 'wacb_app'@'%';")
        // Revoking UPDATE/DELETE is theatre if the role can drop the table or the
        // triggers instead.
        ->and($statements[2])->toContain('REVOKE DROP, ALTER, INDEX, TRIGGER');
});

it('emits MySQL trigger DDL that this suite can review but never runs', function (): void {
    // Production is MySQL 8 and the suite is SQLite, so the MySQL statements would
    // otherwise be strings nobody sees until a deployment.
    $mysql = AppendOnlyTable::guardStatements('audit_logs', 'mysql');

    expect($mysql)->toBe([
        "CREATE TRIGGER audit_logs_no_update BEFORE UPDATE ON `audit_logs` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".AppendOnlyTable::MESSAGE."'",
        "CREATE TRIGGER audit_logs_no_delete BEFORE DELETE ON `audit_logs` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".AppendOnlyTable::MESSAGE."'",
    ]);

    expect(AppendOnlyTable::guardStatements('audit_logs', 'sqlite'))->toHaveCount(2)
        // An engine with no trigger support must not break a migration; the model and
        // builder guards still hold there.
        ->and(AppendOnlyTable::guardStatements('audit_logs', 'pgsql'))->toBe([]);
});

it('keeps no updated_at column, because a row is never updated', function (): void {
    expect(Schema::hasColumn('audit_logs', 'created_at'))->toBeTrue()
        ->and(Schema::hasColumn('audit_logs', 'updated_at'))->toBeFalse()
        ->and((new AuditLog)->usesTimestamps())->toBeFalse();
});
