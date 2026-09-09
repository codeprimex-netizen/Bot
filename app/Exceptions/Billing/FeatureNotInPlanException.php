<?php

declare(strict_types=1);

namespace App\Exceptions\Billing;

use App\Enums\PlanFeature;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * A tenant asked for a feature its plan does not include — refused with **402 or
 * 403** (Req 11.3 / B2, Req 22.2 / C5, Correctness Property 7).
 *
 * Raised by `PlanGate::authorize()`, by the `plan.feature` middleware, and by the
 * `plan.feature` Gate ability's denial response. It is **non-retryable**: unlike
 * `QuotaExceededException` (task 2.4), nothing about waiting changes the answer — the
 * plan has to change.
 *
 * ## Which status, and why
 *
 * The design lists this exception as "402/403" without splitting the two, so the rule
 * is fixed here and asserted by `PlanGateTest`:
 *
 * | Situation                                                        | Status | Reads as        |
 * |------------------------------------------------------------------|--------|-----------------|
 * | at least one **active** plan in the catalogue grants the feature   | **402** | "upgrade your plan" |
 * | no active plan grants it (retired, beta, platform-disabled, unpriced) | **403** | "not permitted"  |
 * | no tenant resolved on the request at all                          | **403** | "not permitted"  |
 *
 * The distinction is the one a client can act on. **402 Payment Required** promises a
 * remedy: the feature is on sale, and paying for a higher plan turns this into a 200 —
 * so the panel shows an upgrade CTA and an API client can surface a billing link.
 * **403 Forbidden** promises no remedy: no amount of money buys the feature right now,
 * so an upgrade CTA would be a lie and the honest UI is a disabled control with an
 * explanation (Req 22.2, Design Principle 7). Deriving the status from the live
 * catalogue rather than hard-coding it per feature keeps that promise true after an
 * admin retires or launches a plan, with no code change.
 *
 * A malformed plan is deliberately *not* on this table: `MalformedPlanException`
 * propagates instead, because corrupt platform data is neither a billing state nor a
 * permission state (see that class).
 *
 * ## What may be surfaced
 *
 * The **feature** is safe to name: `PlanFeature::label()` is a platform-authored
 * string, the tenant already sees the feature in the pricing table, and hiding it
 * would make the refusal unactionable. The upgrade plan slugs are likewise public
 * catalogue data. Everything else follows the redaction discipline of
 * `CrossTenantAccessException`: caller-supplied text is escaped and truncated, and
 * only the *subject* tenant's own id appears in the internal message (never another
 * tenant's, and never in a response body).
 */
final class FeatureNotInPlanException extends RuntimeException implements HttpExceptionInterface
{
    /**
     * The feature is sold on another active plan: the tenant can have it by paying.
     */
    public const int STATUS_UPGRADE_AVAILABLE = 402;

    /**
     * The feature cannot currently be bought at all.
     */
    public const int STATUS_NOT_AVAILABLE = 403;

    /**
     * Stable machine-readable code for the 402 case — the one an API client turns
     * into a billing prompt.
     */
    public const string ERROR_CODE = 'feature_not_in_plan';

    /**
     * Stable machine-readable code for the 403 case.
     */
    public const string ERROR_CODE_UNAVAILABLE = 'feature_unavailable';

    /**
     * @param  list<string>  $upgradePlans  slugs of active plans that grant the feature
     */
    private function __construct(
        public readonly PlanFeature $feature,
        public readonly ?string $tenantId,
        public readonly bool $upgradeable,
        public readonly array $upgradePlans,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * The tenant's plan omits $feature, but some active plan sells it — **402**.
     *
     * @param  list<string>  $upgradePlans  slugs to offer, in catalogue order
     */
    public static function upgradeRequired(PlanFeature $feature, string $tenantId, array $upgradePlans, ?string $planSlug = null): self
    {
        return new self($feature, $tenantId, true, $upgradePlans, sprintf(
            'Tenant %s is on %s, which does not include the "%s" feature. It is available on: %s.',
            $tenantId,
            $planSlug === null || $planSlug === '' ? 'no plan' : sprintf('plan "%s"', self::redact($planSlug)),
            $feature->value,
            $upgradePlans === [] ? 'no active plan' : implode(', ', array_map(self::redact(...), $upgradePlans)),
        ));
    }

    /**
     * No active plan grants $feature, so there is nothing to upgrade to — **403**.
     */
    public static function notAvailable(PlanFeature $feature, string $tenantId, ?string $planSlug = null): self
    {
        return new self($feature, $tenantId, false, [], sprintf(
            'Tenant %s requested the "%s" feature, which no active plan grants — it is retired, '
            .'not yet launched, or disabled platform-wide, so this is a refusal and not an upgrade prompt. '
            .'Tenant plan: %s.',
            $tenantId,
            $feature->value,
            $planSlug === null || $planSlug === '' ? 'none' : sprintf('"%s"', self::redact($planSlug)),
        ));
    }

    /**
     * A feature-gated route or ability was reached with **no tenant bound** — **403**.
     *
     * Fails closed rather than guessing: `plan.feature` is documented to sit after
     * `resolve.tenant` (and, in the panel, after `tenant.member`), so an unbound
     * context means either a misordered middleware stack or a genuinely tenant-less
     * request. Neither one has a plan, and a plan gate with no plan grants nothing.
     */
    public static function withoutTenant(PlanFeature $feature): self
    {
        return new self($feature, null, false, [], sprintf(
            'Refusing the "%s" feature gate with no tenant bound: there is no plan to consult. '
            .'Stack `plan.feature` after `resolve.tenant`, or bind a tenant with TenantContext::runFor().',
            $feature->value,
        ));
    }

    public function getStatusCode(): int
    {
        return $this->upgradeable ? self::STATUS_UPGRADE_AVAILABLE : self::STATUS_NOT_AVAILABLE;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return [];
    }

    /**
     * The stable code for this flavour of the refusal.
     */
    public function errorCode(): string
    {
        return $this->upgradeable ? self::ERROR_CODE : self::ERROR_CODE_UNAVAILABLE;
    }

    /**
     * The sentence a client may be shown: names the feature (safe, and the only way
     * the message is actionable) and promises a remedy only when one exists.
     */
    public function publicMessage(): string
    {
        return $this->upgradeable
            ? sprintf('%s is not included in your current plan. Upgrade to enable it.', $this->feature->label())
            : sprintf('%s is not available on your account.', $this->feature->label());
    }

    /**
     * Keep caller-supplied text out of logs verbatim, exactly as the tenancy
     * exceptions do it.
     */
    private static function redact(string $value): string
    {
        return mb_strimwidth(addcslashes($value, "\0..\37\177"), 0, 120, '…');
    }
}
