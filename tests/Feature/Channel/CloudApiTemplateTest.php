<?php

declare(strict_types=1);

use App\Enums\ChannelMode;
use App\Enums\ChannelTemplateCategory;
use App\Enums\ChannelTemplateStatus;
use App\Models\ChannelCredential;
use App\Models\CloudApiTemplate;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| cloud_api_templates — the approved-template registry (Req 8.12 / A8)
|--------------------------------------------------------------------------
| A local mirror of the provider's approval state, so task 8.4 can answer "is there an approved
| template for this?" without a provider call on every send. What is pinned here is that only
| `APPROVED` sends, that a template is bound to the account it was approved against, and that
| the registry is per tenant.
*/

it('binds a template to the provider account it was approved against', function (): void {
    $tenant = Tenant::factory()->create();

    app(TenantContext::class)->runFor($tenant, function () use ($tenant): void {
        $live = ChannelCredential::factory()->forMode(ChannelMode::CloudApi)->labelled('live')
            ->create(['tenant_id' => $tenant->id]);
        $sandbox = ChannelCredential::factory()->forMode(ChannelMode::CloudApi)->labelled('sandbox')
            ->create(['tenant_id' => $tenant->id]);

        CloudApiTemplate::factory()->approved()->named('order_update')->create([
            'tenant_id' => $tenant->id,
            'credential_id' => $live->id,
        ]);

        // Sending a template approved on one account through another fails at the provider,
        // so the lookup refuses it here instead.
        expect(CloudApiTemplate::sendable($live->id, 'order_update', 'en_US'))->not->toBeNull()
            ->and(CloudApiTemplate::sendable($sandbox->id, 'order_update', 'en_US'))->toBeNull()
            ->and(CloudApiTemplate::sendable($live->id, 'order_update', 'de_DE'))->toBeNull();
    });
});

it('permits a send on APPROVED alone', function (): void {
    $tenant = Tenant::factory()->create();

    app(TenantContext::class)->runFor($tenant, function () use ($tenant): void {
        $credential = ChannelCredential::factory()->forMode(ChannelMode::CloudApi)
            ->create(['tenant_id' => $tenant->id]);

        $states = [
            'pending' => CloudApiTemplate::factory(),
            'approved' => CloudApiTemplate::factory()->approved(),
            'rejected' => CloudApiTemplate::factory()->rejected(),
            // Paused is deliberately not sendable: the provider has stopped accepting it, so
            // attempting the send would turn a local block into a remote failure mid-campaign.
            'paused' => CloudApiTemplate::factory()->paused(),
        ];

        foreach ($states as $label => $factory) {
            $template = $factory->named('tpl_'.$label)->create([
                'tenant_id' => $tenant->id,
                'credential_id' => $credential->id,
            ]);

            expect($template->isSendable())->toBe($label === 'approved', $label);
        }

        expect(CloudApiTemplate::query()->approved()->count())->toBe(1)
            ->and(CloudApiTemplate::query()->withStatus(ChannelTemplateStatus::Paused)->count())->toBe(1)
            ->and(CloudApiTemplate::query()->withStatus(ChannelTemplateStatus::Pending)->count())->toBe(1);
    });
});

it('keeps one template per account, name and language', function (): void {
    $tenant = Tenant::factory()->create();

    app(TenantContext::class)->runFor($tenant, function () use ($tenant): void {
        $credential = ChannelCredential::factory()->forMode(ChannelMode::CloudApi)
            ->create(['tenant_id' => $tenant->id]);

        CloudApiTemplate::factory()->named('welcome', 'en_US')->create([
            'tenant_id' => $tenant->id,
            'credential_id' => $credential->id,
        ]);

        // A second localisation of one name is a different template, and the commonest thing
        // a tenant does.
        $german = CloudApiTemplate::factory()->named('welcome', 'de_DE')->create([
            'tenant_id' => $tenant->id,
            'credential_id' => $credential->id,
        ]);

        expect($german->language)->toBe('de_DE');

        expect(fn () => CloudApiTemplate::factory()->named('welcome', 'en_US')->create([
            'tenant_id' => $tenant->id,
            'credential_id' => $credential->id,
        ]))->toThrow(QueryException::class);
    });
});

