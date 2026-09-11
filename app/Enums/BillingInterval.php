<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The period a plan's price covers — the `plans.interval` column.
 *
 * Only whole-month multiples exist by design: every renewal date is expressible
 * as "the same day of the month, N months on", which keeps proration and
 * period-boundary arithmetic (phase 10, `subscriptions.current_period_end`)
 * free of day-count edge cases.
 */
enum BillingInterval: string
{
    case Month = 'MONTH';
    case Year = 'YEAR';

    /**
     * Length of one billing period in whole months.
     */
    public function months(): int
    {
        return match ($this) {
            self::Month => 1,
            self::Year => 12,
        };
    }

    /**
     * Human-readable label for pricing tables and invoices.
     */
    public function label(): string
    {
        return match ($this) {
            self::Month => 'Monthly',
            self::Year => 'Yearly',
        };
    }

    /**
     * Suffix for a rendered price, e.g. "$49 / mo".
     */
    public function shortSuffix(): string
    {
        return match ($this) {
            self::Month => 'mo',
            self::Year => 'yr',
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
