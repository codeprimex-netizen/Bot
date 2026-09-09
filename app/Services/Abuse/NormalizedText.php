<?php

declare(strict_types=1);

namespace App\Services\Abuse;

use App\Enums\AbuseSignal;

/**
 * One text, prepared for classification: the several *views* a detector has to be
 * matched against, plus the facts about the text that are safe to store
 * (Req 13.8 / B4).
 *
 * ## Why views rather than one normalized string
 *
 * An injection payload is written to survive exactly one normalization and fail the
 * next: `ignore all previous instructions` becomes `1gnore a11 prev1ous`, or
 * `&#105;gnore`, or a base64 blob, or the same words with zero-width joiners between
 * every letter. Each of those defeats a *different* single normalization, and
 * aggressive normalization that beats all of them at once (strip everything
 * non-alphabetic, fold digits to letters, decode every encoding in place) mangles
 * ordinary text badly enough to produce false blocks on legitimate messages.
 *
 * So normalization is not lossy-in-place: `views()` yields the canonical text *and*
 * each derived reading of it, and a detector that matches **any** view has matched.
 * Adding a decoder therefore cannot weaken detection on the other views — a property
 * that a single collapsed string cannot offer.
 *
 * ## What may be kept
 *
 * `contentHash` and `contentLength` are the only two facts that leave the request:
 * they are what `abuse_events` stores, they identify a repeat payload, and neither is
 * readable (design § Observability: message bodies are never logged, only hashed).
 * `raw` and the views stay in memory for the duration of the inspection and are never
 * persisted, logged, or put in an exception message.
 */
final readonly class NormalizedText
{
    /**
     * @param  string  $raw  the text as received — in memory only, never stored
     * @param  string  $canonical  lower-cased, whitespace-collapsed, invisible characters removed
     * @param  list<string>  $folded  transliterated / leet-folded readings of the canonical text
     * @param  list<string>  $decoded  readings recovered by decoding (base64, percent, escapes)
     * @param  list<AbuseSignal>  $flags  what normalization itself discovered
     * @param  string  $contentHash  SHA-256 of `$raw`, hex
     * @param  int  $contentLength  characters in `$raw`
     * @param  bool  $complete  whether every character of `$raw` was examined
     */
    public function __construct(
        public string $raw,
        public string $canonical,
        public array $folded,
        public array $decoded,
        public array $flags,
        public string $contentHash,
        public int $contentLength,
        public bool $complete,
    ) {}

    /**
     * Every reading a detector must be run against, canonical first.
     *
     * De-duplicated, and empty views are dropped: a decoder that produced nothing
     * should not cost every detector an extra pass.
     *
     * @return list<string>
     */
    public function views(): array
    {
        return $this->clean([$this->canonical, ...$this->folded, ...$this->decoded]);
    }

    /**
     * The plain readings: what the text says as written.
     *
     * @return list<string>
     */
    public function plainViews(): array
    {
        return $this->clean([$this->canonical, ...$this->folded]);
    }

    /**
     * The readings that only exist because something was decoded. Kept separate so a
     * detection *inside* an encoded payload can be reported as one
     * (`AbuseSignal::EncodedPayload`): a customer does not base64-encode
     * "ignore all previous instructions" by accident, so the encoding is itself part of
     * the finding.
     *
     * @return list<string>
     */
    public function decodedViews(): array
    {
        return $this->clean($this->decoded);
    }

    /**
     * @param  list<string>  $views
     * @return list<string>
     */
    private function clean(array $views): array
    {
        return array_values(array_unique(array_filter(
            $views,
            static fn (string $view): bool => $view !== '',
        )));
    }

    /**
     * Whether there is anything at all to classify. An empty text is not suspicious —
     * it is simply not text — and the guardrail allows it rather than failing closed,
     * because "the customer sent an image with no caption" must not look like an
     * attack.
     */
    public function isEmpty(): bool
    {
        return $this->views() === [];
    }
}
