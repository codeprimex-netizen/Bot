<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BillingInterval;
use App\Enums\QuotaKind;
use App\Exceptions\Billing\MalformedPlanException;
use App\Observers\PlanObserver;
use App\Support\Billing\PlanFeatures;
use App\Support\Billing\PlanLimits;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One sellable tier: what it costs, what it unlocks (`features`), and how much of
 * each metered resource it grants (`limits`) — Req 25.1 / D2.
 *
 * A plan is **platform-level, not tenant-owned**: many tenants share one row, so it
 * carries no `tenant_id` and deliberately does not use `BelongsToTenant`
 * (`TenantOwnedModelsGuardTest` asserts that rule for the tables that *are* owned).
 * The per-tenant half of the story lives in `tenant_usage`, which counts consumption
 * against the ceilings declared here.
 *
 * ## Reading a plan
 *
 * Nothing outside this model touches the JSON columns. Callers ask questions:
 *
 * ```php
 * $plan->allows('ai');                        // PlanGate (task 2.2)
 * $plan->limitFor(QuotaKind::MessagesMonthly); // QuotaGuard (task 2.3); null = unlimited
 * ```
 *
 * Both go through `PlanFeatures` / `PlanLimits`, which validate the map every time
 * it is read and throw `MalformedPlanException` when it cannot be interpreted —
 * a plan the platform cannot read grants nothing (see those classes for the exact
 * shape, and for why an *absent* key is 0 rather than unlimited).
 *
 * `PlanObserver` validates the same maps on `saving`, so bad data is normally
 * rejected at the write that introduced it, and bumps the plan cache version on
 * every write (Req 30.4).
 *
 * ## Reading a plan *cheaply*
 *
 * `PlanGate`/`QuotaGuard` run on every inbound message, so they should resolve the
 * tenant's plan through `App\Services\Billing\PlanRepository` (cached, versioned)
 * rather than querying this model directly.
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property int $price_cents
 * @property string $currency
 * @property BillingInterval $interval
 * @property array<array-key, mixed>|null $features raw JSON map; read it via `features()`
 * @property array<array-key, mixed>|null $limits raw JSON map; read it via `limits()`
 * @property bool $active
 * @property int $sort
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
#[ObservedBy([PlanObserver::class])]
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    use HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'price_cents',
        'currency',
        'interval',
        'features',
        'limits',
        'active',
        'sort',
    ];

    /**
     * Validated view of `features`, memoised per raw value.
     */
    private ?PlanFeatures $featuresCache = null;

    /**
     * Validated view of `limits`, memoised per raw value.
     */
    private ?PlanLimits $limitsCache = null;

    /**
     * Raw JSON the memoised views were built from, so an assignment or a `refresh()`
     * re-validates instead of serving the previous plan's answer.
     *
     * @var array<string, string>
     */
    private array $mapSignatures = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'interval' => BillingInterval::class,
            'features' => 'array',
            'limits' => 'array',
            'active' => 'boolean',
            'sort' => 'integer',
        ];
    }

    /**
     * Tenants currently on this plan.
     *
     * `tenants.plan_id` is `nullOnDelete`, so retiring a plan empties this relation
     * rather than deleting anyone.
     *
     * @return HasMany<Tenant, $this>
     */
    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Feature flags — the API `PlanGate` (task 2.2) is built on
    |--------------------------------------------------------------------------
    */

    /**
     * Whether this plan includes $feature. Absent flag = not included.
     *
     * @throws MalformedPlanException when `features` cannot be interpreted
     */
    public function allows(string $feature): bool
    {
        return $this->featureFlags()->allows($feature);
    }

    /**
     * The validated feature map, including explicitly disabled flags.
     *
     * @return array<string, bool>
     *
     * @throws MalformedPlanException
     */
    public function features(): array
    {
        return $this->featureFlags()->all();
    }

    /**
     * Just the granted feature keys — for plan comparison tables.
     *
     * @return list<string>
     *
     * @throws MalformedPlanException
     */
    public function enabledFeatures(): array
    {
        return $this->featureFlags()->enabled();
    }

    /**
     * The feature map as a value object, for callers that pass it around.
     *
     * @throws MalformedPlanException
     */
    public function featureFlags(): PlanFeatures
    {
        $changed = $this->signatureChanged('features');

        if ($this->featuresCache === null || $changed) {
            $this->featuresCache = PlanFeatures::fromRaw($this->getAttribute('features'), $this->describe());
        }

        return $this->featuresCache;
    }

    /*
    |--------------------------------------------------------------------------
    | Quota limits — the API `QuotaGuard` (task 2.3) is built on
    |--------------------------------------------------------------------------
    */

    /**
     * The allowance this plan grants for $kind: a non-negative ceiling, or **null
     * for unlimited**. A kind the plan does not declare grants 0.
     *
     * @throws MalformedPlanException when `limits` cannot be interpreted
     */
    public function limitFor(QuotaKind $kind): ?int
    {
        return $this->quotaLimits()->for($kind);
    }

    /**
     * Whether $kind is explicitly unlimited — so a caller can branch without
     * conflating "unlimited" with "not answered".
     *
     * @throws MalformedPlanException
     */
    public function isUnlimited(QuotaKind $kind): bool
    {
        return $this->quotaLimits()->isUnlimited($kind);
    }

    /**
     * Whether the plan prices $kind at all: the difference between a deliberate 0
     * and an omission.
     *
     * @throws MalformedPlanException
     */
    public function declaresLimitFor(QuotaKind $kind): bool
    {
        return $this->quotaLimits()->declares($kind);
    }

    /**
     * The validated limit map, keyed by `QuotaKind::value`.
     *
     * @return array<string, int|null>
     *
     * @throws MalformedPlanException
     */
    public function limits(): array
    {
        return $this->quotaLimits()->all();
    }

    /**
     * The limit map as a value object, for callers that pass it around.
     *
     * @throws MalformedPlanException
     */
    public function quotaLimits(): PlanLimits
    {
        $changed = $this->signatureChanged('limits');

        if ($this->limitsCache === null || $changed) {
            $this->limitsCache = PlanLimits::fromRaw($this->getAttribute('limits'), $this->describe());
        }

        return $this->limitsCache;
    }

    /*
    |--------------------------------------------------------------------------
    | Integrity & queries
    |--------------------------------------------------------------------------
    */

    /**
     * Force both JSON maps through validation. Called by `PlanObserver::saving()`
     * so a malformed plan is rejected at the write rather than at the read.
     *
     * @throws MalformedPlanException
     */
    public function assertWellFormed(): void
    {
        $this->featureFlags();
        $this->quotaLimits();
    }

    /**
     * Only plans that may still be sold or displayed (`idx(active)`).
     *
     * @param  Builder<Plan>  $query
     * @return Builder<Plan>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * Catalogue order: the admin-chosen `sort`, then price, then slug so the order
     * is total (two plans sharing a `sort` never swap between requests).
     *
     * @param  Builder<Plan>  $query
     * @return Builder<Plan>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort')->orderBy('price_cents')->orderBy('slug');
    }

    /**
     * How this plan is named in an error message: slug first, since that is what an
     * admin recognises.
     */
    private function describe(): string
    {
        $slug = $this->getAttribute('slug');

        if (is_string($slug) && $slug !== '') {
            return sprintf('plan "%s"', $slug);
        }

        $id = $this->getAttribute('id');

        return is_string($id) && $id !== ''
            ? sprintf('plan %s', $id)
            : 'an unsaved plan';
    }

    /**
     * Whether the raw JSON behind $key differs from what the memoised view was
     * built from.
     */
    private function signatureChanged(string $key): bool
    {
        // The *raw* attribute: for a JSON cast Eloquent stores the encoded string on
        // both hydration and assignment, so this is a stable, cheap fingerprint.
        $raw = $this->getAttributes()[$key] ?? null;
        $signature = is_string($raw) ? $raw : serialize($raw);

        if (($this->mapSignatures[$key] ?? null) === $signature) {
            return false;
        }

        $this->mapSignatures[$key] = $signature;

        return true;
    }
}
