<?php

declare(strict_types=1);

use App\Enums\BillingInterval;
use App\Enums\QuotaKind;
use App\Exceptions\Billing\MalformedPlanException;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Plan;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| plans schema, casting and the typed API PlanGate/QuotaGuard read (Req 25.1 / D2)
|--------------------------------------------------------------------------
*/

it('has the columns and indexes the catalogue is queried by', function (): void {
    expect(Schema::hasColumns('plans', [
        'id', 'name', 'slug', 'price_cents', 'currency', 'interval',
        'features', 'limits', 'active', 'sort', 'created_at', 'updated_at',
    ]))->toBeTrue();

    $indexes = collect(Schema::getIndexes('plans'));

    // idx(active) — design.md's plans index — plus the composite that serves the
    // pricing-page query (filter *and* order) end to end.
    expect($indexes->firstWhere('name', 'plans_active_index'))->not->toBeNull()
        ->and($indexes->firstWhere('name', 'plans_active_sort_index'))->not->toBeNull()
        ->and($indexes->firstWhere('name', 'plans_active_sort_index')['columns'] ?? null)->toBe(['active', 'sort'])
        ->and($indexes->contains(fn (array $index): bool => $index['columns'] === ['slug'] && $index['unique']))->toBeTrue();
});

it('is a platform table, not a tenant-owned one', function (): void {
    // Many tenants share one plan row, so scoping it by tenant would make the
    // catalogue unreadable. The mirror image of TenantOwnedModelsGuardTest.
    expect(Schema::hasColumn('plans', 'tenant_id'))->toBeFalse()
        ->and(class_uses_recursive(Plan::class))->not->toContain(BelongsToTenant::class);
});

it('refuses two plans with the same slug', function (): void {
    Plan::factory()->create(['slug' => 'growth']);

    expect(fn (): Plan => Plan::factory()->create(['slug' => 'growth']))
        ->toThrow(QueryException::class);
});

it('round-trips the enum, JSON and boolean casts through the database', function (): void {
    $plan = Plan::factory()->create([
        'name' => 'Growth',
        'slug' => 'growth',
        'price_cents' => 4900,
        'currency' => 'USD',
        'interval' => BillingInterval::Year,
        'features' => ['ai' => true, 'campaigns' => false],
        'limits' => [QuotaKind::Sessions->value => 3, QuotaKind::MessagesMonthly->value => null],
        'active' => false,
        'sort' => 20,
    ]);

    $row = DB::table('plans')->where('id', $plan->id)->first();

    // Stored as JSON text and a raw enum value, not as PHP-serialised anything.
    expect(json_decode((string) $row->features, true))->toBe(['ai' => true, 'campaigns' => false])
        ->and(json_decode((string) $row->limits, true))->toBe(['SESSIONS' => 3, 'MESSAGES_MONTHLY' => null])
        ->and($row->interval)->toBe('YEAR');

    $fresh = Plan::query()->findOrFail($plan->id);

    expect($fresh->id)->toBeString()->toHaveLength(26)
        ->and($fresh->interval)->toBe(BillingInterval::Year)
        ->and($fresh->price_cents)->toBe(4900)
        ->and($fresh->sort)->toBe(20)
        ->and($fresh->active)->toBeFalse()
        ->and($fresh->features())->toBe(['ai' => true, 'campaigns' => false])
        ->and($fresh->limits())->toBe(['SESSIONS' => 3, 'MESSAGES_MONTHLY' => null]);
});

it('answers feature questions without the caller touching JSON', function (): void {
    $plan = Plan::factory()->create([
        'features' => ['ai' => true, 'campaigns' => false],
    ]);

    expect($plan->allows('ai'))->toBeTrue()
        ->and($plan->allows('campaigns'))->toBeFalse()
        // Absent flag: a feature added by a later phase is not granted retroactively.
        ->and($plan->allows('white_label'))->toBeFalse()
        ->and($plan->enabledFeatures())->toBe(['ai']);
});

