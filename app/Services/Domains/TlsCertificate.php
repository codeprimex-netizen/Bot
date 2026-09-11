<?php

declare(strict_types=1);

namespace App\Services\Domains;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * The part of a presented X.509 certificate Req 9.7 needs: *which* names it covers,
 * *when* it is valid, and whether the chain was trusted.
 *
 * Deliberately not the certificate. A `DomainProbe` returns one of these rather than a
 * stream resource or a parsed array so the rules that decide a verification —
 * `coversHost()`, `isValidAt()`, `$trusted` — are pure functions over data a test can
 * construct, and are therefore the same rules in a test as against a live host.
 *
 * ## Why `$trusted` is a field rather than "we only return trusted ones"
 *
 * Because the tenant has to be told *which* thing is wrong. "No certificate could be
 * retrieved", "the certificate is not trusted", "it expired" and "it does not cover this
 * name" are four different fixes, and collapsing the middle two into a handshake failure
 * would leave the most common real-world case — a self-signed or half-installed chain —
 * indistinguishable from an unreachable host. `NetworkDomainProbe` therefore verifies
 * first and only falls back to an unverified handshake to *report* on the failure; the
 * result carries the verdict rather than hiding it.
 *
 * A domain is never verified on an untrusted certificate: `DomainVerifier` refuses
 * `$trusted === false` outright.
 *
 * @immutable
 */
final readonly class TlsCertificate
{
    /**
     * @param  list<string>  $names  every name the certificate covers — subject CN plus
     *                               every `DNS:` entry of the subjectAltName extension,
     *                               lowercased, wildcards kept as `*.example.com`
     * @param  bool  $trusted  whether the chain verified against the system trust store
     */
    public function __construct(
        public array $names,
        public CarbonImmutable $validFrom,
        public CarbonImmutable $validTo,
        public bool $trusted = true,
    ) {}

    /**
     * Whether this certificate covers $host — exact match, or a wildcard one label above
     * it.
     *
     * The wildcard rule is RFC 6125's, and its narrowness is the point: `*.acme.example`
     * covers `chat.acme.example` and **not** `deep.chat.acme.example` (a wildcard spans
     * exactly one label) and **not** `acme.example` (the bare parent is a different
     * name). A looser match would accept a certificate the tenant's TLS terminator will
     * not actually present for the host, so the platform would verify a domain that then
     * fails for real users.
     *
     * $host is expected already normalised (lowercase punycode, no root dot) — every
     * caller reads it from `tenant_domains.host`, which `CanonicalBase::host()`
     * normalises on write.
     */
    public function coversHost(string $host): bool
    {
        $host = strtolower(trim($host, ". \t"));

        if ($host === '') {
            return false;
        }

        foreach ($this->names as $name) {
            if ($this->nameCovers(strtolower(trim($name, ". \t")), $host)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether $at falls inside the validity window (inclusive of both edges).
     */
    public function isValidAt(DateTimeInterface $at): bool
    {
        $moment = CarbonImmutable::instance($at);

        return ! $moment->lessThan($this->validFrom) && ! $moment->greaterThan($this->validTo);
    }

    /**
     * Whether the certificate expires within $days — the panel's "renew this" warning
     * and the re-check sweep's priority signal.
     */
    public function expiresWithinDays(int $days, ?DateTimeInterface $at = null): bool
    {
        $moment = $at === null ? CarbonImmutable::now() : CarbonImmutable::instance($at);

        return $this->validTo->lessThanOrEqualTo($moment->addDays(max(0, $days)));
    }

    private function nameCovers(string $name, string $host): bool
    {
        if ($name === '') {
            return false;
        }

        if ($name === $host) {
            return true;
        }

        if (! str_starts_with($name, '*.')) {
            return false;
        }

        $suffix = substr($name, 1);          // '.acme.example'

        if (! str_ends_with($host, $suffix)) {
            return false;
        }

        $label = substr($host, 0, -strlen($suffix));

        // Exactly one label, and a non-empty one: `*.acme.example` must not match
        // `acme.example` (no label) or `a.b.acme.example` (two).
        return $label !== '' && ! str_contains($label, '.');
    }
}
