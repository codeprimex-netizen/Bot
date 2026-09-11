<?php

declare(strict_types=1);

use App\Support\Database\TenantSchema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| The migration helper every tenant-owned table uses (Req 1.1, 1.2 / A1)
|--------------------------------------------------------------------------
| One call has to produce all three things a tenant-owned table needs — the ULID
| column, the cascading foreign key, and an index that leads with `tenant_id` — so
| that later phases cannot get one of them subtly wrong.
*/

afterEach(function (): void {
    Schema::dropIfExists('helper_probe');
});

it('adds the tenant_id column, its foreign key and a leading index', function (): void {
    Schema::create('helper_probe', function (Blueprint $table): void {
        $table->id();
        TenantSchema::tenantId($table, 'status');
        $table->string('status');
    });

    $index = collect(Schema::getIndexes('helper_probe'))
        ->firstWhere(fn (array $index): bool => $index['columns'] === ['tenant_id', 'status']);

    expect(Schema::hasColumn('helper_probe', 'tenant_id'))->toBeTrue()
        ->and($index)->not->toBeNull()
        ->and($index['name'] ?? null)->toBe('helper_probe_tenant_id_status_index')
        ->and($index['unique'] ?? null)->toBeFalse()
        ->and(collect(Schema::getForeignKeys('helper_probe'))->contains(
            fn (array $foreign): bool => $foreign['columns'] === ['tenant_id']
                && $foreign['foreign_table'] === 'tenants'
                && str_contains(strtolower((string) $foreign['on_delete']), 'cascade'),
        ))->toBeTrue();
});

it('indexes tenant_id on its own when no further columns are named', function (): void {
    Schema::create('helper_probe', function (Blueprint $table): void {
        $table->id();
        TenantSchema::tenantId($table);
    });

    expect(collect(Schema::getIndexes('helper_probe'))->contains(
        fn (array $index): bool => $index['columns'] === ['tenant_id'],
    ))->toBeTrue();
});

it('can make the tenant index a unique constraint', function (): void {
    Schema::create('helper_probe', function (Blueprint $table): void {
        $table->id();
        TenantSchema::tenantId($table, ['kind'], unique: true);
        $table->string('kind');
    });

    $index = collect(Schema::getIndexes('helper_probe'))
        ->firstWhere(fn (array $index): bool => $index['columns'] === ['tenant_id', 'kind']);

    expect($index['unique'] ?? null)->toBeTrue()
        ->and($index['name'] ?? null)->toBe('helper_probe_tenant_id_kind_unique');
});

it('omits the foreign key for tables on another connection', function (): void {
    // Tenant tier DEDICATED_DB (Req 1.6): a cross-database foreign key is impossible,
    // but the column and its index still are not optional.
    Schema::create('helper_probe', function (Blueprint $table): void {
        $table->id();
        TenantSchema::tenantId($table, constrained: false);
    });

    expect(Schema::getForeignKeys('helper_probe'))->toBe([])
        ->and(Schema::hasColumn('helper_probe', 'tenant_id'))->toBeTrue()
        ->and(collect(Schema::getIndexes('helper_probe'))->contains(
            fn (array $index): bool => $index['columns'] === ['tenant_id'],
        ))->toBeTrue();
});

it('never repeats tenant_id in the index and drops empty column names', function (): void {
    Schema::create('helper_probe', function (Blueprint $table): void {
        $table->id();
        TenantSchema::tenantId($table, ['tenant_id', '', 'status', 'status']);
        $table->string('status');
    });

    expect(collect(Schema::getIndexes('helper_probe'))->contains(
        fn (array $index): bool => $index['columns'] === ['tenant_id', 'status'],
    ))->toBeTrue();
});

it('keeps generated index names inside the 64-character limit', function (): void {
    $long = TenantSchema::indexName(
        'conversation_knowledge_base_chunk_embeddings',
        ['tenant_id', 'conversation_id', 'knowledge_base_id', 'created_at'],
    );

    expect(strlen($long))->toBeLessThanOrEqual(64)
        ->and($long)->toStartWith('conversation_knowledge_base_ch')
        ->and($long)->toEndWith('_index')
        // Deterministic: the same inputs always name the same index.
        ->and(TenantSchema::indexName(
            'conversation_knowledge_base_chunk_embeddings',
            ['tenant_id', 'conversation_id', 'knowledge_base_id', 'created_at'],
        ))->toBe($long)
        ->and(TenantSchema::indexName('campaigns', ['tenant_id', 'status']))->toBe('campaigns_tenant_id_status_index');
});
