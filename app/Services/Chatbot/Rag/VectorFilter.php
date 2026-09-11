<?php

declare(strict_types=1);

namespace App\Services\Chatbot\Rag;

use App\Exceptions\Tenancy\CrossTenantAccessException;
use App\Exceptions\Tenancy\MissingTenantContextException;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;

/**
 * The tenant constraint every vector-store operation must carry (Req 32.1 / NFR3;
 * design.md § STRIDE row "Vector store" — *"payload filter `tenant_id` on every query
 * (Property 20); per-tenant namespaces"*).
 *
 * ## Why this type exists instead of a convention
 *
 * Inside MySQL, cross-tenant isolation is structural: `TenantScope` is a global scope, so
 * a query that forgets the tenant does not return everybody's rows — it refuses to run.
 * An external vector store has no equivalent. It is an HTTP/gRPC call with a filter
 * argument, and the filter is *just an argument*: omit it and the ANN index cheerfully
 * returns the nearest neighbours across every tenant in the collection, with no error and
 * no log line. The same embedding that answers "what is your refund policy?" for one
 * tenant is a very close neighbour of another tenant's refund policy.
 *
 * "Always pass `tenant_id`" is therefore the one rule in this design that cannot be left
 * to review, because the failure is silent, the blast radius is every tenant, and the
 * evidence is a *plausible* answer. So the tenant is not an argument that a caller
 * remembers to pass — it is the type the store's methods accept. A query with no tenant
 * filter is not *discouraged* by this class; with `VectorStore` taking a `VectorFilter`,
 * it is unrepresentable.
 *
 * ## Three properties, and how each is obtained
 *
 * 1. **You cannot build one without a tenant.** The constructor is private. `forTenant()`
 *    needs a tenant, and `forCurrentTenant()` fails closed with
 *    `MissingTenantContextException` when none is bound — the same answer `TenantScope`
 *    gives, for the same reason (an unresolved context is a bug, and a bug must not
 *    become a cross-tenant read).
 * 2. **The tenant cannot be edited away.** The object is immutable, and `with()` — which
 *    exists so a caller can add `kb_id`, `locale`, `owner_type`, ... — refuses a
 *    `tenant_id` naming a different tenant with `CrossTenantAccessException` (403), the
 *    same typed refusal the database boundary uses. An identical value is accepted as a
 *    no-op so a caller may be explicit.
 * 3. **The filter always contains the tenant.** `toPayloadFilter()` merges the extras
 *    *under* the tenant key, never over it, so no combination of caller input can produce
 *    a filter without `tenant_id`.
 *
 * `namespace()` provides the second half of the design's mitigation — per-tenant
 * collections/namespaces — from one place, so a driver cannot invent its own naming and
 * two drivers cannot disagree about which collection a tenant's vectors live in. Belt
 * *and* braces: the namespace keeps tenants in separate indexes where the driver supports
 * it, and the payload filter holds even when several tenants share one collection.
 *
 * ## Scope of this class
 *
 * Task 13.2 owns the drivers (`QdrantVectorStore`, `PgvectorVectorStore`,
 * `MysqlVectorStore`) and 13.5 the retriever. This is deliberately only the constraint
 * they must express — no client, no transport, no stub implementation.
 */
final readonly class VectorFilter
{
    /**
     * @param  array<string, scalar|null>  $extra  additional equality constraints, never
     *                                             including `tenant_id`
     */
    private function __construct(
        private string $tenantId,
        private array $extra,
    ) {}

    /**
     * A filter for a named tenant — provisioning, offboarding, a platform-side re-index.
     */
    public static function forTenant(Tenant|string $tenant): self
    {
        $tenantId = $tenant instanceof Tenant ? (string) $tenant->getKey() : trim($tenant);

        if ($tenantId === '') {
            throw MissingTenantContextException::forQuery(self::class);
        }

        return new self($tenantId, []);
    }

    /**
     * A filter for the tenant this unit of work is acting as.
     *
     * The normal way to build one, and the reason it resolves the context itself rather
     * than taking a tenant argument: a call site that has to *name* the tenant is a call
     * site that can name the wrong one.
     *
     * Platform mode is **not** honoured. `TenantScope` drops its constraint under the
     * audited platform bypass because a platform-wide SQL read is a legitimate,
     * auditable operation; a platform-wide *ANN* read is not — it would return one
     * tenant's chunks as context for another tenant's answer, which is the exact threat
     * this row of the STRIDE table names. Platform-side vector work names its tenant
     * (`forTenant()`), one tenant at a time.
     *
     * @throws MissingTenantContextException when no tenant is bound
     */
    public static function forCurrentTenant(): self
    {
        $tenantId = app(TenantContext::class)->currentId();

        if ($tenantId === null || $tenantId === '') {
            throw MissingTenantContextException::forQuery(self::class);
        }

        return new self($tenantId, []);
    }

    /**
     * The same filter with additional equality constraints.
     *
     * @param  array<string, scalar|null>  $filters
     *
     * @throws CrossTenantAccessException when `tenant_id` is present and names another tenant
     */
    public function with(array $filters): self
    {
        $extra = $this->extra;

        foreach ($filters as $key => $value) {
            if ($key === TenantScope::COLUMN) {
                $this->assertSameTenant($value);

                continue;
            }

            $extra[$key] = $value;
        }

        return new self($this->tenantId, $extra);
    }

    /**
     * The payload filter to send with the query.
     *
     * `tenant_id` is written last so it cannot be shadowed by an extra of the same name,
     * however this object was assembled.
     *
     * @return non-empty-array<string, scalar|null>
     */
    public function toPayloadFilter(): array
    {
        return [...$this->extra, TenantScope::COLUMN => $this->tenantId];
    }

    /**
     * The collection/namespace this tenant's vectors live in.
     *
     * Derived, never stored: two drivers asking for the same tenant's namespace get the
     * same string, and no driver has to invent a naming scheme.
     */
    public function namespace(): string
    {
        $prefix = config('wa.security.vector.namespace_prefix');
        $prefix = is_string($prefix) && $prefix !== '' ? $prefix : 'wacb';

        return $prefix.'_'.$this->tenantId;
    }

    public function tenantId(): string
    {
        return $this->tenantId;
    }

    /**
     * The extra constraints on their own — for a driver whose API separates the
     * mandatory tenant term from the optional ones.
     *
     * @return array<string, scalar|null>
     */
    public function extraFilters(): array
    {
        return $this->extra;
    }

    /**
     * A `tenant_id` supplied by a caller is only allowed to restate this filter's tenant.
     */
    private function assertSameTenant(mixed $value): void
    {
        $requested = is_string($value) ? $value : (is_int($value) ? (string) $value : '');

        if ($requested === $this->tenantId) {
            return;
        }

        throw CrossTenantAccessException::forAttribution(
            self::class,
            $requested === '' ? '<not-an-id>' : $requested,
            $this->tenantId,
        );
    }
}
