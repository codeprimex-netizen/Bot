<?php

declare(strict_types=1);

namespace App\Support\Pii;

/**
 * An operator-supplied redaction pattern that has been validated and delimited —
 * the only form `PiiScanner` will accept a tenant pattern in.
 *
 * Two compiled forms are kept because the scanner has two subjects to deal with.
 * `$pattern` carries the `u` modifier and is used for text that is valid UTF-8;
 * `$asciiPattern` is the same source without it, for text that is not (where a
 * `u`-flagged pattern refuses to run at all and would silently redact nothing).
 * Both are compiled up front, so a pattern that only works for one of the two is
 * rejected at validation time rather than discovered mid-message.
 *
 * `$source` is the operator's original body, kept for diagnostics only — never
 * used for matching, because it has not been delimited or bounded.
 */
final readonly class CompiledPiiPattern
{
    public function __construct(
        public string $source,
        public string $pattern,
        public string $asciiPattern,
    ) {}

    /**
     * The form to apply to this subject.
     */
    public function for(bool $unicode): string
    {
        return $unicode ? $this->pattern : $this->asciiPattern;
    }
}
