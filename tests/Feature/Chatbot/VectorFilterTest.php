<?php

declare(strict_types=1);

use App\Exceptions\Tenancy\CrossTenantAccessException;
use App\Exceptions\Tenancy\MissingTenantContextException;
use App\Models\Tenant;
use App\Services\Chatbot\Rag\VectorFilter;
use App\Services\Chatbot\Rag\VectorStore;
use App\Services\Tenancy\TenantContext;

/*
|--------------------------------------------------------------------------
| Vector store: the tenant filter is a type, not a convention (Req 32.1 / NFR3)
|--------------------------------------------------------------------------
| design.md's STRIDE row for this boundary is *"payload filter `tenant_id` on every
| query (Property 20); per-tenant namespaces"*. Inside MySQL that guarantee is
| structural — `TenantScope` refuses to run a query it cannot constrain. An external
| ANN index has no equivalent: omit the filter and it returns other tenants' nearest
| neighbours, silently, with a plausible answer.
|
| Task 13.2 owns the drivers, so there is nothing to wire yet. What *is* enforceable
| today is the shape of the contract they must implement, and that is what these
| tests hold: a query with no tenant filter must be unrepresentable, not merely
| discouraged. The last two tests are the anti-rot ones — they fail if a later task
| loosens the signature back to a bare tenant id or a free filter array.
*/

it('cannot be built with no tenant bound', function (): void {
    app(TenantContext::class)->forget();

    // Fails closed exactly as `TenantScope` does, and for the same reason: an
    // unresolved context is a bug, and a bug must not become a cross-tenant read.
    expect(fn (): VectorFilter => VectorFilter::forCurrentTenant())
        ->toThrow(MissingTenantContextException::class);
});

it('cannot be built from an empty tenant id', function (): void {
    expect(fn (): VectorFilter => VectorFilter::forTenant(''))
        ->toThrow(MissingTenantContextException::class)
        ->and(fn (): VectorFilter => VectorFilter::forTenant('   '))
        ->toThrow(MissingTenantContextException::class);
});

it('always carries the tenant in the payload filter', function (): void {
    $tenant = Tenant::factory()->create();
    app(TenantContext::class)->set($tenant);

    expect(VectorFilter::forCurrentTenant()->toPayloadFilter())->toBe(['tenant_id' => $tenant->id])
        ->and(VectorFilter::forTenant($tenant)->tenantId())->toBe($tenant->id)
        ->and(VectorFilter::forTenant($tenant->id)->toPayloadFilter())->toBe(['tenant_id' => $tenant->id]);
});

it('keeps the tenant term when extra constraints are added', function (): void {
    $tenant = Tenant::factory()->create();

    $filter = VectorFilter::forTenant($tenant)->with(['kb_id' => 7, 'locale' => 'en'])->with(['owner_type' => 'article']);

    expect($filter->toPayloadFilter())->toBe([
        'kb_id' => 7,
        'locale' => 'en',
        'owner_type' => 'article',
        // Written last: no combination of caller input can shadow it.
        'tenant_id' => $tenant->id,
    ])->and($filter->extraFilters())->toBe(['kb_id' => 7, 'locale' => 'en', 'owner_type' => 'article']);
});

it('refuses an extra constraint that names another tenant', function (): void {
    $tenant = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    $filter = VectorFilter::forTenant($tenant);

    expect(fn (): VectorFilter => $filter->with(['tenant_id' => $other->id]))
        ->toThrow(CrossTenantAccessException::class)
        // A non-id value is not a way round it either.
        ->and(fn (): VectorFilter => $filter->with(['tenant_id' => null]))
        ->toThrow(CrossTenantAccessException::class)
        // Restating the same tenant is a no-op, so a caller may be explicit.
        ->and($filter->with(['tenant_id' => $tenant->id])->toPayloadFilter())
        ->toBe(['tenant_id' => $tenant->id]);
});

it('is immutable, so a filter handed to a driver cannot be edited underneath it', function (): void {
    $tenant = Tenant::factory()->create();
    $filter = VectorFilter::forTenant($tenant);
    $widened = $filter->with(['kb_id' => 1]);

    expect($filter->toPayloadFilter())->toBe(['tenant_id' => $tenant->id])
        ->and($widened)->not->toBe($filter);
});

