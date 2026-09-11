<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Enums\KeyPurpose;
use App\Enums\KeyStatus;
use App\Exceptions\Security\CiphertextIntegrityException;
use App\Exceptions\Security\KeyUnavailableException;
use App\Models\EncryptionKey;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;
use Throwable;

/**
 * The `FieldCipher` implementation: AES-256-GCM under a per-tenant DEK that is
 * itself sealed by the master key (Req 32.5 / NFR3).
 *
 * Read `FieldCipher` for the contract and the guarantees. This class is where the
 * three decisions that make those guarantees hold live:
 *
 * ## 1. The tenant is authenticated, not stored
 *
 * Every operation builds its additional authenticated data from
 * `tenant | purpose | version` (see `context()`), for **both** the field encryption
 * and the DEK wrap. Consequences, all of them deliberate:
 *
 * - a ciphertext moved to another tenant's row fails to decrypt — the isolation is
 *   the AEAD tag, not a `where` clause somebody has to remember;
 * - a `wrapped_dek` blob copied between rows fails to unwrap, so an attacker with
 *   write access to `encryption_keys` cannot re-point a tenant at a key they
 *   control;
 * - the version cannot be edited in a stored value to make it decrypt under a
 *   different key.
 *
 * ## 2. DEKs live in memory for one unit of work, and nowhere else
 *
 * Unwrapping is a key-store round trip, so unwrapped DEKs are memoised per
 * `(tenant, purpose, version)` — but only on this instance, which
 * `SecurityServiceProvider` binds with `scoped()`. That means the cache dies with
 * the request or the job, and a long-lived queue worker cannot carry tenant A's DEK
 * into tenant B's job. DEKs are never written to the cache store, the session, or a
 * log; `forgetKeys()` shortens the window further, and `__debugInfo()` keeps them
 * out of dumps and stack traces.
 *
 * (PHP cannot reliably zero a string's memory — `sodium_memzero()` needs an
 * extension this build does not require — so the lifetime *is* the mitigation.)
 *
 * ## 3. Rotation adds a version; re-encryption is lazy
 *
 * `rotate()` inserts the new `ACTIVE` version and demotes the previous one to
 * `RETIRING` in a single transaction, so there is never a moment with two active
 * versions or none. Existing ciphertext is **not** rewritten: it names its version
 * and keeps decrypting. It moves forward when it is next written (the casts encrypt
 * on write, which uses the active version), or when task 4.2's backfill calls
 * `reencrypt()`. Only once no ciphertext references a `RETIRING` version may it
 * become `RETIRED`, at which point it stops unwrapping — so retiring too early
 * fails loudly instead of losing data quietly.
 */
final class EnvelopeFieldCipher implements FieldCipher
{
    /**
     * Bytes of the AES-GCM nonce and tag.
     */
    private const int IV_BYTES = 12;

    private const int TAG_BYTES = 16;

    /**
     * Prefix of the additional authenticated data, so this binding can never be
     * confused with the DEK-wrap binding below or with a future format.
     */
    private const string FIELD_CONTEXT = 'wa:field:v1';

    private const string WRAP_CONTEXT = 'wa:dek-wrap:v1';

    /**
     * Unwrapped DEKs for this unit of work, keyed `tenant|purpose|version`.
     *
     * @var array<string, string>
     */
    private array $dataKeys = [];

    /**
     * Active key row per `tenant|purpose`, memoised so a request that encrypts fifty
     * fields issues one lookup rather than fifty.
     *
     * A rotation in *another* process is therefore not seen until this unit of work
     * ends, which is harmless: the version this one keeps writing under is at worst
     * `RETIRING`, and `RETIRING` versions decrypt. `rotate()` clears the memo in this
     * process.
     *
     * @var array<string, EncryptionKey>
     */
    private array $activeKeys = [];

    /**
     * Key rows by `tenant|purpose|version`, memoised for the decrypt path (a listing
     * of a hundred rows written under the same version reads it once). Holds no
     * plaintext — the DEK inside is sealed.
     *
     * @var array<string, EncryptionKey>
     */
    private array $versionKeys = [];

