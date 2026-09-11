<?php

declare(strict_types=1);

namespace App\Casts;

use App\Casts\Concerns\ResolvesEncryptionTenant;
use App\Enums\KeyPurpose;
use App\Exceptions\Security\CiphertextIntegrityException;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * An envelope-encrypted JSON column: `array` in PHP, one opaque ciphertext at rest.
 *
 * ```php
 * protected function casts(): array
 * {
 *     return ['secret_config' => EncryptedArray::class];
 * }
 * ```
 *
 * The shape task 6.4 needs. `channel_credentials.secret_config` holds a *bag* of
 * per-mode secrets — access token, verify token, provider api key — and encrypting
 * the bag as a whole is better than encrypting each key separately: the field names
 * are hidden too (so the ciphertext does not advertise which provider a tenant
 * uses), one DEK operation covers the lot, and adding a provider field later needs
 * no migration (design § Channel Mode: `secret_config(blob, FieldCipher
 * envelope-encrypted)`).
 *
 * Same rules as `Encrypted`: tenant taken from the row, `null` stays `null`, and
 * nothing that fails to authenticate is ever returned — a value that decrypts but is
 * not a JSON document is a `CiphertextIntegrityException`, not an empty array,
 * because "no credentials" and "unreadable credentials" must not look alike to the
 * code deciding whether a channel mode is usable.
 *
 * @implements CastsAttributes<array<array-key, mixed>, array<array-key, mixed>>
 */
final class EncryptedArray implements CastsAttributes
{
    use ResolvesEncryptionTenant;

    private readonly KeyPurpose $purpose;

    /**
     * @param  string|null  $purpose  a `KeyPurpose` value, e.g. `EncryptedArray::class.':EXPORT'`
     */
    public function __construct(?string $purpose = null)
    {
        $this->purpose = $purpose === null || $purpose === ''
            ? KeyPurpose::Field
            : KeyPurpose::from(strtoupper($purpose));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<array-key, mixed>|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null) {
            return null;
        }

        $tenantId = self::encryptionTenantId($model, $key, $attributes);

        $decoded = json_decode(
            self::fieldCipher()->decrypt($tenantId, (string) $value, $this->purpose),
            true,
        );

        if (! is_array($decoded)) {
            throw CiphertextIntegrityException::malformedJson($tenantId, $key);
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, string|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$key => null];
        }

        $encoded = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return [$key => self::fieldCipher()->encrypt(
            self::encryptionTenantId($model, $key, $attributes),
            $encoded,
            $this->purpose,
        )];
    }
}
