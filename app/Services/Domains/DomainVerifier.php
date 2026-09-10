<?php

declare(strict_types=1);

namespace App\Services\Domains;

use App\Enums\DomainChallengeMethod;
use App\Enums\DomainVerificationFailure;
use App\Exceptions\Tenancy\DomainProbeUnavailableException;
use App\Models\TenantDomain;
use App\Services\Audit\AuditService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Ownership challenge **and** TLS check — the two things Req 9.7 requires before a
 * custom domain may be used, and the only writer of `tenant_domains.verified_at`.
 *
 * ```php
 * $challenge = $verifier->issueChallenge($domain);          // give the tenant instructions
 * // …the tenant publishes the TXT record…
 * $result = $verifier->verify($domain);                     // check them
 *
 * $result->verified;          // true → the host is now used for this tenant's URLs
 * $result->publicMessage();   // false → an actionable sentence, no network text
 * ```
 *
 * ## Both halves, every time
 *
 * Req 9.7 is a conjunction: *"unverified until an ownership-verification challenge
 * succeeds **and** a valid TLS certificate covering that domain is confirmed"*. So
 * `verify()` runs the challenge, then the certificate, and writes `verified_at` only if
 * neither produced a failure. There is no argument, config key, or environment that
 * skips either half — `wa.tenancy.domains` tunes *how* they are checked (record prefix,
 * port, timeouts), never *whether*.
 *
 * ## Fail closed, in exactly one direction
 *
 * Every path that is not "both halves passed" leaves the row unverified:
 *
 * | Situation | `verified_at` |
 * |---|---|
 * | both halves pass | set (or kept) |
 * | challenge missing / expired / not published / wrong | **cleared** |
 * | no trusted certificate, expired, or not covering the host | **cleared** |
 * | the platform could not look at all (`DomainProbeUnavailableException`) | **unchanged** |
 *
 * The last row is the only asymmetry and it is deliberate: a broken resolver or an open
 * circuit breaker is evidence about the platform, not about the domain. Revoking there
 * would take *every* tenant's custom domain down during our own outage, and it would do
 * it at the moment we are least able to put them back. It still cannot *grant* anything —
 * an unverified row stays unverified — so "there is no assume-verified path" holds
 * unconditionally. See `DomainVerificationFailure::isConclusive()`.
 *
 * ## Re-checkable, because the challenge is standing
 *
 * A successful verification drops the challenge *deadline* but keeps the method and
 * token, so `verify()` on a verified domain re-proves ownership rather than merely
 * confirming that some certificate exists. That distinction is the one that matters: a
 * domain that changes hands and is pointed back at the platform would still pass a
 * TLS-only check while no longer being the tenant's. `wa:domains:recheck` walks verified
 * domains on a schedule for exactly this.
 *
 * ## Every transition is audited
 *
 * Claiming, challenging, verifying and revoking a domain each write to the tenant's
 * audit chain (Req 24.2 / D1), because attaching a domain changes where that tenant's
 * webhook callbacks and signed links point — an operator investigating a redirected
 * callback needs the moment it moved and who moved it. Payloads carry the host, the
 * method, and reason codes; the token appears only as a short fingerprint, because an
 * audit entry is append-only for ever and a live credential should not be.
 */