    /**
     * @param  string  $cipher  AEAD used for field values; must be an authenticated
     *                          mode, which is checked before first use
     * @param  int  $dataKeyBytes  DEK length — 32 for AES-256
     */
    public function __construct(
        private readonly KeyWrapper $wrapper,
        private readonly string $cipher = 'aes-256-gcm',
        private readonly int $dataKeyBytes = 32,
    ) {}

    public function encrypt(Tenant|string $tenant, #[SensitiveParameter] string $plaintext, KeyPurpose $purpose = KeyPurpose::Field): string
    {
        $tenantId = self::tenantId($tenant);
        $key = $this->activeKey($tenantId, $purpose);
        $iv = random_bytes(self::IV_BYTES);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            $this->assertedCipher(),
            $this->dataKey($key),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            self::context(self::FIELD_CONTEXT, $tenantId, $purpose, $key->version),
            self::TAG_BYTES,
        );

        if ($ciphertext === false) {
            // Encryption itself failing means the platform's crypto is unusable. The
            // only safe outcome is that nothing is written.
            throw KeyUnavailableException::unsupportedAlgorithm($this->cipher);
        }

        return (new CipherPayload($purpose, $key->version, $iv, $tag, $ciphertext))->encode();
    }

    public function decrypt(Tenant|string $tenant, string $ciphertext, KeyPurpose $purpose = KeyPurpose::Field): string
    {
        $tenantId = self::tenantId($tenant);
        $payload = CipherPayload::parse($ciphertext);

        if ($payload->purpose !== $purpose) {
            throw CiphertextIntegrityException::purposeMismatch($purpose, $payload->purpose);
        }

        $key = $this->keyAtVersion($tenantId, $purpose, $payload->keyVersion);

        $plaintext = openssl_decrypt(
            $payload->ciphertext,
            $this->assertedCipher(),
            $this->dataKey($key),
            OPENSSL_RAW_DATA,
            $payload->iv,
            $payload->tag,
            self::context(self::FIELD_CONTEXT, $tenantId, $purpose, $key->version),
        );

        if ($plaintext === false) {
            // Tampering, truncation, or a value written for another tenant/purpose/
            // version. Which one it was is deliberately not distinguished.
            throw CiphertextIntegrityException::authenticationFailed($tenantId, $purpose, $payload->keyVersion);
        }

        return $plaintext;
    }

    public function rotate(Tenant|string $tenant, KeyPurpose $purpose = KeyPurpose::Field): EncryptionKey
    {
        $tenantId = self::tenantId($tenant);

        // One transaction, so the lineage is never observed with two active versions
        // (the demote and the insert land together) — and never with none, because the
        // insert is what replaces the demoted row.
        $key = DB::transaction(function () use ($tenantId, $purpose): EncryptionKey {
            $current = EncryptionKey::activeFor($tenantId, $purpose);

            if ($current !== null) {
                // RETIRING, not RETIRED: everything already written under this version
                // must keep decrypting until it has been re-encrypted.
                $current->status = KeyStatus::Retiring;
                $current->rotated_at = now();
                $current->save();
            }

            return $this->mint($tenantId, $purpose, EncryptionKey::latestVersion($tenantId, $purpose) + 1);
        });

        // The next encrypt in this process must pick up the new version rather than the
        // memoised old one, and the demoted row's cached copy must not keep claiming
        // to be ACTIVE.
        $this->forgetLineage($tenantId, $purpose);

        return $key;
    }

    public function provision(Tenant|string $tenant, KeyPurpose $purpose = KeyPurpose::Field): EncryptionKey
    {
        return $this->activeKey(self::tenantId($tenant), $purpose);
    }

    public function isStale(Tenant|string $tenant, string $ciphertext, KeyPurpose $purpose = KeyPurpose::Field): bool
    {
        $payload = CipherPayload::parse($ciphertext);

        if ($payload->purpose !== $purpose) {
            throw CiphertextIntegrityException::purposeMismatch($purpose, $payload->purpose);
        }

        return $payload->keyVersion !== $this->activeKey(self::tenantId($tenant), $purpose)->version;
    }

    public function reencrypt(Tenant|string $tenant, string $ciphertext, KeyPurpose $purpose = KeyPurpose::Field): string
    {
        return $this->encrypt($tenant, $this->decrypt($tenant, $ciphertext, $purpose), $purpose);
    }

