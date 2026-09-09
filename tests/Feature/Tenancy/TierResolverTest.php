<?php

declare(strict_types=1);

use App\Enums\TenantTier;
use App\Exceptions\Tenancy\UnknownShardConnectionException;
use App\Models\Tenant;
use App\Models\TenantTierAssignment;
use App\Services\Tenancy\ConfiguredTierResolver;
use App\Services\Tenancy\TierResolver;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Tier resolution, lane weights & shard routing (Req 1.6, 1.7 / A1; Req 30.2, 30.6 / NFR1)
|--------------------------------------------------------------------------
| The whole point of the resolver is that moving a tenant up the isolation ladder is
| a value change, never a code change. These tests therefore exercise both halves of
| that claim: the `tenant_tiers` row, and the `config/wa.php` layer underneath it —
| including the case that matters today, where no shard is configured at all and every
| tenant must resolve to the shared connection exactly as before.
|
| None of these tests bind a tenant context: the scheduler and shard router ask about
| tenants they are not acting as, so resolution must work with nothing bound.
*/

/**
 * A resolver with an empty memo, for asserting what the *shared* cache holds.
 */
function freshTierResolver(): ConfiguredTierResolver
{
    return new ConfiguredTierResolver(app(CacheFactory::class));
}

/**
 * Number of queries against `tenant_tiers` while $work runs.
 */
function countsTierQueries(Closure $work): int
{
    $queries = 0;

    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        if (str_contains($query->sql, 'tenant_tiers')) {
            $queries++;
        }
    });

    $work();

    return $queries;
}

/**
 * Register a database connection under $name, so shard routing has somewhere to point.
 */
function defineShardConnection(string $name): void
{
    config(['database.connections.'.$name => config('database.connections.sqlite')]);
}

it('puts a tenant with no tier row on the configured default tier and weight', function (): void {
    $tenant = Tenant::factory()->create();
    $resolver = app(TierResolver::class);

    expect($resolver->tierOf($tenant))->toBe(TenantTier::Shared)
        ->and($resolver->laneWeight($tenant))->toBe(1)
        ->and($resolver->connection($tenant))->toBeNull()
        ->and($resolver->shardKey($tenant))->toBeNull();
});

it('resolves the tier from the tenant row and the weight from that tier', function (): void {
    $tenant = Tenant::factory()->create();
    TenantTierAssignment::factory()->dedicatedWorkers()->create(['tenant_id' => $tenant->id]);

    $resolver = app(TierResolver::class);

    expect($resolver->tierOf($tenant))->toBe(TenantTier::DedicatedWorker)
        // No pinned weight, so the tier's configured default applies.
        ->and($resolver->laneWeight($tenant))->toBe(5)
        // Own workers, still the shared database — the hybrid resting state.
        ->and($resolver->connection($tenant))->toBeNull();
});

it('honours a lane weight pinned on the tenant row', function (): void {
    $tenant = Tenant::factory()->create();
    TenantTierAssignment::factory()->dedicatedWorkers()->withLaneWeight(37)->create(['tenant_id' => $tenant->id]);

    expect(app(TierResolver::class)->laneWeight($tenant))->toBe(37);
});

it('clamps a lane weight into [1, lane_weight_max]', function (): void {
    $greedy = Tenant::factory()->create();
    $zeroed = Tenant::factory()->create();

    TenantTierAssignment::factory()->withLaneWeight(10_000)->create(['tenant_id' => $greedy->id]);
    TenantTierAssignment::factory()->withLaneWeight(0)->create(['tenant_id' => $zeroed->id]);

    config(['wa.tenancy.tiers.lane_weight_max' => 100]);

    $resolver = app(TierResolver::class);

    // Ceiling bounds one tenant's share of a window (Req 30.6); the floor of 1 means
    // a weight can be lowered but never zeroed, so no tenant is starved (Req 1.7).
    expect($resolver->laneWeight($greedy))->toBe(100)
        ->and($resolver->laneWeight($zeroed))->toBe(1);
});