it('ignores platform mode instead of dropping the tenant term', function (): void {
    $tenant = Tenant::factory()->create();
    app(TenantContext::class)->forget();

    app(TenantContext::class)->asPlatform('vector filter test', function () use ($tenant): void {
        // `TenantScope` drops its constraint here, because a platform-wide *SQL* read is
        // a legitimate audited operation. A platform-wide *ANN* read is not: it would
        // return one tenant's chunks as context for another tenant's answer, which is
        // the exact threat this row names. Platform vector work names its tenant.
        expect(fn (): VectorFilter => VectorFilter::forCurrentTenant())
            ->toThrow(MissingTenantContextException::class)
            ->and(VectorFilter::forTenant($tenant)->toPayloadFilter())->toBe(['tenant_id' => $tenant->id]);
    });
});

it('derives one namespace per tenant, from one place', function (): void {
    $first = Tenant::factory()->create();
    $second = Tenant::factory()->create();

    expect(VectorFilter::forTenant($first)->namespace())->toBe('wacb_'.$first->id)
        ->and(VectorFilter::forTenant($second)->namespace())->not->toBe(VectorFilter::forTenant($first)->namespace());

    config()->set('wa.security.vector.namespace_prefix', 'acme');
    expect(VectorFilter::forTenant($first)->namespace())->toBe('acme_'.$first->id);

    // An empty or non-string prefix falls back rather than producing `_{id}`, which
    // would collide across deployments sharing a collection.
    config()->set('wa.security.vector.namespace_prefix', '');
    expect(VectorFilter::forTenant($first)->namespace())->toBe('wacb_'.$first->id);
});

/*
|--------------------------------------------------------------------------
| The contract shape — what stops task 13.2 from re-opening the hole
|--------------------------------------------------------------------------
*/

it('makes every data-touching store method take the filter, so an unfiltered query cannot be expressed', function (): void {
    $reflection = new ReflectionClass(VectorStore::class);

    foreach ($reflection->getMethods() as $method) {
        if ($method->getName() === 'driver') {
            continue;
        }

        $first = $method->getParameters()[0] ?? null;
        $type = $first?->getType();

        expect($type)->toBeInstanceOf(ReflectionNamedType::class)
            ->and($type instanceof ReflectionNamedType ? $type->getName() : null)
            ->toBe(VectorFilter::class, sprintf(
                'VectorStore::%s() must take a VectorFilter first: a tenant id passed as a bare '
                .'scalar leaves the payload-filter translation to the implementation, which is '
                .'the step that gets forgotten silently.',
                $method->getName(),
            ));
    }
});

it('offers no way to pass a tenant id or a filter as a bare value', function (): void {
    $reflection = new ReflectionClass(VectorStore::class);
    $offenders = [];

    foreach ($reflection->getMethods() as $method) {
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();
            $typeName = $type instanceof ReflectionNamedType ? $type->getName() : null;
            $name = strtolower($parameter->getName());

            $isTenantScalar = str_contains($name, 'tenant') && in_array($typeName, ['int', 'string'], true);
            $isFreeFilter = str_contains($name, 'filter') && $typeName === 'array';

            if ($isTenantScalar || $isFreeFilter) {
                $offenders[] = $method->getName().'($'.$parameter->getName().')';
            }
        }
    }

    expect($offenders)->toBe([], sprintf(
        'These VectorStore parameters re-open the boundary design.md closes with "payload filter '
        .'tenant_id on every query": %s. Carry both on VectorFilter instead.',
        implode(', ', $offenders),
    ));
});

it('describes only the operations a tenant filter can constrain', function (): void {
    // `driver()` is the one method with no filter, and it must stay the one: it returns a
    // label, touches no vectors, and is the obvious place a future "list collections" or
    // "count all" would be tempted to hide.
    $unfiltered = array_values(array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        array_filter(
            (new ReflectionClass(VectorStore::class))->getMethods(),
            static fn (ReflectionMethod $method): bool => $method->getNumberOfParameters() === 0,
        ),
    ));

    expect($unfiltered)->toBe(['driver']);
});
