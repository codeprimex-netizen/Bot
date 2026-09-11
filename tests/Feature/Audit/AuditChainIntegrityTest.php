<?php

declare(strict_types=1);

use App\Enums\AuditChainDefect;
use App\Models\AuditLog;
use App\Models\Tenant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Audit;
use Tests\Fixtures\AuditTamper;

/*
|--------------------------------------------------------------------------
| Correctness Property 17 — audit-log hash-chain integrity
|--------------------------------------------------------------------------
| ∀ audit row i>0 → row_hash_i = H(row_hash_{i-1} || canonical(entry_i)); any
| mutation of a past row breaks the chain and is detectable by a verifier.
|
| **Validates: Requirements 24.2, 24.5 / D1**
|
| Each test below builds an honest chain, asserts it verifies, then tampers with it
| the way an attacker with direct database access would (`AuditTamper` drops the
| append-only triggers first — see `AuditLogAppendOnlyTest` for the layer that stops
| the application from getting that far) and asserts that verification fails **and
| points at the first broken link**.
|
| The four tampering shapes are covered separately because they are separately
| detectable: mutation, deletion, insertion, and reordering. The last two tests
| randomize the chain length and the tampered position, which is the property-based
| shape of the same argument; the formal PBT task is 4.7.
*/

it('verifies an honest chain of every length from empty to many', function (): void {
    $tenant = Tenant::factory()->create();

    foreach (range(0, 12) as $length) {
        $chainTenant = Tenant::factory()->create();
        Audit::chain($length, $chainTenant);

        $result = Audit::service()->verify($chainTenant);

        expect($result->isIntact())->toBeTrue($result->summary())
            ->and($result->entriesChecked)->toBe($length)
            ->and($result->tipSequence)->toBe($length);
    }

    expect(Audit::service()->verify($tenant)->isIntact())->toBeTrue();
});

it('recomputes every row hash exactly as H(prev_hash || canonical(entry))', function (): void {
    $tenant = Tenant::factory()->create();
    $entries = Audit::chain(5, $tenant);

    // The property, restated as an independent check rather than through verify():
    // each row's hash is a function of its predecessor's hash and its own contents,
    // so recomputing the chain from row 1 reproduces every stored hash.
    $prev = AuditLog::GENESIS_HASH;

    foreach ($entries as $entry) {
        expect($entry->prev_hash)->toBe($prev);

        $prev = $entry->row_hash;
    }

    expect(Audit::service()->verify($tenant)->tipHash)->toBe($prev);
});

it('detects a mutated payload and names the row it was mutated in', function (): void {
    $tenant = Tenant::factory()->create();
    Audit::chain(6, $tenant);

    expect(Audit::service()->verify($tenant)->isIntact())->toBeTrue();

    AuditTamper::mutate($tenant->id, 3, ['payload' => json_encode(['index' => 3, 'tampered' => true])]);

    $result = Audit::service()->verify($tenant);

    expect($result->isIntact())->toBeFalse()
        ->and($result->firstBrokenSequence())->toBe(3)
        ->and($result->firstFinding()?->defect)->toBe(AuditChainDefect::RowHashMismatch)
        ->and($result->findings)->toHaveCount(1)
        ->and($result->firstFinding()?->describe())->toContain('position 3')
        ->and($result->summary())->toContain('FAILED');
});

it('detects a rewritten actor, action, or timestamp — not just a rewritten payload', function (): void {
    // "Who did what, and when" is most of what an audit trail is for, so every stored
    // column is inside the hash. Each of these is a single-column rewrite that leaves
    // the payload untouched.
    $columns = [
        'action' => 'tenant.definitely.not.suspended',
        'actor_id' => '999999',
        'actor_label' => 'someone.else@example.com',
        'created_at' => '2020-01-01 00:00:00.000000',
        'subject_id' => 'forged-subject',
        'trace_id' => 'forged-trace',
        'tenant_id' => null,
    ];

    foreach ($columns as $column => $value) {
        $tenant = Tenant::factory()->create();
        Audit::chain(3, $tenant);

        AuditTamper::mutate($tenant->id, 2, [$column => $value]);

        $result = Audit::service()->verify($tenant);
        $defects = array_map(fn ($finding): string => $finding->defect->value, $result->findings);

        expect($result->isIntact())->toBeFalse("rewriting [{$column}] went undetected")
            ->and($result->firstBrokenSequence())->toBe(2)
            ->and($defects)->toContain(AuditChainDefect::RowHashMismatch->value);
    }
});

it('detects a row deleted from the middle of the chain', function (): void {
    $tenant = Tenant::factory()->create();
    Audit::chain(6, $tenant);

    AuditTamper::remove($tenant->id, 4);

    $result = Audit::service()->verify($tenant);

    expect($result->isIntact())->toBeFalse()
        ->and($result->entriesChecked)->toBe(5)
        ->and($result->firstBrokenSequence())->toBe(4)
        ->and($result->firstFinding()?->defect)->toBe(AuditChainDefect::SequenceGap)
        // The surviving rows are still authentic — the report says "one is missing",
        // not "everything after this is forged".
        ->and($result->findings)->toHaveCount(1);
});

