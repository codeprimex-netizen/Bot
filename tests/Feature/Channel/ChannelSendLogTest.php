<?php

declare(strict_types=1);

use App\Enums\ChannelCapability;
use App\Enums\ChannelMode;
use App\Enums\ChannelSendResult;
use App\Exceptions\Audit\AppendOnlyViolationException;
use App\Models\ChannelSendLog;
use App\Models\Session;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| channel_send_log — the only trace a refused operation leaves (Req 8.3, 8.10, 8.11 / A8)
|--------------------------------------------------------------------------
| Req 8.3 requires an operation unsupported by a session's mode to be refused before any driver
| call, with no side effect — so a capability block has no message row, no provider id, and no
| failed request to inspect. This log is the one side effect a block is allowed to have, which is
| what makes Properties 21 and 26 assertable and what makes "why was my group send refused?"
| answerable. Its writers arrive with tasks 6.3, 8.1 and 8.5; the table and its guards are here.
*/

it('records a capability block that never reached the provider', function (): void {
    $tenant = Tenant::factory()->create();

    $log = app(TenantContext::class)->runFor($tenant, function () use ($tenant): ChannelSendLog {
        $session = Session::factory()->create([
            'tenant_id' => $tenant->id,
            'channel_mode' => ChannelMode::CloudApi,
        ]);

        return ChannelSendLog::factory()->blocked(ChannelCapability::Groups, ChannelMode::CloudApi)->create([
            'tenant_id' => $tenant->id,
            'session_id' => $session->id,
        ]);
    });

    expect($log->result)->toBe(ChannelSendResult::Blocked)
        ->and($log->reachedProvider())->toBeFalse()
        ->and($log->provider_message_id)->toBeNull()
        ->and($log->block_reason)->toBe('MODE_CAPABILITY')
        // The row knows it is a capability refusal because the matrix says so, rather than
        // because a caller labelled it.
        ->and($log->isCapabilityBlock())->toBeTrue()
        ->and($log->created_at)->not->toBeNull();
});

it('distinguishes a failover step from a terminal failure', function (): void {
    $tenant = Tenant::factory()->create();

    [$step, $terminal] = app(TenantContext::class)->runFor($tenant, function () use ($tenant): array {
        $session = Session::factory()->create(['tenant_id' => $tenant->id]);

        return [
            ChannelSendLog::factory()->failedOver(ChannelMode::Baileys, ChannelMode::CloudApi)->create([
                'tenant_id' => $tenant->id,
                'session_id' => $session->id,
                'idempotency_key' => 'msg-chain-1',
            ]),
            ChannelSendLog::factory()->failed()->create([
                'tenant_id' => $tenant->id,
                'session_id' => $session->id,
                'idempotency_key' => 'msg-chain-2',
            ]),
        ];
    });

    // Req 8.10/8.11: "source mode, target mode, trigger reason" per failover, and a
    // FAILED_OVER row is not a lost message — another attempt follows it.
    expect($step->isFailoverAttempt())->toBeTrue()
        ->and($step->failover_from)->toBe(ChannelMode::CloudApi)
        ->and($step->mode)->toBe(ChannelMode::Baileys)
        ->and($step->result->isTerminal())->toBeFalse()
        ->and($terminal->isFailoverAttempt())->toBeFalse()
        ->and($terminal->result->isTerminal())->toBeTrue();
});

it('refuses to be rewritten or deleted through the model', function (): void {
    $tenant = Tenant::factory()->create();

    app(TenantContext::class)->runFor($tenant, function () use ($tenant): void {
        $log = ChannelSendLog::factory()->create([
            'tenant_id' => $tenant->id,
            'session_id' => Session::factory()->create(['tenant_id' => $tenant->id])->id,
        ]);

        // An audit whose rows can be edited answers nothing. Both the instance path and the
        // mass path — which fires no model events — are refused.
        expect(function () use ($log): void {
            $log->block_reason = 'rewritten';
            $log->save();
        })->toThrow(AppendOnlyViolationException::class)
            ->and(fn () => $log->delete())->toThrow(AppendOnlyViolationException::class)
            ->and(fn () => ChannelSendLog::query()->update(['block_reason' => 'rewritten']))
            ->toThrow(AppendOnlyViolationException::class)
            ->and(fn () => ChannelSendLog::query()->delete())->toThrow(AppendOnlyViolationException::class)
            ->and(ChannelSendLog::query()->count())->toBe(1);
    });
});