it('reshapes the whole fairness curve from config without touching a row', function (): void {
    $shared = Tenant::factory()->create();
    $dedicated = Tenant::factory()->create();
    TenantTierAssignment::factory()->dedicatedWorkers()->create(['tenant_id' => $dedicated->id]);

    $resolver = app(TierResolver::class);

    expect($resolver->laneWeight($shared))->toBe(1)
        ->and($resolver->laneWeight($dedicated))->toBe(5);

    config(['wa.tenancy.tiers.lane_weights' => [
        TenantTier::Shared->value => 2,
        TenantTier::DedicatedWorker->value => 20,
    ]]);

    // Same resolver instance, same rows, no cache clear: the config layer is read on
    // every call precisely so a flip is immediate.
    expect($resolver->laneWeight($shared))->toBe(2)
        ->and($resolver->laneWeight($dedicated))->toBe(20);
});

it('flips the platform default tier in config', function (): void {
    $tenant = Tenant::factory()->create();
    $resolver = app(TierResolver::class);

    expect($resolver->tierOf($tenant))->toBe(TenantTier::Shared);

    config(['wa.tenancy.tiers.default' => TenantTier::DedicatedWorker->value]);

    expect($resolver->tierOf($tenant))->toBe(TenantTier::DedicatedWorker)
        ->and($resolver->laneWeight($tenant))->toBe(5);

    // A nonsense value falls back to SHARED rather than throwing during dispatch.
    config(['wa.tenancy.tiers.default' => 'PLATINUM']);

    expect($resolver->tierOf($tenant))->toBe(TenantTier::Shared);
});

it('lets a config override outrank a tenant row, by id or by slug', function (): void {
    $byId = Tenant::factory()->create(['slug' => 'acme', 'subdomain' => 'acme']);
    $bySlug = Tenant::factory()->create(['slug' => 'globex', 'subdomain' => 'globex']);

    TenantTierAssignment::factory()->dedicatedWorkers()->create(['tenant_id' => $byId->id]);

    config(['wa.tenancy.tiers.overrides' => [
        $byId->id => TenantTier::Shared->value,
        'globex' => 'dedicated-db',
    ]]);

    $resolver = app(TierResolver::class);

    expect($resolver->tierOf($byId))->toBe(TenantTier::Shared)
        // The row's pinned weight is a per-tenant decision and survives the override;
        // only the tier itself is overridden.
        ->and($resolver->laneWeight($byId))->toBe(1)
        ->and($resolver->tierOf($bySlug))->toBe(TenantTier::DedicatedDb);
});

it('keeps every non-dedicated-db tenant on the shared connection', function (): void {
    $shared = Tenant::factory()->create();
    $workers = Tenant::factory()->create();

    // A shard pin on a tenant that is not on the dedicated-database tier is inert.
    TenantTierAssignment::factory()->dedicatedWorkers()->create([
        'tenant_id' => $workers->id,
        'shard_key' => 'shard-eu-1',
        'dedicated_conn' => ['connection' => 'nope_not_configured'],
    ]);

    config(['wa.tenancy.tiers.shard.connections' => ['shard-eu-1' => 'nope_not_configured']]);

    $resolver = app(TierResolver::class);

    expect($resolver->connection($shared))->toBeNull()
        ->and($resolver->connection($workers))->toBeNull();
});

it('routes a dedicated-database tenant to the connection named on its row', function (): void {
    defineShardConnection('tenant_shard_eu');

    $tenant = Tenant::factory()->create();
    TenantTierAssignment::factory()->dedicatedDatabase('tenant_shard_eu')->create(['tenant_id' => $tenant->id]);

    $resolver = app(TierResolver::class);

    expect($resolver->tierOf($tenant))->toBe(TenantTier::DedicatedDb)
        ->and($resolver->connection($tenant))->toBe('tenant_shard_eu')
        ->and($resolver->laneWeight($tenant))->toBe(10);
});

