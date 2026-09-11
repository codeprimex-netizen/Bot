<?php

declare(strict_types=1);

namespace App\Services\Reliability;

use App\Enums\ErrorClass;
use Throwable;

/**
 * Maps a `Throwable` to the `ErrorClass` that decides its fate — the seam every subsystem
 * classifies **its own** provider errors through (design.md § Error Handling;
 * ROADMAP Phase 23; Req 33.2 / NFR4).
 *
 * ```php
 * final class CloudApiErrorClassifier implements ErrorClassifier
 * {
 *     public function classify(Throwable $e): ?ErrorClass
 *     {
 *         if (! $e instanceof CloudApiException) {
 *             return null;                       // not mine — ask the next one
 *         }
 *
 *         return match ($e->code) {
 *             131_026 => ErrorClass::NotOnWhatsApp,
 *             130_429 => ErrorClass::RateLimit,
 *             default => null,
 *         };
 *     }
 * }
 * ```
 *
 * …then one line in `config/wa.php`:
 *
 * ```php
 * 'reliability' => ['retry' => ['classifiers' => [CloudApiErrorClassifier::class]]],
 * ```
 *
 * ## Why `null` and not `UNKNOWN`
 *
 * A classifier returning `null` says *"I have no opinion"*, which is what lets the chain
 * work: `CompositeErrorClassifier` moves on to the next classifier, then to the platform's
 * own typed exceptions, and only then to the configured default. A classifier that
 * returned `ErrorClass::Unknown` for everything it does not recognise would silently
 * become the answer for every failure in the platform — the LLM provider's classifier
 * would start deciding the fate of bridge errors.
 *
 * So: recognise narrowly, decline loudly, and never guess on somebody else's behalf.
 *
 * ## Contract
 *
 * A classifier is asked *while a failure is being handled*, often inside a queue worker's
 * `failed()` path. It must therefore be **pure and cheap**: no database, no network, no
 * throwing (a classifier that throws is skipped, and its own failure is the second error
 * in a report about the first). Implementations are resolved from the container, so
 * constructor injection is available for config or a logger.
 */
interface ErrorClassifier
{
    /**
     * The class of $e, or null when this classifier has no opinion about it.
     */
    public function classify(Throwable $e): ?ErrorClass;
}
