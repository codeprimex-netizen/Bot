<?php

declare(strict_types=1);

namespace App\Services\Domains;

use App\Enums\CircuitScope;
use App\Exceptions\Reliability\CircuitOpenException;
use App\Exceptions\Tenancy\DomainProbeUnavailableException;
use App\Services\Reliability\CircuitBreaker;
use App\Services\Reliability\RetryPolicy;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * A `DomainProbe` wrapped in the two things every fallible network dependency on this
 * platform gets: a **circuit breaker** and a **bounded retry budget** (Req 31.3, 31.1 /
 * NFR2 applied to Req 9.7 / A9).
 *
 * Composed exactly as `RetryPolicy`'s docblock prescribes, breaker innermost:
 *
 * ```
 *   attempt loop (RetryPolicy: how long to wait, and whether to bother)
 *     └── breaker (CircuitBreaker: may this lookup run at all?)
 *           └── NetworkDomainProbe (one DNS query / HTTP request / TLS handshake)
 * ```
 *
 * ## Why a domain lookup needs a breaker at all
 *
 * A hanging DNS lookup on a verification screen is a stalled worker, and the sweep that
 * re-checks verified domains walks a batch of them in one process. If the platform's
 * resolver or egress goes, every one of those lookups pays the full timeout before
 * failing — so the whole batch turns into minutes of blocked worker for a result that
 * was never going to arrive. The breaker turns the second failure onward into an
 * immediate, cheap "inconclusive".
 *
 * ## What can and cannot trip it
 *
 * Only `DomainProbeUnavailableException` — the platform's own inability to ask — reaches
 * the breaker as a failure. A tenant's dead host does **not**: the inner probe returns
 * `null`/`[]` for a refused connection, a timeout, a 404, or a bad certificate, and this
 * class passes that through untouched. That asymmetry is the isolation guarantee: one
 * tenant typing `chat.exmaple.com` cannot fence every other tenant's verification off.
 *
 * ## Why the retry is deliberately tiny
 *
 * This waits **inline** — inside the request a tenant is watching, or inside the
 * scheduled sweep — so the budget is capped twice: `attempts` (default 2) and
 * `max_delay_ms` (default 250 ms per wait). A verification is re-runnable by definition
 * (the tenant presses the button again, or the sweep comes round), so spending a
 * caller's latency on optimism here buys nothing. `Illuminate\Support\Sleep` rather than
 * `usleep()` so the wait is assertable in tests instead of real.
 *
 * ## Fail inconclusive, never fail verified
 *
 * Every exit that is not a value is a `DomainProbeUnavailableException`, which
 * `DomainVerifier` maps to `DomainVerificationFailure::ProbeUnavailable` — the one
 * outcome that leaves an existing verification alone and still refuses to grant a new
 * one. There is no path through this class that fabricates a TXT record, a body, or a
 * certificate.
 */
final readonly class GuardedDomainProbe implements DomainProbe
{
    public const int DEFAULT_ATTEMPTS = 2;

    public const int DEFAULT_MAX_DELAY_MS = 250;

    public function __construct(
        private DomainProbe $inner,
        private CircuitBreaker $breaker,
        private RetryPolicy $retry,
        private string $breakerName = 'domains',
        private int $attempts = self::DEFAULT_ATTEMPTS,
        private int $maxDelayMs = self::DEFAULT_MAX_DELAY_MS,
    ) {}

    public function txtRecords(string $name): array
    {
        /** @var list<string> $records */
        $records = $this->guarded('dns.txt', $name, fn (): array => $this->inner->txtRecords($name));

        return $records;
    }

    public function fetch(string $url): ?string
    {
        /** @var string|null $body */
        $body = $this->guarded('http.challenge', $url, fn (): ?string => $this->inner->fetch($url));

        return $body;
    }

    public function certificate(string $host, int $port): ?TlsCertificate
    {
        /** @var TlsCertificate|null $certificate */
        $certificate = $this->guarded(
            'tls.certificate',
            $host,
            fn (): ?TlsCertificate => $this->inner->certificate($host, $port),
        );

        return $certificate;
    }

    /**
     * Run one probe operation through the breaker, retrying only what the matrix says is
     * worth retrying.
     *
     * @template TReturn
     *
     * @param  string  $operation  short label for messages — never a resolver address
     * @param  string  $target  the host or URL being probed, for the message only
     * @param  callable(): TReturn  $probe
     * @return TReturn
     *
     * @throws DomainProbeUnavailableException when the operation cannot be completed
     */
    private function guarded(string $operation, string $target, callable $probe): mixed
    {
        $attempts = max(1, $this->attempts);

        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->breaker->call(CircuitScope::Provider, $this->breakerName, $probe);
            } catch (CircuitOpenException) {
                // The door is shut: the lookup was not attempted, and no amount of waiting
                // inside this loop will open it.
                throw DomainProbeUnavailableException::circuitOpen($operation, $target);
            } catch (Throwable $e) {
                $failure = $e instanceof DomainProbeUnavailableException
                    ? $e
                    // A probe throwing its own exception type must still fail inconclusive,
                    // and must not let a resolver or client message — which may quote a
                    // proxy or a request header — escape into a tenant-facing screen.
                    : DomainProbeUnavailableException::lookupFailed($operation, $target);

                if ($attempt >= $attempts) {
                    throw $failure;
                }

                $decision = $this->retry->decide($e, $attempt);

                if (! $decision->shouldRetry) {
                    throw $failure;
                }

                $this->wait($decision->delayMs);
            }
        }
    }

    /**
     * Wait out a backoff, clamped so an inline lookup cannot inherit a queue-sized delay.
     */
    private function wait(int $delayMs): void
    {
        $delay = max(0, min($delayMs, max(0, $this->maxDelayMs)));

        if ($delay > 0) {
            Sleep::for($delay)->milliseconds();
        }
    }
}
