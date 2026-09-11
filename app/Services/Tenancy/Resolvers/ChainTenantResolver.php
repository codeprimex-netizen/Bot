<?php

declare(strict_types=1);

namespace App\Services\Tenancy\Resolvers;

use App\Services\Tenancy\TenantResolution;
use App\Services\Tenancy\TenantResolver;
use Illuminate\Http\Request;

/**
 * Runs the configured resolvers in order and takes the first hit.
 *
 * The order comes from `config('wa.tenancy.resolvers')`; the default and its
 * rationale are documented there.
 */
final readonly class ChainTenantResolver implements TenantResolver
{
    /**
     * @param  list<TenantResolver>  $resolvers  in precedence order, first match wins
     */
    public function __construct(private array $resolvers) {}

    public function resolve(Request $request): ?TenantResolution
    {
        foreach ($this->resolvers as $resolver) {
            $resolution = $resolver->resolve($request);

            if ($resolution instanceof TenantResolution) {
                return $resolution;
            }
        }

        return null;
    }

    /**
     * @return list<TenantResolver>
     */
    public function resolvers(): array
    {
        return $this->resolvers;
    }
}
