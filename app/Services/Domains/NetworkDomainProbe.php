<?php

declare(strict_types=1);

namespace App\Services\Domains;

use App\Exceptions\Tenancy\DomainProbeUnavailableException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Throwable;

/**
 * The real `DomainProbe`: a DNS TXT lookup, an HTTP fetch, and a TLS handshake against
 * a host the platform does not control (Req 9.7 / A9).
 *
 * This is the production implementation and it is bound in every environment
 * (`DomainServiceProvider`); the only substitute anywhere is
 * `Tests\Fixtures\Domains\FakeDomainProbe`, which is not autoloadable outside the test
 * suite.
 *
 * ## Every call is bounded
 *
 * `connect_timeout` (default 3s) and `timeout` (default 5s) bound each operation, and
 * the DNS lookup gets the same ceiling by being wrapped in PHP's own resolver timeout
 * where the platform's resolver honours it. That matters more here than almost anywhere
 * else on the platform: these hosts are *supplied by tenants*, so an unbounded lookup is
 * a stalled worker triggered by somebody else's typo. `GuardedDomainProbe` adds the
 * circuit breaker and retry budget on top; this class does no retrying of its own.
 *
 * ## What is a domain problem and what is a platform problem
 *
 * Only failures of *our* ability to ask throw `DomainProbeUnavailableException`: the
 * resolver returning an error, or the HTTP client failing for a reason that is not a
 * connection to the tenant's host. Everything the tenant's host does — refusing the
 * connection, timing out, answering 404, presenting a bad certificate — is returned as
 * an absent value, because it is an answer. If a dead tenant host threw, one typo would
 * trip the shared breaker and fence every other tenant's verification off.
 *
 * ## TLS: verify first, then look again to explain
 *
 * The handshake runs with `verify_peer` on, so a certificate that reaches
 * `TlsCertificate::$trusted === true` really chained to the system trust store.
 * `verify_peer_name` is deliberately **off**: name matching is done by
 * `TlsCertificate::coversHost()` instead, so "the certificate does not cover this
 * domain" is a distinct, reportable outcome rather than an opaque handshake failure. If
 * verification fails, a second, unverified handshake runs *only* to describe what is
 * there (`$trusted === false`) — the tenant with a half-installed chain gets told that,
 * and the untrusted certificate is never enough to verify.
 */
