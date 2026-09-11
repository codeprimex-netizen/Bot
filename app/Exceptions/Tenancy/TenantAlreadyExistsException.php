<?php

declare(strict_types=1);

namespace App\Exceptions\Tenancy;

use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Provisioning was asked for a slug or subdomain another tenant already owns — **409**
 * (Req 1.8 / A1; Req 9.3 / A9).
 *
 * ## Why this is not "return the existing tenant"
 *
 * `provision()` is a *create*, and the tempting idempotent reading — "the tenant is
 * already there, hand it back" — is the dangerous one: the caller would receive a
 * tenant it does not own, with somebody else's contacts, sessions and wallet behind
 * it, because a slug collision is far more likely to be two different customers
 * picking the same company name than one customer retrying. So a collision is a
 * conflict, and the caller (registration form, admin screen) asks for another label.
 *
 * ## Why the *host* is checked, not just the unique index
 *
 * `tenants.slug` and `tenants.subdomain` are both unique, but the host a tenant
 * answers on is either of them: `SubdomainTenantResolver` matches an explicit
 * `subdomain` first and falls back to `slug` where a tenant never set one. A new
 * tenant whose `subdomain` equals an existing tenant's `slug` would therefore pass
 * both unique indexes while quietly taking over the host that tenant is reachable at.
 * `hostTaken()` is that case, refused for the same reason as a straight duplicate.
 */
final class TenantAlreadyExistsException extends RuntimeException implements HttpExceptionInterface
{
    public const int STATUS = 409;

    /**
     * The only sentence a client is ever shown. It names neither the colliding tenant
     * nor which of the two labels collided, so the endpoint cannot be used to
     * enumerate which company names are already customers.
     */
    public const string PUBLIC_MESSAGE = 'That workspace name or address is already taken. Please choose another.';

    public const string ERROR_CODE = 'tenant_already_exists';

    private function __construct(
        public readonly string $attribute,
        public readonly string $value,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function slug(string $slug): self
    {
        return new self('slug', $slug, sprintf(
            'A tenant with slug [%s] already exists. Slugs are unique: they name the '
            .'tenant in URLs, exports and support tooling.',
            $slug,
        ));
    }

    public static function subdomain(string $subdomain): self
    {
        return new self('subdomain', $subdomain, sprintf(
            'A tenant with subdomain [%s] already exists.',
            $subdomain,
        ));
    }

    /**
     * The label is free on both unique indexes but is already how an existing tenant
     * is reached — its `slug` with no `subdomain` set.
     */
    public static function hostTaken(string $attribute, string $label): self
    {
        return new self($attribute, $label, sprintf(
            'Label [%s] (from "%s") is already the host of an existing tenant — it is '
            .'that tenant\'s slug and it has no explicit subdomain, so `%s.{apex}` '
            .'resolves to it today. Taking the label would silently move its traffic.',
            $label,
            $attribute,
            $label,
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
