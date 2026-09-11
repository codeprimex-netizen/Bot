<?php

declare(strict_types=1);

use App\Enums\ChannelCapability;
use App\Enums\ChannelMode;
use App\Enums\SessionStatus;
use App\Exceptions\Tenancy\CrossTenantAccessException;
use App\Models\Session;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| sessions_wa — the reused engine table, made tenant-owned (Req 2.1–2.6 / A2)
|--------------------------------------------------------------------------
| The table is the substrate two later phases declare foreign keys onto
| (`channel_webhook_routes`, `conversations`), and the row-level mapping "every session
| belongs to exactly one tenant" is what lets the Node sidecar stay tenant-blind. So the
| shape of the key, the per-tenant uniqueness of `name`, and the scoping of every read
| are contract rather than detail, and are pinned here.
*/

it('keys sessions by an opaque ULID so the id can be handed to a tenant-blind sidecar', function (): void {
    $tenant = Tenant::factory()->create();

    $session = app(TenantContext::class)->runFor(
        $tenant,
        fn (): Session => Session::factory()->create(['tenant_id' => $tenant->id]),
    );

    expect(Str::isUlid($session->id))->toBeTrue()
        ->and($session->getTable())->toBe('sessions_wa')
        ->and(Schema::getColumnType('sessions_wa', 'id'))->not->toContain('int');
});

it('stamps the acting tenant on create without being told', function (): void {
    $tenant = Tenant::factory()->create();

    $session = app(TenantContext::class)->runFor(
        $tenant,
        fn (): Session => Session::create(['name' => 'Support']),
    );

    expect($session->tenant_id)->toBe($tenant->id)
        ->and($session->status)->toBe(SessionStatus::Initializing);
});

it('scopes every read to the acting tenant', function (): void {
    $context = app(TenantContext::class);
    [$acme, $globex] = [Tenant::factory()->create(), Tenant::factory()->create()];

    $context->runFor($acme, fn (): Session => Session::factory()->create(['tenant_id' => $acme->id]));
    $foreign = $context->runFor($globex, fn (): Session => Session::factory()->create(['tenant_id' => $globex->id]));

    $context->runFor($acme, function () use ($foreign): void {
        expect(Session::query()->count())->toBe(1)
            // A foreign id is a typed 403, not a bare 404: Req 1.3 wants the attempt named.
            ->and(fn () => Session::query()->find($foreign->id))->toThrow(CrossTenantAccessException::class);
    });
});

it('lets two tenants both call a session "Main" but refuses a duplicate within one tenant', function (): void {
    $context = app(TenantContext::class);
    [$acme, $globex] = [Tenant::factory()->create(), Tenant::factory()->create()];

    $context->runFor($acme, fn (): Session => Session::create(['name' => 'Main']));

    // The engine's global `name(uniq)` would have made "Main" taken for everybody once one
    // tenant used it — a cross-tenant denial of service, and a leak that somebody else exists.
    $globexSession = $context->runFor($globex, fn (): Session => Session::create(['name' => 'Main']));

    expect($globexSession->name)->toBe('Main');

    $context->runFor($acme, function (): void {
        expect(fn () => Session::create(['name' => 'Main']))->toThrow(QueryException::class);
    });
});

it('casts the status to the enum and the counters to integers', function (): void {
    $tenant = Tenant::factory()->create();

    $session = app(TenantContext::class)->runFor($tenant, fn (): Session => Session::factory()
        ->connected()
        ->create(['tenant_id' => $tenant->id, 'weight' => 5, 'reconnects' => 2]));

    $fresh = app(TenantContext::class)->runFor($tenant, fn (): Session => Session::query()->findOrFail($session->id));

    expect($fresh->status)->toBe(SessionStatus::Connected)
        ->and($fresh->weight)->toBe(5)
        ->and($fresh->reconnects)->toBe(2)
        ->and($fresh->connected_at)->not->toBeNull();
});

it('keeps "online" and "sendable" as different scopes', function (): void {
    $tenant = Tenant::factory()->create();

    app(TenantContext::class)->runFor($tenant, function () use ($tenant): void {
        Session::factory()->connected()->create(['tenant_id' => $tenant->id]);
        Session::factory()->throttled()->create(['tenant_id' => $tenant->id]);
        Session::factory()->pairing()->create(['tenant_id' => $tenant->id]);

        // A pool query that used online() to pick a session to send from would quietly
        // defeat the anti-ban gate, which is the whole reason THROTTLED exists.
        expect(Session::query()->online()->count())->toBe(2)
            ->and(Session::query()->sendable()->count())->toBe(1);
    });
});

it('excludes the void-credential states from the restartable set', function (): void {
    $tenant = Tenant::factory()->create();

    app(TenantContext::class)->runFor($tenant, function () use ($tenant): void {
        Session::factory()->connected()->create(['tenant_id' => $tenant->id]);
        Session::factory()->loggedOut()->create(['tenant_id' => $tenant->id]);
        Session::factory()->withStatus(SessionStatus::Replaced)->create(['tenant_id' => $tenant->id]);
        Session::factory()->withStatus(SessionStatus::Failed)->create(['tenant_id' => $tenant->id]);

        // Reconnecting a logged-out session produces a fresh failure every time and hides
        // the session that actually needs a human to re-pair it.
        expect(Session::query()->restartable()->count())->toBe(2);
    });
});

