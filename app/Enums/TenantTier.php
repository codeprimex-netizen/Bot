<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How much of the platform a tenant gets to itself (Req 1.6 / A1).
 *
 * The three tiers are a **ladder, not a fork**: every tier runs the same code and
 * the same row-level `tenant_id` isolation, and moving a tenant up the ladder is a
 * value change in `tenant_tiers` (or a `config/wa.php` flip) rather than a code
 * change. `App\Services\Tenancy\TierResolver` is the only thing that reads the tier,
 * so nothing downstream branches on it:
 *
 * | Tier               | Isolation                                        | Migration path |
 * |--------------------|--------------------------------------------------|----------------|
 * | `SHARED`           | row-level `tenant_id`, shared workers + DB       | grows in place |
 * | `DEDICATED_WORKER` | shared DB, own Supervisor worker group + lanes   | flip the tier; no data move |
 * | `DEDICATED_DB`     | own MySQL connection/shard, own workers          | online copy → dual-write → cutover via `TierResolver::connection()` |
 *
 * The ladder is hybrid by design: a high-volume tenant can sit on
 * `DEDICATED_WORKER` (its own lanes, still the shared database) indefinitely, and
 * only escalate to `DEDICATED_DB` when isolation or data-residency demands it.
 */
enum TenantTier: string
{
    case Shared = 'SHARED';
    case DedicatedWorker = 'DEDICATED_WORKER';
    case DedicatedDb = 'DEDICATED_DB';

    /**
     * Lane weight used when neither the tenant's row nor `config/wa.php` names one.
     *
     * Weight 1 is the floor of the weighted-fair scheduler (Req 1.7 / A1): a lane
     * always gets *some* share, never zero, so a misconfigured weight can never
     * starve a tenant outright.
     */
    public const int FALLBACK_LANE_WEIGHT = 1;

    /**
     * Built-in weights, used when `wa.tenancy.tiers.lane_weights` names none.
     *
     * @var array<string, int>
     */
    private const array BUILT_IN_LANE_WEIGHTS = [
        'SHARED' => 1,
        'DEDICATED_WORKER' => 5,
        'DEDICATED_DB' => 10,
    ];

    /**
     * Whether this tier's work runs on its own Supervisor worker group / queue lanes
     * instead of the shared ones.
     *
     * The dispatch scheduler (task 1.4) asks this to decide which lane set a tenant's
     * jobs belong on; both dedicated tiers get their own workers, only the top tier
     * also gets its own database.
     */
    public function usesDedicatedWorkers(): bool
    {
        return match ($this) {
            self::Shared => false,
            self::DedicatedWorker, self::DedicatedDb => true,
        };
    }

    /**
     * Whether this tier's rows live on their own database connection / shard.
     *
     * Only `DEDICATED_DB` does. `TierResolver::connection()` returns `null` (the
     * shared connection) for every other tier without consulting any shard config.
     */
    public function usesDedicatedConnection(): bool
    {
        return $this === self::DedicatedDb;
    }

    /**
     * The fair-scheduling weight a tenant on this tier gets when its `tenant_tiers`
     * row does not override one (Req 1.7 / A1).
     *
     * Read from `wa.tenancy.tiers.lane_weights` so the whole platform's fairness
     * curve is an operator config flip; a missing, non-numeric, or non-positive
     * config value falls back to the built-in weight rather than producing a zero
     * (which would starve the lane).
     */
    public function defaultLaneWeight(): int
    {
        $configured = config('wa.tenancy.tiers.lane_weights.'.$this->value);

        if (is_int($configured) && $configured > 0) {
            return $configured;
        }

        if (is_string($configured) && ctype_digit($configured) && (int) $configured > 0) {
            return (int) $configured;
        }

        return self::BUILT_IN_LANE_WEIGHTS[$this->value] ?? self::FALLBACK_LANE_WEIGHT;
    }

    /**
     * Position on the ladder — `SHARED` (0) to `DEDICATED_DB` (2).
     *
     * Escalation is monotonic (a tenant only ever moves up), so provisioning and
     * admin screens compare ranks rather than enumerating pairs of tiers.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Shared => 0,
            self::DedicatedWorker => 1,
            self::DedicatedDb => 2,
        };
    }

    /**
     * Whether this tier is $other or higher up the ladder.
     */
    public function isAtLeast(self $other): bool
    {
        return $this->rank() >= $other->rank();
    }

    /**
     * Human-readable label for admin panels.
     */
    public function label(): string
    {
        return match ($this) {
            self::Shared => 'Shared',
            self::DedicatedWorker => 'Dedicated workers',
            self::DedicatedDb => 'Dedicated database',
        };
    }

    /**
     * The tier named by a config/environment value, or null when it names none.
     *
     * Accepts the canonical `DEDICATED_DB` spelling as well as the looser
     * `dedicated-db` / `dedicated db` an operator may type into `.env`, so a tier
     * flip never fails silently on formatting.
     */
    public static function tryFromLoose(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $normalized = strtoupper(str_replace(['-', ' '], '_', trim($value)));

        return self::tryFrom($normalized);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
