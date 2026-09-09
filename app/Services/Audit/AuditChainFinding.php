<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Enums\AuditChainDefect;

/**
 * One broken link, described well enough to investigate without re-running the
 * verifier (Req 24.5 / D1, Correctness Property 17).
 *
 * "The chain is broken" is not a usable finding. Whoever is woken up needs to know
 * *where* (chain and position), *which row* (so it can be read), *what kind* of
 * break, and *what was expected versus found* — which together distinguish "somebody
 * edited row 42's payload" from "rows 40–45 are gone".
 */
final readonly class AuditChainFinding
{
    /**
     * @param  int  $sequence  the position the break was found at (the *expected* position
     *                         for a gap, so the report points at what is missing)
     * @param  string|null  $entryId  the offending row's id, `null` when the row is absent
     */
    public function __construct(
        public AuditChainDefect $defect,
        public string $chainKey,
        public int $sequence,
        public ?string $entryId,
        public string $expected,
        public string $found,
    ) {}

    /**
     * A single line for a log, an alert, or the Admin audit viewer (task 30.6).
     */
    public function describe(): string
    {
        return sprintf(
            '%s in audit chain [%s] at position %d%s: expected %s, found %s.',
            $this->defect->value,
            $this->chainKey,
            $this->sequence,
            $this->entryId === null ? '' : sprintf(' (entry %s)', $this->entryId),
            $this->expected === '' ? '(nothing)' : $this->expected,
            $this->found === '' ? '(nothing)' : $this->found,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'defect' => $this->defect->value,
            'chain_key' => $this->chainKey,
            'sequence' => $this->sequence,
            'entry_id' => $this->entryId,
            'expected' => $this->expected,
            'found' => $this->found,
        ];
    }
}
