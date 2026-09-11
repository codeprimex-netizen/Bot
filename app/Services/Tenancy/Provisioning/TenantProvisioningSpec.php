<?php

declare(strict_types=1);

namespace App\Services\Tenancy\Provisioning;

use App\Enums\TenantRole;
use App\Enums\TenantTier;
use App\Exceptions\Tenancy\InvalidProvisioningSpecException;
use App\Models\Plan;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * The `array $spec` of `TenantLifecycle::provision()`, validated once and turned into
 * typed, defaulted values the steps can trust (Req 1.8 / A1).
 *
 * ## Why validation happens here, before any step runs
 *
 * Provisioning writes to five places, and the first thing it writes is a row every
 * later write hangs off. Validating as it goes would mean discovering a bad locale
 * *after* the tenant row, the plan association and the DEK exist — recoverable
 * (the pipeline compensates), but it turns a caller's typo into a rolled-back
 * transaction and a wasted key. So the whole spec is parsed and rejected up front,
 * and by the time step 1 runs there is nothing left to be invalid.
 *
 * ## Everything is refused, nothing is repaired
 *
 * An unknown key, an unknown timezone, a reserved subdomain and a zero lane weight are
 * all `InvalidProvisioningSpecException`. The one thing this class *does* do silently
 * is **normalise**: names are trimmed, labels are lower-cased and slugified, so
 * `"  Acme Corp "` and `"Acme_Corp"` arrive at the same slug rather than at two
 * tenants that look identical in every panel. Normalising a value is not the same as
 * accepting a wrong one.
 *
 * ## Accepted keys
 *
 * | Key | Type | Default |
 * |---|---|---|
 * | `name` | string | **required** |
 * | `slug` | string | `Str::slug(name)` |
 * | `subdomain` | string\|null | the slug (explicit `null` = no subdomain) |
 * | `plan` | `Plan`\|string (slug) | `wa.tenancy.default_plan_slug` |
 * | `timezone` | string (IANA) | `wa.tenancy.default_timezone` |
 * | `locale` | string | `wa.tenancy.default_locale` |
 * | `trial_days` | int ≥ 0 | `wa.tenancy.trial_days` |
 * | `trial_ends_at` | `Carbon`\|string\|null | derived from `trial_days` |
 * | `tier` | `TenantTier`\|string | none (the tenant runs on the configured default tier) |
 * | `lane_weight` | int ≥ 1 | none — requires `tier` |
 * | `data_region` | string | none — requires `tier` |
 * | `shard_key` | string | none — requires `tier` |
 * | `owner` | `User`\|int | none (see `Steps\AssignOwnerMembershipStep`) |
 * | `owner_role` | `TenantRole`\|string | `TenantRole::Owner` |
 */
final class TenantProvisioningSpec
{
    /**
     * The complete accepted key set. Anything else is a typo, and a typo is refused —
     * see `InvalidProvisioningSpecException::unknownKeys()` for why.
     */
    private const array KEYS = [
        'name', 'slug', 'subdomain', 'plan', 'timezone', 'locale',
        'trial_days', 'trial_ends_at',
        'tier', 'lane_weight', 'data_region', 'shard_key',
        'owner', 'owner_role',
    ];

    /**
     * Keys that only make sense on a `tenant_tiers` row, i.e. only alongside `tier`.
     */
    private const array TIER_DETAIL_KEYS = ['lane_weight', 'data_region', 'shard_key'];

    /**
     * One DNS label — identical to `SubdomainTenantResolver::LABEL_PATTERN`, because a
     * label this class accepts must be one that resolver can later match. The reserved
     * list is read from the same `wa.tenancy.reserved_subdomains` config for the same
     * reason: policy lives in config, so the two cannot diverge.
     */
    private const string LABEL_PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/';

    private const int NAME_LIMIT = 255;

    private const int LOCALE_LIMIT = 10;

    /**
     * @param  string|null  $planSlug  null = seed the configured default plan
     * @param  Carbon|null  $trialEndsAt  when the TRIAL status stops covering this tenant
     * @param  TenantTier|null  $tier  null = no `tenant_tiers` row; the tenant runs on the config default
     * @param  int|null  $ownerUserId  null = no membership row is created (see the step)
     */
    private function __construct(
        public readonly string $name,
        public readonly string $slug,
        public readonly ?string $subdomain,
        public readonly ?string $planSlug,
        public readonly string $timezone,
        public readonly string $locale,
        public readonly ?Carbon $trialEndsAt,
        public readonly ?TenantTier $tier,
        public readonly ?int $laneWeight,
        public readonly ?string $dataRegion,
        public readonly ?string $shardKey,
        public readonly ?int $ownerUserId,
        public readonly TenantRole $ownerRole,
    ) {}

