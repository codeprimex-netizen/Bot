<?php

declare(strict_types=1);

namespace App\Services\Channel\Bsp;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * One HTTP request a `BspAdapter` wants made — the shape, and nothing about how it is made
 * (Req 8.1, 8.2 / A8; design § Channel Mode 2.2 mode 4).
 *
 * ```php
 * // TwilioAdapter::textMessage() — the partner's vocabulary, and no HTTP client in sight
 * return BspRequest::form(
 *     'POST',
 *     $this->baseUrl($credentials).'/2010-04-01/Accounts/'.$sid.'/Messages.json',
 *     ['To' => 'whatsapp:+919812345678', 'From' => $from, 'Body' => $text],
 *     ['Authorization' => 'Basic '.base64_encode($sid.':'.$token)],
 * );
 * ```
 *
 * ## Why an adapter describes a request instead of making one
 *
 * Eight partners have eight endpoints, eight body encodings and eight auth schemes, and
 * exactly **one** of those differences is allowed to reach the network layer. Everything the
 * platform insists on around a provider call — the circuit breaker and the bounded inline
 * retry (`App\Services\Channel\ProviderCallGuard`), the connect/read timeouts, the
 * fail-closed conversion of an unknown throwable, the `Http::fake()`-able client from the
 * injected factory — is identical for all eight and is `BspGatewayChannelDriver`'s.
 *
 * If an adapter held a client, each of those eight classes would be a place the breaker could
 * be forgotten, and seven of them would be forgotten eventually. So an adapter is a pure
 * function from credentials to *this*: a method, a URL, headers, and a body in one of two
 * encodings. It performs no I/O, holds no state, and can be asserted on directly in a test
 * without a fake server.
 *
 * ## Two encodings, because the partners genuinely split down the middle
 *
 * | Encoding | Partners |
 * |---|---|
 * | `application/x-www-form-urlencoded` (`form()`) | Twilio (`Messages.json`), Gupshup (`/wa/api/v1/msg`) |
 * | `application/json` (`json()`) | 360dialog, Vonage, MessageBird, Infobip, WATI, Kaleyra |
 *
 * A `get()` carries neither and takes its parameters in the query string.
 *
 * ## Secrets live in `headers`, and this class knows it
 *
 * Every partner authenticates in a header — Twilio's `Authorization: Basic`, 360dialog's
 * `D360-API-KEY`, Gupshup's `apikey`, Vonage's `Authorization: Bearer <JWS>`, MessageBird's
 * `Authorization: AccessKey`, Infobip's `Authorization: App`, WATI's bearer, Kaleyra's
 * `api-key`. So an instance of this class holds a decrypted secret for the length of one call,
 * which is exactly the window Req 8.5 permits — and `__debugInfo()` replaces the value of any
 * header whose name looks like credential material, so a `dd()`, a `var_dump()`, or a PHPUnit
 * failure diff of a request cannot print a tenant's API key. `ChannelCredentials` makes the
 * same argument about its own secret bag, for the same reason.
 *
 * Nothing here is serialisable-by-accident either: the class is `readonly`, the driver never
 * stores one, and the only thing it is passed to is the HTTP client.
 */
