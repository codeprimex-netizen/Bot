<?php

declare(strict_types=1);

namespace App\Services\Security\Pii;

use App\Models\Tenant;
use App\Support\Pii\TenantPatternCompiler;
use Psr\Log\LoggerInterface;

/**
 * Reads tenant redaction patterns from `wa.security.pii.tenant_patterns`, keyed by
 * tenant id, tenant slug, or `*` for every tenant.
 *
 * Configuration is the right home for these *today* and possibly for good: the
 * patterns are operator knowledge ("our policy numbers look like `POL-\d{8}`"), they
 * change rarely, and keeping them out of the database means no migration and no
 * per-message query on the egress path. A settings screen can replace this class by
 * implementing the same interface — `wa.security.pii.pattern_source` — without the
 * redactor changing.
 *
 * Compilation results are memoised per tenant for the life of the instance (one
 * request or job), and rejections are reported **once** per tenant rather than per
 * message, so a typo in an operator's regex produces one actionable warning instead
 * of one per inbound message.
 */
final class ConfigTenantPiiPatternSource implements TenantPiiPatternSource
{
    /**
     * @var array<string, list<\App\Support\Pii\CompiledPiiPattern>>
     */
    private array $memo = [];

    public function __construct(
        private readonly TenantPatternCompiler $compiler,
        private readonly LoggerInterface $log,
    ) {}

    public function patternsFor(?Tenant $tenant): array
    {
        $key = $tenant?->getKey();
        $cacheKey = is_string($key) && $key !== '' ? $key : '*';

        return $this->memo[$cacheKey] ??= $this->compile($tenant);
    }

    /**
     * @return list<\App\Support\Pii\CompiledPiiPattern>
     */
    private function compile(?Tenant $tenant): array
    {
        $sources = $this->sourcesFor($tenant);

        if ($sources === []) {
            return [];
        }

        $compilation = $this->compiler->compile($sources);

        if ($compilation->hasRejections()) {
            // The reason codes, not the message text: this line is written on a path
            // that handles customer messages, so it says nothing about them. The
            // pattern bodies are operator-authored configuration and are safe to name,
            // and the log processor scrubs them anyway if one happens to embed a
            // literal phone number.
            $this->log->warning('Tenant PII patterns rejected', [
                'tenant_id' => $tenant?->getKey(),
                'rejected' => $compilation->rejections,
            ]);
        }

        return $compilation->patterns;
    }

    /**
     * Platform-wide patterns first, then the tenant's own — by id, then by slug.
     *
     * @return list<string>
     */
    private function sourcesFor(?Tenant $tenant): array
    {
        $configured = config('wa.security.pii.tenant_patterns', []);

        if (! is_array($configured) || $configured === []) {
            return [];
        }

        $keys = ['*'];

        $id = $tenant?->getKey();

        if (is_string($id) && $id !== '') {
            $keys[] = $id;
        }

        if ($tenant !== null && is_string($tenant->slug) && $tenant->slug !== '') {
            $keys[] = $tenant->slug;
        }

        $sources = [];

        foreach ($keys as $key) {
            $entry = $configured[$key] ?? null;

            // A single pattern may be written as a bare string; a list of them as an
            // array. Anything else is ignored rather than coerced.
            foreach (is_string($entry) ? [$entry] : (is_array($entry) ? $entry : []) as $pattern) {
                if (is_string($pattern) && trim($pattern) !== '') {
                    $sources[] = $pattern;
                }
            }
        }

        return array_values(array_unique($sources));
    }
}