    /**
     * Parse and validate a provisioning spec.
     *
     * @param  array<array-key, mixed>  $spec
     *
     * @throws InvalidProvisioningSpecException on any unknown key or unusable value
     */
    public static function fromArray(array $spec): self
    {
        self::assertKnownKeys($spec);

        $name = self::name($spec);
        $slug = self::label('slug', $spec['slug'] ?? $name);
        $tier = self::tier($spec);

        return new self(
            name: $name,
            slug: $slug,
            subdomain: self::subdomain($spec, $slug),
            planSlug: self::planSlug($spec),
            timezone: self::timezone($spec),
            locale: self::locale($spec),
            trialEndsAt: self::trialEndsAt($spec),
            tier: $tier,
            laneWeight: self::laneWeight($spec, $tier),
            dataRegion: self::tierDetail($spec, 'data_region', $tier),
            shardKey: self::tierDetail($spec, 'shard_key', $tier),
            ownerUserId: self::ownerUserId($spec),
            ownerRole: self::ownerRole($spec),
        );
    }

    /**
     * The tenant attributes the record step writes — everything about the tenant row
     * itself, and nothing about the four side tables.
     *
     * @return array<string, mixed>
     */
    public function tenantAttributes(): array
    {
        return [
            'name' => $this->name,
            'slug' => $this->slug,
            'subdomain' => $this->subdomain,
            'timezone' => $this->timezone,
            'locale' => $this->locale,
            'trial_ends_at' => $this->trialEndsAt,
        ];
    }

    /**
     * Whether a `tenant_tiers` row is needed at all.
     *
     * A tier that *is* the configured default gets no row: `TierResolver` already
     * answers that tier for a tenant with none, and a redundant row would have to be
     * kept in step with `wa.tenancy.tiers.default` for ever after (flip the default and
     * every tenant provisioned "on the default" would be pinned to the old one).
     * Details — a pinned weight, region or shard — always need a row, whatever the tier.
     */
    public function needsTierRow(): bool
    {
        if ($this->tier === null) {
            return false;
        }

        if ($this->laneWeight !== null || $this->dataRegion !== null || $this->shardKey !== null) {
            return true;
        }

        return $this->tier !== self::defaultTier();
    }

    /*
    |--------------------------------------------------------------------------
    | Parsing
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<array-key, mixed>  $spec
     */
    private static function assertKnownKeys(array $spec): void
    {
        $unknown = [];

        foreach (array_keys($spec) as $key) {
            if (! in_array((string) $key, self::KEYS, true)) {
                $unknown[] = (string) $key;
            }
        }

        if ($unknown !== []) {
            throw InvalidProvisioningSpecException::unknownKeys($unknown, self::KEYS);
        }
    }

    /**
     * @param  array<array-key, mixed>  $spec
     */
    private static function name(array $spec): string
    {
        $name = is_string($spec['name'] ?? null) ? trim((string) $spec['name']) : '';

        if ($name === '') {
            throw InvalidProvisioningSpecException::missingName();
        }

        return mb_substr($name, 0, self::NAME_LIMIT);
    }

    /**
     * Normalise a caller-supplied label (or a name to derive one from) into a DNS label
     * that is free to use.
     */
    private static function label(string $key, mixed $value): string
    {
        if (! is_string($value)) {
            throw InvalidProvisioningSpecException::invalidLabel($key, get_debug_type($value), 'not a string');
        }

        // `Str::slug` handles the interesting half: accents folded, spaces and
        // underscores to hyphens, everything else dropped. It cannot produce an
        // over-long label or refuse a reserved one, so those are checked after.
        $label = Str::slug(Str::lower(trim($value)));

        if ($label === '') {
            throw InvalidProvisioningSpecException::invalidLabel($key, $value, 'nothing usable is left after normalisation');
        }

        if (strlen($label) > 63) {
            throw InvalidProvisioningSpecException::invalidLabel($key, $value, 'longer than 63 characters');
        }

        if (preg_match(self::LABEL_PATTERN, $label) !== 1) {
            throw InvalidProvisioningSpecException::invalidLabel($key, $value, 'not a valid DNS label');
        }

        if (in_array($label, self::reservedLabels(), true)) {
            throw InvalidProvisioningSpecException::invalidLabel($key, $value, 'reserved for the platform');
        }

        return $label;
    }