it('re-syncs only the states the provider can still change, stalest first', function (): void {
    $tenant = Tenant::factory()->create();

    app(TenantContext::class)->runFor($tenant, function () use ($tenant): void {
        $credential = ChannelCredential::factory()->forMode(ChannelMode::CloudApi)
            ->create(['tenant_id' => $tenant->id]);

        $never = CloudApiTemplate::factory()->named('never_synced')->create([
            'tenant_id' => $tenant->id,
            'credential_id' => $credential->id,
        ]);
        CloudApiTemplate::factory()->approved()->named('fresh')->create([
            'tenant_id' => $tenant->id,
            'credential_id' => $credential->id,
            'synced_at' => now(),
        ]);
        $stale = CloudApiTemplate::factory()->paused()->named('stale')->create([
            'tenant_id' => $tenant->id,
            'credential_id' => $credential->id,
            'synced_at' => now()->subDays(2),
        ]);
        // Only the tenant can move a rejection, by resubmitting — so re-reading it forever
        // would spend the platform's provider budget on an answer that cannot change.
        CloudApiTemplate::factory()->rejected()->named('rejected')->create([
            'tenant_id' => $tenant->id,
            'credential_id' => $credential->id,
            'synced_at' => now()->subYear(),
        ]);

        $due = CloudApiTemplate::query()->dueForSync(now()->subHour())->pluck('name')->all();

        expect($due)->toBe(['never_synced', 'stale'])
            ->and($never->isStale(now()->subHour()))->toBeTrue()
            ->and($stale->isStale(now()->subHour()))->toBeTrue();
    });
});

it('scopes the registry to the acting tenant', function (): void {
    $context = app(TenantContext::class);
    [$acme, $globex] = [Tenant::factory()->create(), Tenant::factory()->create()];

    foreach ([$acme, $globex] as $tenant) {
        $context->runFor($tenant, function () use ($tenant): void {
            $credential = ChannelCredential::factory()->forMode(ChannelMode::CloudApi)
                ->create(['tenant_id' => $tenant->id]);

            CloudApiTemplate::factory()->approved()->named('shared_name')->create([
                'tenant_id' => $tenant->id,
                'credential_id' => $credential->id,
            ]);
        });
    }

    // Two tenants may both have a template called `shared_name`: uniqueness is per account,
    // and an approval is a fact about one WABA.
    $context->runFor($acme, function (): void {
        expect(CloudApiTemplate::query()->count())->toBe(1)
            ->and(CloudApiTemplate::withoutTenantScope()->count())->toBe(2);
    });
});

it('casts the category and status, and indexes the table the way design.md specifies', function (): void {
    $tenant = Tenant::factory()->create();

    app(TenantContext::class)->runFor($tenant, function () use ($tenant): void {
        $credential = ChannelCredential::factory()->forMode(ChannelMode::CloudApi)
            ->create(['tenant_id' => $tenant->id]);

        $template = CloudApiTemplate::factory()
            ->ofCategory(ChannelTemplateCategory::Marketing)
            ->create(['tenant_id' => $tenant->id, 'credential_id' => $credential->id]);

        expect($template->category)->toBe(ChannelTemplateCategory::Marketing)
            ->and($template->category->isPromotional())->toBeTrue()
            ->and($template->status)->toBe(ChannelTemplateStatus::Pending)
            // The relation is itself tenant-scoped, so a foreign `credential_id` cannot be
            // reached through it.
            ->and($template->credential)->toBeInstanceOf(ChannelCredential::class);
    });

    $indexes = collect(Schema::getIndexes('cloud_api_templates'));

    expect($indexes->contains(fn (array $i): bool => array_slice($i['columns'], 0, 2) === ['tenant_id', 'status']))
        ->toBeTrue()
        ->and($indexes->contains(fn (array $i): bool => $i['columns'] === ['tenant_id', 'credential_id', 'name', 'language']
            && $i['unique']))
        ->toBeTrue();
});
