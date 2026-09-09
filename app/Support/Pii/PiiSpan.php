<?php

declare(strict_types=1);

namespace App\Support\Pii;

use App\Enums\PiiKind;

/**
 * One stretch of text the scanner identified as PII: where it starts, what it says,
 * and what kind of identifier it is.
 *
 * Offsets are **byte** offsets, matching `preg_match_all(PREG_OFFSET_CAPTURE)` and
 * `substr_replace()`. That is deliberate: replacement happens on bytes, so keeping
 * one unit throughout removes the class of bug where a multi-byte character shifts
 * a replacement by two positions and corrupts the text a customer sees.
 */
final readonly class PiiSpan
{
    public function __construct(
        public int $start,
        public string $text,
        public PiiKind $kind,
    ) {}

    public function length(): int
    {
        return strlen($this->text);
    }

    /**
     * First byte offset *after* this span.
     */
    public function end(): int
    {
        return $this->start + $this->length();
    }

    /**
     * Whether two spans claim any of the same bytes — the test the scanner's
     * overlap resolution is built on.
     */
    public function overlaps(self $other): bool
    {
        return $this->start < $other->end() && $other->start < $this->end();
    }
}
