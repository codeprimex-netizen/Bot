<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a domain is not verified (Req 9.7 / A9) — a fixed code, never a message from the
 * network.
 *
 * Stored in `tenant_domains.last_failure_reason` and returned on every
 * `App\Services\Domains\DomainVerification`. A code rather than free text for three
 * reasons: the tenant-facing screen needs a sentence it can translate, an operator
 * needs to aggregate ("42 domains failing `tls_untrusted` since 03:00"), and a
 * resolver or HTTP error string can quote a request — a header, a URL, an internal
 * hostname — which must not land in a column the panel renders.
 *
 * ## Conclusive vs inconclusive — the one distinction that changes state
 *
 * `isConclusive()` splits *evidence that the domain is not (or is no longer) ours* from
 * *absence of evidence either way*:
 *
 *  - **conclusive** — DNS answered and the record is missing or wrong; the host served
 *    the wrong token; the certificate is expired, untrusted, or names another host.
 *    A verified domain in this state is **revoked** (`verified_at` cleared) because the
 *    condition Req 9.7 verified against has demonstrably stopped holding.
 *  - **inconclusive** — the platform could not consult DNS or open a socket at all
 *    (`probe_unavailable`): its own resolver is broken or the circuit breaker guarding
 *    it is open. A verified domain keeps its verification, because our outage is not
 *    the tenant's evidence — revoking here would take *every* tenant's custom domain
 *    down at the moment the platform is least able to fix it.
 *
 * Both leave an **unverified** row unverified. There is no path in either direction
 * that verifies without conclusive success, so "fail closed" holds for the transition
 * that matters (`null` → verified) in every case.
 */
enum DomainVerificationFailure: string
{
    /** No challenge has ever been issued for this claim, so there is nothing to check. */
    case ChallengeMissing = 'challenge_missing';

    /**
     * The challenge was issued too long ago (`challenge_expires_at` is past). Only ever
     * reachable before the first success — a verified domain's challenge has no deadline.
     */
    case ChallengeExpired = 'challenge_expired';

    /** DNS answered, but there is no TXT record at the challenge name. */
    case DnsRecordMissing = 'dns_record_missing';

    /** DNS answered with TXT records, none of which carries the token. */
    case DnsRecordMismatch = 'dns_record_mismatch';

    /** The host did not serve the challenge path (no answer, or not a 200). */
    case HttpChallengeUnreachable = 'http_challenge_unreachable';

    /** The host served the challenge path with a body that is not the token. */
    case HttpChallengeMismatch = 'http_challenge_mismatch';

    /** No TLS handshake completed on the host, or it presented no certificate. */
    case TlsUnavailable = 'tls_unavailable';

    /** A certificate was presented but the chain is not trusted. */
    case TlsUntrusted = 'tls_untrusted';

    /** The certificate is outside its validity window. */
    case TlsExpired = 'tls_expired';

    /** The certificate is valid but covers neither the host nor a wildcard over it. */
    case TlsHostMismatch = 'tls_host_mismatch';

    /**
     * The platform could not consult DNS / the network at all — the one inconclusive
     * outcome (see the class docblock).
     */
    case ProbeUnavailable = 'probe_unavailable';

    public static function tryFromLoose(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom(strtolower(trim($value)));
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * Whether this outcome is evidence *against* the domain, rather than the absence
     * of evidence. Only a conclusive failure revokes an existing verification.
     */
    public function isConclusive(): bool
    {
        return $this !== self::ProbeUnavailable;
    }

    /**
     * Whether the ownership challenge (rather than TLS) is what failed — the half the
     * tenant fixes by publishing a record or a file.
     */
    public function isChallengeFailure(): bool
    {
        return match ($this) {
            self::ChallengeMissing, self::ChallengeExpired,
            self::DnsRecordMissing, self::DnsRecordMismatch,
            self::HttpChallengeUnreachable, self::HttpChallengeMismatch => true,
            self::TlsUnavailable, self::TlsUntrusted, self::TlsExpired,
            self::TlsHostMismatch, self::ProbeUnavailable => false,
        };
    }

    /**
     * The sentence the tenant is shown. Actionable, and it never quotes the network.
     */
    public function publicMessage(): string
    {
        return match ($this) {
            self::ChallengeMissing => 'No verification challenge exists for this domain. Start verification to get one.',
            self::ChallengeExpired => 'The verification challenge has expired. Start a new check to get a fresh one.',
            self::DnsRecordMissing => 'The verification TXT record was not found in DNS. Publish it and check again — DNS changes can take time to propagate.',
            self::DnsRecordMismatch => 'A TXT record was found, but its value does not match the one issued for this domain.',
            self::HttpChallengeUnreachable => 'The domain did not serve the verification file. Point it at the platform, or publish the file, and check again.',
            self::HttpChallengeMismatch => 'The domain served the verification path, but with the wrong content.',
            self::TlsUnavailable => 'No HTTPS certificate could be retrieved for the domain.',
            self::TlsUntrusted => 'The domain presented an HTTPS certificate that is not trusted.',
            self::TlsExpired => 'The domain\'s HTTPS certificate is expired or not yet valid.',
            self::TlsHostMismatch => 'The domain\'s HTTPS certificate does not cover this domain name.',
            self::ProbeUnavailable => 'The domain could not be checked just now. Nothing has changed; please try again shortly.',
        };
    }
}
