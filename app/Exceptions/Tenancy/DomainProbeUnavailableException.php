<?php

declare(strict_types=1);

namespace App\Exceptions\Tenancy;

use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * The platform could not consult DNS or open a socket at all — **503, retryable**
 * (Req 9.7 / A9 guarded by Req 31.3 / NFR2).
 *
 * This is the *inconclusive* outcome of a domain check, and the distinction it carries
 * is the whole reason it exists as a type rather than a boolean:
 *
 *  - a DNS answer that does not match, or a certificate that is expired, is **evidence
 *    about the domain** — the tenant is told, and an already-verified domain is revoked;
 *  - this exception is **evidence about the platform** — our resolver is broken, our
 *    egress is blocked, or the circuit breaker guarding the probe is open. Nothing is
 *    known about the domain, so nothing about the domain changes.
 *
 * `DomainVerifier` catches it and returns
 * `DomainVerificationFailure::ProbeUnavailable`, which is the only failure that leaves
 * an existing `verified_at` in place. It never verifies anything: an unverified domain
 * stays unverified, so there is still no path from "could not check" to "verified".
 *
 * ## Why it never quotes the underlying failure
 *
 * The message names the operation (`dns.txt`, `http.challenge`, `tls.certificate`) and
 * the host, and stops there. A resolver or HTTP client exception can carry a proxy URL,
 * an internal resolver address, or a request header, and this exception is rendered to
 * a tenant on the domain-verification screen.
 */
final class DomainProbeUnavailableException extends RuntimeException implements HttpExceptionInterface
{
    public const int STATUS = 503;

    public const string PUBLIC_MESSAGE = 'The domain could not be checked just now. Nothing has changed; please try again shortly.';

    public const string ERROR_CODE = 'domain_probe_unavailable';

    /**
     * Seconds a client should wait before asking again. Short: the usual cause is a
     * breaker that is about to start probing again.
     */
    public const int RETRY_AFTER_SECONDS = 30;

    /**
     * @param  string  $operation  short label: `dns.txt`, `http.challenge`, `tls.certificate`
     */
    private function __construct(
        public readonly string $operation,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * The lookup could not be performed — resolver error, blocked egress, or an
     * exhausted retry budget.
     */
    public static function lookupFailed(string $operation, string $host): self
    {
        return new self($operation, sprintf(
            'Could not perform [%s] for host [%s]: the lookup itself failed, so nothing is known '
            .'about the domain. The underlying error is deliberately not quoted here — it can '
            .'carry resolver and proxy detail, and this message reaches a tenant.',
            $operation,
            $host,
        ));
    }

    /**
     * The circuit breaker guarding domain probes is open: the lookup was not attempted.
     */
    public static function circuitOpen(string $operation, string $host): self
    {
        return new self($operation, sprintf(
            'Domain probing is fenced off by its circuit breaker, so [%s] for host [%s] was not '
            .'attempted. A hanging lookup blocks the worker that asked for it, so refusing fast '
            .'is the point — the check is simply inconclusive until the breaker probes again.',
            $operation,
            $host,
        ));
    }

    public function getStatusCode(): int
    {
        return self::STATUS;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return ['Retry-After' => (string) self::RETRY_AFTER_SECONDS];
    }

    public function publicMessage(): string
    {
        return self::PUBLIC_MESSAGE;
    }

    public function errorCode(): string
    {
        return self::ERROR_CODE;
    }
}
