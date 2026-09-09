<?php

declare(strict_types=1);

use App\Enums\AuditActorType;
use App\Exceptions\Tenancy\MissingTenantContextException;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\TenantUsage;
use App\Models\User;
use App\Services\Audit\AuditActor;
use App\Services\Audit\AuditChainAnchor;
use App\Services\Audit\AuditSubject;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Audit;

/*
|--------------------------------------------------------------------------
| AuditService::write — the API every later caller uses (Req 24.2 / D1)
|--------------------------------------------------------------------------
| Tasks 21.5 (group settings), 30.5 (impersonation), and the plan/billing and
| compliance screens all audit through this one method, so what is asserted here is
| mostly *ergonomics under the fail-closed tenancy rules*: which chain an entry lands
| on, who it is attributed to, and that none of it depends on ambient state that a
| queue worker or console command would not have.
*/

it('appends an entry to the acting tenant chain and stamps the whole record', function (): void {
    $tenant = Tenant::factory()->create();
    Audit::tenancy()->set($tenant);

    $entry = Audit::service()->write('tenant.settings.changed', ['field' => 'locale', 'from' => 'en', 'to' => 'de']);

    expect($entry->chain_key)->toBe($tenant->id)
        ->and($entry->tenant_id)->toBe($tenant->id)
        ->and($entry->sequence)->toBe(1)
        ->and($entry->action)->toBe('tenant.settings.changed')
        ->and($entry->prev_hash)->toBe(AuditLog::GENESIS_HASH)
        ->and($entry->row_hash)->toHaveLength(64)
        ->and($entry->payload)->toBe(['field' => 'locale', 'from' => 'en', 'to' => 'de'])
        ->and($entry->actor_type)->toBe(AuditActorType::System)
        ->and($entry->created_at->format('Y'))->toBe(now()->format('Y'))
        ->and($entry->isPlatformEntry())->toBeFalse()
        ->and($entry->hasCoherentChainKey())->toBeTrue();

    // The hydrated return value is the row: no round-trip needed, but it must agree
    // with what was actually stored.
    $stored = AuditLog::withoutTenantScope()->findOrFail($entry->id);

    expect($stored->row_hash)->toBe($entry->row_hash)
        ->and($stored->payload)->toBe($entry->payload)
        ->and($stored->created_at->format('Y-m-d H:i:s.u'))->toBe($entry->created_at->format('Y-m-d H:i:s.u'));
});

it('numbers positions 1..N per chain and links each row to the one before it', function (): void {
    $tenant = Tenant::factory()->create();
    Audit::tenancy()->set($tenant);

    $entries = [];

    foreach (range(1, 6) as $index) {
        $entries[] = Audit::service()->write('thing.happened', ['index' => $index]);
    }

    expect(array_map(fn (AuditLog $entry): int => $entry->sequence, $entries))->toBe([1, 2, 3, 4, 5, 6]);

    for ($i = 1; $i < count($entries); $i++) {
        expect($entries[$i]->prev_hash)->toBe($entries[$i - 1]->row_hash);
    }

    expect(Audit::service()->verify($tenant)->isIntact())->toBeTrue();
});

it('keeps each tenant chain independent, with its own positions', function (): void {
    [$first, $second] = [Tenant::factory()->create(), Tenant::factory()->create()];

    Audit::service()->write('a', tenant: $first);
    Audit::service()->write('b', tenant: $second);
    $third = Audit::service()->write('c', tenant: $first);
    $fourth = Audit::service()->write('d', tenant: $second);

    expect($third->sequence)->toBe(2)
        ->and($fourth->sequence)->toBe(2)
        ->and($third->chain_key)->toBe($first->id)
        ->and($fourth->chain_key)->toBe($second->id)
        ->and(Audit::service()->verify($first)->isIntact())->toBeTrue()
        ->and(Audit::service()->verify($second)->isIntact())->toBeTrue();
});

it('writes platform entries with a null tenant_id on the platform chain', function (): void {
    $entry = Audit::service()->writeForPlatform('plan.limits.changed', ['plan' => 'pro', 'messages_monthly' => 50_000]);

    expect($entry->tenant_id)->toBeNull()
        ->and($entry->chain_key)->toBe(AuditLog::PLATFORM_CHAIN)
        ->and($entry->isPlatformEntry())->toBeTrue()
        ->and($entry->hasCoherentChainKey())->toBeTrue()
        ->and(Audit::service()->verify()->isIntact())->toBeTrue();
});

it('writes and verifies platform entries with no tenant bound and no platform mode open', function (): void {
    // The console/queue case: `TenantScope` fails closed here, so an audit write that
    // read its chain through the ambient context could not work at all.
    expect(Audit::tenancy()->hasTenant())->toBeFalse()
        ->and(Audit::tenancy()->actingAsPlatform())->toBeFalse()
        ->and(fn () => TenantUsage::query()->count())->toThrow(MissingTenantContextException::class);

    Audit::service()->writeForPlatform('system.maintenance.ran', ['job' => 'retention']);

    expect(Audit::service()->verify()->entriesChecked)->toBe(1)
        ->and(Audit::service()->verify()->isIntact())->toBeTrue();
});

it('writes a tenant chain entry from an unbound context when the tenant is named', function (): void {
    $tenant = Tenant::factory()->create();

    $entry = Audit::service()->write('wallet.topped_up', ['amount_micros' => 1_000_000], tenant: $tenant);

    expect($entry->chain_key)->toBe($tenant->id)
        ->and($entry->tenant_id)->toBe($tenant->id)
        ->and(Audit::service()->verify($tenant)->isIntact())->toBeTrue();
});

