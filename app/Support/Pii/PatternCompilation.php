<?php

declare(strict_types=1);

namespace App\Support\Pii;

/**
 * The outcome of compiling a tenant's configured redaction patterns: the ones that
 * are safe to run, and why each of the others was refused.
 *
 * Rejections are *returned* rather than thrown because one bad regex in a tenant's
 * settings must not stop that tenant's messages from being redacted at all — the
 * remaining patterns and every built-in detector still apply. The caller decides
 * how loudly to complain (`ConfigTenantPiiPatternSource` logs once per tenant).
 */
final readonly class PatternCompilation
{
    /**
     * @param  list<CompiledPiiPattern>  $patterns
     * @param  array<string, string>  $rejections  pattern source => reason code
     */
    public function __construct(
        public array $patterns,
        public array $rejections = [],
    ) {}

    public function hasRejections(): bool
    {
        return $this->rejections !== [];
    }
}
