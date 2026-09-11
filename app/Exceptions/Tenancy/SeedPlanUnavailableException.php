<?php

declare(strict_types=1);

namespace App\Exceptions\Tenancy;

use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * A tenant was to be provisioned onto a plan that does not exist — **503**, and the
 * tenant is not created (Req 1.8 / A1; Req 25.1 / D2).
 *
 * ## Why this fails loudly instead of provisioning a planless tenant
 *
 * `tenants.plan_id` is nullable and `PlanRepository::forTenant()` returns `null` for a
 * tenant with no plan, which `PlanGate` and `QuotaGuard` read as **no features and no
 * allowance**. That is the right default for a plan retired out from under a live
 * tenant — it fails closed. It is the *wrong* outcome for provisioning: the customer
 * would get an account that signs in, shows every screen, and refuses every action,
 * with nothing anywhere saying why. Silently provisioning one is worse than refusing,
 * so Req 1.8 names the seed plan as part of the atomic unit and this is what happens
 * when that part cannot be satisfied.
 *
 * ## Why 503 and not 500
 *
 * The condition is a **missing seed**, not a broken deployment: run
 * `Database\Seeders\PlanSeeder` (or point `wa.tenancy.default_plan_slug` at a plan
 * that exists) and the very same request succeeds. It is therefore retryable, which
 * is what 503 means — the same choice `KeyUnavailableException` makes for an
 * unreachable key store.
 */
final class SeedPlanUnavailableException extends RuntimeException implements HttpExceptionInterface
{
    public const int STATUS = 503;

    public const string PUBLIC_MESSAGE = 'Account creation is temporarily unavailable. Please try again shortly.';

    public const string ERROR_CODE = 'seed_plan_unavailable';

    private function __construct(
        public readonly string $slug,
        public readonly bool $requestedExplicitly,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * The plan the operator asked for by slug is not in the catalogue.
     */
    public static function unknownSlug(string $slug): self
    {
        return new self($slug, true, sprintf(
            'Cannot provision a tenant onto plan [%s]: no such plan. The tenant was not '
            .'created — a tenant with no plan is gated to no features and no allowance, '
            .'which would look like a broken account rather than a failed signup.',
            $slug,
        ));
    }

    /**
     * Nothing was asked for, and `wa.tenancy.default_plan_slug` names a plan that has
     * not been seeded.
     */
    public static function noDefaultPlan(string $slug): self
    {
        return new self($slug, false, sprintf(
            'Cannot provision a tenant: wa.tenancy.default_plan_slug names plan [%s], '
            .'which does not exist. Run `php artisan db:seed --class=Database\\Seeders\\PlanSeeder` '
            .'or point the config at a seeded plan. The tenant was not created, because a '
            .'tenant with no plan has no features and no allowance.',
            $slug,
        ));
    }

    public function getStatusCode(): int
    {
        return self::STATUS;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return [];
    }

    public function publicMessage(): string
    {
        return self::PUBLIC_MESSAGE;
    }
}