final readonly class BspRequest
{
    /**
     * Header names whose value `__debugInfo()` replaces with a placeholder.
     *
     * Matched case-insensitively as a substring, so `Authorization`, `D360-API-KEY`, `apikey`,
     * `api-key`, `X-WATI-Token` and anything a future partner calls its key are all covered
     * without this list having to be exhaustive. The failure mode of a false positive is a
     * redacted header in a debug dump; the failure mode of a false negative is an API key in a
     * CI log.
     */
    public const array SECRET_HEADER_FRAGMENTS = ['auth', 'key', 'token', 'secret', 'signature', 'password'];

    public const string REDACTED = '[redacted]';

    /**
     * @param  string  $method  `GET` or `POST` — the only two verbs the eight partners' send and probe routes use
     * @param  string  $url  absolute, built by the adapter from the credentials' base URL
     * @param  array<string, string>  $headers  including the partner's auth header
     * @param  array<string, mixed>|null  $json  a JSON body, or null
     * @param  array<string, scalar>|null  $form  a form-encoded body, or null
     * @param  array<string, string>  $query  query-string parameters
     *
     * @throws InvalidArgumentException when the request could not be made as described
     */
    private function __construct(
        public string $method,
        public string $url,
        #[SensitiveParameter]
        public array $headers = [],
        public ?array $json = null,
        public ?array $form = null,
        public array $query = [],
    ) {
        if ($this->json !== null && $this->form !== null) {
            throw new InvalidArgumentException(
                'A BSP request carries at most one body: a JSON one or a form-encoded one. Both means '
                .'the HTTP client picks an encoding arbitrarily, and the partner rejects whichever it '
                .'did not expect.'
            );
        }

        if (! str_starts_with($this->url, 'https://') && ! str_starts_with($this->url, 'http://')) {
            // Refused here rather than at the client: a relative URL would be resolved against
            // nothing and a scheme-less one against the current host, which for an outbound
            // partner call means a tenant's API key posted to this platform's own ingress.
            throw new InvalidArgumentException(sprintf(
                'A BSP request needs an absolute URL; got [%s]. The adapter builds it from the '
                .'credentials\' base URL, so an unusable one is a missing or malformed [base_url].',
                mb_strimwidth($this->url, 0, 120, '…'),
            ));
        }
    }

    /**
     * A `GET`, for an account probe.
     *
     * @param  array<string, string>  $headers
     * @param  array<string, string>  $query
     */
    public static function get(
        string $url,
        #[SensitiveParameter]
        array $headers = [],
        array $query = [],
    ): self {
        return new self('GET', $url, $headers, query: $query);
    }

    /**
     * A `POST` with a JSON body.
     *
     * `$query` is for the partners that take part of a send in the query string even on a
     * `POST` — WATI addresses the recipient that way — rather than a shape this class invented.
     *
     * @param  array<string, mixed>  $json
     * @param  array<string, string>  $headers
     * @param  array<string, string>  $query
     */
    public static function json(
        string $url,
        array $json,
        #[SensitiveParameter]
        array $headers = [],
        array $query = [],
    ): self {
        return new self('POST', $url, $headers, json: $json, query: $query);
    }

    /**
     * A `POST` with a form-encoded body — Twilio's, Gupshup's and Kaleyra's send routes.
     *
     * @param  array<string, scalar>  $form
     * @param  array<string, string>  $headers
     * @param  array<string, string>  $query
     */
    public static function form(
        string $url,
        array $form,
        #[SensitiveParameter]
        array $headers = [],
        array $query = [],
    ): self {
        return new self('POST', $url, $headers, form: $form, query: $query);
    }

    /**
     * Whether this request carries a form-encoded body rather than JSON.
     */
    public function isForm(): bool
    {
        return $this->form !== null;
    }

    /**
     * The body, whichever encoding it is in — what the HTTP client is handed.
     *
     * @return array<string, mixed>
     */
    public function body(): array
    {
        return $this->form ?? $this->json ?? [];
    }

    /**
     * What `dd()`, `var_dump()` and a test-failure diff are allowed to see.
     *
     * The URL and the body are shown: a partner endpoint is not a secret, and a message body
     * is already governed by Req 7.3's no-bodies-in-logs rule at the logging layer rather than
     * here (this is a debug dump a developer asked for, not a log line). Header **values** are
     * not, because that is where every one of the eight partners puts its key.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        $headers = [];

        foreach ($this->headers as $name => $value) {
            $headers[$name] = self::isSecretHeader($name) ? self::REDACTED : $value;
        }

        return [
            'method' => $this->method,
            'url' => $this->url,
            'headers' => $headers,
            'json' => $this->json,
            'form' => $this->form,
            'query' => $this->query,
        ];
    }

    /**
     * Whether a header name looks like it carries credential material.
     */
    private static function isSecretHeader(string $name): bool
    {
        $lowered = strtolower($name);

        foreach (self::SECRET_HEADER_FRAGMENTS as $fragment) {
            if (str_contains($lowered, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
