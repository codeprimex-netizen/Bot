<?php

declare(strict_types=1);

namespace App\Exceptions\Url;

use App\Enums\SignedUrlVerdict;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * A signed URL was presented and refused — expired, tampered, or replayed against
 * another host (Req 9.6 / A9; design § Base URL §U.5).
 *
 * ## One refusal, one sentence, whatever the reason
 *
 * Req 9.6 lists three ways a signed URL fails: a past expiry, a window over 3600
 * seconds, and a host that does not match the one bound into the signature. All three
 * — and every malformed input besides — produce **this** exception, with
 * `PUBLIC_MESSAGE` and `STATUS` fixed. That is deliberate. A holder of a signed link is
 * unauthenticated by definition, and a platform that answers "403 wrong signature" but
 * "410 expired" has told them which half of the URL to keep editing. The reason lives in
 * `verdict()` for the log line and nowhere else.
 *
 * For the same reason there is no variant of this exception, no `code` that varies, and
 * no message interpolation: everything that could differ between two refusals has been
 * removed, so the response cannot become an oracle by someone later "improving" the
 * error handling.
 *
 * ## Why 403 rather than 401 or 404
 *
 * The link *is* the credential, so there is nothing to re-authenticate with (401 would
 * invite a login prompt for a resource a session does not grant). 404 would hide that a
 * refusal happened at all, and Req 9.6 requires the response to indicate the URL is
 * invalid or expired. 403 says exactly that: the request was understood and is refused.
 *
 * Req 9.6 also requires that "any pending state" is left unchanged. That is a property
 * of *where* this is raised — before the guarded action, never inside it — which is why
 * `SignedUrlSigner::assertValid()` is the whole of the check and returns nothing: there
 * is no partially-verified state for a caller to act on.
 */
final class InvalidSignedUrlException extends RuntimeException implements HttpExceptionInterface
{
    /**
     * HTTP status for every refusal (Req 9.6).
     */
    public const int STATUS = 403;

    /**
     * The only sentence a client is ever shown. It does not say *which* check failed.
     */
    public const string PUBLIC_MESSAGE = 'This link is invalid or has expired.';

    /**
     * Stable machine-readable code for API clients and the panel.
     */
    public const string ERROR_CODE = 'invalid_signed_url';

    private function __construct(private readonly SignedUrlVerdict $verdict)
    {
        parent::__construct(self::PUBLIC_MESSAGE, self::STATUS);
    }

    /**
     * Refuse a presented URL. `$verdict` is carried for logs and metrics; it never
     * reaches the response.
     */
    public static function refused(SignedUrlVerdict $verdict): self
    {
        return new self($verdict);
    }

    /**
     * Why the link was refused — for an operator, not for the client.
     */
    public function verdict(): SignedUrlVerdict
    {
        return $this->verdict;
    }

    /**
     * A log line naming the failed check. Carries no signature material and no secret.
     */
    public function operatorMessage(): string
    {
        return sprintf('Signed URL refused: %s.', $this->verdict->reason());
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
