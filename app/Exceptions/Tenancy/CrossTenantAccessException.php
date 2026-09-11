<?php

declare(strict_types=1);

namespace App\Exceptions\Tenancy;

use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * A caller acting as one tenant tried to read, write, or reach a row that belongs
 * to **another** tenant — denied with **403** (Req 1.3 / A1, Correctness
 * Property 1).
 *
 * This should be impossible: `TenantScope` already constrains every query to the
 * acting tenant. It exists because "should be impossible" is not a guarantee —
 * Eloquent builds instance writes without global scopes, relations may drop the
 * scope deliberately, and an explicit `tenant_id` on create is a legitimate
 * provisioning feature that a tenant-context write must not be able to abuse. Each
 * of those seams is checked a second time by `TenantOwnershipGuard`, and every one
 * of those checks fails with this exception rather than with a bare 404 or a 500.
 *
 * ## Why 403 and not 404
 *
 * Req 1.3 is explicit: deny with `CrossTenantAccessException` (403). A 404 would be
 * marginally more discreet, but it is also indistinguishable from a bug, and a
 * silent cross-tenant *attempt* is exactly the event an operator needs to see. The
 * discretion is restored by the response body: it is a fixed sentence
 * (`PUBLIC_MESSAGE`), never the internal message.
 *
 * ## What ends up in a message
 *
 * Nothing that identifies another tenant's data. Record keys and tenant ids are
 * **fingerprinted** (a short, stable hash prefix) so two log lines about the same
 * row can be correlated without the log — or an HTTP response — ever carrying the
 * id itself. Free-form input that does reach a message (a route field name) is
 * escaped and truncated exactly as `UnsafeStoragePathException` does it.
 */
final class CrossTenantAccessException extends RuntimeException implements HttpExceptionInterface
{
    /**
     * HTTP status for every flavour of this denial (Req 1.3 / A1).
     */
    public const int STATUS = 403;

    /**
     * The only sentence a client is ever shown: no ids, no model names, no hint
     * about whether the record exists.
     */
    public const string PUBLIC_MESSAGE = 'This record does not belong to the active tenant.';

    /**
     * Stable machine-readable code for API clients and the panel.
     */
    public const string ERROR_CODE = 'cross_tenant_access';

    /**
     * A row hydrated for one tenant surfaced while another tenant was acting.
     *
     * @param  class-string  $model
     */
    public static function forRetrieval(string $model, ?string $ownerTenantId, string $actingTenantId): self
    {
        return new self(sprintf(
            'Refusing to hand a [%s] owned by tenant %s to code acting as tenant %s.',
            self::redact($model),
            self::fingerprint($ownerTenantId),
            self::fingerprint($actingTenantId),
        ));
    }

    /**
     * A write (`save`, `update`, `delete`) against an instance owned by another
     * tenant — the path Eloquent builds through `newModelQuery()`, without scopes.
     *
     * @param  class-string  $model
     */
    public static function forWrite(string $model, string $operation, ?string $ownerTenantId, string $actingTenantId): self
    {
        return new self(sprintf(
            'Refusing to %s a [%s] owned by tenant %s while acting as tenant %s.',
            self::redact($operation),
            self::redact($model),
            self::fingerprint($ownerTenantId),
            self::fingerprint($actingTenantId),
        ));
    }

    /**
     * A create that named a `tenant_id` other than the acting tenant's.
     *
     * @param  class-string  $model
     */
    public static function forAttribution(string $model, string $requestedTenantId, string $actingTenantId): self
    {
        return new self(sprintf(
            'Refusing to create a [%s] attributed to tenant %s while acting as tenant %s. '
            .'Provisioning, imports, and platform writes must run in TenantContext::asPlatform() '
            .'or TenantContext::runFor(), not from inside another tenant.',
            self::redact($model),
            self::fingerprint($requestedTenantId),
            self::fingerprint($actingTenantId),
        ));
    }

    /**
     * A relation load reached through a parent the acting tenant does not own —
     * including relations that drop `TenantScope` on purpose because they already
     * name their tenant.
     *
     * @param  class-string  $parent
     */
    public static function forRelation(string $parent, string $relation, ?string $ownerTenantId, string $actingTenantId): self
    {
        return new self(sprintf(
            'Refusing to load [%s::%s()] on a parent owned by tenant %s while acting as tenant %s.',
            self::redact($parent),
            self::redact($relation),
            self::fingerprint($ownerTenantId),
            self::fingerprint($actingTenantId),
        ));
    }

    /**
     * A find-by-id or route-model binding that named a row belonging to another
     * tenant. The row was found to exist, so this is a denial and not a miss.
     *
     * @param  class-string  $model
     */
    public static function forKey(string $model, string $field, string $value, string $actingTenantId): self
    {
        return new self(sprintf(
            'Refusing to resolve [%s] by %s = %s while acting as tenant %s: that record belongs to another tenant.',
            self::redact($model),
            self::redact($field),
            self::fingerprint($value),
            self::fingerprint($actingTenantId),
        ));
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

    /**
     * The sentence that may be shown to a client.
     */
    public function publicMessage(): string
    {
        return self::PUBLIC_MESSAGE;
    }

    /**
     * A short, stable, non-reversible stand-in for an identifier, so logs can be
     * correlated without ever carrying another tenant's ids.
     */
    private static function fingerprint(?string $id): string
    {
        if ($id === null || $id === '') {
            return '<none>';
        }

        return '#'.substr(hash('sha256', $id), 0, 8);
    }

    /**
     * Keep untrusted input out of logs verbatim: control characters are escaped and
     * the value is truncated before it reaches a message or audit trail.
     */
    private static function redact(string $value): string
    {
        $escaped = addcslashes($value, "\0..\37\177");

        return mb_strimwidth($escaped, 0, 120, '…');
    }
}
