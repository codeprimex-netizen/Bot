<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Exceptions\Security\KeyUnavailableException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use SensitiveParameter;
use Throwable;

/**
 * The real KMS adapter: HashiCorp **Vault transit** engine over its documented HTTP
 * API (Req 32.6 / NFR3; design § Secrets management — "app secrets from Vault /
 * cloud KMS-backed secret store").
 *
 * Transit is "encryption as a service": the master key never leaves Vault, and this
 * process only ever sends it a DEK (or an HMAC secret) to seal and gets a blob back.
 * That is exactly the shape of `KmsClient`, so the mapping is direct:
 *
 * | `KmsClient` | Vault transit |
 * |---|---|
 * | `encrypt($plaintext, $context)` | `POST /v1/{mount}/encrypt/{key}` — `plaintext` + `associated_data`, both base64 |
 * | `decrypt($keyId, $blob, $context)` | `POST /v1/{mount}/decrypt/{key}` — `ciphertext` + `associated_data` |
 * | `activeKeyId()` | `GET /v1/{mount}/keys/{key}` → `data.latest_version` |
 * | `rotate()` | `POST /v1/{mount}/keys/{key}/rotate` |
 *
 * `associated_data` is transit's AEAD additional-data parameter (`aes256-gcm96`,
 * `chacha20-poly1305`), so `EnvelopeFieldCipher`'s `tenant|purpose|version` binding
 * survives intact — a `wrapped_dek` blob moved into another tenant's row still fails
 * to open, with the check performed inside Vault. A transit key configured with a
 * non-AEAD type would ignore the parameter, which is why the key type is read and
 * refused when it cannot authenticate additional data: silently dropping the AAD
 * would silently drop tenant binding.
 *
 * ## Key ids carry the version, on purpose
 *
 * `activeKeyId()` returns `vault:{mount}/{key}:v{n}`. Vault's own ciphertext
 * (`vault:v3:…`) already names the version it used, so decryption would work without
 * the id — but `encryption_keys.kms_key_id` is what the re-wrap sweep queries, and
 * "every DEK still sealed under v2" has to be answerable with one indexed query
 * rather than by opening every blob. The mount and key name travel in the id too, so
 * material sealed before an operator renamed the transit key still decrypts against
 * the key it was sealed with.
 *
 * ## Failure behaviour
 *
 * Everything fails closed as `KeyUnavailableException` (503, retryable) and nothing
 * about the token, the URL, the blob or Vault's response body ever reaches a message
 * or a log. Retries and the circuit breaker are **not** here — they are
 * `GuardedKmsClient`'s job, so this class stays one HTTP call per operation and can
 * be reasoned about (and faked) as such.
 *
 * ## Deployment
 *
 * ```
 * vault secrets enable transit
 * vault write -f transit/keys/wa-master type=aes256-gcm96
 * # policy: capabilities ["update"] on transit/encrypt/wa-master and transit/decrypt/wa-master,
 * #         ["read"] on transit/keys/wa-master, ["update"] on transit/keys/wa-master/rotate
 * ```
 *
 * then `WA_KEY_WRAPPER=App\Services\Security\KmsKeyWrapper`, `WA_KMS_DRIVER=vault`,
 * `WA_VAULT_ADDR`, and `WA_VAULT_TOKEN` (or `WA_VAULT_TOKEN_FILE` for a
 * Kubernetes/agent-injected token, which is re-read on every request so a renewed
 * token is picked up without a restart).
 */
final class VaultTransitKmsClient implements KmsClient
{
    /**
     * Prefix of the ids this client issues, so a stored id can be recognised as
     * (or rejected as not) one of ours before any call is made.
     */
    public const string ID_PREFIX = 'vault:';

    /**
     * Algorithm label stored alongside the blob. The concrete cipher lives in Vault's
     * key configuration and can be migrated there; what this platform needs to record
     * is *which mechanism opens the blob*, and `vault-transit` is that.
     */
    public const string ALGORITHM = 'vault-transit';

    /**
     * Transit key types whose `associated_data` is actually authenticated. A key of
     * any other type would accept the parameter and ignore it, which would drop the
     * tenant binding that makes cross-tenant ciphertext undecryptable — so it is
     * refused instead.
     */
    private const array AEAD_KEY_TYPES = ['aes128-gcm96', 'aes256-gcm96', 'aes256-gcm', 'chacha20-poly1305'];

    /**
     * The key's metadata (`latest_version`, `type`) for this process, memoised: it is
     * read on every `activeKeyId()` and would otherwise be one extra round trip per
     * seal. Dropped by `rotate()`, which is the only thing that changes it here.
     *
     * @var array{latest_version: int, type: string}|null
     */
    private ?array $keyMetadata = null;

