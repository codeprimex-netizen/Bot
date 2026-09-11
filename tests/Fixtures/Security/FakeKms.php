<?php

declare(strict_types=1);

namespace Tests\Fixtures\Security;

use App\Exceptions\Security\KeyUnavailableException;
use App\Services\Security\DekRotator;
use App\Services\Security\FieldCipher;
use App\Services\Security\KeyWrapper;
use App\Services\Security\KmsClient;
use App\Services\Security\KmsKeyWrapper;
use App\Services\Security\KmsSeal;
use App\Services\Security\MasterKeyRewrapper;
use App\Services\Security\SigningSecretStore;

/**
 * An in-memory `KmsClient` for tests (Property 28 / Req 36).
 *
 * ## Why it lives here and not in `app/`
 *
 * It is **test-only, structurally**. `Tests\` is mapped by `autoload-dev`, so this class is
 * not autoloadable in a production install at all — there is nothing to accidentally bind,
 * nothing for `wa.security.kms.driver` to name by mistake, and nothing for the completeness
 * scan of task 39.4 to find in `app/`. Tests bind it explicitly:
 *
 * ```php
 * $kms = FakeKms::bind();          // binds KmsClient + KeyWrapper in the testing container
 * ```
 *
 * ## Why it is a real cipher and not a stub
 *
 * It seals with AES-256-GCM under a key derived per master-key **version**, and it
 * authenticates the caller's `$context`. That matters: a fake that ignored the context (or
 * returned the plaintext) would make every test that relies on tenant binding — a wrapped
 * DEK not opening in another tenant's row — pass vacuously. Here those tests fail for the
 * same reason they would fail against Vault.
 *
 * ## What it can be told to do
 *
 * | Call | Simulates |
 * |---|---|
 * | `rotate()` | the key store minting a new version, old versions still openable |
 * | `revoke(int $version)` | an operator deleting a retired master key version — material sealed under it can no longer be opened |
 * | `failWith(int $times)` | a transient outage: the next N calls fail, then it recovers |
 * | `fail()` / `recover()` | a sustained outage |
 * | `calls(string $operation)` | how often each operation was reached (proves a breaker really did fence it off) |
 */
final class FakeKms implements KmsClient
{
    public const string ALGORITHM = 'fake-kms';

    private const string CIPHER = 'aes-256-gcm';

    private const int IV_BYTES = 12;

    private const int TAG_BYTES = 16;

    /**
     * Master key versions that still exist, newest last.
     *
     * @var list<int>
     */
    private array $versions = [1];

    private bool $failing = false;

    private int $failFor = 0;

    /**
     * @var array<string, int>
     */
    private array $calls = [
        'activeKeyId' => 0,
        'encrypt' => 0,
        'decrypt' => 0,
        'rotate' => 0,
    ];

    /**
     * Bind this fake as the platform's KMS **and** point the key wrapper at it, the way a
     * KMS-backed deployment is configured — so the code under test is the production path.
     */
    public static function bind(): self
    {
        $kms = new self;

        config([
            'wa.security.encryption.wrapper' => KmsKeyWrapper::class,
            'wa.security.kms.driver' => self::class,
            'wa.security.kms.guard.enabled' => false,
        ]);

        app()->instance(self::class, $kms);
        app()->instance(KmsClient::class, $kms);
        // Anything already resolved is holding the config wrapper; drop it so the next
        // resolution builds the KMS-backed path this fake is standing in for.
        foreach ([KeyWrapper::class, FieldCipher::class, SigningSecretStore::class, MasterKeyRewrapper::class, DekRotator::class] as $abstract) {
            app()->forgetInstance($abstract);
        }

        return $kms;
    }

    public function activeKeyId(): string
    {
        $this->calls['activeKeyId']++;
        $this->assertAvailable('kms.keys.read');

        return $this->keyId($this->latestVersion());
    }

    public function encrypt(string $plaintext, string $context): KmsSeal
    {
        $this->calls['encrypt']++;
        $this->assertAvailable('kms.encrypt');

        $version = $this->latestVersion();
        $iv = random_bytes(self::IV_BYTES);
        $tag = '';

        $sealed = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->wrappingKey($version),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $context,
            self::TAG_BYTES,
        );

