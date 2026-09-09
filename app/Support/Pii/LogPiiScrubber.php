<?php

declare(strict_types=1);

namespace App\Support\Pii;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Stringable;
use Throwable;

/**
 * The one-way pass every log record goes through (Req 7.3 / A7; Req 32.2 / NFR3;
 * design.md § Observability — *"Phone numbers redacted, message bodies never logged
 * (only content hashes)"*).
 *
 * Two rules, and they are not the same rule:
 *
 * 1. **PII is masked wherever it appears** — in the message, in context, in extra,
 *    in an exception's message, at any nesting depth. Masking is irreversible: a log
 *    line carries no token map and there is no way back from `+14********71`.
 * 2. **Message bodies are hashed, never written** — even though a body is not PII by
 *    pattern. A `body`/`text`/`prompt`/`reply` key is replaced by
 *    `sha256:… chars:…`, which still proves two log lines refer to the same content
 *    and still lets an operator confirm a specific message reached a specific stage,
 *    without the platform's logs becoming a copy of everyone's conversations.
 *
 * Everything about this class is written for a code path that must not fail and must
 * not recurse:
 *
 * - **No tenant patterns.** Custom patterns are per-tenant configuration; a log line
 *   is often emitted with no tenant bound (a platform job, a boot-time failure), and
 *   resolving them would mean a config read and a compile per log line. Bodies are
 *   hashed anyway, so what is left for a tenant pattern to catch in a log line is
 *   marginal — and this keeps the scrubber from calling anything that itself logs.
 * - **Bounded work.** Depth, item count, and string length are capped, so a log call
 *   carrying a huge nested payload cannot turn logging into the slowest thing in the
 *   request.
 * - **Idempotent.** Every mask this produces is inert to the detectors that produced
 *   it, and an already-hashed value is recognised and left alone, so a record that
 *   passes through two channels of a stack is not hashed twice into a different
 *   digest.
 */
final class LogPiiScrubber
{
    public const string REDACTED = PiiMask::REDACTED;

    private const string DIGEST_PREFIX = 'sha256:';

    public function __construct(
        private readonly PiiScanner $scanner,
        private readonly int $maxStringLength = 4000,
        private readonly int $maxDepth = 8,
        private readonly int $maxItems = 100,
    ) {}

    /**
     * Mask every PII span in one string.
     */
    public function scrub(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        $spans = $this->scanner->scan($text);

        // Replace from the end so earlier offsets stay valid.
        foreach (array_reverse($spans) as $span) {
            $text = substr_replace($text, PiiMask::forKind($span->kind, $span->text), $span->start, $span->length());
        }

        return $text;
    }

    /**
     * Walk a log context/extra array, applying the key rules and masking values.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public function scrubPayload(array $payload): array
    {
        /** @var array<array-key, mixed> $walked */
        $walked = $this->walk($payload, 0);

        return $walked;
    }

    /**
     * The stand-in for message content: proves identity, reveals nothing.
     */
    public function digest(string $text): string
    {
        return sprintf('%s%s chars:%d', self::DIGEST_PREFIX, hash('sha256', $text), mb_strlen($text));
    }

    private function walk(mixed $value, int $depth): mixed
    {
        if ($depth > $this->maxDepth) {
            return '[depth limit]';
        }

        if (is_array($value)) {
            $result = [];
            $seen = 0;

            foreach ($value as $key => $item) {
                if (++$seen > $this->maxItems) {
                    $result['[capped]'] = sprintf('%d more items', count($value) - $this->maxItems);

                    break;
                }

                $result[$key] = $this->forKey((string) $key, $item, $depth);
            }

            return $result;
        }

        return $this->scalar($value, $depth);
    }

    private function forKey(string $key, mixed $value, int $depth): mixed
    {
        if (PiiKeyRules::isSecret($key)) {
            return self::REDACTED;
        }

        if (PiiKeyRules::isContent($key)) {
            return $this->digestOf($value);
        }

        if (is_string($value) && PiiKeyRules::isIdentity($key)) {
            // A value under an identity key is masked whatever its shape, so a bare
            // `"5551234"` under `phone` is masked even though nothing about the digits
            // says it is a subscriber number.
            return preg_match('/^[+\p{Nd}\s().\-]+$/u', $value) === 1
                ? PiiMask::digits($value)
                : $this->scrub($this->truncate($value));
        }

        if (is_string($value) && PiiKeyRules::isOpaqueIdentifier($key, $value)) {
            // A digest, ULID, or correlation id, recorded verbatim: the bare-phone
            // detector matches digit runs inside hex and base32, so scanning these
            // corrupted roughly one in ten of them and destroyed the evidence the line
            // exists for. Checked last, so the secret, content, and identity rules win.
            return $this->truncate($value);
        }

        return $this->walk($value, $depth + 1);
    }

    private function scalar(mixed $value, int $depth): mixed
    {
        if (is_string($value)) {
            return $this->scrub($this->truncate($value));
        }

        if ($value instanceof Throwable) {
            return $this->exceptionShape($value, $depth);
        }

        if ($value instanceof Arrayable) {
            /** @var array<array-key, mixed> $array */
            $array = $value->toArray();

            return $this->walk($array, $depth + 1);
        }

        if ($value instanceof JsonSerializable) {
            return $this->walk($value->jsonSerialize(), $depth + 1);
        }

        if ($value instanceof Stringable) {
            return $this->scrub($this->truncate((string) $value));
        }

        if (is_object($value)) {
            // Anything else is described rather than dumped: a formatter would
            // otherwise serialize its properties, PII and all.
            return sprintf('[object %s]', $value::class);
        }

        return is_scalar($value) || $value === null ? $value : sprintf('[%s]', get_debug_type($value));
    }

    /**
     * An exception, flattened into something safe to render.
     *
     * The object cannot be handed on as-is: `Throwable::getMessage()` is final, so
     * there is no way to mask an exception's own message in place, and every default
     * Laravel formatter prints it. So the record carries a masked *shape* instead —
     * class, masked message, origin, masked trace, and the same for its cause chain.
     * Losing the live object costs the formatter's stack-trace rendering; keeping it
     * would cost every PII value any exception message has ever interpolated.
     */
    private function exceptionShape(Throwable $exception, int $depth): mixed
    {
        if ($depth > $this->maxDepth) {
            return sprintf('[exception %s]', $exception::class);
        }

        $previous = $exception->getPrevious();

        return array_filter([
            'class' => $exception::class,
            'message' => $this->scrub($this->truncate($exception->getMessage())),
            'code' => $exception->getCode(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $this->scrub($this->truncate($exception->getTraceAsString())),
            'previous' => $previous === null ? null : $this->exceptionShape($previous, $depth + 1),
        ], static fn (mixed $item): bool => $item !== null);
    }

    private function digestOf(mixed $value): string
    {
        if (is_string($value) && str_starts_with($value, self::DIGEST_PREFIX)) {
            // Already hashed by an earlier pass: hashing the digest would produce a
            // different value per channel and break correlation.
            return $value;
        }

        $text = match (true) {
            is_string($value) => $value,
            $value instanceof Stringable => (string) $value,
            default => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '',
        };

        return $this->digest($text);
    }

    private function truncate(string $value): string
    {
        if (strlen($value) <= $this->maxStringLength) {
            return $value;
        }

        return mb_strcut($value, 0, $this->maxStringLength).'…[truncated]';
    }
}
