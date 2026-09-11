<?php

declare(strict_types=1);

use App\Enums\DomainChallengeMethod;
use App\Enums\DomainVerificationFailure;
use App\Models\AuditLog;
use App\Services\Url\BaseUrl;
use App\Services\Url\BaseUrlCache;
use Carbon\CarbonImmutable;
use Tests\Fixtures\Domains\DomainWorld;

/*
|--------------------------------------------------------------------------
| Ownership challenge + TLS check (Req 9.7 / A9)
|--------------------------------------------------------------------------
| Req 9.7 is a conjunction — a domain stays unverified until the challenge succeeds
| *and* a valid certificate covering it is confirmed — so every test here is about
| one of the two halves failing, or about both passing.
*/

beforeEach(function (): void {
    config()->set('wa.tenancy.apexes', ['app.example.test']);
    config()->set('app.url', 'https://app.example.test');
});

it('verifies only when the challenge and the certificate both pass', function (): void {
    $world = DomainWorld::create();
    $world->issue();
    $world->satisfyChallenge()->satisfyTls();

    $result = $world->verifier->verify($world->domain);

    expect($result->verified)->toBeTrue()
        ->and($result->failure)->toBeNull()
        ->and($world->stored()->isVerified())->toBeTrue()
        ->and($world->stored()->tls_expires_at)->not->toBeNull();
});

it('leaves the domain unverified when the DNS record is absent', function (): void {
    $world = DomainWorld::create();
    $world->issue();
    $world->satisfyTls();

    $result = $world->verifier->verify($world->domain);

    expect($result->verified)->toBeFalse()
        ->and($result->failure)->toBe(DomainVerificationFailure::DnsRecordMissing)
        ->and($world->stored()->isVerified())->toBeFalse();
});

it('leaves the domain unverified when the DNS record holds someone else\'s value', function (): void {
    $world = DomainWorld::create();
    $challenge = $world->issue();
    $world->probe->publishTxt($challenge->recordName, 'not-the-token');
    $world->satisfyTls();

    expect($world->verifier->verify($world->domain)->failure)
        ->toBe(DomainVerificationFailure::DnsRecordMismatch);
});

it('never verifies on the challenge alone — the certificate is the other half', function (
    array $certificate,
    DomainVerificationFailure $expected,
): void {
    $world = DomainWorld::create();
    $world->issue();
    $world->satisfyChallenge();

    if ($certificate !== []) {
        $world->probe->serveCertificate(
            $world->domain->host,
            $certificate['names'] ?? [],
            $certificate['from'] ?? null,
            $certificate['to'] ?? null,
            $certificate['trusted'] ?? true,
        );
    }

    $result = $world->verifier->verify($world->domain);

    expect($result->verified)->toBeFalse()
        ->and($result->failure)->toBe($expected)
        ->and($world->stored()->isVerified())->toBeFalse();
})->with([
    'no certificate at all' => [[], DomainVerificationFailure::TlsUnavailable],
    'an untrusted chain' => [['trusted' => false], DomainVerificationFailure::TlsUntrusted],
    'an expired certificate' => [[
        'from' => new CarbonImmutable('2020-01-01'),
        'to' => new CarbonImmutable('2020-02-01'),
    ], DomainVerificationFailure::TlsExpired],
    'a certificate for another host' => [
        ['names' => ['other.example']],
        DomainVerificationFailure::TlsHostMismatch,
    ],
]);

it('accepts a wildcard certificate one label above the host', function (): void {
    $world = DomainWorld::create();
    $world->issue();
    $world->satisfyChallenge();
    $world->probe->serveCertificate($world->domain->host, ['*.acme.example']);

    expect($world->verifier->verify($world->domain)->verified)->toBeTrue();
});

it('refuses to verify off an expired challenge', function (): void {
    $world = DomainWorld::create();
    $world->issue();
    $world->satisfyChallenge()->satisfyTls();

    $world->domain->forceFill(['challenge_expires_at' => now()->subMinute()])->save();

    expect($world->verifier->verify($world->domain)->failure)
        ->toBe(DomainVerificationFailure::ChallengeExpired)
        ->and($world->stored()->isVerified())->toBeFalse();
});

it('refuses to verify a claim that was never challenged', function (): void {
    $world = DomainWorld::create();
    $world->satisfyTls();

    expect($world->verifier->verify($world->domain)->failure)
        ->toBe(DomainVerificationFailure::ChallengeMissing)
        ->and($world->stored()->isVerified())->toBeFalse();
});

it('satisfies the HTTP challenge from the served token', function (): void {
    $world = DomainWorld::create();
    $challenge = $world->issue(DomainChallengeMethod::HttpFile);

    expect($challenge->url)->toStartWith('http://chat.acme.example/.well-known/wa-domain-challenge/');

    // A trailing newline from a web server or proxy is tolerated; the token is not.
    $world->probe->serve($challenge->url, $challenge->token."\n");
    $world->satisfyTls();

    expect($world->verifier->verify($world->domain)->verified)->toBeTrue();
});

it('reports an unserved HTTP challenge without verifying anything', function (): void {
    $world = DomainWorld::create();
    $world->issue(DomainChallengeMethod::HttpFile);
    $world->satisfyTls();

    expect($world->verifier->verify($world->domain)->failure)
        ->toBe(DomainVerificationFailure::HttpChallengeUnreachable)
        ->and($world->stored()->isVerified())->toBeFalse();
});

