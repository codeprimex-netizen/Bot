<?php

declare(strict_types=1);

namespace App\Services\Security\Pii;

use App\Exceptions\Security\PiiRedactionException;

/**
 * Removes PII from text on its way *out* of the platform, reversibly
 * (Req 32.2 / NFR3; design.md § AI 1.3; Correctness Property 15).
 *
 * ```php
 * interface PiiRedactor {
 *     public function redact(string $text): RedactionResult;   // {masked, tokenMap}
 *     public function rehydrate(string $text, TokenMap $map): string;
 * }
 * ```
 *
 * ## What this is not
 *
 * It is not encryption at rest. `App\Services\Security\FieldCipher` protects stored
 * fields with a per-tenant DEK and is reversible by anyone holding that key, forever.
 * This class protects data **in transit to a third party** — an LLM or embedding
 * provider — and its reversal window is one request. Conflating the two produces
 * either a token map that lives in the database (a plaintext PII store) or ciphertext
 * sent to a model that cannot read it.
 *
 * It is also not the log redactor. Logs get an *irreversible* pass
 * (`App\Support\Pii\LogPiiScrubber`, wired as a Monolog processor), because a log
 * line has nothing to rehydrate for and an immutable record must not carry a way
 * back.
 *
 * ## Contract
 *
 * 1. **Round trip.** `rehydrate(redact($t)->masked, redact($t)->map) === $t`,
 *    byte for byte, for any `$t`. Half of Property 15 is this equality; a redactor
 *    that loses a character breaks the reply a customer reads.
 * 2. **No raw egress.** `redact($t)->masked` contains no phone number, email
 *    address, card number, or tenant-configured identifier — in any format, at any
 *    position, in any digit script. That is the other half.
 * 3. **Idempotence.** `redact(redact($t)->masked)` changes nothing: existing tokens
 *    are recognised and never re-tokenised.
 * 4. **Request lifetime.** Tokens are only resolvable by the instance that minted
 *    them, for as long as that instance lives — one request or one job. See
 *    `forget()`.
 */
interface PiiRedactor
{
    /**
     * Mask every PII value in `$text`, returning the safe text plus the map that
     * restores it.
     *
     * @throws PiiRedactionException when the text cannot be scanned — the caller gets
     *                               an error rather than unredacted text
     */
    public function redact(string $text): RedactionResult;

    /**
     * Put the real values back, for the customer-facing reply.
     *
     * Tokens the map does not know are left exactly as they are: a stale token from
     * an earlier request, or one a caller invented, is never resolved to a guess.
     *
     * @throws PiiRedactionException
     */
    public function rehydrate(string $text, TokenMap $map): string;

    /**
     * Mask every string in a structure, in place, preserving keys and shape.
     *
     * Structured egress — tool-call arguments, retrieved document metadata, a JSON
     * body — is where a text-only redactor leaks: nobody remembers to walk the array.
     * Tokens accumulate into `currentMap()`.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     *
     * @throws PiiRedactionException on a structure deeper than the redactor will walk
     */
    public function redactStructure(array $payload): array;

    /**
     * Every token minted during this unit of work.
     *
     * A reply may reference a token introduced by any of several `redact()` calls (a
     * prompt, a history, a question), so rehydration needs the accumulated map rather
     * than the one call's slice.
     */
    public function currentMap(): TokenMap;

    /**
     * Drop the token map and re-key the tokeniser: after this call, no token this
     * instance has ever issued can be resolved by it again.
     *
     * Called at unit-of-work boundaries. The redactor is also bound `scoped()`, so
     * request and job boundaries do this by discarding the instance entirely; the
     * explicit method exists for long-running loops (a campaign worker, a batch
     * ingest) that want the map's lifetime to be one *message* rather than one job.
     */
    public function forget(): void;
}
