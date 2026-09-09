<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\BillingInterval;
use App\Enums\QuotaKind;
use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * The starter catalogue (Req 25.1 / D2).
 *
 * Every environment needs at least one plan before a tenant can exist:
 * `TenantLifecycle::provision` (task 1.2) puts new tenants on
 * `wa.tenancy.default_plan_slug`, which points at `starter` below, and the plan
 * screens of task 31.1 need something to edit.
 *
 * **Idempotent** — keyed on `slug`, so re-running it after a deploy updates the
 * three rows rather than duplicating them. Prices and ceilings here are sensible
 * defaults, not product decisions; an admin edits them in the panel afterwards.
 *
 * Each plan declares **every** `QuotaKind` explicitly, including the ones it grants
 * nothing for: an omitted kind grants 0 anyway (see `PlanLimits`), but writing it
 * out keeps "this tier deliberately has no AI credits" distinguishable from "nobody
 * priced AI credits yet", which is what `PlanLimits::undeclared()` reports.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        foreach (self::plans() as $attributes) {
            Plan::query()->updateOrCreate(['slug' => $attributes['slug']], $attributes);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function plans(): array
    {
        return [
            [
                'slug' => 'starter',
                'name' => 'Starter',
                'price_cents' => 0,
                'currency' => 'USD',
                'interval' => BillingInterval::Month,
                'sort' => 10,
                'active' => true,
                'features' => [
                    'flows' => true,
                    'keyword_triggers' => true,
                    'faq' => true,
                    'ai' => false,
                    'campaigns' => false,
                    'api' => false,
                    'webhooks' => false,
                    'custom_domain' => false,
                    'white_label' => false,
                ],
                'limits' => [
                    QuotaKind::MessagesMonthly->value => 1_000,
                    QuotaKind::MessagesDaily->value => 200,
                    QuotaKind::Sessions->value => 1,
                    QuotaKind::Contacts->value => 500,
                    QuotaKind::AiCredits->value => 0,
                    QuotaKind::CampaignsConcurrent->value => 0,
                ],
            ],
            [
                'slug' => 'growth',
                'name' => 'Growth',
                'price_cents' => 4_900,
                'currency' => 'USD',
                'interval' => BillingInterval::Month,
                'sort' => 20,
                'active' => true,
                'features' => [
                    'flows' => true,
                    'keyword_triggers' => true,
                    'faq' => true,
                    'ai' => true,
                    'campaigns' => true,
                    'api' => true,
                    'webhooks' => true,
                    'custom_domain' => true,
                    'white_label' => false,
                ],
                'limits' => [
                    QuotaKind::MessagesMonthly->value => 25_000,
                    QuotaKind::MessagesDaily->value => 2_000,
                    QuotaKind::Sessions->value => 3,
                    QuotaKind::Contacts->value => 10_000,
                    QuotaKind::AiCredits->value => 5_000,
                    QuotaKind::CampaignsConcurrent->value => 2,
                ],
            ],
            [
                'slug' => 'scale',
                'name' => 'Scale',
                'price_cents' => 19_900,
                'currency' => 'USD',
                'interval' => BillingInterval::Month,
                'sort' => 30,
                'active' => true,
                'features' => [
                    'flows' => true,
                    'keyword_triggers' => true,
                    'faq' => true,
                    'ai' => true,
                    'campaigns' => true,
                    'api' => true,
                    'webhooks' => true,
                    'custom_domain' => true,
                    'white_label' => true,
                ],
                'limits' => [
                    // null = unlimited. The daily ceiling stays finite even here: it is
                    // the anti-ban / noisy-neighbour bound (Req 30.6), not a price lever.
                    QuotaKind::MessagesMonthly->value => null,
                    QuotaKind::MessagesDaily->value => 20_000,
                    QuotaKind::Sessions->value => 10,
                    QuotaKind::Contacts->value => null,
                    QuotaKind::AiCredits->value => 50_000,
                    QuotaKind::CampaignsConcurrent->value => 10,
                ],
            ],
        ];
    }
}