    /**
     * @param  string  $address  Vault base address, e.g. `https://vault.internal:8200`
     * @param  string  $token  Vault token; empty when `$tokenFile` supplies it instead
     * @param  string|null  $tokenFile  path a token is read from on every request (agent/CSI injection)
     * @param  string  $mount  transit mount path, `transit` unless remounted
     * @param  string  $keyName  the transit key DEKs are sealed under
     * @param  string|null  $namespace  Vault Enterprise / HCP namespace, if any
     */
    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $address,
        #[SensitiveParameter]
        private readonly string $token = '',
        private readonly ?string $tokenFile = null,
        private readonly string $mount = 'transit',
        private readonly string $keyName = 'wa-master',
        private readonly ?string $namespace = null,
        private readonly int $timeout = 5,
        private readonly int $connectTimeout = 3,
    ) {}

    public function activeKeyId(): string
    {
        return $this->keyId($this->metadata()['latest_version']);
    }

    public function encrypt(#[SensitiveParameter] string $plaintext, string $context): KmsSeal
    {
        $this->assertAeadKey();

        $response = $this->call('encrypt', 'post', $this->path('encrypt/'.$this->keyName), [
            'plaintext' => base64_encode($plaintext),
            'associated_data' => base64_encode($context),
        ]);

        $ciphertext = $this->stringField($response, 'ciphertext', 'encrypt');

        return new KmsSeal(
            $this->keyId($this->versionOf($ciphertext)),
            self::ALGORITHM,
            $ciphertext,
        );
    }

    public function decrypt(string $keyId, string $ciphertext, string $context): string
    {
        // Address the mount and key the blob was *sealed* with, not whatever is
        // configured now: renaming or remounting a transit key must not orphan data.
        [$mount, $keyName] = $this->parseKeyId($keyId);

        $response = $this->call('decrypt', 'post', $this->path('decrypt/'.$keyName, $mount), [
            'ciphertext' => $ciphertext,
            'associated_data' => base64_encode($context),
        ]);

        $plaintext = base64_decode($this->stringField($response, 'plaintext', 'decrypt'), true);

        if ($plaintext === false) {
            throw KeyUnavailableException::kmsMalformedResponse('decrypt');
        }

        return $plaintext;
    }

    public function rotate(): string
    {
        $this->call('rotate', 'post', $this->path('keys/'.$this->keyName.'/rotate'));

        // The new version only exists after the call, so the memo has to go.
        $this->keyMetadata = null;

        return $this->activeKeyId();
    }

    /**
     * `GET /v1/{mount}/keys/{key}` — the key's latest version and type.
     *
     * @return array{latest_version: int, type: string}
     */
    private function metadata(): array
    {
        if ($this->keyMetadata !== null) {
            return $this->keyMetadata;
        }

        $data = $this->call('keys.read', 'get', $this->path('keys/'.$this->keyName));

        $version = $data['latest_version'] ?? null;
        $type = $data['type'] ?? '';

        if (! is_int($version) && ! (is_string($version) && ctype_digit($version))) {
            throw KeyUnavailableException::kmsMalformedResponse('keys.read');
        }

        if ((int) $version < 1) {
            // A key with no versions cannot seal anything; refusing is the fail-closed
            // answer, and it is also the actionable one (`vault write -f .../rotate`).
            throw KeyUnavailableException::kmsNotConfigured('the transit key has no usable version');
        }

        return $this->keyMetadata = [
            'latest_version' => (int) $version,
            'type' => is_string($type) ? $type : '',
        ];
    }

    /**
     * Refuse a transit key that cannot authenticate `associated_data`.
     */
    private function assertAeadKey(): void
    {
        $type = $this->metadata()['type'];

        if ($type !== '' && ! in_array($type, self::AEAD_KEY_TYPES, true)) {
            throw KeyUnavailableException::kmsNotConfigured(sprintf(
                'transit key type [%s] cannot authenticate associated data; use one of %s',
                $type,
                implode(', ', self::AEAD_KEY_TYPES),
            ));
        }
    }

    /**
     * One Vault call, with every failure mode collapsed onto the fail-closed path.
     *
     * @param  string  $operation  short label for messages — never a URL, never a payload
     * @param  array<string, string>  $payload
     * @return array<string, mixed> Vault's `data` object
     */
    private function call(string $operation, string $method, string $path, array $payload = []): array
    {
        try {
            $request = $this->request();

            $response = $method === 'get'
                ? $request->get($path)
                : $request->post($path, $payload);
        } catch (Throwable) {
            // Connection refused, DNS failure, TLS error, timeout. The provider's own
            // message may quote the request body, so it is dropped entirely.
            throw KeyUnavailableException::kmsUnreachable($operation);
        }

        if ($response->failed()) {
            throw KeyUnavailableException::kmsRejected($operation, $response->status());
        }

        return $this->data($response, $operation);
    }

    /**
     * A configured, authenticated client. Nothing here is logged: the token is a
     * header on a fresh request each time and is never held anywhere else.
     */
    private function request(): PendingRequest
    {
        $token = $this->resolvedToken();

        if ($this->address === '') {
            throw KeyUnavailableException::kmsNotConfigured('no Vault address is configured (WA_VAULT_ADDR)');
        }

        if ($token === '') {
            throw KeyUnavailableException::kmsNotConfigured('no Vault token is available (WA_VAULT_TOKEN / WA_VAULT_TOKEN_FILE)');
        }

        $request = $this->http
            ->asJson()
            ->acceptJson()
            ->withHeaders(['X-Vault-Token' => $token])
            ->connectTimeout(max(1, $this->connectTimeout))
            ->timeout(max(1, $this->timeout))
            ->baseUrl(rtrim($this->address, '/'));

        return $this->namespace === null || $this->namespace === ''
            ? $request
            : $request->withHeaders(['X-Vault-Namespace' => $this->namespace]);
    }

    /**
     * The token, re-read from its file on every request so an agent-renewed token is
     * picked up without restarting the process.
     */
    private function resolvedToken(): string
    {
        if ($this->tokenFile !== null && $this->tokenFile !== '' && is_readable($this->tokenFile)) {
            $contents = @file_get_contents($this->tokenFile);

            if (is_string($contents) && trim($contents) !== '') {
                return trim($contents);
            }
        }

        return $this->token;
    }

    /**
     * Vault's `data` object, or a fail-closed error.
     *
     * @return array<string, mixed>
     */
    private function data(Response $response, string $operation): array
    {
        // `keys/{name}/rotate` answers 204 with no body, which is a success with no data.
        if ($response->status() === 204) {
            return [];
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw KeyUnavailableException::kmsMalformedResponse($operation);
        }

        $data = $body['data'] ?? [];

        if (! is_array($data)) {
            throw KeyUnavailableException::kmsMalformedResponse($operation);
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * A required, non-empty string field of a response.
     *
     * @param  array<string, mixed>  $data
     */
    private function stringField(array $data, string $field, string $operation): string
    {
        $value = $data[$field] ?? null;

        if (! is_string($value) || $value === '') {
            throw KeyUnavailableException::kmsMalformedResponse($operation);
        }

        return $value;
    }

    /**
     * `vault:{mount}/{key}:v{n}` — the id shape this client issues and parses.
     */
    private function keyId(int $version): string
    {
        return sprintf('%s%s/%s:v%d', self::ID_PREFIX, $this->mount, $this->keyName, $version);
    }

    /**
     * Split a stored id back into the mount and key name that address it.
     *
     * @return array{0: string, 1: string}
     */
    private function parseKeyId(string $keyId): array
    {
        if (! str_starts_with($keyId, self::ID_PREFIX)) {
            throw KeyUnavailableException::kmsUnknownKeyId($keyId);
        }

        $body = substr($keyId, strlen(self::ID_PREFIX));
        $versionAt = strrpos($body, ':v');
        $reference = $versionAt === false ? $body : substr($body, 0, $versionAt);
        $slash = strrpos($reference, '/');

        if ($slash === false || $slash === 0 || $slash === strlen($reference) - 1) {
            throw KeyUnavailableException::kmsUnknownKeyId($keyId);
        }

        return [substr($reference, 0, $slash), substr($reference, $slash + 1)];
    }

    /**
     * The key version out of a transit ciphertext (`vault:v3:…`).
     *
     * Read from the ciphertext rather than from `latest_version`, because those two
     * can differ: a key rotated between the metadata read and the seal would otherwise
     * be recorded under the wrong version, and the re-wrap sweep would skip a blob
     * that needs moving.
     */
    private function versionOf(string $ciphertext): int
    {
        if (preg_match('/^vault:v(\d+):/', $ciphertext, $matches) === 1) {
            return (int) $matches[1];
        }

        // Transit has emitted `vault:v1:` since 0.6; a blob without it is not one of
        // its ciphertexts, so it must not be recorded as if it were.
        throw KeyUnavailableException::kmsMalformedResponse('encrypt');
    }

    /**
     * `/v1/{mount}/{suffix}` — the transit API path.
     */
    private function path(string $suffix, ?string $mount = null): string
    {
        return sprintf('/v1/%s/%s', trim($mount ?? $this->mount, '/'), $suffix);
    }

    /**
     * Keep the token out of dumps, stack traces, and log context.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'address' => $this->address,
            'mount' => $this->mount,
            'keyName' => $this->keyName,
            'namespace' => $this->namespace,
            'token' => '(redacted)',
        ];
    }
}
