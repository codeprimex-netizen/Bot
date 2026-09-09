<?php

declare(strict_types=1);

namespace App\Services\Abuse;

use App\Enums\AbuseSignal;
use App\Enums\AbuseVector;
use App\Enums\GuardAction;

/**
 * The answer the anti-fraud heuristics give about one attempt: allow, flag, or block —
 * and, when blocked, for how long (Req 32.7 / NFR3).
 *
 * ## Every block carries its own expiry
 *
 * `retryAfterSeconds()` is the remainder of the *tripped* counter's window, so a refusal
 * is always a wait rather than a verdict on a person. That is not politeness, it is a
 * requirement: signup traffic arrives through carrier NAT and corporate egress, so any
 * threshold keyed on an address will eventually catch a legitimate customer, and the only
 * safe design is one where being caught costs them minutes, visibly, instead of costing
 * them the product.
 *
 * ## Explainable from stored data
 *
 * `evidence` holds every counter that was consulted with its limit, its window, and its
 * remaining time — the `abuse_events` row is built from it, so months later an operator
 * can reconstruct exactly why an attempt was refused without the trail ever having stored
 * the email address, the phone number, or the fingerprint (`subjectHash` is a keyed
 * digest).
 */
final readonly class FraudVerdict
{
    /**
     * @param  list<AbuseSignal>  $signals
     * @param  array<string, mixed>  $evidence  counters with their limits and windows
     */
    private function __construct(
        public GuardAction $action,
        public array $signals,
        public array $evidence,
        public AbuseVector $vector,
        public string $surface,
        public ?string $subjectHash,
        private int $retryAfter,
    ) {}

    /**
     * @param  list<AbuseSignal>  $signals
     * @param  array<string, mixed>  $evidence
     */
    public static function make(
        array $signals,
        array $evidence,
        AbuseVector $vector,
        string $surface,
        ?string $subjectHash = null,
        int $retryAfter = 0,
    ): self {
        return new self(
            AbuseSignal::actionFor($signals),
            $signals,
            $evidence,
            $vector,
            $surface,
            $subjectHash,
            max(0, $retryAfter),
        );
    }

    public function isAllowed(): bool
    {
        return $this->action->isAllowed();
    }

    public function blocks(): bool
    {
        return $this->action->blocks();
    }

    /**
     * Whether the attempt may proceed — true for a flag.
     */
    public function permits(): bool
    {
        return $this->action->permits();
    }

    /**
     * Seconds the caller should wait. At least 1 for a block (a `Retry-After: 0` invites
     * an immediate retry, which is what the counter is there to prevent) and 0 otherwise.
     */
    public function retryAfterSeconds(): int
    {
        return $this->action->blocks() ? max(1, $this->retryAfter) : 0;
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

    public function signalList(): string
    {
        return implode(', ', $this->signalValues());
    }

    /**
     * The sentence a prospective customer is shown. It never says *which* signal tripped —
     * that would let a farmer tune around it — but it always says how long to wait, so a
     * legitimate user behind a shared address knows the difference between "wait" and
     * "give up".
     */
    public function explanation(): string
    {
        if (! $this->action->blocks()) {
            return 'The attempt was accepted.';
        }

        if ($this->has(AbuseSignal::DisposableEmailDomain)) {
            // The one refusal that waiting does not fix, so it says what will: a different
            // address. Saying "try again later" here would send a legitimate customer away
            // to wait for something that is never going to change.
            return 'This email domain is not accepted. Please sign up with a permanent email address.';
        }

        $seconds = $this->retryAfterSeconds();
        $minutes = (int) ceil($seconds / 60);

        return $seconds < 60
            ? sprintf('Too many attempts. Please try again in %d second%s.', $seconds, $seconds === 1 ? '' : 's')
            : sprintf('Too many attempts. Please try again in about %d minute%s.', $minutes, $minutes === 1 ? '' : 's');
    }

    /**
     * The `abuse_events` evidence shape.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'action' => $this->action->value,
            'vector' => $this->vector->value,
            'surface' => $this->surface,
            'signals' => $this->signalValues(),
            'retry_after_seconds' => $this->retryAfterSeconds(),
            'counters' => $this->evidence,
        ];
    }

    /**
     * The row this verdict implies. Never carries content: only the keyed subject digest
     * and the counters.
     */
    public function toDraft(): AbuseEventDraft
    {
        return new AbuseEventDraft(
            vector: $this->vector,
            action: $this->action,
            signals: $this->signals,
            evidence: $this->toArray(),
            surface: $this->surface,
            subjectHash: $this->subjectHash,
        );
    }
}