    public function isEnvelope(string $value): bool
    {
        return CipherPayload::looksLikeEnvelope($value);
    }

    public function forgetKeys(): void
    {
        $this->dataKeys = [];
        $this->activeKeys = [];
        $this->versionKeys = [];
    }

    /**
     * The tenant's active key, provisioning the lineage on first use.
     *
     * First use is the only moment a key row is created implicitly, and it is safe:
     * generating a *new* key for a tenant that has none cannot make anything else
     * decrypt incorrectly. Refusing instead would make encryption depend on whether
     * provisioning happened to have run — and a hard failure on a write path is
     * exactly the pressure that grows plaintext fallbacks elsewhere.
     */
    private function activeKey(string $tenantId, KeyPurpose $purpose): EncryptionKey
    {
        $lineage = self::lineageKey($tenantId, $purpose);

        if (isset($this->activeKeys[$lineage])) {
            return $this->activeKeys[$lineage];
        }

        $key = EncryptionKey::activeFor($tenantId, $purpose) ?? $this->provisionFirstVersion($tenantId, $purpose);

        return $this->remember($key);
    }

    /**
     * Create version 1 of a lineage, tolerating a concurrent creator.
     *
     * `uniq(tenant_id, purpose, active_flag)` means only one of two racing requests
     * can insert an active row; the loser re-reads instead of retrying, so both end
     * up using the same key and neither writes ciphertext under a key that lost.
     */
    private function provisionFirstVersion(string $tenantId, KeyPurpose $purpose): EncryptionKey
    {
        try {
            return $this->mint($tenantId, $purpose, EncryptionKey::FIRST_VERSION);
        } catch (QueryException) {
            $key = EncryptionKey::activeFor($tenantId, $purpose);

            if ($key === null) {
                throw KeyUnavailableException::notProvisioned($tenantId, $purpose);
            }

            return $key;
        }
    }

    /**
     * Generate a DEK, seal it, and persist the sealed form as the new active version.
     *
     * The plaintext DEK exists only inside this method and in the in-process cache;
     * what reaches the database is `WrappedKey::$blob`. If sealing fails, nothing is
     * written at all.
     */
    private function mint(string $tenantId, KeyPurpose $purpose, int $version): EncryptionKey
    {
        $dataKey = random_bytes($this->dataKeyBytes);
        $wrapped = $this->wrapper->wrap($dataKey, self::context(self::WRAP_CONTEXT, $tenantId, $purpose, $version));

        // `tenant_id` is named explicitly: provisioning runs before any tenant context
        // exists. Naming a *different* tenant than the acting one is still refused by
        // TenantOwnershipGuard (CrossTenantAccessException), so this cannot be used to
        // mint keys for somebody else.
        $key = new EncryptionKey;
        $key->forceFill([
            'tenant_id' => $tenantId,
            'purpose' => $purpose,
            'version' => $version,
            'status' => KeyStatus::Active,
            'kms_key_id' => $wrapped->keyId,
            'algorithm' => $wrapped->algorithm,
            'wrapped_dek' => $wrapped->blob,
        ]);
        $key->save();

        $this->dataKeys[self::dataKeyCacheKey($tenantId, $purpose, $version)] = $dataKey;

        return $key;
    }

    /**
     * The key row a stored ciphertext names, or a fail-closed error.
     */
    private function keyAtVersion(string $tenantId, KeyPurpose $purpose, int $version): EncryptionKey
    {
        $key = $this->versionKeys[self::dataKeyCacheKey($tenantId, $purpose, $version)]
            ?? EncryptionKey::atVersion($tenantId, $purpose, $version);

        if ($key === null) {
            throw KeyUnavailableException::missingVersion($tenantId, $purpose, $version);
        }

        if (! $key->canDecrypt()) {
            throw KeyUnavailableException::unusableVersion($tenantId, $purpose, $version, $key->status);
        }

        return $this->remember($key);
    }

    /**
     * Memoise a key row for this unit of work.
     */
    private function remember(EncryptionKey $key): EncryptionKey
    {
        $this->versionKeys[self::dataKeyCacheKey($key->tenant_id, $key->purpose, $key->version)] = $key;

        if ($key->canEncrypt()) {
            $this->activeKeys[self::lineageKey($key->tenant_id, $key->purpose)] = $key;
        }

        return $key;
    }

