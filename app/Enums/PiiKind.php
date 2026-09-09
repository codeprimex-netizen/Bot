<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a piece of redacted text *was* (Req 32.2 / NFR3, Correctness Property 15).
 *
 * The kind is carried for three reasons, none of them cosmetic:
 *
 * 1. **Token labelling.** A token reads `[[PII:PHONE:…]]`, so the model still knows
 *    it is being handed a phone number and can write "I'll text you on
 *    `[[PII:PHONE:…]]`" — a reply that rehydrates into something a customer can
 *    read. An opaque `[[REDACTED]]` destroys that.
 * 2. **Kind-specific one-way masking in logs.** A phone keeps its first and last
 *    two digits (enough to correlate with a support ticket), a card keeps its last
 *    four (the PCI convention), an email keeps its domain. One mask for all three
 *    would be either too revealing or useless.
 * 3. **Deterministic overlap resolution.** When two detectors claim overlapping
 *    text the more specific kind wins, and "more specific" has to be an ordering
 *    rather than a coin toss (see `PiiScanner`).
 */
enum PiiKind: string
{
    case Email = 'EMAIL';
    case Phone = 'PHONE';
    case Card = 'CARD';

    /**
     * An operator-configured, tenant-specific pattern (order ids, policy numbers,
     * national identifiers — whatever that tenant considers identifying).
     */
    case Custom = 'CUSTOM';

    /**
     * Detector precedence when two spans overlap: lower wins.
     *
     * Built-ins outrank custom patterns because a custom pattern is a superset
     * guess by an operator, while `CARD` and `EMAIL` are structurally verified.
     */
    public function precedence(): int
    {
        return match ($this) {
            self::Email => 10,
            self::Card => 20,
            self::Phone => 30,
            self::Custom => 40,
        };
    }
}