it('infers the chain from a tenant-owned subject, even in platform mode', function (): void {
    $tenant = Tenant::factory()->create();
    $usage = Audit::tenancy()->runFor($tenant, fn (): TenantUsage => TenantUsage::factory()->create(['tenant_id' => $tenant->id]));

    $entry = Audit::tenancy()->asPlatform('admin adjusts usage', fn (): AuditLog => Audit::service()->write('usage.adjusted', ['used' => 5], $usage));

    // The action belongs to the tenant's history, not the platform's, even though the
    // admin performed it with no tenant bound.
    expect($entry->tenant_id)->toBe($tenant->id)
        ->and($entry->chain_key)->toBe($tenant->id)
        ->and($entry->subject_type)->toBe(TenantUsage::class)
        ->and($entry->subject_id)->toBe((string) $usage->id);
});

it('records the subject of an action, whether a model, a value object, or a bare string', function (): void {
    $tenant = Tenant::factory()->create();

    $fromModel = Audit::service()->write('tenant.suspended', [], $tenant, tenant: $tenant);
    $fromObject = Audit::service()->write('lane.paused', [], new AuditSubject('queue.lane', 'campaigns'), tenant: $tenant);
    $fromString = Audit::service()->write('flag.toggled', [], 'feature.rag', tenant: $tenant);

    expect($fromModel->subject_type)->toBe(Tenant::class)
        ->and($fromModel->subject_id)->toBe($tenant->id)
        ->and($fromObject->subject_type)->toBe('queue.lane')
        ->and($fromObject->subject_id)->toBe('campaigns')
        ->and($fromString->subject_type)->toBe('feature.rag')
        ->and($fromString->subject_id)->toBeNull();
});

it('attributes an entry to the authenticated user when no actor is passed', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    Audit::tenancy()->set($tenant);

    thisTest()->actingAs($user);

    $entry = Audit::service()->write('profile.updated');

    expect($entry->actor_type)->toBe(AuditActorType::User)
        ->and($entry->actor_id)->toBe((string) $user->id)
        ->and($entry->actor_label)->toBe($user->email);
});

it('attributes an impersonation to the admin, with the user as its subject', function (): void {
    $admin = User::factory()->create();
    $victim = User::factory()->create();
    $tenant = Tenant::factory()->create();

    $entry = Audit::service()->write(
        'user.impersonated',
        ['expires_in_minutes' => 30],
        $victim,
        AuditActor::admin($admin),
        $tenant,
    );

    expect($entry->actor_type)->toBe(AuditActorType::Admin)
        ->and($entry->actor_id)->toBe((string) $admin->id)
        ->and($entry->actor_label)->toBe($admin->email)
        ->and($entry->subject_type)->toBe(User::class)
        ->and($entry->subject_id)->toBe((string) $victim->id);
});

it('falls back to a system actor when nobody is authenticated', function (): void {
    $entry = Audit::service()->writeForPlatform('retention.purged', ['tables' => 3]);

    expect($entry->actor_type)->toBe(AuditActorType::System)
        ->and($entry->actor_id)->toBeNull();
});

it('captures request correlation fields when written from a request', function (): void {
    $tenant = Tenant::factory()->create();
    Audit::tenancy()->set($tenant);

    request()->headers->set('X-Trace-Id', 'trace-abc-123');
    request()->server->set('REMOTE_ADDR', '203.0.113.9');
    request()->headers->set('User-Agent', 'PestBrowser/1.0');

    $entry = Audit::service()->write('session.connected');

    expect($entry->trace_id)->toBe('trace-abc-123')
        ->and($entry->ip_address)->toBe('203.0.113.9')
        ->and($entry->user_agent)->toBe('PestBrowser/1.0');
});

it('exposes the chain tip as an anchor that can be re-verified later', function (): void {
    $tenant = Tenant::factory()->create();

    expect(Audit::service()->anchor($tenant)->isEmpty())->toBeTrue();

    $entry = Audit::service()->write('a', tenant: $tenant);
    $anchor = Audit::service()->anchor($tenant);

    expect($anchor->chainKey)->toBe($tenant->id)
        ->and($anchor->sequence)->toBe(1)
        ->and($anchor->rowHash)->toBe($entry->row_hash)
        ->and(Audit::service()->verify($tenant, $anchor)->isIntact())->toBeTrue();

    // Anchors survive a round-trip through storage/transport.
    expect(Audit::service()->verify($tenant, AuditChainAnchor::fromArray($anchor->toArray()))->isIntact())
        ->toBeTrue();
});

it('reports an intact empty chain rather than an error', function (): void {
    $tenant = Tenant::factory()->create();
    $result = Audit::service()->verify($tenant);

    expect($result->isIntact())->toBeTrue()
        ->and($result->entriesChecked)->toBe(0)
        ->and($result->tipSequence)->toBe(0)
        ->and($result->tipHash)->toBe(AuditLog::GENESIS_HASH)
        ->and($result->summary())->toContain('is intact');
});

it('scopes a tenant read of the trail to that tenant and hides platform entries', function (): void {
    [$first, $second] = [Tenant::factory()->create(), Tenant::factory()->create()];

    Audit::service()->write('a', tenant: $first);
    Audit::service()->write('b', tenant: $second);
    Audit::service()->writeForPlatform('c');

    $visible = Audit::tenancy()->runFor($first, fn (): array => AuditLog::query()->pluck('action')->all());

    // Req 24: the trail is a platform-admin surface. A tenant sees its own history and
    // never another tenant's — and never the platform chain, which `tenant_id IS NULL`
    // excludes structurally.
    expect($visible)->toBe(['a'])
        ->and(DB::table('audit_logs')->count())->toBe(3);
});
