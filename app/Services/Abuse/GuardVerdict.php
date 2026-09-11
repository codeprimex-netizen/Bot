<?php

declare(strict_types=1);

namespace App\Services\Abuse;

use App\Enums\AbuseSignal;
use App\Enums\AbuseVector;
use App\Enums\GuardAction;

/**
 * The answer `Guardrail::inspectInput()`/`inspectOutput()` gives: `allow|flag|block`
 * plus the reasons (design § AI 1.3: `GuardVerdict // allow|flag|block + reasons`;
 * Req 13.8 / B4).
 *
 * ```php
 * $verdict = $guardrail->inspectInput($body, GuardContext::forSession($sessionId));
 *
 * if ($verdict->blocks()) {
 *     return;                      // reply suppressed; the abuse event is already recorded
 * }
 *
 * $safeText = $verdict->text();    // fence-sanitized, ready to be fenced into the prompt
 * ```
 *
 * ## `text()` is the sanitized text, and it is never the same object as what was stored
 *
 * A verdict carries the text it was formed from so the caller does not have to re-derive
 * it — but the copy it carries has already been through
 * `PromptFence::sanitize()`, so a caller that forwards `text()` cannot forward a
 * delimiter escape even when the verdict only flagged. The text lives in memory for the
 * duration of the request and appears in **no** persisted or logged form: `toArray()`,
 * which is what the `abuse_events` row is built from, contains the hash and the length
 * and nothing else (design § Observability: bodies are hashed, never logged).
 *
 * ## Recording happens before the caller sees this
 *
 * By the time a verdict exists, its `abuse_events` row has been written (for `FLAG` and
 * `BLOCK`). A caller therefore cannot forget to record one, and a caller that ignores
 * the verdict entirely still leaves a trail — which is the point of Req 13.8's "record
 * an entry in the abuse-events store" being the platform's obligation rather than the
 * call site's.
 */
final readonly class GuardVerdict
{
    /**
     * @param  list<AbuseSignal>  $signals
     * @param  list<string>  $detectors  rule ids, never matched text
     * @param  string  $sanitizedText  in-memory only; never stored, logged, or put in an exception
     */
    private function __construct(
        public GuardAction $action,
        public array $signals,
        public array $detectors,
        public AbuseVector $vector,
        public string $surface,
        public string $contentHash,
        public int $contentLength,
        private string $sanitizedText,
    ) {}

    /**
     * Build a verdict from a classification. The action is the classification's — no
     * caller may soften it.
     */
    public static function from(
        Classification $classification,
        AbuseVector $vector,
        string $surface,
        NormalizedText $text,
        string $sanitizedText,
    ): self {
        return new self(
            $classification->action(),
            $classification->signals,
            $classification->detectors,
            $vector,
            $surface,
            $text->contentHash,
            $text->contentLength,
            $sanitizedText,
        );
    }

    /**
     * A verdict for text nothing was found in.
     */
    public static function allowed(AbuseVector $vector, string $surface, NormalizedText $text, string $sanitizedText): self
    {
        return new self(GuardAction::Allow, [], [], $vector, $surface, $text->contentHash, $text->contentLength, $sanitizedText);
    }

    /*
    |--------------------------------------------------------------------------
    | What the caller does next
    |--------------------------------------------------------------------------
    */

    public function isAllowed(): bool
    {
        return $this->action->isAllowed();
    }

    public function flags(): bool
    {
        return $this->action->flags();
    }

    public function blocks(): bool
    {
        return $this->action->blocks();
    }

    /**
     * Whether the text may be used — true for a flag.
     */
    public function permits(): bool
    {
        return $this->action->permits();
    }

    /**
     * The text, sanitized, for a caller that may use it. Empty for a block: there is
     * nothing a blocked text may legitimately be used for, so the verdict does not
     * hand one back.
     */
    public function text(): string
    {
        return $this->action->blocks() ? '' : $this->sanitizedText;
    }

    public function has(AbuseSignal $signal): bool
    {
        return in_array($signal, $this->signals, true);
    }

    /**
     * Whether this verdict came from a "could not classify" outcome rather than from a
     * positive detection — the distinction an operator triages by.
     */
    public function isFailClosed(): bool
    {
        foreach ($this->signals as $signal) {
            if ($signal->isFailClosed()) {
                return true;
            }
        }

        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Explaining it
    |--------------------------------------------------------------------------
    */

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

    /**
     * Comma-separated signal values, for an exception or a log line.
     */
    public function signalList(): string
    {
        return implode(', ', $this->signalValues());
    }

    /**
     * The human-readable reasons — design's *"allow|flag|block + reasons"*. Safe to
     * show an operator: each is a description of a rule, never of the text.
     *
     * @return list<string>
     */
    public function reasons(): array
    {
        return array_values(array_map(
            static fn (AbuseSignal $signal): string => $signal->label(),
            $this->signals,
        ));
    }

    /**
     * One sentence for an operator view.
     */
    public function explanation(): string
    {
        if ($this->signals === []) {
            return 'No abuse signals were found.';
        }

        return sprintf('%s: %s.', $this->action->label(), implode('; ', $this->reasons()));
    }

    /**
     * The `abuse_events` evidence shape — everything the decision was made from, and
     * nothing that could carry content.
     *
     * @return array{action: string, vector: string, surface: string, signals: list<string>, detectors: list<string>, content_hash: string, content_length: int, fail_closed: bool}
     */
    public function toArray(): array
    {
        return [
            'action' => $this->action->value,
            'vector' => $this->vector->value,
            'surface' => $this->surface,
            'signals' => $this->signalValues(),
            'detectors' => $this->detectors,
            'content_hash' => $this->contentHash,
            'content_length' => $this->contentLength,
            'fail_closed' => $this->isFailClosed(),
        ];
    }
}
