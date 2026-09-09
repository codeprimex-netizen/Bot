<?php

declare(strict_types=1);

namespace App\Services\Reliability;

use App\Exceptions\Reliability\OutboxDeliveryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;

/**
 * The default transport: POST the effect to the destination URL as JSON, with the dedup
 * header (Req 31.4 / NFR2, Algorithm 6, Correctness Property 16).
 *
 * This is the shape D2.2 actually needs — an outbound webhook — and it is a real
 * implementation rather than a placeholder, so the outbox is delivering effects from the
 * moment the relay is scheduled. Phase 5+ channel drivers replace it (or route around it)
 * by naming another `OutboxTransport` in `wa.reliability.outbox.transport`.
 *
 * ## Failures are thrown as-is, or wrapped in a status
 *
 * - A receiver that cannot be reached raises the HTTP client's own `ConnectionException`,
 *   which is left to propagate **unwrapped**: `PlatformErrorClassifier` already classifies
 *   it as `NETWORK`, and wrapping it would throw that classification away.
 * - A receiver that answers with a non-2xx raises `OutboxDeliveryException::rejected()`
 *   carrying *its* status, so the retry matrix treats a 500 (retry), a 429 (defer, honour
 *   `Retry-After`) and a 400 (park) differently without this class having an opinion.
 * - A row with no usable destination raises `OutboxDeliveryException::undeliverable()` and
 *   parks on the first attempt — retrying a missing URL twelve times only delays the
 *   moment somebody notices.
 *
 * ## Why the client's own retries are off
 *
 * `retry()` is deliberately not used. The relay is the retry mechanism: it owns the
 * attempt budget, the jittered backoff, and the `attempts` column an operator reads. A
 * transport that retried internally would multiply the two budgets, hide attempts from the
 * row, and hold the claim lease open across all of them.
 *
 * Timeouts are bounded for the same reason — a claimed row's lease
 * (`wa.reliability.outbox.lease_seconds`) has to outlive the whole attempt, so an
 * unbounded read is not an option.
 */
final readonly class HttpOutboxTransport implements OutboxTransport
{
    /**
     * Seconds to wait for the whole request, and for the connection alone, when config
     * says nothing.
     */
    public const int DEFAULT_TIMEOUT = 10;

    public const int DEFAULT_CONNECT_TIMEOUT = 5;

    /**
     * Schemes a webhook may use. `https` first because that is what a destination should
     * be; plain `http` stays allowed for an in-cluster receiver that is not on the public
     * internet.
     */
    private const array ALLOWED_SCHEMES = ['https', 'http'];

    public function __construct(private HttpFactory $http) {}

    /**
     * @throws OutboxDeliveryException the destination is unusable, or the receiver refused
     * @throws ConnectionException the receiver could not be reached
     */
    public function deliver(OutboxDelivery $delivery): void
    {
        $url = $this->destinationOf($delivery);

        $response = $this->http
            ->asJson()
            ->acceptJson()
            ->withHeaders($delivery->headers)
            ->connectTimeout($this->connectTimeout())
            ->timeout($this->timeout())
            ->post($url, $delivery->payload);

        if ($response->failed()) {
            throw OutboxDeliveryException::rejected($delivery, $response->status(), $this->retryAfter($response));
        }
    }

    /**
     * The destination, proven usable before a single byte leaves the process.
     *
     * @throws OutboxDeliveryException
     */
    private function destinationOf(OutboxDelivery $delivery): string
    {
        $destination = trim((string) $delivery->destination);

        if ($destination === '') {
            throw OutboxDeliveryException::undeliverable(
                $delivery,
                'the row names no destination, and this transport resolves none of its own',
            );
        }

        $scheme = strtolower((string) parse_url($destination, PHP_URL_SCHEME));
        $host = (string) parse_url($destination, PHP_URL_HOST);

        if ($host === '' || ! in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            throw OutboxDeliveryException::undeliverable(
                $delivery,
                sprintf('its destination is not an absolute %s URL', implode('/', self::ALLOWED_SCHEMES)),
            );
        }

        return $destination;
    }

    /**
     * A `Retry-After` the receiver named, in its numeric form only.
     *
     * The HTTP-date form is ignored rather than parsed, for the reason
     * `RetryPolicy::retryAfterSecondsFrom()` gives: a clock skew would turn a two-second
     * wait into a two-hour one, and the header is only honoured at all for the classes
     * whose backoff shape asks for it.
     */
    private function retryAfter(Response $response): ?int
    {
        $header = trim((string) $response->header('Retry-After'));

        return preg_match('/^\d+$/', $header) === 1 ? (int) $header : null;
    }

    private function timeout(): int
    {
        return $this->positiveConfig('wa.reliability.outbox.http.timeout', self::DEFAULT_TIMEOUT);
    }

    private function connectTimeout(): int
    {
        return $this->positiveConfig('wa.reliability.outbox.http.connect_timeout', self::DEFAULT_CONNECT_TIMEOUT);
    }

    /**
     * A positive integer from config, falling back to the compiled-in default — so a
     * deleted or nonsensical key degrades to a working timeout rather than to none.
     */
    private function positiveConfig(string $key, int $default): int
    {
        $value = config($key);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
    }
}