it('answers quota questions, with null meaning unlimited', function (): void {
    $plan = Plan::factory()->create([
        'limits' => [
            QuotaKind::MessagesMonthly->value => null,
            QuotaKind::Sessions->value => 3,
            QuotaKind::AiCredits->value => 0,
        ],
    ]);

    expect($plan->limitFor(QuotaKind::Sessions))->toBe(3)
        ->and($plan->limitFor(QuotaKind::MessagesMonthly))->toBeNull()
        ->and($plan->isUnlimited(QuotaKind::MessagesMonthly))->toBeTrue()
        ->and($plan->isUnlimited(QuotaKind::Sessions))->toBeFalse()
        // A deliberate zero, not an omission.
        ->and($plan->limitFor(QuotaKind::AiCredits))->toBe(0)
        ->and($plan->declaresLimitFor(QuotaKind::AiCredits))->toBeTrue()
        // Omitted entirely: grants nothing, and is *not* reported as unlimited.
        ->and($plan->limitFor(QuotaKind::Contacts))->toBe(0)
        ->and($plan->isUnlimited(QuotaKind::Contacts))->toBeFalse()
        ->and($plan->declaresLimitFor(QuotaKind::Contacts))->toBeFalse();
});

it('re-reads its maps after they are reassigned', function (): void {
    $plan = Plan::factory()->create(['features' => ['ai' => false]]);

    expect($plan->allows('ai'))->toBeFalse();

    $plan->features = ['ai' => true];

    // The memoised view must follow the attribute, or an admin's edit would keep
    // reading as the pre-edit plan for the rest of the request.
    expect($plan->allows('ai'))->toBeTrue();

    $plan->save();
    $plan->refresh();

    expect($plan->allows('ai'))->toBeTrue();
});

it('refuses to persist a plan whose maps cannot be interpreted', function (array $attributes): void {
    expect(fn (): Plan => Plan::factory()->create($attributes))
        ->toThrow(MalformedPlanException::class);

    expect(Plan::query()->count())->toBe(0);
})->with([
    'non-boolean flag' => [['features' => ['ai' => 1]]],
    'feature list' => [['features' => ['ai', 'campaigns']]],
    'unknown quota kind' => [['limits' => ['SESSION' => 3]]],
    'negative ceiling' => [['limits' => ['SESSIONS' => -1]]],
    'string ceiling' => [['limits' => ['SESSIONS' => '3']]],
]);

it('fails loudly rather than granting access when a stored map is corrupt', function (): void {
    $plan = Plan::factory()->create();

    // A row edited by hand, restored from a backup, or written by a future
    // migration: the write-time guard never ran, so the read must still refuse.
    DB::table('plans')->where('id', $plan->id)->update([
        'features' => '{"ai": "yes"}',
        'limits' => '{"SESSION": 3}',
    ]);

    $corrupt = Plan::query()->findOrFail($plan->id);

    expect(fn (): bool => $corrupt->allows('ai'))->toThrow(MalformedPlanException::class)
        ->and(fn (): ?int => $corrupt->limitFor(QuotaKind::Sessions))->toThrow(MalformedPlanException::class)
        ->and(fn () => $corrupt->assertWellFormed())->toThrow(MalformedPlanException::class);
});

it('names the offending plan when a map is corrupt', function (): void {
    $plan = Plan::factory()->create(['slug' => 'growth']);

    DB::table('plans')->where('id', $plan->id)->update(['features' => '{"ai": 1}']);

    expect(fn (): bool => Plan::query()->findOrFail($plan->id)->allows('ai'))
        ->toThrow(MalformedPlanException::class, 'plan "growth"');
});

it('lists the sellable catalogue in a total, stable order', function (): void {
    $second = Plan::factory()->create(['slug' => 'b-plan', 'sort' => 10, 'price_cents' => 100]);
    $first = Plan::factory()->create(['slug' => 'a-plan', 'sort' => 10, 'price_cents' => 0]);
    $third = Plan::factory()->create(['slug' => 'c-plan', 'sort' => 20]);
    $retired = Plan::factory()->inactive()->create(['slug' => 'legacy', 'sort' => 1]);

    expect(Plan::query()->active()->ordered()->pluck('slug')->all())
        ->toBe([$first->slug, $second->slug, $third->slug])
        ->and(Plan::query()->active()->pluck('slug'))->not->toContain($retired->slug);
});

it('builds well-formed plans from the factory', function (): void {
    $plan = Plan::factory()->create();

    expect($plan->quotaLimits()->undeclared())->toBe([])
        ->and($plan->limitFor(QuotaKind::MessagesMonthly))->toBe(100);

    $unlimited = Plan::factory()->unlimited()->yearly()->create();

    expect($unlimited->isUnlimited(QuotaKind::MessagesMonthly))->toBeTrue()
        ->and($unlimited->interval)->toBe(BillingInterval::Year);

    $bare = Plan::factory()->withoutLimits()->create();

    expect($bare->limitFor(QuotaKind::Sessions))->toBe(0)
        ->and($bare->quotaLimits()->undeclared())->toHaveCount(count(QuotaKind::cases()));
});