it('dedupes one attempt per idempotency key within a tenant, and never across tenants', function (): void {
    $context = app(TenantContext::class);
    [$acme, $globex] = [Tenant::factory()->create(), Tenant::factory()->create()];

    $write = static fn (Tenant $tenant, ?string $key): ChannelSendLog => ChannelSendLog::factory()->create([
        'tenant_id' => $tenant->id,
        'session_id' => Session::factory()->create(['tenant_id' => $tenant->id])->id,
        'idempotency_key' => $key,
    ]);

    $context->runFor($acme, function () use ($write, $acme): void {
        $write($acme, 'msg-1');

        // A retried job cannot double-log the same attempt.
        expect(fn () => $write($acme, 'msg-1'))->toThrow(QueryException::class);
    });

    // ...but one tenant's choice of key is not another tenant's business. design.md writes
    // `idempotency_key(uniq)`; taken globally that would let tenant A make tenant B's send
    // unrecordable, which is the defect `idempotency_keys(scope, key)` already avoids.
    $context->runFor($globex, function () use ($write, $globex): void {
        expect($write($globex, 'msg-1')->idempotency_key)->toBe('msg-1');
    });

    // A refusal can precede any message, so a row may legitimately have no key at all — and
    // several such rows must coexist.
    $context->runFor($acme, function () use ($write, $acme): void {
        $write($acme, null);
        $write($acme, null);

        expect(ChannelSendLog::query()->whereNull('idempotency_key')->count())->toBe(2);
    });
});

it('reads one session history newest first, scoped to the acting tenant', function (): void {
    $context = app(TenantContext::class);
    [$acme, $globex] = [Tenant::factory()->create(), Tenant::factory()->create()];

    $sessionId = $context->runFor($acme, function () use ($acme): string {
        $session = Session::factory()->create(['tenant_id' => $acme->id]);

        ChannelSendLog::factory()->create([
            'tenant_id' => $acme->id,
            'session_id' => $session->id,
            'idempotency_key' => 'older',
            'created_at' => now()->subHour(),
        ]);
        ChannelSendLog::factory()->blocked()->create([
            'tenant_id' => $acme->id,
            'session_id' => $session->id,
            'created_at' => now(),
        ]);

        return $session->id;
    });

    $context->runFor($globex, function () use ($globex): void {
        ChannelSendLog::factory()->create([
            'tenant_id' => $globex->id,
            'session_id' => Session::factory()->create(['tenant_id' => $globex->id])->id,
        ]);
    });

    $context->runFor($acme, function () use ($sessionId): void {
        $rows = ChannelSendLog::query()->forSession($sessionId)->get();

        expect($rows)->toHaveCount(2)
            ->and($rows->first()?->result)->toBe(ChannelSendResult::Blocked)
            ->and(ChannelSendLog::query()->withResult(ChannelSendResult::Blocked)->count())->toBe(1)
            ->and(ChannelSendLog::query()->onMode(ChannelMode::CloudApi)->count())->toBe(1)
            // Property 1: the other tenant's row is invisible.
            ->and(ChannelSendLog::query()->count())->toBe(2)
            ->and(ChannelSendLog::withoutTenantScope()->count())->toBe(3);
    });
});

it('indexes the table the way design.md specifies, and keeps no updated_at', function (): void {
    $indexes = collect(Schema::getIndexes('channel_send_log'));

    expect($indexes->contains(fn (array $i): bool => array_slice($i['columns'], 0, 3) === ['tenant_id', 'session_id', 'created_at']))
        ->toBeTrue()
        ->and(Schema::hasColumn('channel_send_log', 'updated_at'))->toBeFalse()
        ->and(Schema::hasColumn('channel_send_log', 'created_at'))->toBeTrue();
});