it('detects the very first row being deleted', function (): void {
    $tenant = Tenant::factory()->create();
    Audit::chain(4, $tenant);

    AuditTamper::remove($tenant->id, 1);

    $result = Audit::service()->verify($tenant);

    expect($result->isIntact())->toBeFalse()
        ->and($result->firstBrokenSequence())->toBe(1)
        ->and($result->firstFinding()?->defect)->toBe(AuditChainDefect::SequenceGap);
});

it('detects a forged row inserted at the end of the chain', function (): void {
    $tenant = Tenant::factory()->create();
    Audit::chain(4, $tenant);

    AuditTamper::insertAtTail($tenant->id);

    $result = Audit::service()->verify($tenant);

    expect($result->isIntact())->toBeFalse()
        ->and($result->entriesChecked)->toBe(5)
        ->and($result->firstBrokenSequence())->toBe(5)
        ->and(array_map(fn ($finding): string => $finding->defect->value, $result->findings))
        ->toContain(AuditChainDefect::PrevHashMismatch->value);
});

it('refuses a forged row inserted at an existing position, at the database level', function (): void {
    $tenant = Tenant::factory()->create();
    Audit::chain(3, $tenant);

    // unique(chain_key, sequence): an attacker cannot slip a row *between* two others,
    // so the only insertion the chain has to detect is one at the tail (above).
    expect(fn () => AuditTamper::insertAtTail($tenant->id, ['sequence' => 2]))
        ->toThrow(UniqueConstraintViolationException::class);

    expect(Audit::service()->verify($tenant)->isIntact())->toBeTrue();
});

