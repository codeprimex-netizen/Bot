<?php

declare(strict_types=1);

namespace App\Services\Domains;

use App\Exceptions\Tenancy\DomainProbeUnavailableException;

/**
 * The one seam between domain verification and the network (Req 9.7 / A9).
 *
 * Everything `DomainVerifier` needs from outside the process goes through these three
 * methods: a DNS TXT lookup, an HTTP fetch, and a TLS handshake. Behind the interface,
 * `NetworkDomainProbe` really performs them and `GuardedDomainProbe` wraps that in the
 * platform's circuit breaker and retry budget. `Tests\Fixtures\Domains\FakeDomainProbe`
 * is bound **only** in the testing container (Property 28): it lives under `tests/`,
 * which `autoload-dev` maps, so it is not autoloadable in a production install at all.
 *
 * ## Absent vs unknown — the contract every implementation must honour
 *
 * The distinction runs through all three methods and it is the reason they return what
 * they return:
 *
 *  - **a value, including an empty one** means the question was answered. No TXT records
 *    (`[]`), a host that refused the connection (`null` body), a handshake that produced
 *    no trusted certificate (`null`) — these are all *evidence about the domain*, and a
 *    domain with no evidence for it stays (or becomes) unverified.
 *  - **`DomainProbeUnavailableException`** means the question could not be asked: the
 *    resolver itself errored, or the circuit breaker is open. That is evidence about the
 *    *platform*, and `DomainVerifier` treats it as inconclusive — it never verifies, and
 *    it never revokes.
 *
 * A tenant's dead host must therefore **not** throw. If it did, one misconfigured
 * domain would trip the shared breaker and fence every other tenant's verification off.
 *
 * ## Timeouts, not optimism
 *
 * Every implementation must bound both connect and total time
 * (`wa.tenancy.domains.probe.connect_timeout` / `timeout`). A verification runs inside a
 * request the tenant is waiting on, or inside the scheduled sweep; an unbounded DNS
 * lookup there is a stalled worker, which is a platform outage caused by one tenant's
 * typo.
 */
interface DomainProbe
{
    /**
     * Every TXT value published at $name, in no particular order.
     *
     * Multi-string TXT records are returned joined, as the wire format defines them, so
     * a value split across 255-byte chunks compares equal to the one that was issued.
     *
     * @return list<string> empty when the name exists with no TXT records, or does not exist
     *
     * @throws DomainProbeUnavailableException when the lookup could not be performed
     */
    public function txtRecords(string $name): array;

    /**
     * The body of a `200` response to `GET $url`, or null for anything else.
     *
     * Null covers every "the host did not serve this": a refused connection, a timeout,
     * a redirect, a 404, a 500. All of them mean the challenge is unsatisfied, and none
     * of them says anything about the platform.
     *
     * Redirects are **not** followed. A challenge that can be satisfied by redirecting
     * to a host the tenant does control would prove control of the redirect target
     * rather than of the claimed host.
     *
     * @throws DomainProbeUnavailableException when the request could not be attempted
     */
    public function fetch(string $url): ?string;

    /**
     * The certificate $host presents on $port over a *verified* TLS handshake, or null.
     *
     * Null covers a refused connection, a timeout, an untrusted chain, and a peer that
     * presented nothing parseable — every case where the platform cannot confirm a
     * trusted certificate. The chain is verified by the implementation, so a caller
     * holding a `TlsCertificate` already knows it was trusted.
     *
     * @throws DomainProbeUnavailableException when the handshake could not be attempted
     */
    public function certificate(string $host, int $port): ?TlsCertificate;
}
