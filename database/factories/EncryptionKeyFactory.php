<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\KeyPurpose;
use App\Enums\KeyStatus;
use App\Models\EncryptionKey;
use App\Models\Tenant;
use App\Services\Security\EnvelopeFieldCipher;
use App\Services\Security\KeyWrapper;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Key rows whose sealed DEK is **real**.
 *
 * A factory that stuffed a random string into `wrapped_dek` would produce rows that
 * look right and cannot decrypt anything — every test touching them would then be
 * testing the error path by accident. So this factory generates an actual DEK and
 * seals it through the bound `KeyWrapper`, exactly as `FieldCipher` does, using the
 * same authenticated context (tenant, purpose, version) — which also means a key
 * built here is bound to *its* tenant and cannot be reused for another.
 *
 * @extends Factory<EncryptionKey>
 */
class EncryptionKeyFactory extends Factory
{
    protected $model = EncryptionKey::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'purpose' => KeyPurpose::Field,
            'version' => EncryptionKey::FIRST_VERSION,
            'status' => KeyStatus::Active,
        ];
    }

    /**
     * Seal a fresh DEK once the tenant, purpose and version are known — the context
     * that binds the wrap is made of exactly those three values.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (EncryptionKey $key): void {
            if ($key->getAttribute('wrapped_dek') !== null) {
                return;
            }

            $purpose = $key->purpose instanceof KeyPurpose ? $key->purpose : KeyPurpose::Field;

            $wrapped = app(KeyWrapper::class)->wrap(
                random_bytes(32),
                EnvelopeFieldCipher::wrapContext(
                    (string) $key->getAttribute('tenant_id'),
                    $purpose,
                    $key->version,
                ),
            );

            $key->forceFill([
                'kms_key_id' => $wrapped->keyId,
                'algorithm' => $wrapped->algorithm,
                'wrapped_dek' => $wrapped->blob,
            ]);
        });
    }

    public function ofPurpose(KeyPurpose $purpose): static
    {
        return $this->state(fn (array $attributes): array => ['purpose' => $purpose]);
    }

    public function version(int $version): static
    {
        return $this->state(fn (array $attributes): array => ['version' => $version]);
    }

    /**
     * Rotated out, still readable.
     */
    public function retiring(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => KeyStatus::Retiring,
            'rotated_at' => now(),
        ]);
    }

    /**
     * Withdrawn: must refuse to unwrap.
     */
    public function retired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => KeyStatus::Retired,
            'rotated_at' => now(),
        ]);
    }
}
