<?php

declare(strict_types=1);

namespace App\Services\Abuse;

use App\Enums\AbuseSignal;
use App\Enums\GuardAction;

/**
 * What a classifier found: the signals, and the id of the detector that produced
 * each one (Req 13.8 / B4).
 *
 * ## Detector ids, not matched text
 *
 * `detectors` holds values like `instruction_override.ignore_previous` — the *rule*
 * that fired, never the characters that made it fire. That is what lets an
 * `abuse_events` row explain itself to an operator ("this was the fenced-delimiter
 * rule, not the role-reassignment one") while still obeying the platform's rule that
 * message content is never stored outside the message tables (design § Observability).
 * It is also what makes tuning possible: a noisy rule can be identified from the
 * stored evidence and adjusted, which is impossible when every finding is recorded as
 * the same opaque "injection detected".
 *
 * Instances are built up immutably (`with()`, `merge()`), so a composite classifier
 * cannot accidentally drop another classifier's findings.
 */
final readonly class Classification
{
    /**
     * @param  list<AbuseSignal>  $signals
     * @param  list<string>  $detectors
     */
    public function __construct(
        public array $signals = [],
        public array $detectors = [],
    ) {}

    public static function clean(): self
    {
        return new self;
    }

    /**
     * This classification plus one signal.
     *
     * A signal already present is not added twice, but its detector id is kept: two
     * different rules finding the same thing is stronger evidence than one, and an
     * operator needs to see both.
     */
    public function with(AbuseSignal $signal, string $detector = ''): self
    {
        $signals = in_array($signal, $this->signals, true)
            ? $this->signals
            : [...$this->signals, $signal];

        $detectors = $detector === '' || in_array($detector, $this->detectors, true)
            ? $this->detectors
            : [...$this->detectors, $detector];

        return new self($signals, $detectors);
    }

    public function merge(self $other): self
    {
        $merged = $this;

        foreach ($other->signals as $signal) {
            $merged = $merged->with($signal);
        }

        foreach ($other->detectors as $detector) {
            $merged = new self($merged->signals, in_array($detector, $merged->detectors, true)
                ? $merged->detectors
                : [...$merged->detectors, $detector]);
        }

        return $merged;
    }

    /**
     * The strictest action across the signals — `ALLOW` when there are none.
     */
    public function action(): GuardAction
    {
        return AbuseSignal::actionFor($this->signals);
    }

    public function isClean(): bool
    {
        return $this->signals === [];
    }

    public function has(AbuseSignal $signal): bool
    {
        return in_array($signal, $this->signals, true);
    }

    /**
     * @return list<string>
     */
    public function signalValues(): array
    {
        return array_values(array_map(
            static fn (AbuseSignal $signal): string => $signal->value,
            $this->signals,
        ));
    }
}