final readonly class DomainVerifier
{
    /**
     * Challenge token length. 43 characters of `Str::random` alphabet is ~256 bits — the
     * same order as ACME's token, and both DNS-TXT-safe and URL-safe as generated.
     */
    public const int TOKEN_LENGTH = 43;

    public const string DEFAULT_DNS_PREFIX = '_wa-challenge';

    public const string DEFAULT_HTTP_PATH = '.well-known/wa-domain-challenge';

    public const int DEFAULT_CHALLENGE_TTL_HOURS = 72;

    public const int DEFAULT_TLS_PORT = 443;

    public function __construct(
        private DomainProbe $probe,
        private AuditService $audit,
    ) {}

    /**
     * Mint a fresh challenge for $domain and return the instructions.
     *
     * **A verified domain is revoked first.** Asking to re-prove ownership means the
     * current proof is being replaced, and between the two the platform has no proof at
     * all — so the host stops being used for URL generation until the new challenge is
     * satisfied. The alternative (mint a new token while leaving `verified_at` set) is a
     * footgun: the next re-check would test a token the tenant has not published yet and
     * revoke the domain anyway, just later and without having said so.
     */
    public function issueChallenge(TenantDomain $domain, ?DomainChallengeMethod $method = null): DomainChallenge
    {
        $method ??= $domain->challenge_method ?? DomainChallengeMethod::default();
        $token = $this->mintToken();
        $now = Carbon::now();
        $expiresAt = $now->copy()->addHours($this->challengeTtlHours());

        if ($domain->isVerified()) {
            $this->revoke($domain, DomainVerificationFailure::ChallengeMissing);
        }

        $domain->forceFill([
            'challenge_method' => $method,
            'challenge_token' => $token,
            'challenge_issued_at' => $now,
            'challenge_expires_at' => $expiresAt,
            // The previous check's verdict describes a challenge that no longer exists.
            'last_failure_reason' => null,
        ])->save();

        $this->audit->write('tenant.domain.challenge_issued', [
            'host' => $domain->host,
            'method' => $method->value,
            'expires_at' => $expiresAt->toIso8601String(),
            // A fingerprint, not the token: an audit entry is append-only for ever.
            'token_fingerprint' => $this->fingerprint($token),
        ], $domain, tenant: $domain->tenant_id);

        return $this->challengeFor($domain, $method, $token, $expiresAt);
    }

    /**
     * Run both halves of Req 9.7 against $domain and record the outcome.
     *
     * Safe to call on a verified domain — that is the re-check — and safe to call
     * repeatedly: it writes only the row it was given, and every write is idempotent for
     * the same evidence.
     */
    public function verify(TenantDomain $domain): DomainVerification
    {
        $now = Carbon::now();
        $wasVerified = $domain->isVerified();

        $failure = $this->checkChallenge($domain, $now);
        $certificate = null;

        if ($failure === null) {
            [$failure, $certificate] = $this->checkTls($domain);
        }

        $tlsExpiresAt = $certificate instanceof TlsCertificate
            ? Carbon::instance($certificate->validTo->toDateTime())
            : null;

        if ($failure === null) {
            return $this->recordSuccess($domain, $now, $tlsExpiresAt, $wasVerified);
        }

        return $this->recordFailure($domain, $failure, $now, $tlsExpiresAt, $wasVerified);
    }

    /**
     * Clear a verification explicitly — the operator/tenant kill switch, and the path
     * `issueChallenge()` takes when replacing a standing proof.
     *
     * The row is kept (the claim survives, so the host is not released to another tenant)
     * and both host caches are invalidated by the model's `saved` hook, so the domain
     * stops being emitted *and* stops resolving on the very next request.
     */
    public function revoke(TenantDomain $domain, DomainVerificationFailure $reason): void
    {
        if (! $domain->isVerified()) {
            return;
        }

        $domain->forceFill([
            'verified_at' => null,
            'tls_expires_at' => null,
            'last_failure_reason' => $reason,
        ])->save();

        $this->audit->write('tenant.domain.unverified', [
            'host' => $domain->host,
            'reason' => $reason->value,
        ], $domain, tenant: $domain->tenant_id);
    }

    /**
     * The instructions for $domain's *current* challenge, or null when it has none.
     *
     * For the verification screen re-rendering what was already issued, so a tenant that
     * reloads the page is not handed a new token (which would invalidate the record it
     * has just published).
     */
    public function currentChallenge(TenantDomain $domain): ?DomainChallenge
    {
        $method = $domain->challenge_method;
        $token = $domain->challenge_token;

        if ($method === null || $token === null) {
            return null;
        }

        return $this->challengeFor($domain, $method, $token, $domain->challenge_expires_at);
    }

    /**
     * Half one: does the host still carry the proof it was issued?
     *
     * @return DomainVerificationFailure|null null when the challenge is satisfied
     */
    private function checkChallenge(TenantDomain $domain, Carbon $now): ?DomainVerificationFailure
    {
        $method = $domain->challenge_method;
        $token = $domain->challenge_token;

        if ($method === null || $token === null) {
            return DomainVerificationFailure::ChallengeMissing;
        }

        // A deadline only exists before the first success; a standing proof has none.
        if ($domain->challenge_expires_at !== null && $now->greaterThan($domain->challenge_expires_at)) {
            return DomainVerificationFailure::ChallengeExpired;
        }

        $challenge = $this->challengeFor($domain, $method, $token, $domain->challenge_expires_at);

        try {
            return match ($method) {
                DomainChallengeMethod::DnsTxt => $this->checkDnsChallenge($challenge),
                DomainChallengeMethod::HttpFile => $this->checkHttpChallenge($challenge),
            };
        } catch (DomainProbeUnavailableException) {
            return DomainVerificationFailure::ProbeUnavailable;
        }
    }

    private function checkDnsChallenge(DomainChallenge $challenge): ?DomainVerificationFailure
    {
        $records = $this->probe->txtRecords($challenge->recordName);

        if ($records === []) {
            return DomainVerificationFailure::DnsRecordMissing;
        }

        foreach ($records as $record) {
            // `hash_equals` because the comparison is against a secret the caller
            // published: a timing-distinguishable compare here is a (weak, but free to
            // avoid) oracle for the token.
            if (hash_equals($challenge->recordValue, trim($record, " \t\"'"))) {
                return null;
            }
        }

        return DomainVerificationFailure::DnsRecordMismatch;
    }

    private function checkHttpChallenge(DomainChallenge $challenge): ?DomainVerificationFailure
    {
        $body = $this->probe->fetch($challenge->url);

        if ($body === null) {
            return DomainVerificationFailure::HttpChallengeUnreachable;
        }

        return hash_equals($challenge->recordValue, trim($body))
            ? null
            : DomainVerificationFailure::HttpChallengeMismatch;
    }

    /**
     * Half two: a trusted, in-window certificate that actually covers the host.
     *
     * All four failure modes are distinguished, because the tenant's fix differs for each
     * — nothing to fetch, an untrusted chain, an expired certificate, a certificate for
     * another name. The certificate is returned alongside so a *successful* check can
     * record its expiry without a second handshake.
     *
     * @return array{0: DomainVerificationFailure|null, 1: TlsCertificate|null}
     */
    private function checkTls(TenantDomain $domain): array
    {
        try {
            $certificate = $this->probe->certificate($domain->host, $this->tlsPort());
        } catch (DomainProbeUnavailableException) {
            return [DomainVerificationFailure::ProbeUnavailable, null];
        }

        if (! $certificate instanceof TlsCertificate) {
            return [DomainVerificationFailure::TlsUnavailable, null];
        }

        if (! $certificate->trusted) {
            return [DomainVerificationFailure::TlsUntrusted, $certificate];
        }

        if (! $certificate->isValidAt(Carbon::now())) {
            return [DomainVerificationFailure::TlsExpired, $certificate];
        }

        if (! $certificate->coversHost($domain->host)) {
            return [DomainVerificationFailure::TlsHostMismatch, $certificate];
        }

        return [null, $certificate];
    }

    /**
     * Both halves passed: the row becomes (or stays) usable.
     *
     * The deadline is dropped so the challenge becomes the standing proof; the method and
     * token stay so the next re-check re-proves ownership.
     */
    private function recordSuccess(
        TenantDomain $domain,
        Carbon $now,
        ?Carbon $tlsExpiresAt,
        bool $wasVerified,
    ): DomainVerification {
        $domain->forceFill([
            // Kept, not refreshed: `verified_at` answers "since when has this host been
            // usable", and a re-check confirming an unchanged fact must not rewrite it —
            // it is what explains a signed link issued at a given time.
            'verified_at' => $domain->verified_at ?? $now,
            'challenge_expires_at' => null,
            'last_checked_at' => $now,
            'last_failure_reason' => null,
            'tls_expires_at' => $tlsExpiresAt,
        ])->save();

        if (! $wasVerified) {
            $this->audit->write('tenant.domain.verified', [
                'host' => $domain->host,
                'method' => $domain->challenge_method?->value,
                'tls_expires_at' => $tlsExpiresAt?->toIso8601String(),
            ], $domain, tenant: $domain->tenant_id);
        }

        return DomainVerification::passed($now, $tlsExpiresAt);
    }

    /**
     * A half failed: record the evidence, and revoke if the failure is conclusive.
     */
    private function recordFailure(
        TenantDomain $domain,
        DomainVerificationFailure $failure,
        Carbon $now,
        ?Carbon $tlsExpiresAt,
        bool $wasVerified,
    ): DomainVerification {
        $revoke = $wasVerified && $failure->isConclusive();

        $attributes = [
            'last_checked_at' => $now,
            'last_failure_reason' => $failure,
        ];

        if ($revoke) {
            $attributes['verified_at'] = null;
            $attributes['tls_expires_at'] = null;
        } elseif ($tlsExpiresAt !== null) {
            // A certificate was seen even though the check failed (expired, wrong name):
            // recording its expiry is what lets the panel say *when* it lapsed.
            $attributes['tls_expires_at'] = $tlsExpiresAt;
        }

        $domain->forceFill($attributes)->save();

        if ($revoke) {
            $this->audit->write('tenant.domain.unverified', [
                'host' => $domain->host,
                'reason' => $failure->value,
            ], $domain, tenant: $domain->tenant_id);
        }

        return DomainVerification::failed($failure, $now, $revoke, $revoke ? null : $tlsExpiresAt);
    }

    /**
     * Build the challenge value for a stored method/token pair.
     *
     * One place derives the record name and the URL, so the instructions the tenant is
     * shown and the location the verifier reads cannot drift apart.
     */
    private function challengeFor(
        TenantDomain $domain,
        DomainChallengeMethod $method,
        string $token,
        ?Carbon $expiresAt,
    ): DomainChallenge {
        return new DomainChallenge(
            method: $method,
            host: $domain->host,
            token: $token,
            recordName: $this->dnsPrefix().'.'.$domain->host,
            recordValue: $token,
            // Plain HTTP on purpose: the certificate this challenge is a precondition for
            // may not exist yet (see `DomainChallengeMethod::HttpFile`).
            url: 'http://'.$domain->host.'/'.$this->httpPath().'/'.$token,
            expiresAt: $expiresAt ?? Carbon::now()->addHours($this->challengeTtlHours()),
        );
    }

    private function mintToken(): string
    {
        return Str::random(self::TOKEN_LENGTH);
    }

    /**
     * A short, non-reversible tag for the audit payload.
     */
    private function fingerprint(string $token): string
    {
        return substr(hash('sha256', $token), 0, 12);
    }

    private function dnsPrefix(): string
    {
        $prefix = config('wa.tenancy.domains.dns_record_prefix');

        return is_string($prefix) && trim($prefix) !== ''
            ? trim($prefix, ". \t")
            : self::DEFAULT_DNS_PREFIX;
    }

    private function httpPath(): string
    {
        $path = config('wa.tenancy.domains.http_challenge_path');

        return is_string($path) && trim($path) !== ''
            ? trim($path, "/ \t")
            : self::DEFAULT_HTTP_PATH;
    }

    private function challengeTtlHours(): int
    {
        $hours = config('wa.tenancy.domains.challenge_ttl_hours');
        $hours = is_numeric($hours) ? (int) $hours : self::DEFAULT_CHALLENGE_TTL_HOURS;

        // A non-positive TTL would issue a challenge that is already expired, which reads
        // as "verification is broken" rather than as a misconfiguration.
        return $hours > 0 ? $hours : self::DEFAULT_CHALLENGE_TTL_HOURS;
    }

    private function tlsPort(): int
    {
        $port = config('wa.tenancy.domains.tls_port');
        $port = is_numeric($port) ? (int) $port : self::DEFAULT_TLS_PORT;

        return $port >= 1 && $port <= 65535 ? $port : self::DEFAULT_TLS_PORT;
    }
}
