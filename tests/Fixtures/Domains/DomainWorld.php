<?php

declare(strict_types=1);

namespace Tests\Fixtures\Domains;

use App\Enums\DomainChallengeMethod;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Services\Domains\DomainChallenge;
use App\Services\Domains\DomainRegistrar;
use App\Services\Domains\DomainVerifier;

/**
 * One tenant, one claimed host, and the zone/host the fake probe answers from — the
 * setup every domain-verification test starts from (Req 9.7 / A9).
 *
 * A value handed back from `create()` rather than properties on the test case: Pest
 * closures are bound to the PHPUnit base class, so `$this->probe` is invisible to static
 * analysis. This keeps the shared setup in one place without that cost, and makes what a
 * test is doing explicit — `$world->satisfyChallenge()` names the thing that would
 * otherwise be four lines of publishing records.
 */
final readonly class DomainWorld
{
    private function __construct(
        public Tenant $tenant,
        public TenantDomain $domain,
        public FakeDomainProbe $probe,
        public DomainVerifier $verifier,
    ) {}

    /**
     * A tenant with an unverified claim on $host, and the fake probe bound.
     */
    public static function create(string $host = 'chat.acme.example'): self
    {
        $probe = FakeDomainProbe::bind();
        $tenant = Tenant::factory()->create();

        return new self(
            tenant: $tenant,
            domain: app(DomainRegistrar::class)->claim($tenant, $host),
            probe: $probe,
            verifier: app(DomainVerifier::class),
        );
    }

    /**
     * Issue a challenge and return it.
     */
    public function issue(?DomainChallengeMethod $method = null): DomainChallenge
    {
        return $this->verifier->issueChallenge($this->domain, $method);
    }

    /**
     * Publish whatever the current challenge asks for, so the ownership half passes.
     */
    public function satisfyChallenge(): self
    {
        $challenge = $this->verifier->currentChallenge($this->domain);

        if ($challenge === null) {
            return $this;
        }

        if ($challenge->method === DomainChallengeMethod::DnsTxt) {
            $this->probe->publishTxt($challenge->recordName, $challenge->recordValue);
        } else {
            $this->probe->serve($challenge->url, $challenge->recordValue);
        }

        return $this;
    }

    /**
     * Present a valid certificate covering the claimed host, so the TLS half passes.
     */
    public function satisfyTls(): self
    {
        $this->probe->serveCertificate($this->domain->host);

        return $this;
    }

    /**
     * Issue a challenge, satisfy both halves, and verify — a domain in the state the
     * re-check tests care about.
     */
    public function verify(): self
    {
        $this->issue();
        $this->satisfyChallenge()->satisfyTls();
        $this->verifier->verify($this->domain);

        return $this;
    }

    /**
     * The row as it stands in the database.
     */
    public function stored(): TenantDomain
    {
        $fresh = $this->domain->fresh();

        return $fresh instanceof TenantDomain ? $fresh : $this->domain;
    }
}
