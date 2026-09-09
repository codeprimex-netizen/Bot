<?php

declare(strict_types=1);

namespace App\Casts\Concerns;

use App\Exceptions\Security\KeyUnavailableException;
use App\Models\Scopes\TenantScope;
use App\Services\Security\FieldCipher;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Answers "whose key protects this attribute?" for the encrypting casts.
 *
 * It looks harder than it should because of one ordering fact: on an insert,
 * Eloquent runs a cast's `set()` when the attribute is assigned, which is *before*
 * `BelongsToTenant`'s `creating` hook stamps `tenant_id`. So the obvious
 * implementation — read the row's `tenant_id` — fails on the most ordinary call
 * there is, `Model::create([...])`.
 *
 * Hence two sources, in this order:
 *
 * 1. **The row's own `tenant_id`**, from the attribute array or the model. Ownership
 *    of the row decides, so an explicitly attributed row (provisioning, an import,
 *    a platform write) encrypts under the tenant it names.
 * 2. **The acting tenant** from `TenantContext`. Safe precisely because it is the
 *    same value `BelongsToTenant` is about to stamp: the row cannot end up owned by
 *    a tenant other than the one whose key encrypted it. And if the caller *did*
 *    name a different tenant, `TenantOwnershipGuard` rejects the create outright
 *    (`CrossTenantAccessException`) before it is stored.
 *
 * If neither yields a tenant the operation is **refused**. There is no global key to
 * fall back to and no honest guess to make; encrypting under the wrong tenant's key
 * would be worse than failing, because it would look like it worked.
 */
trait ResolvesEncryptionTenant
{
    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws KeyUnavailableException when no tenant can be resolved
     */
    private static function encryptionTenantId(Model $model, string $attribute, array $attributes): string
    {
        $candidates = [
            $attributes[TenantScope::COLUMN] ?? null,
            $model->getAttribute(TenantScope::COLUMN),
            app(TenantContext::class)->currentId(),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        throw KeyUnavailableException::withoutTenant($model::class, $attribute);
    }

    private static function fieldCipher(): FieldCipher
    {
        return app(FieldCipher::class);
    }
}
