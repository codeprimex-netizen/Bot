<?php

declare(strict_types=1);

namespace App\Enums;

use InvalidArgumentException;

/**
 * The canonical catalogue of plan-gated features — the single vocabulary shared by
 * `plans.features` JSON, `PlanGate`, the resolution-pipeline stages
 * (`ResolverStage::requiresFeature()`, task 11.3), the panel components (Req 22.2 /
 * C5), and the Admin plan editor (task 31.1).
 *
 * ## Why an enum and not free strings
 *
 * A plan gate fails **closed**: an absent flag means "not included" (see
 * `PlanFeatures`). That is the right default, and it is also what makes a typo
 * catastrophic *and* invisible — `requiresFeature(): 'a_i'` would skip the LLM stage
 * for every tenant on every plan, forever, with no error anywhere. There is no test
 * that can fail for a gate key nobody ever grants, so the mistake is unfalsifiable.
 *
 * Naming the features once, here, converts that class of bug into a loud
 * `InvalidArgumentException` at the call site (`PlanGate::allows($t, 'a_i')`) and
 * gives the plan editor a list to render instead of a free-text box.
 *
 * The *storage* layer stays deliberately permissive: `PlanFeatures` still accepts any
 * well-formed `"key": bool` pair, so a plan row written by an older deploy, a
 * migration, or a future phase is readable rather than fatal. The catalogue is the
 * gate's vocabulary, not a database constraint — `keys()` is what an admin screen
 * offers, and a stray key left in a plan row simply gates nothing.
 *
 * ## Adding a feature
 *
 * Add the case here, then grant it in `PlanSeeder` / the plan editor. Existing plans
 * do **not** get it: an absent flag is `false`, so a new gate key starts denied for
 * everyone until an admin grants it — the safe direction.
 */
enum PlanFeature: string
{
    /*
    |--------------------------------------------------------------------------
    | Chatbot & AI (B2–B5)
    |--------------------------------------------------------------------------
    */

    /** LLM smart replies, RAG, semantic cache — the whole `LlmReplyStage` (Req 13 / B4). */
    case Ai = 'ai';

    /** Speech-to-text for inbound voice notes (Req 13 / B4, Req 15 / B6). */
    case Stt = 'stt';

    /** The no-code flow builder and `ActiveFlowStage` (Req 14 / B5, Req 22.1 / C5). */
    case Flows = 'flows';

    /** Keyword-trigger auto-replies — `KeywordTriggerStage` (Req 12.1 / B3). */
    case KeywordTriggers = 'keyword_triggers';

    /** Intent detection + FAQ answers — `IntentFaqStage` (Req 12.2 / B3). */
    case Faq = 'faq';

    /** Live-agent handoff and the agent inbox — `LiveAgentStage` (Req 16 / B7). */
    case Handoff = 'handoff';

    /** A/B testing of replies, flows, and campaign copy (Req 18 / B9). */
    case AbTesting = 'ab_testing';

    /** Outbound integrations / action nodes (HTTP, CRM, sheets) inside flows (Req 19 / B10). */
    case Integrations = 'integrations';

    /*
    |--------------------------------------------------------------------------
    | Messaging (C3)
    |--------------------------------------------------------------------------
    */

    /** Bulk campaigns and scheduled broadcasts (Req 20.2 / C3). */
    case Campaigns = 'campaigns';

    /** Message templates, including approved Cloud API / BSP templates (Req 20.5 / C3). */
    case Templates = 'templates';

    /*
    |--------------------------------------------------------------------------
    | Channels, groups & contacts (C2, C4)
    |--------------------------------------------------------------------------
    */

    /** WhatsApp Channel (newsletter) management (Req 8 / A8, §Channels Full Mgmt). */
    case Channels = 'channels';

    /** Group management — create, invite, admin ops (Req 7 / A7, §Groups Full Mgmt). */
    case Groups = 'groups';

    /** Own-group member/number extraction (Req 21.4 / C4). */
    case Extraction = 'extraction';

    /*
    |--------------------------------------------------------------------------
    | Platform surface (C1, C6, D)
    |--------------------------------------------------------------------------
    */

    /** The tenant REST API (Req 26 / D3). */
    case Api = 'api';

    /** Outgoing webhooks / event subscriptions (Req 26 / D3). */
    case Webhooks = 'webhooks';

    /** A tenant-owned custom domain for the panel and links (Req 9 / A9). */
    case CustomDomain = 'custom_domain';

    /** White-labelling: tenant branding instead of the platform's (Req 27 / D4). */
    case WhiteLabel = 'white_label';

    /**
     * Human-readable name, shown in upgrade prompts, disabled-feature tooltips, and
     * the plan editor.
     *
     * These strings are platform-authored, never tenant input, which is why
     * `FeatureNotInPlanException` may safely interpolate one into a client-visible
     * message.
     */
    public function label(): string
    {
        return match ($this) {
            self::Ai => 'AI smart replies',
            self::Stt => 'Voice-note transcription',
            self::Flows => 'Flow builder',
            self::KeywordTriggers => 'Keyword auto-replies',
            self::Faq => 'Intent & FAQ replies',
            self::Handoff => 'Live-agent handoff',
            self::AbTesting => 'A/B testing',
            self::Integrations => 'Integrations & action nodes',
            self::Campaigns => 'Bulk campaigns',
            self::Templates => 'Message templates',
            self::Channels => 'Channel management',
            self::Groups => 'Group management',
            self::Extraction => 'Group number extraction',
            self::Api => 'REST API access',
            self::Webhooks => 'Webhooks',
            self::CustomDomain => 'Custom domain',
            self::WhiteLabel => 'White labelling',
        };
    }

    /**
     * Resolve either form callers use — the enum, or the raw string a route
     * (`plan.feature:ai`), a stage's `requiresFeature()`, or a Blade view carries.
     *
     * @throws InvalidArgumentException on a key that is not in the catalogue
     */
    public static function coerce(self|string $feature): self
    {
        return $feature instanceof self ? $feature : self::fromKey($feature);
    }

    /**
     * The case named by $key, normalising surrounding whitespace and case so a route
     * string or a hand-written config entry is not rejected for cosmetics.
     *
     * An unknown key is a **caller bug and is reported as one**: returning `false`
     * (as a plan lookup would) is precisely the silent-denial-forever failure this
     * catalogue exists to prevent.
     *
     * @throws InvalidArgumentException
     */
    public static function fromKey(string $key): self
    {
        $feature = self::tryFromKey($key);

        if ($feature === null) {
            throw new InvalidArgumentException(sprintf(
                'Unknown plan feature key "%s". A gate key that is not in the catalogue would deny '
                .'every tenant on every plan and never fail a test, so it is refused here. Known keys: %s.',
                mb_strimwidth(addcslashes($key, "\0..\37\177"), 0, 60, '…'),
                implode(', ', self::keys()),
            ));
        }

        return $feature;
    }

    /**
     * The case named by $key, or null — for callers that legitimately probe an
     * unvalidated string (an import, a plan row written by an older deploy).
     */
    public static function tryFromKey(string $key): ?self
    {
        return self::tryFrom(mb_strtolower(trim($key)));
    }

    /**
     * Every gate key, in declaration order.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * `key => label` for the plan editor's checkbox list and plan comparison tables.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