it('audits the verification, once', function (): void {
    $world = DomainWorld::create();
    $world->verify();

    // A re-check of an unchanged fact must not append a second "verified" entry.
    $world->verifier->verify($world->domain);

    expect(AuditLog::withoutTenantScope()->where('action', '=', 'tenant.domain.challenge_issued')->count())->toBe(1)
        ->and(AuditLog::withoutTenantScope()->where('action', '=', 'tenant.domain.verified')->count())->toBe(1);
});

it('keeps the token out of the audit payload', function (): void {
    $world = DomainWorld::create();
    $challenge = $world->issue();

    $entry = AuditLog::withoutTenantScope()->where('action', '=', 'tenant.domain.challenge_issued')->first();

    expect($entry)->not->toBeNull()
        ->and(json_encode($entry?->payload))->not->toContain($challenge->token);
});

it('does not rewrite verified_at when a re-check confirms an unchanged domain', function (): void {
    $world = DomainWorld::create();
    $world->verify();

    $verifiedAt = $world->stored()->verified_at?->toIso8601String();

    thisTest()->travel(2)->hours();
    $world->verifier->verify($world->domain);

    expect($world->stored()->verified_at?->toIso8601String())->toBe($verifiedAt);
});

it('revokes a verified domain whose ownership proof has conclusively gone', function (): void {
    $world = DomainWorld::create();
    $challenge = $world->issue();
    $world->satisfyChallenge()->satisfyTls();
    $world->verifier->verify($world->domain);

    // The tenant tidies the record away — or the domain changes hands.
    $world->probe->withdrawTxt($challenge->recordName);

    $result = $world->verifier->verify($world->domain);

    expect($result->verified)->toBeFalse()
        ->and($result->revoked)->toBeTrue()
        ->and($world->stored()->isVerified())->toBeFalse()
        ->and($world->stored()->last_failure_reason)->toBe(DomainVerificationFailure::DnsRecordMissing)
        ->and(AuditLog::withoutTenantScope()->where('action', '=', 'tenant.domain.unverified')->count())->toBe(1);
});

it('revokes a verified domain whose certificate stops covering it', function (): void {
    $world = DomainWorld::create();
    $world->verify();

    $world->probe->withdrawCertificate($world->domain->host);

    expect($world->verifier->verify($world->domain)->revoked)->toBeTrue()
        ->and($world->stored()->isVerified())->toBeFalse();
});

it('keeps a verified domain when the platform itself cannot look', function (): void {
    $world = DomainWorld::create();
    $world->verify();

    $world->probe->unavailable();
    $result = $world->verifier->verify($world->domain);

    expect($result->verified)->toBeFalse()
        ->and($result->isInconclusive())->toBeTrue()
        ->and($result->revoked)->toBeFalse()
        ->and($world->stored()->isVerified())->toBeTrue()
        ->and($world->stored()->last_failure_reason)->toBe(DomainVerificationFailure::ProbeUnavailable);
});

it('still refuses to verify an unverified claim when the platform cannot look', function (): void {
    $world = DomainWorld::create();
    $world->issue();
    $world->satisfyChallenge()->satisfyTls();
    $world->probe->unavailable();

    expect($world->verifier->verify($world->domain)->verified)->toBeFalse()
        ->and($world->stored()->isVerified())->toBeFalse();
});

it('revokes before re-issuing a challenge, so there is never an unproven verified row', function (): void {
    $world = DomainWorld::create();
    $world->verify();

    $reissued = $world->issue();

    expect($world->stored()->isVerified())->toBeFalse()
        ->and($world->stored()->challenge_token)->toBe($reissued->token)
        ->and(AuditLog::withoutTenantScope()->where('action', '=', 'tenant.domain.unverified')->count())->toBe(1);
});

it('makes a verification visible to URL generation immediately, and a revocation too', function (): void {
    $world = DomainWorld::create();
    $challenge = $world->issue();
    $world->satisfyChallenge()->satisfyTls();

    $baseUrl = app(BaseUrl::class);

    // Warm the cache with the pre-verification answer, so a stale read would be visible.
    expect($baseUrl->forTenant($world->tenant))->toBe('https://app.example.test');

    $versionBefore = app(BaseUrlCache::class)->version();
    $world->verifier->verify($world->domain);

    expect(app(BaseUrlCache::class)->version())->toBeGreaterThan($versionBefore)
        ->and($baseUrl->forTenant($world->tenant))->toBe('https://chat.acme.example');

    $world->probe->withdrawTxt($challenge->recordName);
    $world->verifier->verify($world->domain);

    expect($baseUrl->forTenant($world->tenant))->toBe('https://app.example.test');
});

it('leaves the row alone when revoke is called on an unverified domain', function (): void {
    $world = DomainWorld::create();

    $world->verifier->revoke($world->domain, DomainVerificationFailure::TlsExpired);

    expect($world->stored()->last_failure_reason)->toBeNull()
        ->and(AuditLog::withoutTenantScope()->where('action', '=', 'tenant.domain.unverified')->exists())->toBeFalse();
});
