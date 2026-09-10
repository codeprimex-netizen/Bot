<?php

declare(strict_types=1);

namespace App\Services\Domains;

use App\Exceptions\Tenancy\HostUnavailableException;
use App\Exceptions\Url\InvalidBaseUrlException;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Services\Audit\AuditService;
use App\Support\Url\CanonicalBase;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The write path for a tenant's custom-domain claim: who may claim which host, and the
 * four ways a host is refused (Req 9.7 / A9).
 *
 * ```php
 * $domain = $registrar->claim($tenant, 'chat.acme.example');       // an unverified claim
 * $challenge = $verifier->issueChallenge($domain);                 // …then prove it
 * ```
 *
 * A claim created here is **unverified by construction** — `claim()` never writes
 * `verified_at`, and there is no argument that would let it. Turning a claim into an
 * origin the platform emits links to is `DomainVerifier`'s job and requires evidence.
 * That split is why this class can be reached from a tenant-facing screen at all: the
 * worst a tenant can do with it is reserve a host it does not own, which grants nothing
 * and is visible to an operator.
 *
 * ## The four refusals, and the order they are checked in
 *
 * All of them are `HostUnavailableException` (409) with one public sentence, and the
 * order is cheapest-and-most-structural first:
 *
 * 1. **not a hostname** — `CanonicalBase::host()` refuses a port, a path, a CR/LF, an
 *    over-long name. `InvalidBaseUrlException`, not a 409: the input is malformed rather
 *    than unavailable.
 * 2. **a platform apex** — `bot.example.com` itself. Claiming it would point the
 *    deployment's own origin at one tenant.
 * 3. **inside the platform's host space** — anything under an apex, at any depth. Hosts
 *    there are *issued* from a tenant's slug/subdomain, so a claim could take over
 *    another tenant's issued host without touching its row, and a reserved label
 *    (`admin.app.example.com`) could shadow the platform itself. Reserved labels are
 *    named specifically in the message so the operator-facing detail says *which*.
 * 4. **already claimed** — a row exists for another tenant, verified or not.
 *
 * ## Why a *pending* claim reserves the host
 *
 * Step 4 refuses a host another tenant merely claimed. That is the right trade: allowing
 * two live claims on one host would mean two tenants racing to verify, with the loser
 * discovering only after publishing DNS records that the host went to somebody else —
 * and the global `unique(host)` would force the refusal at that point anyway, just later
 * and less explicably. The cost is that an abandoned claim parks a host; releasing it is
 * `release()`, which an operator or the owning tenant can call.
 *
 * ## Why the refusal cannot leak the holder
 *
 * The availability check is `exists()`, not `first()`: the holding row is never loaded,
 * so the tenant that holds it is not in scope at the point the exception is built — and
 * `HostUnavailableException::alreadyInUse()` has no parameter for it either. A caller
 * cannot log what it does not have. The one exception is a claim by the *same* tenant,
 * which is not a refusal at all: the existing row is returned so a tenant re-submitting
 * its own host gets its claim back rather than a confusing conflict.
 */
final readonly class DomainRegistrar
{
    /**
     * Named in `InvalidBaseUrlException` messages when the *claimed* host is malformed —
     * distinct from `TenantDomain::HOST_SOURCE`, which names a host already stored.
     */
    public const string CLAIM_SOURCE = 'custom domain claim';

    public function __construct(
        private PlatformHosts $hosts,
        private AuditService $audit,
    ) {}

    /**
     * Record $tenant's claim on $host, unverified.
     *
     * Idempotent for the same tenant: an existing claim (verified or not) is returned
     * unchanged rather than duplicated, so a tenant that re-submits the same host does
     * not lose its verification.
     *
     * @throws InvalidBaseUrlException when $host is not a usable hostname
     * @throws HostUnavailableException when $host is reserved, a platform host, or claimed
     */
    public function claim(Tenant $tenant, string $host): TenantDomain
    {
        $host = CanonicalBase::host($host, self::CLAIM_SOURCE);

        $this->assertClaimable($host);

        $existing = TenantDomain::query()
            ->where('tenant_id', '=', $tenant->getKey())
            ->where('host', '=', $host)
            ->first();

        if ($existing instanceof TenantDomain) {
            return $existing;
        }

        // Availability is decided by `exists()`, never by loading the row: the holder must
        // not be in scope where the refusal is built (see the class docblock).
        if (TenantDomain::query()->where('host', '=', $host)->exists()) {
            throw HostUnavailableException::alreadyInUse($host);
        }

        try {
            $domain = new TenantDomain;
            $domain->fill([
                'tenant_id' => $tenant->getKey(),
                'host' => $host,
            ]);
            $domain->save();
        } catch (UniqueConstraintViolationException) {
            // Lost the race to another tenant claiming the same host between the check and
            // the insert. Reported as the same conflict the pre-check reports, so a caller
            // handles one outcome — and the winner is still not named.
            throw HostUnavailableException::alreadyInUse($host);
        }

        // A domain attached to a tenant changes where that tenant's webhook callbacks and
        // signed links will point once it verifies, so the attachment itself is auditable
        // — not just the verification (Req 24.2 / D1).
        $this->audit->write('tenant.domain.claimed', [
            'host' => $host,
        ], $domain, tenant: $tenant);

        return $domain;
    }

    /**
     * Withdraw a claim.
     *
     * A verified domain is *revoked before it is removed* rather than simply deleted, so
     * the sequence in the audit chain reads "unverified, then removed": the moment the
     * host stopped being used for URL generation is a distinct fact from the moment the
     * row went, and only the first one explains a signed link that stopped verifying.
     * The model's `deleted` hook then invalidates both caches, so the host stops
     * resolving on the next request.
     */
    public function release(TenantDomain $domain): void
    {
        $host = $domain->host;
        $tenantId = $domain->tenant_id;
        $wasVerified = $domain->isVerified();

        if ($wasVerified) {
            $domain->forceFill([
                'verified_at' => null,
                'tls_expires_at' => null,
            ])->save();
        }

        $domain->delete();

        $this->audit->write('tenant.domain.released', [
            'host' => $host,
            'was_verified' => $wasVerified,
        ], $domain, tenant: $tenantId);
    }

    /**
     * Whether $host could be claimed right now — the panel's live "is this available?"
     * check.
     *
     * Returns a bool rather than throwing because this is a *question*, asked on
     * keystrokes, and its answer is the same one for all four refusals: no. A caller that
     * needs to know why calls `claim()` and catches. Malformed input is `false`, not an
     * exception, for the same reason.
     */
    public function isAvailable(string $host): bool
    {
        try {
            $normalised = CanonicalBase::host($host, self::CLAIM_SOURCE);
            $this->assertClaimable($normalised);
        } catch (InvalidBaseUrlException|HostUnavailableException) {
            return false;
        }

        return ! TenantDomain::query()->where('host', '=', $normalised)->exists();
    }

    /**
     * The three structural refusals — apex, reserved label, platform host space.
     *
     * Separate from the "already claimed" check because these need no query at all: they
     * are decided from config, so a malicious or mistaken host is refused before it can
     * cost a database round trip.
     *
     * @throws HostUnavailableException
     */
    private function assertClaimable(string $host): void
    {
        if ($this->hosts->isApex($host)) {
            throw HostUnavailableException::platformApex($host);
        }

        $reserved = $this->hosts->reservedLabelIn($host);

        if ($reserved !== null) {
            throw HostUnavailableException::reserved($host, $reserved);
        }

        $apex = $this->hosts->apexFor($host);

        if ($apex !== null) {
            throw HostUnavailableException::tenantSubdomain($host, $apex);
        }
    }
}