it('maps a shard key through the configured shard map', function (): void {
    defineShardConnection('tenant_shard_apac');

    $tenant = Tenant::factory()->create();
    TenantTierAssignment::factory()
        ->dedicatedDatabase(null, 'shard-apac-1')
        ->inRegion('ap-south-1')
        ->create(['tenant_id' => $tenant->id]);

    config(['wa.tenancy.tiers.shard.connections' => ['shard-apac-1' => 'tenant_shard_apac']]);

    $resolver = app(TierResolver::class);

    expect($resolver->shardKey($tenant))->toBe('shard-apac-1')
        ->and($resolver->connection($tenant))->toBe('tenant_shard_apac');
});

it('falls back to a deterministic consistent hash over the configured ring', function (): void {
    defineShardConnection('tenant_shard_a');
    defineShardConnection('tenant_shard_b');
    config(['wa.tenancy.tiers.shard.ring' => ['tenant_shard_a', 'tenant_shard_b']]);

    $tenant = Tenant::factory()->create();
    TenantTierAssignment::factory()->dedicatedDatabase()->create(['tenant_id' => $tenant->id]);

    $first = app(TierResolver::class)->connection($tenant);

    expect($first)->toBeIn(['tenant_shard_a', 'tenant_shard_b'])
        // Stable across calls and across instances: a tenant is never routed to two
        // different databases.
        ->and(app(TierResolver::class)->connection($tenant))->toBe($first)
        ->and(freshTierResolver()->connection($tenant))->toBe($first);
});

it('keeps a dedicated-database tenant on the shared connection while no shard exists yet', function (): void {
    $tenant = Tenant::factory()->create();
    TenantTierAssignment::factory()->dedicatedDatabase()->create(['tenant_id' => $tenant->id]);

    // The state every single-database install is in: the tier is real (own workers,
    // own lane weight), the cutover target simply is not configured yet.
    expect(config('wa.tenancy.tiers.shard.connections'))->toBe([])
        ->and(config('wa.tenancy.tiers.shard.ring'))->toBe([])
        ->and(app(TierResolver::class)->connection($tenant))->toBeNull();
});

it('refuses to fall back to the shared database when a named shard connection does not exist', function (): void {
    $tenant = Tenant::factory()->create();
    TenantTierAssignment::factory()->dedicatedDatabase('tenant_shard_missing')->create(['tenant_id' => $tenant->id]);

    // Silently reading another population's rows would be worse than failing, so a
    // typo in the shard map is an operator error, not a fallback.
    expect(fn (): ?string => app(TierResolver::class)->connection($tenant))
        ->toThrow(UnknownShardConnectionException::class);
});

it('caches the tier lookup and shares it across resolver instances', function (): void {
    $tenant = Tenant::factory()->create();
    TenantTierAssignment::factory()->dedicatedWorkers()->create(['tenant_id' => $tenant->id]);

    $resolver = freshTierResolver();

    $queries = countsTierQueries(function () use ($resolver, $tenant): void {
        expect($resolver->tierOf($tenant))->toBe(TenantTier::DedicatedWorker)
            ->and($resolver->laneWeight($tenant))->toBe(5)
            ->and($resolver->connection($tenant))->toBeNull();
    });

    expect($queries)->toBe(1);

    // A second instance (a new request, a new worker tick) reads the shared cache.
    $second = countsTierQueries(function () use ($tenant): void {
        expect(freshTierResolver()->tierOf($tenant))->toBe(TenantTier::DedicatedWorker);
    });

    expect($second)->toBe(0);
});

it('caches the absence of a tier row too', function (): void {
    $tenant = Tenant::factory()->create();

    $resolver = freshTierResolver();
    $resolver->tierOf($tenant);

    $queries = countsTierQueries(function () use ($tenant): void {
        expect(freshTierResolver()->tierOf($tenant))->toBe(TenantTier::Shared);
    });

    expect($queries)->toBe(0);
});