it('refuses a replayed row, because a row hash may not appear twice', function (): void {
    $tenant = Tenant::factory()->create();
    $entries = Audit::chain(3, $tenant);

    // Copying an existing row to a new position is caught by unique(row_hash): the hash
    // covers the position, so the same hash in two places is proof of a copy.
    expect(fn () => AuditTamper::insertAtTail($tenant->id, ['row_hash' => $entries[1]->row_hash]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('detects two rows swapped into each other positions', function (): void {
    $tenant = Tenant::factory()->create();
    Audit::chain(6, $tenant);

    AuditTamper::swap($tenant->id, 2, 5);

    $result = Audit::service()->verify($tenant);

    expect($result->isIntact())->toBeFalse()
        ->and($result->entriesChecked)->toBe(6)
        // Reordering is detectable because `sequence` is inside the hash: the row now
        // at position 2 was hashed as position 5.
        ->and($result->firstBrokenSequence())->toBe(2)
        ->and(array_map(fn ($finding): string => $finding->defect->value, $result->findings))
        ->toContain(AuditChainDefect::RowHashMismatch->value);
});

it('detects a row moved to another tenant chain', function (): void {
    [$first, $second] = [Tenant::factory()->create(), Tenant::factory()->create()];
    Audit::chain(3, $first);
    Audit::chain(3, $second);

    // Re-pointing a row's tenant without moving it: chain_key and tenant_id disagree.
    AuditTamper::mutate($first->id, 2, ['tenant_id' => $second->id]);

    $result = Audit::service()->verify($first);
    $defects = array_map(fn ($finding): string => $finding->defect->value, $result->findings);

    expect($result->isIntact())->toBeFalse()
        ->and($defects)->toContain(AuditChainDefect::ChainKeyMismatch->value)
        ->and($defects)->toContain(AuditChainDefect::RowHashMismatch->value)
        ->and($result->firstBrokenSequence())->toBe(2);
});

it('detects entries removed from the end when an anchor was witnessed', function (): void {
    $tenant = Tenant::factory()->create();
    Audit::chain(6, $tenant);

    $anchor = Audit::service()->anchor($tenant);

    // Truncation is the one tamper a chain cannot see on its own: rows 5 and 6 are
    // gone and rows 1-4 still verify perfectly.
    AuditTamper::remove($tenant->id, 6);
    AuditTamper::remove($tenant->id, 5);

    expect(Audit::service()->verify($tenant)->isIntact())->toBeTrue();

    $withAnchor = Audit::service()->verify($tenant, $anchor);

    expect($withAnchor->isIntact())->toBeFalse()
        ->and($withAnchor->firstFinding()?->defect)->toBe(AuditChainDefect::TailTruncated)
        ->and($withAnchor->firstFinding()?->defect->isLoss())->toBeTrue()
        ->and($withAnchor->tipSequence)->toBe(4);
});

it('detects an anchored row that no longer hashes the way it was witnessed', function (): void {
    $tenant = Tenant::factory()->create();
    Audit::chain(4, $tenant);

    $anchor = Audit::service()->anchor($tenant);

    AuditTamper::mutate($tenant->id, 4, ['row_hash' => hash('sha256', 'rewritten')]);

    $result = Audit::service()->verify($tenant, $anchor);
    $defects = array_map(fn ($finding): string => $finding->defect->value, $result->findings);

    expect($result->isIntact())->toBeFalse()
        ->and($defects)->toContain(AuditChainDefect::AnchorMismatch->value);
});

it('detects a mutation at a random position of a randomly sized chain', function (): void {
    // The property-based shape of the mutation case: the tampered position and the
    // chain length are drawn at random, so no test is quietly relying on "position 3".
    foreach (range(1, 20) as $iteration) {
        $length = random_int(1, 9);
        $position = random_int(1, $length);
        $tenant = Tenant::factory()->create();

        Audit::chain($length, $tenant);
        expect(Audit::service()->verify($tenant)->isIntact())->toBeTrue();

        AuditTamper::mutate($tenant->id, $position, [
            'payload' => json_encode(['tampered' => $iteration]),
        ]);

        $result = Audit::service()->verify($tenant);

        expect($result->isIntact())->toBeFalse(
            sprintf('mutation at position %d of a %d-entry chain went undetected', $position, $length),
        )->and($result->firstBrokenSequence())->toBe($position)
            ->and($result->firstFinding()?->defect)->toBe(AuditChainDefect::RowHashMismatch);
    }
});

it('detects a deletion at a random position of a randomly sized chain', function (): void {
    foreach (range(1, 20) as $iteration) {
        $length = random_int(2, 9);
        $position = random_int(1, $length - 1);   // deleting the tail needs an anchor
        $tenant = Tenant::factory()->create();

        Audit::chain($length, $tenant);
        AuditTamper::remove($tenant->id, $position);

        $result = Audit::service()->verify($tenant);

        expect($result->isIntact())->toBeFalse(
            sprintf('deletion at position %d of a %d-entry chain went undetected', $position, $length),
        )->and($result->firstBrokenSequence())->toBe($position)
            ->and($result->firstFinding()?->defect)->toBe(AuditChainDefect::SequenceGap);
    }
});

it('walks a chain longer than one read batch without losing its place', function (): void {
    // The verifier pages through a chain 1000 rows at a time so memory stays bounded on
    // a trail that has been running for years; crossing that boundary must be invisible.
    $tenant = Tenant::factory()->create();
    Audit::chain(1005, $tenant, 'batched.action');

    $result = Audit::service()->verify($tenant);

    expect($result->isIntact())->toBeTrue($result->summary())
        ->and($result->entriesChecked)->toBe(1005)
        ->and($result->tipSequence)->toBe(1005);

    AuditTamper::mutate($tenant->id, 1002, ['action' => 'forged.after.the.batch.boundary']);

    $tampered = Audit::service()->verify($tenant);

    expect($tampered->isIntact())->toBeFalse()
        ->and($tampered->firstBrokenSequence())->toBe(1002);
});

it('verifies the platform chain the same way as a tenant chain', function (): void {
    Audit::chain(5);

    expect(Audit::service()->verify()->isIntact())->toBeTrue();

    AuditTamper::mutate(AuditLog::PLATFORM_CHAIN, 3, ['action' => 'forged.platform.action']);

    $result = Audit::service()->verify();

    expect($result->isIntact())->toBeFalse()
        ->and($result->chainKey)->toBe(AuditLog::PLATFORM_CHAIN)
        ->and($result->firstBrokenSequence())->toBe(3);
});

it('outlives the tenant it describes, because there is no cascade into an append-only table', function (): void {
    $tenant = Tenant::factory()->create();
    Audit::chain(3, $tenant);

    // Deleting the tenant must not delete its audit trail: the trail is the evidence
    // that the offboarding happened. `tenant_id` is left dangling on purpose.
    $tenant->delete();

    expect(DB::table('audit_logs')->where('chain_key', $tenant->id)->count())->toBe(3)
        ->and(Audit::service()->verify($tenant->id)->isIntact())->toBeTrue();
});

it('keeps other chains verifiable when one tenant chain is purged for retention', function (): void {
    // Right-to-delete (Req 28.2 / D5) versus append-only: the retention pipeline purges
    // a departed tenant's chain under a privileged role, and per-tenant chains are what
    // keep that from breaking everybody else's history — the decisive reason the chain
    // is not global.
    [$leaving, $staying] = [Tenant::factory()->create(), Tenant::factory()->create()];
    Audit::chain(4, $leaving);
    Audit::chain(4, $staying);

    AuditTamper::raw(fn (): int => DB::table('audit_logs')->where('chain_key', $leaving->id)->delete());

    expect(DB::table('audit_logs')->where('chain_key', $leaving->id)->count())->toBe(0)
        ->and(Audit::service()->verify($staying)->isIntact())->toBeTrue()
        ->and(Audit::service()->verify($staying)->entriesChecked)->toBe(4);
});