it('states transition legality without writing or throwing', function (): void {
    $tenant = Tenant::factory()->create();

    app(TenantContext::class)->runFor($tenant, function () use ($tenant): void {
        $session = Session::factory()->loggedOut()->create(['tenant_id' => $tenant->id]);

        expect($session->canTransitionTo(SessionStatus::Connected))->toBeFalse()
            ->and($session->isTerminal())->toBeTrue()
            ->and($session->canSend())->toBeFalse()
            // A predicate, not an action: nothing was written by asking.
            ->and($session->refresh()->status)->toBe(SessionStatus::LoggedOut);
    });
});

it('soft-deletes so the rows that reference a session keep resolving', function (): void {
    $tenant = Tenant::factory()->create();

    app(TenantContext::class)->runFor($tenant, function () use ($tenant): void {
        $session = Session::factory()->connected()->create(['tenant_id' => $tenant->id]);

        $session->delete();

        expect(Session::query()->count())->toBe(0)
            ->and(Session::query()->withTrashed()->count())->toBe(1)
            ->and($session->fresh()?->deleted_at)->not->toBeNull();
    });
});

it('declares exactly one messaging backend, defaulting to BAILEYS', function (): void {
    // Task 6.1 added the column, with the ChannelMode enum and the credential tables that
    // give it meaning. The default is the compatibility promise: a session created by code
    // that never heard of Channel Mode is a Baileys session, and behaves exactly as it did
    // before the column existed (Req 2.6 / A8.1).
    $tenant = Tenant::factory()->create();

    $session = app(TenantContext::class)->runFor(
        $tenant,
        fn (): Session => Session::create(['name' => 'Support']),
    );

    expect(Schema::hasColumn('sessions_wa', 'channel_mode'))->toBeTrue()
        // Reported before the row is re-read, not only after: a lifecycle service comparing
        // an unsaved model's mode must not see "no backend".
        ->and($session->channel_mode)->toBe(ChannelMode::Baileys)
        ->and($session->fresh()?->channel_mode)->toBe(ChannelMode::Baileys)
        // ...and the pre-Channel-Mode behaviour a Baileys session must keep.
        ->and($session->requiresAntiBan())->toBeTrue()
        ->and($session->supports(ChannelCapability::Groups))->toBeTrue();
});

it('indexes sessions by tenant and mode so a per-mode sweep does not scan', function (): void {
    $leads = collect(Schema::getIndexes('sessions_wa'))->contains(
        fn (array $index): bool => array_slice($index['columns'], 0, 2) === ['tenant_id', 'channel_mode'],
    );

    expect($leads)->toBeTrue();
});

it('separates web-protocol sessions from official-mode ones', function (): void {
    $tenant = Tenant::factory()->create();

    app(TenantContext::class)->runFor($tenant, function () use ($tenant): void {
        Session::factory()->create(['tenant_id' => $tenant->id, 'channel_mode' => ChannelMode::Baileys]);
        Session::factory()->create(['tenant_id' => $tenant->id, 'channel_mode' => ChannelMode::OnPremise]);
        $official = Session::factory()->create([
            'tenant_id' => $tenant->id,
            'channel_mode' => ChannelMode::CloudApi,
        ]);

        // The anti-ban sweep (task 9.6) has no business paging through official-mode
        // sessions, whose pacing is the provider's (Property 24).
        expect(Session::query()->onWebProtocol()->count())->toBe(2)
            ->and(Session::query()->onMode(ChannelMode::CloudApi)->count())->toBe(1)
            ->and($official->requiresAntiBan())->toBeFalse()
            ->and($official->supports(ChannelCapability::Groups))->toBeFalse();
    });
});

it('stores an optional ordered failover chain, and reads past anything it cannot interpret', function (): void {
    // Task 6.3's column (Req 8.10, 8.11 / A8). NULL is the default and means *no failover* —
    // design § Channel Mode 2.6 — so a session that existed before the column keeps exactly
    // one driver.
    $tenant = Tenant::factory()->create();

    app(TenantContext::class)->runFor($tenant, function () use ($tenant): void {
        $plain = Session::factory()->create(['tenant_id' => $tenant->id]);

        expect(Schema::hasColumn('sessions_wa', 'failover_modes'))->toBeTrue()
            ->and($plain->failover_modes)->toBeNull()
            ->and($plain->failoverModes())->toBe([]);

        $configured = Session::factory()->create([
            'tenant_id' => $tenant->id,
            'channel_mode' => ChannelMode::CloudApi,
            // A chain written by a newer release, plus a value no release ever wrote.
            'failover_modes' => ['BAILEYS', 'TELEGRAM', 'ON_PREMISE', 7],
        ]);

        // Order preserved, unknown values dropped rather than fatal: stored configuration a
        // release cannot fully interpret must degrade to a shorter chain, never to a session
        // nobody can route. (`channel_mode` is cast strictly for the opposite reason.)
        expect($configured->failoverModes())->toBe([ChannelMode::Baileys, ChannelMode::OnPremise])
            ->and($configured->fresh()?->failoverModes())->toBe([ChannelMode::Baileys, ChannelMode::OnPremise]);
    });
});
