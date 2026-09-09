<?php

declare(strict_types=1);

namespace App\Enums;

use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Carbon;

/**
 * A metered, period-bucketed limit tracked in `tenant_usage`.
 *
 * Counters come in two shapes:
 *  - **accruing** counters (`MESSAGES_MONTHLY`, `MESSAGES_DAILY`, `AI_CREDITS`)
 *    reset when their period rolls over, so they are bucketed by a period key;
 *  - **gauges** (`SESSIONS`, `CONTACTS`, `CAMPAIGNS_CONCURRENT`) measure a
 *    point-in-time count that never resets, so they share one standing bucket.
 */
enum QuotaKind: string
{
    case MessagesMonthly = 'MESSAGES_MONTHLY';
    case MessagesDaily = 'MESSAGES_DAILY';
    case Sessions = 'SESSIONS';
    case Contacts = 'CONTACTS';
    case AiCredits = 'AI_CREDITS';
    case CampaignsConcurrent = 'CAMPAIGNS_CONCURRENT';

    /**
     * Period key for the standing (never-resetting) bucket of a gauge quota.
     */
    public const GAUGE_PERIOD_KEY = 'CURRENT';

    /**
     * A gauge measures current state (how many sessions exist right now) rather
     * than accrued usage over a period.
     */
    public function isGauge(): bool
    {
        return match ($this) {
            self::Sessions, self::Contacts, self::CampaignsConcurrent => true,
            self::MessagesMonthly, self::MessagesDaily, self::AiCredits => false,
        };
    }

    /**
     * `date()` format used to bucket this quota, or null for gauges.
     */
    public function periodFormat(): ?string
    {
        return match ($this) {
            self::MessagesMonthly, self::AiCredits => 'Y-m',
            self::MessagesDaily => 'Y-m-d',
            self::Sessions, self::Contacts, self::CampaignsConcurrent => null,
        };
    }

    /**
     * The `tenant_usage.period_key` this quota is counted under at $at.
     *
     * Keys are computed in $timezone (the tenant's timezone) so a tenant's
     * daily bucket rolls over at their local midnight.
     */
    public function periodKey(?DateTimeInterface $at = null, string $timezone = 'UTC'): string
    {
        $format = $this->periodFormat();

        if ($format === null) {
            return self::GAUGE_PERIOD_KEY;
        }

        $moment = $at === null
            ? Carbon::now($timezone)
            : Carbon::instance(Carbon::parse($at))->setTimezone(new DateTimeZone($timezone));

        return $moment->format($format);
    }

    /**
     * Human-readable label for panels and quota-exhausted messages.
     */
    public function label(): string
    {
        return match ($this) {
            self::MessagesMonthly => 'Messages per month',
            self::MessagesDaily => 'Messages per day',
            self::Sessions => 'Connected numbers',
            self::Contacts => 'Contacts',
            self::AiCredits => 'AI credits',
            self::CampaignsConcurrent => 'Concurrent campaigns',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
