<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a guardrail inspection tells its caller to do — the `allow|flag|block` of
 * design § AI 1.3 (`Guardrail::inspectInput/inspectOutput`), Req 13.8 / B4 and
 * Req 32.7 / NFR3.
 *
 * | Action  | The text is | The caller | Recorded in `abuse_events` |
 * |---------|-------------|------------|----------------------------|
 * | `ALLOW` | clean       | proceeds   | no                         |
 * | `FLAG`  | suspicious but usable | proceeds | yes (`wa.security.guardrail.record_flags`) |
 * | `BLOCK` | refused     | suppresses the reply / refuses the request | always |
 *
 * Three values rather than a boolean for the same reason `QuotaOutcome` has three:
 * the middle case carries information a boolean destroys. A message that was
 * *obfuscated* but carried no injection payload is worth recording and worth
 * showing to a human reviewer, and it is not worth refusing to answer — while a
 * boolean would force that case into either "clean" (evidence lost) or "blocked"
 * (a legitimate customer ignored).
 *
 * ## Escalation is monotone, and that is the safety argument
 *
 * A verdict over several signals is `escalate()`d across them, and escalation only
 * ever moves **towards** `BLOCK`. No detector can lower another detector's finding,
 * so there is no ordering of signals — and therefore no ordering of the detectors
 * that produced them — in which an injection payload ends up allowed because a
 * later, milder check ran last.
 */
enum GuardAction: string
{
    case Allow = 'ALLOW';
    case Flag = 'FLAG';
    case Block = 'BLOCK';

    /**
     * Severity rank, used by `escalate()`. Not exposed as the enum's value because
     * the stored value must stay a readable label, not a magic number.
     */
    public function severity(): int
    {
        return match ($this) {
            self::Allow => 0,
            self::Flag => 1,
            self::Block => 2,
        };
    }

    /**
     * The stricter of two actions — the only way actions are combined.
     */
    public function escalate(self $other): self
    {
        return $other->severity() > $this->severity() ? $other : $this;
    }

    /**
     * Whether the inspected text may be used at all.
     *
     * True for `FLAG`: a flag records and warns, it does not refuse.
     */
    public function permits(): bool
    {
        return $this !== self::Block;
    }

    public function isAllowed(): bool
    {
        return $this === self::Allow;
    }

    public function flags(): bool
    {
        return $this === self::Flag;
    }

    public function blocks(): bool
    {
        return $this === self::Block;
    }

    /**
     * Whether an inspection with this action is worth an `abuse_events` row.
     *
     * `ALLOW` is not: a row per clean inbound message would turn the abuse trail
     * into a copy of the message log, which is exactly what the platform's "message
     * bodies are never stored in logs" posture forbids.
     */
    public function isRecordable(): bool
    {
        return $this !== self::Allow;
    }

    /**
     * Human-readable label for panels and operator views.
     */
    public function label(): string
    {
        return match ($this) {
            self::Allow => 'Allowed',
            self::Flag => 'Flagged for review',
            self::Block => 'Blocked',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