    /**
     * Drop every memoised row of one lineage — called after a rotation, so nothing
     * keeps using a version this process has just demoted.
     */
    private function forgetLineage(string $tenantId, KeyPurpose $purpose): void
    {
        $lineage = self::lineageKey($tenantId, $purpose);

        unset($this->activeKeys[$lineage]);

        foreach (array_keys($this->versionKeys) as $cacheKey) {
            if (str_starts_with($cacheKey, $lineage.'|')) {
                unset($this->versionKeys[$cacheKey]);
            }
        }
    }

    /**
     * The unwrapped DEK for one key row.
     */
    private function dataKey(EncryptionKey $key): string
    {
        $cacheKey = self::dataKeyCacheKey($key->tenant_id, $key->purpose, $key->version);

        if (isset($this->dataKeys[$cacheKey])) {
            return $this->dataKeys[$cacheKey];
        }

        $wrapped = $key->toWrappedKey();

        try {
            $dataKey = $this->wrapper->unwrap(
                $wrapped->keyId,
                $wrapped->blob,
                self::context(self::WRAP_CONTEXT, $key->tenant_id, $key->purpose, $key->version),
            );
        } catch (KeyUnavailableException $e) {
            throw $e;
        } catch (Throwable) {
            // A wrapper that fails in an unexpected way (a KMS SDK throwing its own
            // exception type, say) must still fail *closed*, and must not let a
            // provider's message — which may quote request payloads — escape.
            throw KeyUnavailableException::unwrapFailed($wrapped->keyId, $wrapped->algorithm);
        }

        if (strlen($dataKey) !== $this->dataKeyBytes) {
            throw KeyUnavailableException::unwrapFailed($wrapped->keyId, $wrapped->algorithm);
        }

        return $this->dataKeys[$cacheKey] = $dataKey;
    }

    /**
     * Refuse to run under a cipher that is not an available authenticated mode: with
     * a non-AEAD cipher `openssl_encrypt()` ignores the tag and the AAD, which would
     * silently drop guarantees 1 and 2.
     */
    private function assertedCipher(): string
    {
        if (! str_ends_with($this->cipher, '-gcm') || ! in_array($this->cipher, openssl_get_cipher_methods(), true)) {
            throw KeyUnavailableException::unsupportedAlgorithm($this->cipher);
        }

        return $this->cipher;
    }

    /**
     * The authenticated context that binds a **wrapped DEK** to exactly one tenant,
     * purpose and version — so a `wrapped_dek` blob cannot be moved between rows.
     *
     * Public because anything that seals a DEK for this cipher to open (the model
     * factory; a future import or restore tool) must use the identical string, and
     * duplicating the format is how those two drift apart.
     */
    public static function wrapContext(string $tenantId, KeyPurpose $purpose, int $version): string
    {
        return self::context(self::WRAP_CONTEXT, $tenantId, $purpose, $version);
    }

    /**
     * The additional authenticated data that binds a value to exactly one tenant,
     * purpose and key version.
     */
    private static function context(string $domain, string $tenantId, KeyPurpose $purpose, int $version): string
    {
        return implode('|', [$domain, $tenantId, $purpose->value, (string) $version]);
    }

    private static function lineageKey(string $tenantId, KeyPurpose $purpose): string
    {
        return $tenantId.'|'.$purpose->value;
    }

    private static function dataKeyCacheKey(string $tenantId, KeyPurpose $purpose, int $version): string
    {
        return self::lineageKey($tenantId, $purpose).'|'.$version;
    }

    /**
     * Accept a model or a bare id, so callers holding either do not have to convert.
     */
    private static function tenantId(Tenant|string $tenant): string
    {
        return $tenant instanceof Tenant ? $tenant->id : $tenant;
    }

    /**
     * Keep unwrapped DEKs out of dumps, stack traces, and log context.
     *
     * `dd($cipher)` or a framework error page rendering local variables would
     * otherwise print every DEK this request has unwrapped.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'cipher' => $this->cipher,
            'wrapper' => $this->wrapper::class,
            'unwrappedDataKeys' => count($this->dataKeys).' (redacted)',
            'lineages' => array_keys($this->activeKeys),
        ];
    }
}
