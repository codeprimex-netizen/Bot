<?php

declare(strict_types=1);

namespace App\Exceptions\Tenancy;

use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * A custom-domain claim was refused because the host is taken or reserved — **409**
 * (Req 9.7 / A9: *"THEN THE system SHALL reject the assignment and return a response
 * indicating the host is already in use or reserved"*).
 *
 * The sibling of `TenantAlreadyExistsException`, which refuses a *slug/subdomain* at
 * provisioning time. This one refuses a *host* at claim time, and the two never
 * overlap: a slug names a tenant, a host names an origin.
 *
 * ## Why nothing here can name the holding tenant
 *
 * `alreadyInUse()` takes the host and nothing else — there is no parameter for the
 * current holder, so no message, log line, or exception property can carry one even by
 * accident. That is deliberate rather than tidy: `tenant_domains.host` is globally
 * unique and any tenant may probe any host, so an error that named the holder would
 * turn this endpoint into an oracle answering "which of my competitors is a customer,
 * and under which workspace?". The refusal is explainable ("already in use") without
 * being informative about *whose*.
 *
 * The caller that decides the refusal never loads the row either — `DomainRegistrar`
 * asks `exists()`, not `first()` — so the holder's identity is not in scope at the
 * point the exception is constructed.
 *
 * ## The four reasons, and why they are one exception
 *
 * | Named constructor | The host is… |
 * |---|---|
 * | `alreadyInUse()` | claimed by another tenant (verified or not — a pending claim still reserves it) |
 * | `reserved()` | a platform-owned label under an apex (`wa.tenancy.reserved_subdomains`) |
 * | `platformApex()` | an apex itself, or the deployment's own canonical host |
 * | `tenantSubdomain()` | of the form `{label}.{apex}` — platform-issued, never claimable |
 *
 * One class and one public sentence, because a client that could tell them apart could
 * enumerate the difference between "taken" and "reserved", and the tenant's next action
 * is identical in all four: choose another host. `$reason` is available to the panel and
 * the logs for the operator-facing detail.
 */
final class HostUnavailableException extends RuntimeException implements HttpExceptionInterface
{
    public const int STATUS = 409;

    /**
     * The only sentence a client is ever shown. Names no tenant and does not
     * distinguish "taken" from "reserved".
     */
    public const string PUBLIC_MESSAGE = 'That domain is already in use or reserved. Please choose another.';

    public const string ERROR_CODE = 'host_unavailable';

    /**
     * @param  string  $host  the normalised host that was refused — the caller's own input
     * @param  string  $reason  machine-readable detail for logs and the admin panel:
     *                          `already_in_use`, `reserved`, `platform_apex`, `tenant_subdomain`
     */
    private function __construct(
        public readonly string $host,
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * Another tenant holds this host.
     *
     * Deliberately unaware of *which* tenant: see the class docblock.
     */
    public static function alreadyInUse(string $host): self
    {
        return new self($host, 'already_in_use', sprintf(
            'Host [%s] is already claimed by another tenant. One host has one owner: a second '
            .'claim would decide which tenant\'s webhook callbacks and signed links point at '
            .'a name somebody else controls. The holder is deliberately not named here.',
            $host,
        ));
    }

    /**
     * The host is a platform-reserved label under one of the deployment's apexes.
     */
    public static function reserved(string $host, string $label): self
    {
        return new self($host, 'reserved', sprintf(
            'Host [%s] uses the platform-reserved label [%s] (wa.tenancy.reserved_subdomains). '
            .'Those labels serve the platform itself, so a tenant holding one would shadow it.',
            $host,
            $label,
        ));
    }

    /**
     * The host *is* an apex the platform serves, or its own canonical host.
     */
    public static function platformApex(string $host): self
    {
        return new self($host, 'platform_apex', sprintf(
            'Host [%s] is a platform apex. Claiming it would point the deployment\'s own origin '
            .'at one tenant.',
            $host,
        ));
    }

    /**
     * The host is a tenant subdomain of an apex — issued by the platform through
     * `{slug}.{apex}`, so it is not a *custom* domain and cannot be claimed as one.
     */
    public static function tenantSubdomain(string $host, string $apex): self
    {
        return new self($host, 'tenant_subdomain', sprintf(
            'Host [%s] sits under the platform apex [%s], where hosts are issued from the '
            .'tenant\'s own slug/subdomain rather than claimed. A claim here could take over '
            .'another tenant\'s issued host without touching its row.',
            $host,
            $apex,
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

    public function errorCode(): string
    {
        return self::ERROR_CODE;
    }
}