    /**
     * The tenant's host label. Defaults to the slug — Req 9.3 spells a tenant's host as
     * `{slug}.{apex}`, and `SubdomainTenantResolver` falls back to the slug for tenants
     * with no explicit subdomain, so writing it out here makes the resolver's two
     * lookups disjoint rather than relying on the fallback.
     *
     * An explicit `null` is honoured: a tenant provisioned by a platform admin for
     * API-only use has no host to claim.
     *
     * @param  array<array-key, mixed>  $spec
     */
    private static function subdomain(array $spec, string $slug): ?string
    {
        if (! array_key_exists('subdomain', $spec)) {
            return $slug;
        }

        if ($spec['subdomain'] === null) {
            return null;
        }

        return self::label('subdomain', $spec['subdomain']);
    }

    /**
     * The plan slug to seed, or null to use `wa.tenancy.default_plan_slug`.
     *
     * A `Plan` instance is accepted and reduced to its slug rather than kept: the step
     * re-reads it through `PlanRepository` so provisioning cannot be handed a stale or
     * unsaved model.
     *
     * @param  array<array-key, mixed>  $spec
     */
    private static function planSlug(array $spec): ?string
    {
        $plan = $spec['plan'] ?? null;

        if ($plan === null) {
            return null;
        }

        $slug = $plan instanceof Plan ? $plan->slug : $plan;

        if (! is_string($slug) || trim($slug) === '') {
            throw InvalidProvisioningSpecException::invalidLabel('plan', get_debug_type($plan), 'not a plan or plan slug');
        }

        return trim($slug);
    }

    /**
     * @param  array<array-key, mixed>  $spec
     */
    private static function timezone(array $spec): string
    {
        $timezone = $spec['timezone'] ?? config('wa.tenancy.default_timezone', 'UTC');
        $timezone = is_string($timezone) ? trim($timezone) : '';

        if ($timezone === '' || ! in_array($timezone, timezone_identifiers_list(), true)) {
            throw InvalidProvisioningSpecException::invalidTimezone(is_string($spec['timezone'] ?? null) ? (string) $spec['timezone'] : $timezone);
        }

        return $timezone;
    }

    /**
     * @param  array<array-key, mixed>  $spec
     */
    private static function locale(array $spec): string
    {
        $locale = $spec['locale'] ?? config('wa.tenancy.default_locale', 'en');
        $locale = is_string($locale) ? trim($locale) : '';

        if ($locale === ''
            || strlen($locale) > self::LOCALE_LIMIT
            || preg_match('/^[A-Za-z]{2,3}(?:[_-][A-Za-z0-9]{2,8})*$/', $locale) !== 1) {
            throw InvalidProvisioningSpecException::invalidLocale($locale);
        }

        return $locale;
    }

    /**
     * When the trial ends. `trial_days` and `trial_ends_at` are mutually exclusive —
     * accepting both would mean silently preferring one, and the two disagreeing is
     * exactly the case where guessing is wrong.
     *
     * @param  array<array-key, mixed>  $spec
     */
    private static function trialEndsAt(array $spec): ?Carbon
    {
        $hasDays = array_key_exists('trial_days', $spec);
        $hasDate = array_key_exists('trial_ends_at', $spec);

        if ($hasDays && $hasDate) {
            throw InvalidProvisioningSpecException::invalidTrialWindow('both "trial_days" and "trial_ends_at" were given');
        }

        if ($hasDate) {
            $endsAt = $spec['trial_ends_at'];

            if ($endsAt === null) {
                // An open-ended trial: legitimate for an internal or migrated tenant, so
                // it is allowed — but only when asked for explicitly.
                return null;
            }

            if ($endsAt instanceof DateTimeInterface) {
                return Carbon::instance($endsAt);
            }

            if (! is_string($endsAt)) {
                throw InvalidProvisioningSpecException::invalidTrialWindow('"trial_ends_at" is not a date');
            }

            try {
                return Carbon::parse($endsAt);
            } catch (Throwable) {
                throw InvalidProvisioningSpecException::invalidTrialWindow('"trial_ends_at" could not be parsed as a date');
            }
        }

        $days = $hasDays ? $spec['trial_days'] : config('wa.tenancy.trial_days', 14);

        if (! is_numeric($days) || (int) $days < 0 || (float) $days !== (float) (int) $days) {
            throw InvalidProvisioningSpecException::invalidTrialWindow('"trial_days" must be a non-negative integer');
        }

        return Carbon::now()->addDays((int) $days);
    }

