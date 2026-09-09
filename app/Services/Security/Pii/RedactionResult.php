<?php

declare(strict_types=1);

namespace App\Services\Security\Pii;

/**
 * What `PiiRedactor::redact()` returns: the text that is safe to send, and the map
 * that can undo it (design.md § AI 1.3 — `RedactionResult {masked, tokenMap}`).
 *
 * The two travel together because a caller that has one without the other is in
 * trouble either way: masked text with no map produces a reply full of `[[PII:…]]`
 * for the customer to read, and a map with no masked text is a plaintext PII store
 * with no purpose. Keeping them in one value also means the map's own
 * anti-persistence guard covers the pair — serializing a `RedactionResult` reaches
 * `TokenMap::__serialize()` and throws.
 */
final readonly class RedactionResult
{
    public function __construct(
        public string $masked,
        public TokenMap $map,
    ) {}

    /**
     * Whether anything was actually removed — the cheap check before deciding
     * whether a reply needs rehydrating at all.
     */
    public function isRedacted(): bool
    {
        return ! $this->map->isEmpty();
    }
}
