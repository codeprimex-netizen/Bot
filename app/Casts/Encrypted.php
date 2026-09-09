<?php

declare(strict_types=1);

namespace App\Casts;

use App\Casts\Concerns\ResolvesEncryptionTenant;
use App\Enums\KeyPurpose;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Stringable;

/**
 * Declare a column encrypted, and it is (Req 32.5 / NFR3).
 *
 * ```php
 * protected function casts(): array
 * {
 *     return [
 *         'access_token' => Encrypted::class,             // FIELD key (the default)
 *         'archive_key' => Encrypted::class.':EXPORT',    // another key lineage
 *     ];
 * }
 * ```
 *
 * The tenant is taken from the row itself (see `ResolvesEncryptionTenant`), which is
 * why this cast belongs on `BelongsToTenant` models: the key a value is encrypted
 * under is the key of the tenant that owns the row, so no call site has to remember
 * to pass a tenant and none can pass the wrong one. This is the ergonomic path task
 * 6.4's `ChannelCredentialStore` uses for per-tenant, per-mode credentials (design
 * § Channel Mode: "secret-redacted and envelope-encrypted").
 *
 * ## Behaviour worth knowing
 *
 * - **`null` stays `null`.** An absent value is not a secret, and encrypting one
 *   would make "no token configured" indistinguishable from "a token exists".
 * - **A plaintext value in the column is an error, not a fallback.** Reading one
 *   throws `CiphertextIntegrityException` (see `FieldCipher`) rather than passing it
 *   through, so a column that somehow never got encrypted is loud instead of silent.
 * - **Writes always use the tenant's active key version**, which is what makes the
 *   lazy re-encryption strategy work: a rotated-out version disappears from a row as
 *   soon as anything writes to it.
 * - **Reads are not cached by the cast.** `FieldCipher` caches the *DEK* for the unit
 *   of work, so repeated access is one unwrap plus one cheap AES call.
 *
 * @implements CastsAttributes<string, string>
 */
final class Encrypted implements CastsAttributes
{
    use ResolvesEncryptionTenant;

    private readonly KeyPurpose $purpose;

    /**
     * @param  string|null  $purpose  a `KeyPurpose` value, e.g. `Encrypted::class.':EXPORT'`
     */
    public function __construct(?string $purpose = null)
    {
        $this->purpose = $purpose === null || $purpose === ''
            ? KeyPurpose::Field
            : KeyPurpose::from(strtoupper($purpose));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        // Only a NULL column is "absent". Anything else must authenticate — including
        // an empty string, which is not one of our envelopes and therefore signals a
        // column that was written around the cast.
        if ($value === null) {
            return null;
        }

        return self::fieldCipher()->decrypt(
            self::encryptionTenantId($model, $key, $attributes),
            (string) $value,
            $this->purpose,
        );
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

        return [$key => self::fieldCipher()->encrypt(
            self::encryptionTenantId($model, $key, $attributes),
            self::stringify($value),
            $this->purpose,
        )];
    }

    /**
     * Accept the scalar shapes a secret column legitimately receives.
     */
    private static function stringify(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value) || is_bool($value) || $value instanceof Stringable) {
            return (string) $value;
        }

        return (string) json_encode($value);
    }
}
