<?php

declare(strict_types=1);

use App\Enums\AuditChainDefect;
use App\Exceptions\Audit\AuditChainBusyException;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Services\Audit\AuditService;
use App\Services\Audit\HashChainAuditService;
use App\Services\Tenancy\TenantContext;
use App\Support\Audit\AuditPayloadNormalizer;
use App\Support\Audit\AuditPayloadRedactor;
use App\Support\Audit\CanonicalSerializer;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Fixtures\Audit;
use Tests\Fixtures\LocklessStore;

/*
|--------------------------------------------------------------------------
| Concurrent appends cannot fork or duplicate a chain position (Req 24.5 / D1)
|--------------------------------------------------------------------------
| Position n+1 has exactly one valid predecessor, so an append is a
| read-tip-then-insert race. Two mechanisms close it, and both are tested here:
|
|   • a per-chain cache lock serializes appends in the common case;
|   • unique(chain_key, sequence) makes the database the final arbiter — a writer that
|     raced through anyway loses the insert and retries from the new tip.
|
| The lock is the fast path; the unique index is the guarantee. Neither is allowed to
| drop an entry silently, because a missing audit row is a missing record of a
| privileged action.
*/

it('never issues the same position twice, even over many appends', function (): void {
    $tenant = Tenant::factory()->create();
    Audit::chain(25, $tenant);

    $sequences = DB::table('audit_logs')->where('chain_key', $tenant->id)->orderBy('sequence')->pluck('sequence')->all();

    expect($sequences)->toBe(range(1, 25))
        ->and(DB::table('audit_logs')->where('chain_key', $tenant->id)->distinct()->count('row_hash'))->toBe(25)
        ->and(Audit::service()->verify($tenant)->isIntact())->toBeTrue();
});

it('serializes appends behind the chain lock and refuses rather than forking when it cannot get it', function (): void {
    $tenant = Tenant::factory()->create();

    // A competing writer (another FPM worker, another queue process) holds the lock.
    $held = Cache::lock('audit:chain:'.$tenant->id, 30);

    expect($held->get())->toBeTrue();

    // With a zero-second wait window the append gives up immediately — and raises,
    // rather than writing an entry that guessed at its own position.
    config()->set('wa.audit.lock_seconds', 0);
    app()->forgetInstance(AuditService::class);

    expect(fn () => app(AuditService::class)->write('contended', tenant: $tenant))
        ->toThrow(AuditChainBusyException::class, 'Could not acquire the audit chain lock');

    expect(DB::table('audit_logs')->count())->toBe(0);

    $held->release();

    // Once the lock is free the very same call succeeds, at position 1.
    expect(app(AuditService::class)->write('contended', tenant: $tenant)->sequence)->toBe(1);
});

it('locks per chain, so one busy tenant cannot block another', function (): void {
    [$busy, $other] = [Tenant::factory()->create(), Tenant::factory()->create()];

    $held = Cache::lock('audit:chain:'.$busy->id, 30);
    expect($held->get())->toBeTrue();

    config()->set('wa.audit.lock_seconds', 0);
    app()->forgetInstance(AuditService::class);

    // The platform chain and every other tenant chain are unaffected — which is the
    // noisy-neighbour argument for chaining per tenant rather than globally.
    expect(app(AuditService::class)->write('fine', tenant: $other)->sequence)->toBe(1)
        ->and(app(AuditService::class)->writeForPlatform('also fine')->sequence)->toBe(1)
        ->and(fn () => app(AuditService::class)->write('blocked', tenant: $busy))
        ->toThrow(AuditChainBusyException::class);

    $held->release();
});

it('re-reads the tip and chains onto the winner when it loses the position race', function (): void {
    $tenant = Tenant::factory()->create();
    $first = Audit::service()->write('first', tenant: $tenant);

    // A competing writer inserts at position 2 *while our append is in flight* — the
    // interleaving a lock would normally prevent, forced here to prove the database
    // backstop and the retry both work.
    $armed = true;
    $competitor = null;

    DB::beforeExecuting(function (string $query) use (&$armed, &$competitor, $tenant, $first): void {
        if (! $armed || ! str_contains($query, 'insert into "audit_logs"')) {
            return;
        }

        $armed = false;
        $competitor = [
            'id' => (string) Str::ulid(),
            'chain_key' => $tenant->id,
            'sequence' => 2,
            'tenant_id' => $tenant->id,
            'action' => 'competitor',
            'payload' => '{}',
            'actor_type' => 'SYSTEM',
            'prev_hash' => $first->row_hash,
            'row_hash' => hash('sha256', 'competitor'),
            'created_at' => now()->format('Y-m-d H:i:s.u'),
        ];

        DB::table('audit_logs')->insert($competitor);
    });

    $second = Audit::service()->write('second', tenant: $tenant);
    $armed = false;

    expect($competitor)->not->toBeNull()
        // Position 2 was taken, so the append took 3 — and linked to what is actually
        // there, not to the tip it originally read.
        ->and($second->sequence)->toBe(3)
        ->and($second->prev_hash)->toBe($competitor['row_hash'])
        ->and(DB::table('audit_logs')->where('chain_key', $tenant->id)->count())->toBe(3);

    // The chain now contains one honest row, one forged row, and one honest row. The
    // verifier blames exactly the forged one.
    $result = Audit::service()->verify($tenant);

    expect($result->isIntact())->toBeFalse()
        ->and($result->findings)->toHaveCount(1)
        ->and($result->firstBrokenSequence())->toBe(2)
        ->and($result->firstFinding()?->defect)->toBe(AuditChainDefect::RowHashMismatch);
});

it('keeps positions unique per chain, not across chains', function (): void {
    [$first, $second] = [Tenant::factory()->create(), Tenant::factory()->create()];

    Audit::chain(3, $first);
    Audit::chain(3, $second);
    Audit::chain(3);

    // Nine rows, three chains, positions 1..3 in each: the unique index is on
    // (chain_key, sequence), so chains advance independently.
    expect(DB::table('audit_logs')->count())->toBe(9)
        ->and(DB::table('audit_logs')->where('sequence', 1)->count())->toBe(3)
        ->and(Audit::service()->verify($first)->isIntact())->toBeTrue()
        ->and(Audit::service()->verify($second)->isIntact())->toBeTrue()
        ->and(Audit::service()->verify()->isIntact())->toBeTrue();
});

it('appends with a cache store that offers no locks at all, leaning on the unique index', function (): void {
    // A store with no lock support must not stop an audit write: the retry loop plus
    // unique(chain_key, sequence) still converge on a correct chain. Built by hand
    // because every store the framework ships *does* support locks.
    $service = new HashChainAuditService(
        app(TenantContext::class),
        DB::connection(),
        new class implements CacheFactory
        {
            public function store($name = null): CacheRepository
            {
                return new CacheRepository(new LocklessStore);
            }
        },
        app(CanonicalSerializer::class),
        app(AuditPayloadNormalizer::class),
        app(AuditPayloadRedactor::class),
    );

    $tenant = Tenant::factory()->create();

    foreach (range(1, 5) as $index) {
        expect($service->write('unlocked', ['i' => $index], tenant: $tenant)->sequence)->toBe($index);
    }

    expect($service->verify($tenant)->isIntact())->toBeTrue()
        ->and(AuditLog::withoutTenantScope()->where('chain_key', $tenant->id)->count())->toBe(5);
});
