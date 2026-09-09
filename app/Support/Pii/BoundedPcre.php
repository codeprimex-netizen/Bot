<?php

declare(strict_types=1);

namespace App\Support\Pii;

/**
 * Runs a block of matching under a **lowered PCRE backtrack limit** — the platform's
 * actual defence against a catastrophically backtracking operator-supplied regex
 * (Req 32.2 / NFR3).
 *
 * Static analysis of a regex cannot decide whether it backtracks exponentially;
 * that question is undecidable in general and the published heuristics all have
 * false negatives. `TenantPatternCompiler` applies the cheap heuristics anyway,
 * because rejecting an obviously dangerous pattern gives the operator a clear
 * error instead of a slow one — but the *guarantee* is here: PCRE counts its own
 * backtracks and gives up, so the worst case of any pattern this wraps is a fixed
 * number of steps rather than an unbounded one.
 *
 * Giving up shows as `preg_*` returning `false` with
 * `preg_last_error() === PREG_BACKTRACK_LIMIT_ERROR`, which callers treat as
 * "this pattern contributed nothing to this text" — never as "this text is clean"
 * (built-in detectors) and never as a fatal error for the message (tenant
 * patterns).
 *
 * The limit is process-global while the callback runs, so the block it wraps is kept
 * to the matching itself and the previous value is always restored.
 */
final class BoundedPcre
{
    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function within(int $backtrackLimit, callable $callback): mixed
    {
        $previous = ini_get('pcre.backtrack_limit');

        if ($backtrackLimit > 0) {
            ini_set('pcre.backtrack_limit', (string) $backtrackLimit);
        }

        try {
            return $callback();
        } finally {
            if ($backtrackLimit > 0 && is_string($previous)) {
                ini_set('pcre.backtrack_limit', $previous);
            }
        }
    }
}
