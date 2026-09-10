<?php

declare(strict_types=1);

namespace App\Exceptions\Url;

use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * A request arrived on a host this deployment does not serve, and was refused before
 * anything ran (Req 9.4, 9.5 / A9; design § Base URL §U.5).
 *
 * ## Why 400, and why the same 400 the framework gives
 *
 * Symfony raises `SuspiciousOperationException` when `Request::getHost()` sees a host
 * outside the trusted-host patterns, and Laravel maps that (it is a
 * `RequestExceptionInterface`) to a **400**. Since `TrustHosts` and
 * `EnforceAllowedHost` guard the same set from two levels, they must refuse with the same
 * status, or which layer noticed first would be observable — and an operator debugging a
 * misconfigured DNS record would be chasing two different symptoms of one cause.
 *
 * 400 is also the honest code: the request is malformed *as addressed to us*. 404 would
 * claim the path does not exist (it may well), 421 invites an HTTP/2 client to retry on a
 * fresh connection to the same wrong name, and a redirect to the canonical host would turn
 * the platform into an open redirector driven by a header.
 *
 * ## The response says what is wrong and nothing else
 *
 * `PUBLIC_MESSAGE` names the problem — Req 9.5 requires the response to indicate the host
 * is not recognised — without echoing the host back. A reflected `Host` header in a body
 * is a small XSS/log-injection surface for no benefit: the sender already knows what it
 * sent, and an operator reads the host from `operatorMessage()` in the log instead.
 *
 * Rendered as plain text (see `bootstrap/app.php`) rather than through the error views: an
 * error page is a Blade template that may build asset and route URLs, and building URLs
 * while serving a host we have just refused is the one thing this refusal exists to
 * prevent.
 */
final class HostNotAllowedException extends RuntimeException implements HttpExceptionInterface
{
    /**
     * HTTP status for every refusal — the same one Symfony's untrusted-host path yields.
     */
    public const int STATUS = 400;

    /**
     * The only sentence a client is ever shown.
     */
    public const string PUBLIC_MESSAGE = 'The requested host is not recognized by this server.';

    /**
     * Stable machine-readable code for API clients.
     */
    public const string ERROR_CODE = 'host_not_allowed';

    /**
     * Stand-in for a host that could not even be read — Symfony refused it before we could
     * (it returns `''` from `getHost()` after raising), or the request carried none.
     */
    public const string UNKNOWN_HOST = '(unreadable)';

    private function __construct(private readonly string $host)
    {
        parent::__construct(self::PUBLIC_MESSAGE, self::STATUS);
    }

    /**
     * Refuse $host. The value is carried for the log line only; it never reaches the
     * response body.
     */
    public static function forHost(string $host): self
    {
        $host = trim($host);

        return new self($host === '' ? self::UNKNOWN_HOST : $host);
    }

    /**
     * The refused host, for an operator.
     */
    public function host(): string
    {
        return $this->host;
    }

    /**
     * The one sentence a client is shown — never the internal message, and never the host.
     */
    public function publicMessage(): string
    {
        return self::PUBLIC_MESSAGE;
    }

    /**
     * A log line naming the host. Truncated, because the value is attacker-controlled and
     * a log line is not the place to store a kilobyte of someone else's header. Control
     * characters are stripped for the same reason a base URL refuses them: a CR/LF in a
     * log is a forged second entry.
     */
    public function operatorMessage(): string
    {
        $host = preg_replace('/[^\x21-\x7E]/', '?', $this->host) ?? self::UNKNOWN_HOST;

        return sprintf(
            'Refused a request on host [%s]: it is not the platform apex, a tenant '
            .'subdomain, or a verified custom domain.',
            mb_strimwidth($host, 0, 120, '…'),
        );
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
        return [];
    }
}
