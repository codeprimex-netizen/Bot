<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a template is *for*, in the provider's own taxonomy (design § Channel Mode data
 * model: `category(MARKETING|UTILITY|AUTHENTICATION)`).
 *
 * The category is not decoration: it is what the provider prices and rate-limits a
 * conversation by, and marketing templates are the ones subject to per-user frequency
 * capping. Stored as declared, so task 8.4 can sync approval state without re-deriving
 * intent and task 8.1's provider-rate branch has the axis it needs to reason about tiers.
 */
enum ChannelTemplateCategory: string
{
    /** Promotional content: offers, announcements, re-engagement. */
    case Marketing = 'MARKETING';

    /** A transaction the customer is already in: receipts, updates, appointments. */
    case Utility = 'UTILITY';

    /** One-time passcodes and account verification. */
    case Authentication = 'AUTHENTICATION';

    /**
     * Whether this category is promotional, and so subject to the strictest provider
     * pacing and per-user frequency caps.
     */
    public function isPromotional(): bool
    {
        return match ($this) {
            self::Marketing => true,
            self::Utility, self::Authentication => false,
        };
    }
}