it('invalidates the cache automatically when a tier row is written or deleted', function (): void {
    $tenant = Tenant::factory()->create();
    $resolver = app(TierResolver::class);

    expect($resolver->tierOf($tenant))->toBe(TenantTier::Shared);

    $assignment = TenantTierAssignment::factory()->dedicatedWorkers()->create(['tenant_id' => $tenant->id]);

    expect($resolver->tierOf($tenant))->toBe(TenantTier::DedicatedWorker)
        ->and(freshTierResolver()->tierOf($tenant))->toBe(TenantTier::DedicatedWorker);

    $assignment->update(['tier' => TenantTier::DedicatedDb, 'lane_weight' => 12]);

    expect($resolver->tierOf($tenant))->toBe(TenantTier::DedicatedDb)
        ->and($resolver->laneWeight($tenant))->toBe(12);

    $assignment->delete();

    expect($resolver->tierOf($tenant))->toBe(TenantTier::Shared)
        ->and($resolver->laneWeight($tenant))->toBe(1);
});

it('drops every tenant from the cache on flush', function (): void {
    $acme = Tenant::factory()->create(['slug' => 'acme', 'subdomain' => 'acme']);
    $globex = Tenant::factory()->create(['slug' => 'globex', 'subdomain' => 'globex']);

    $resolver = app(TierResolver::class);

    expect($resolver->tierOf($acme))->toBe(TenantTier::Shared)
        ->and($resolver->tierOf($globex))->toBe(TenantTier::Shared);

    // Written behind the model, so only an explicit flush can be what makes the
    // resolver notice (an out-of-band migration or a direct SQL fix-up).
    foreach ([$acme, $globex] as $tenant) {
        DB::table('tenant_tiers')->insert([
            'tenant_id' => $tenant->id,
            'tier' => TenantTier::DedicatedWorker->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    expect($resolver->tierOf($acme))->toBe(TenantTier::Shared)
        ->and($resolver->tierOf($globex))->toBe(TenantTier::Shared);

    $resolver->flush();

    expect($resolver->tierOf($acme))->toBe(TenantTier::DedicatedWorker)
        ->and($resolver->tierOf($globex))->toBe(TenantTier::DedicatedWorker)
        ->and(freshTierResolver()->tierOf($acme))->toBe(TenantTier::DedicatedWorker);
});

it('forgets one tenant without disturbing another', function (): void {
    $acme = Tenant::factory()->create(['slug' => 'acme', 'subdomain' => 'acme']);
    $globex = Tenant::factory()->create(['slug' => 'globex', 'subdomain' => 'globex']);

    $resolver = app(TierResolver::class);
    $resolver->tierOf($acme);
    $resolver->tierOf($globex);

    DB::table('tenant_tiers')->insert([
        ['tenant_id' => $acme->id, 'tier' => TenantTier::DedicatedDb->value],
        ['tenant_id' => $globex->id, 'tier' => TenantTier::DedicatedDb->value],
    ]);

    $resolver->forget($acme);

    expect($resolver->tierOf($acme))->toBe(TenantTier::DedicatedDb)
        ->and($resolver->tierOf($globex))->toBe(TenantTier::Shared);

    $resolver->forget($globex->id);

    expect($resolver->tierOf($globex))->toBe(TenantTier::DedicatedDb);
});

it('resolves without caching when caching is turned off', function (): void {
    config(['wa.tenancy.tiers.cache.enabled' => false]);

    $tenant = Tenant::factory()->create();
    TenantTierAssignment::factory()->dedicatedWorkers()->create(['tenant_id' => $tenant->id]);

    $queries = countsTierQueries(function () use ($tenant): void {
        expect(freshTierResolver()->tierOf($tenant))->toBe(TenantTier::DedicatedWorker)
            ->and(freshTierResolver()->tierOf($tenant))->toBe(TenantTier::DedicatedWorker);
    });

    // One query per instance — the per-instance memo still holds within a request.
    expect($queries)->toBe(2);
});