    /**
     * @param  array<array-key, mixed>  $spec
     */
    private static function tier(array $spec): ?TenantTier
    {
        $tier = $spec['tier'] ?? null;

        if ($tier === null) {
            self::assertNoOrphanTierDetails($spec);

            return null;
        }

        if ($tier instanceof TenantTier) {
            return $tier;
        }

        $resolved = is_string($tier) ? TenantTier::tryFrom(Str::upper(trim($tier))) : null;

        if ($resolved === null) {
            throw InvalidProvisioningSpecException::invalidTier(is_string($tier) ? $tier : get_debug_type($tier));
        }

        return $resolved;
    }

    /**
     * @param  array<array-key, mixed>  $spec
     */
    private static function assertNoOrphanTierDetails(array $spec): void
    {
        $orphans = [];

        foreach (self::TIER_DETAIL_KEYS as $key) {
            if (array_key_exists($key, $spec) && $spec[$key] !== null) {
                $orphans[] = $key;
            }
        }

        if ($orphans !== []) {
            throw InvalidProvisioningSpecException::tierDetailsWithoutTier($orphans);
        }
    }

    /**
     * @param  array<array-key, mixed>  $spec
     */
    private static function laneWeight(array $spec, ?TenantTier $tier): ?int
    {
        $weight = $spec['lane_weight'] ?? null;

        if ($weight === null || $tier === null) {
            return null;
        }

        if (! is_numeric($weight) || (float) $weight !== (float) (int) $weight || (int) $weight < 1) {
            throw InvalidProvisioningSpecException::invalidLaneWeight(is_scalar($weight) ? (string) $weight : get_debug_type($weight));
        }

        return (int) $weight;
    }

    /**
     * @param  array<array-key, mixed>  $spec
     */
    private static function tierDetail(array $spec, string $key, ?TenantTier $tier): ?string
    {
        $value = $spec[$key] ?? null;

        if ($value === null || $tier === null) {
            return null;
        }

        if (! is_string($value) || trim($value) === '') {
            throw InvalidProvisioningSpecException::invalidLabel($key, get_debug_type($value), 'not a non-empty string');
        }

        return trim($value);
    }

    /**
     * @param  array<array-key, mixed>  $spec
     */
    private static function ownerUserId(array $spec): ?int
    {
        $owner = $spec['owner'] ?? null;

        if ($owner === null) {
            return null;
        }

        if ($owner instanceof User) {
            return (int) $owner->getKey();
        }

        if (is_numeric($owner) && (float) $owner === (float) (int) $owner && (int) $owner > 0) {
            return (int) $owner;
        }

        throw InvalidProvisioningSpecException::unknownOwner(is_scalar($owner) ? (string) $owner : get_debug_type($owner));
    }

    /**
     * @param  array<array-key, mixed>  $spec
     */
    private static function ownerRole(array $spec): TenantRole
    {
        $role = $spec['owner_role'] ?? null;

        if ($role === null) {
            return TenantRole::Owner;
        }

        if ($role instanceof TenantRole) {
            return $role;
        }

        $resolved = is_string($role) ? TenantRole::tryFrom(Str::lower(trim($role))) : null;

        if ($resolved === null) {
            throw InvalidProvisioningSpecException::invalidRole(is_string($role) ? $role : get_debug_type($role));
        }

        return $resolved;
    }

    /**
     * @return list<string>
     */
    private static function reservedLabels(): array
    {
        $configured = config('wa.tenancy.reserved_subdomains');
        $reserved = [];

        foreach (is_array($configured) ? $configured : [] as $label) {
            if (is_string($label) && trim($label) !== '') {
                $reserved[] = Str::lower(trim($label));
            }
        }

        return array_values(array_unique($reserved));
    }

    private static function defaultTier(): TenantTier
    {
        $configured = config('wa.tenancy.tiers.default');

        return (is_string($configured) ? TenantTier::tryFrom($configured) : null) ?? TenantTier::Shared;
    }
}