final readonly class NetworkDomainProbe implements DomainProbe
{
    public function __construct(
        private HttpFactory $http,
        private int $connectTimeout = 3,
        private int $timeout = 5,
    ) {}

    public function txtRecords(string $name): array
    {
        $name = trim($name, ". \t");

        if ($name === '') {
            return [];
        }

        // Suppressed rather than trusted: `dns_get_record` emits a warning *and* returns
        // false on a resolver error, and the warning is the thing that would otherwise
        // become an exception under a strict error handler. The false is handled below,
        // which is the branch that matters.
        $records = @dns_get_record($name, DNS_TXT);

        if ($records === false) {
            // The resolver failed. Not "the record is absent" — an absent record is an
            // empty array, and conflating the two would let a broken resolver revoke
            // every verified domain on the platform.
            throw DomainProbeUnavailableException::lookupFailed('dns.txt', $name);
        }

        $values = [];

        foreach ($records as $record) {
            $value = $this->txtValue($record);

            if ($value !== null) {
                $values[] = $value;
            }
        }

        return $values;
    }

    public function fetch(string $url): ?string
    {
        try {
            $response = $this->http
                // A challenge satisfied by a redirect would prove control of the redirect
                // *target*, not of the claimed host.
                ->withoutRedirecting()
                ->connectTimeout($this->connectTimeout)
                ->timeout($this->timeout)
                ->withHeaders(['Accept' => 'text/plain'])
                ->get($url);
        } catch (ConnectionException) {
            // The host did not answer. That is an answer: the challenge is unsatisfied.
            return null;
        } catch (Throwable) {
            // The request could not be made at all (client misconfiguration, a faked
            // transport refusing). Nothing is known about the domain.
            throw DomainProbeUnavailableException::lookupFailed('http.challenge', $url);
        }

        return $response->status() === 200 ? $response->body() : null;
    }

    public function certificate(string $host, int $port): ?TlsCertificate
    {
        $host = trim($host, ". \t");

        if ($host === '' || $port < 1 || $port > 65535) {
            return null;
        }

        $verified = $this->handshake($host, $port, verifyPeer: true);

        if ($verified instanceof TlsCertificate) {
            return $verified;
        }

        // Verification failed. Look again without it — purely to tell the tenant whether
        // there is an untrusted certificate there (fixable) or nothing at all.
        return $this->handshake($host, $port, verifyPeer: false);
    }

    /**
     * One TLS handshake, returning what the peer presented or null.
     */
    private function handshake(string $host, int $port, bool $verifyPeer): ?TlsCertificate
    {
        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'verify_peer' => $verifyPeer,
                // Name matching is `TlsCertificate::coversHost()`'s job; see the class
                // docblock for why that split exists.
                'verify_peer_name' => false,
                'SNI_enabled' => true,
                'peer_name' => $host,
                'allow_self_signed' => ! $verifyPeer,
            ],
        ]);

        $errorNumber = 0;
        $errorMessage = '';

        $client = @stream_socket_client(
            sprintf('ssl://%s:%d', $host, $port),
            $errorNumber,
            $errorMessage,
            max(1, $this->connectTimeout),
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($client === false) {
            // Refused, timed out, or (on the verified pass) an untrusted chain. All of
            // them are facts about the host, so none of them throws.
            return null;
        }

        try {
            $params = stream_context_get_params($client);
            $peer = $params['options']['ssl']['peer_certificate'] ?? null;

            if (! $peer instanceof \OpenSSLCertificate) {
                return null;
            }

            return $this->parse($peer, $verifyPeer);
        } finally {
            fclose($client);
        }
    }

    /**
     * Reduce a peer certificate to the names and window Req 9.7 is decided on.
     */
    private function parse(\OpenSSLCertificate $peer, bool $trusted): ?TlsCertificate
    {
        $parsed = openssl_x509_parse($peer);

        if (! is_array($parsed)) {
            return null;
        }

        $from = $parsed['validFrom_time_t'] ?? null;
        $to = $parsed['validTo_time_t'] ?? null;

        if (! is_int($from) || ! is_int($to)) {
            // A certificate whose validity cannot be read cannot be checked against the
            // clock, so it cannot support a verification.
            return null;
        }

        $names = $this->names($parsed);

        return $names === []
            ? null
            : new TlsCertificate(
                names: $names,
                validFrom: CarbonImmutable::createFromTimestampUTC($from),
                validTo: CarbonImmutable::createFromTimestampUTC($to),
                trusted: $trusted,
            );
    }

    /**
     * Subject CN plus every `DNS:` entry of subjectAltName, lowercased and de-duplicated.
     *
     * Both, because a certificate may carry the host in either: modern issuers put every
     * name in the SAN extension, while an older one may only have the CN. Non-DNS SAN
     * entries (`IP:`, `email:`) are dropped — they cannot cover a hostname.
     *
     * @param  array<array-key, mixed>  $parsed
     * @return list<string>
     */
    private function names(array $parsed): array
    {
        $names = [];

        $subject = $parsed['subject'] ?? null;
        $commonName = is_array($subject) ? ($subject['CN'] ?? null) : null;

        if (is_string($commonName) && trim($commonName) !== '') {
            $names[] = strtolower(trim($commonName));
        }

        $extensions = $parsed['extensions'] ?? null;
        $san = is_array($extensions) ? ($extensions['subjectAltName'] ?? null) : null;

        foreach (is_string($san) ? explode(',', $san) : [] as $entry) {
            $entry = trim($entry);

            if (! str_starts_with(strtolower($entry), 'dns:')) {
                continue;
            }

            $name = strtolower(trim(substr($entry, 4)));

            if ($name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * One TXT record's value, with a multi-chunk record rejoined.
     *
     * A TXT value longer than 255 bytes is transmitted as several strings and must be
     * concatenated to compare equal to what was issued — `entries` holds the chunks, and
     * `txt` is PHP's already-joined convenience form.
     *
     * @param  array<array-key, mixed>  $record
     */
    private function txtValue(array $record): ?string
    {
        $entries = $record['entries'] ?? null;

        if (is_array($entries) && $entries !== []) {
            $chunks = array_filter($entries, 'is_string');

            return $chunks === [] ? null : implode('', $chunks);
        }

        $txt = $record['txt'] ?? null;

        return is_string($txt) && $txt !== '' ? $txt : null;
    }
}