        if ($sealed === false) {
            throw KeyUnavailableException::kmsRejected('kms.encrypt', 500);
        }

        return new KmsSeal(
            $this->keyId($version),
            self::ALGORITHM,
            sprintf('fake:v%d:%s', $version, base64_encode($iv.$tag.$sealed)),
        );
    }

    public function decrypt(string $keyId, string $ciphertext, string $context): string
    {
        $this->calls['decrypt']++;
        $this->assertAvailable('kms.decrypt');

        $version = $this->versionOf($keyId);

        if (! in_array($version, $this->versions, true)) {
            // An operator removed this master key version: material sealed under it is
            // unopenable, which is exactly what the re-wrap sweep must survive.
            throw KeyUnavailableException::kmsUnknownKeyId($keyId);
        }

        $blob = base64_decode((string) preg_replace('/^fake:v\d+:/', '', $ciphertext), true);

        if ($blob === false || strlen($blob) <= self::IV_BYTES + self::TAG_BYTES) {
            throw KeyUnavailableException::kmsMalformedResponse('kms.decrypt');
        }

        $plaintext = openssl_decrypt(
            substr($blob, self::IV_BYTES + self::TAG_BYTES),
            self::CIPHER,
            $this->wrappingKey($version),
            OPENSSL_RAW_DATA,
            substr($blob, 0, self::IV_BYTES),
            substr($blob, self::IV_BYTES, self::TAG_BYTES),
            $context,
        );

        if ($plaintext === false) {
            // Wrong context (another tenant, purpose, version, or scope) or tampering.
            throw KeyUnavailableException::kmsRejected('kms.decrypt', 400);
        }

        return $plaintext;
    }

    public function rotate(): string
    {
        $this->calls['rotate']++;
        $this->assertAvailable('kms.rotate');

        $this->versions[] = $this->latestVersion() + 1;

        return $this->keyId($this->latestVersion());
    }

    /*
    |--------------------------------------------------------------------------
    | Test controls
    |--------------------------------------------------------------------------
    */

    /**
     * Remove a master key version, as an operator retiring it from the key store would.
     */
    public function revoke(int $version): self
    {
        $this->versions = array_values(array_filter(
            $this->versions,
            static fn (int $candidate): bool => $candidate !== $version,
        ));

        return $this;
    }

    /**
     * A sustained outage.
     */
    public function fail(): self
    {
        $this->failing = true;

        return $this;
    }

    public function recover(): self
    {
        $this->failing = false;
        $this->failFor = 0;

        return $this;
    }

    /**
     * A transient outage: the next `$times` calls fail, then it works again.
     */
    public function failWith(int $times): self
    {
        $this->failFor = max(0, $times);

        return $this;
    }

    /**
     * How often one operation was actually reached — the way a test proves a circuit
     * breaker fenced the store off rather than merely swallowing its errors.
     */
    public function calls(string $operation): int
    {
        return $this->calls[$operation] ?? 0;
    }

    /**
     * @return list<int>
     */
    public function versions(): array
    {
        return $this->versions;
    }

    public function keyId(int $version): string
    {
        return 'fake:master:v'.$version;
    }

    private function latestVersion(): int
    {
        return $this->versions === [] ? 0 : max($this->versions);
    }

    private function versionOf(string $keyId): int
    {
        return preg_match('/:v(\d+)$/', $keyId, $matches) === 1 ? (int) $matches[1] : 0;
    }

    private function assertAvailable(string $operation): void
    {
        if ($this->failFor > 0) {
            $this->failFor--;

            throw KeyUnavailableException::kmsUnreachable($operation);
        }

        if ($this->failing) {
            throw KeyUnavailableException::kmsUnreachable($operation);
        }

        if ($this->versions === []) {
            throw KeyUnavailableException::kmsNotConfigured('the fake key store has no versions');
        }
    }

    /**
     * A distinct 32-byte key per master key version.
     */
    private function wrappingKey(int $version): string
    {
        return hash_hkdf('sha256', 'fake-kms-master-'.$version, 32, 'fake-kms');
    }
}
