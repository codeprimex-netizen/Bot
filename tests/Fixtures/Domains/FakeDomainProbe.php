<?php

declare(strict_types=1);

namespace Tests\Fixtures\Domains;

use App\Exceptions\Tenancy\DomainProbeUnavailableException;
use App\Services\Domains\DomainProbe;
use App\Services\Domains\TlsCertificate;
use Carbon\CarbonImmutable;

/**
 * An in-memory `DomainProbe` for tests (Property 28).
 *
 * ## Why it lives here and not in `app/`
 *
 * It is **test-only, structurally**. `Tests\` is mapped by `autoload-dev`, so this class
 * is not autoloadable in a production install at all — there is nothing for
 * `wa.tenancy.domains.probe.driver` to name by mistake, and nothing for the completeness
 * scan of task 39.4 to find in `app/`. Tests bind it explicitly:
 *
 * ```php
 * $probe = FakeDomainProbe::bind();
 * $probe->publishTxt('_wa-challenge.chat.acme.example', $challenge->token)
 *       ->serveCertificate('chat.acme.example');
 * ```
 *
 * ## Why it models the *shape* of the real probe rather than the answers
 *
 * It stores what a zone and a host would serve, and answers from that — so a test that
 * publishes a record under the wrong name, or a certificate for the wrong host, fails for
 * the same reason it would fail against a live domain. A fake that returned "verified"
 * would make every fail-closed test pass vacuously, which is the one thing these tests
 * exist to prevent.
 *
 * | Call | Simulates |
 * |---|---|
 * | `publishTxt($name, $value)` | a TXT record in the tenant's zone |
 * | `serve($url, $body)` | the host serving a challenge file |
 * | `serveCertificate($host, ...)` | a TLS terminator presenting a certificate |
 * | `unavailable()` / `available()` | the platform's own resolver failing (inconclusive) |
 * | `calls($operation)` | how often each probe was reached (proves a breaker fenced it off) |
 */
final class FakeDomainProbe implements DomainProbe
{
    /**
     * @var array<string, list<string>>
     */
    private array $txt = [];

    /**
     * @var array<string, string>
     */
    private array $bodies = [];

    /**
     * @var array<string, TlsCertificate>
     */
    private array $certificates = [];

    private bool $failing = false;

    /**
     * @var array<string, int>
     */
    private array $calls = [
        'txtRecords' => 0,
        'fetch' => 0,
        'certificate' => 0,
    ];

    /**
     * Bind this fake as the container's `DomainProbe`, unguarded.
     *
     * Unguarded on purpose: the breaker and retry budget are `GuardedDomainProbe`'s and
     * are tested against it directly. A test about verification should not have its
     * assertions perturbed by a breaker state carried over from an earlier one.
     */
    public static function bind(): self
    {
        $probe = new self;

        app()->instance(DomainProbe::class, $probe);

        return $probe;
    }

    public function publishTxt(string $name, string ...$values): self
    {
        $key = $this->key($name);
        $this->txt[$key] = array_values(array_merge($this->txt[$key] ?? [], $values));

        return $this;
    }

    public function serve(string $url, string $body): self
    {
        $this->bodies[$url] = $body;

        return $this;
    }

    /**
     * @param  list<string>  $names  names the certificate covers; defaults to $host itself
     */
    public function serveCertificate(
        string $host,
        array $names = [],
        ?CarbonImmutable $validFrom = null,
        ?CarbonImmutable $validTo = null,
        bool $trusted = true,
    ): self {
        $this->certificates[$this->key($host)] = new TlsCertificate(
            names: $names === [] ? [$this->key($host)] : $names,
            validFrom: $validFrom ?? CarbonImmutable::now()->subDay(),
            validTo: $validTo ?? CarbonImmutable::now()->addDays(60),
            trusted: $trusted,
        );

        return $this;
    }

    /**
     * Stop presenting a certificate for $host — a TLS terminator that went away.
     */
    public function withdrawCertificate(string $host): self
    {
        unset($this->certificates[$this->key($host)]);

        return $this;
    }

    /**
     * Remove every TXT record at $name — the tenant tidying its zone up.
     */
    public function withdrawTxt(string $name): self
    {
        unset($this->txt[$this->key($name)]);

        return $this;
    }

    /**
     * The platform cannot look at all: every probe throws the inconclusive exception.
     */
    public function unavailable(): self
    {
        $this->failing = true;

        return $this;
    }

    public function available(): self
    {
        $this->failing = false;

        return $this;
    }

    public function calls(string $operation): int
    {
        return $this->calls[$operation] ?? 0;
    }

    public function txtRecords(string $name): array
    {
        $this->calls['txtRecords']++;

        if ($this->failing) {
            throw DomainProbeUnavailableException::lookupFailed('dns.txt', $name);
        }

        return $this->txt[$this->key($name)] ?? [];
    }

    public function fetch(string $url): ?string
    {
        $this->calls['fetch']++;

        if ($this->failing) {
            throw DomainProbeUnavailableException::lookupFailed('http.challenge', $url);
        }

        return $this->bodies[$url] ?? null;
    }

    public function certificate(string $host, int $port): ?TlsCertificate
    {
        $this->calls['certificate']++;

        if ($this->failing) {
            throw DomainProbeUnavailableException::lookupFailed('tls.certificate', $host);
        }

        return $this->certificates[$this->key($host)] ?? null;
    }

    private function key(string $value): string
    {
        return strtolower(rtrim(trim($value), '.'));
    }
}
