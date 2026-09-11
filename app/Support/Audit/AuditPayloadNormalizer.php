<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Exceptions\Audit\AuditPayloadException;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use JsonSerializable;
use Stringable;
use UnitEnum;

/**
 * Turns whatever a caller passed into the flat, JSON-representable shape the
 * canonical serializer can hash (Req 24.5 / D1).
 *
 * Two jobs, and both of them exist so that an audit write **cannot be made to
 * fail by its own input**:
 *
 * 1. **Shape.** Enums become their values, dates become UTC ISO-8601 strings,
 *    models become `{type, id}`, arrayables/`JsonSerializable` are unwrapped.
 *    Anything genuinely unrepresentable (a resource, a closure) is a programming
 *    error at the call site and is refused loudly.
 * 2. **Robustness.** Invalid UTF-8 is replaced by a hash marker, over-long strings
 *    are truncated, over-wide arrays are capped, and over-deep structures are cut
 *    off. None of these throw. That is deliberate: audit rows record what
 *    (partly untrusted) actors did, so if malformed input could abort the write, an
 *    attacker would have a way to **suppress their own audit trail** — a far worse
 *    outcome than a truncated payload. The caps also keep one row from turning into
 *    a megabyte of JSON.
 *
 * Truncation is always *marked* (`…[truncated …]`, `[capped …]`), so a reader can
 * see that the payload was reduced rather than silently believing it is complete.
 */
final class AuditPayloadNormalizer
{
    /**
     * Marker prefix for a value that had to be replaced rather than kept.
     */
    public const string REPLACED_PREFIX = '[audit:';

    public function __construct(
        private readonly int $maxDepth = 6,
        private readonly int $maxStringLength = 2000,
        private readonly int $maxArrayItems = 100,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function normalize(mixed $payload): array
    {
        $normalized = $this->value($payload, '', 0);

        if (! is_array($normalized)) {
            // A scalar payload is still auditable: it is recorded under a stable key
            // rather than rejected, so no caller has to wrap trivial values by hand.
            return ['value' => $normalized];
        }

        /** @var array<string, mixed> $keyed */
        $keyed = array_is_list($normalized) ? ['items' => $normalized] : $normalized;

        return $keyed;
    }

    private function value(mixed $value, string $path, int $depth): mixed
    {
        return match (true) {
            $value === null, is_bool($value), is_int($value) => $value,
            is_float($value) => $this->float($value),
            is_string($value) => $this->string($value),
            is_array($value) => $this->array($value, $path, $depth),
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            $value instanceof DateTimeInterface => $value->format(DateTimeInterface::ATOM),
            $value instanceof Model => $this->model($value),
            $value instanceof Arrayable => $this->array($value->toArray(), $path, $depth),
            $value instanceof JsonSerializable => $this->value($value->jsonSerialize(), $path, $depth),
            $value instanceof Stringable => $this->string((string) $value),
            default => throw AuditPayloadException::unsupportedType(get_debug_type($value), $path),
        };
    }

    /**
     * NAN/INF carry no audit meaning and cannot be hashed, so they are recorded as
     * what they were rather than aborting the entry.
     */
    private function float(float $value): float|string
    {
        if (is_nan($value)) {
            return self::REPLACED_PREFIX.'nan]';
        }

        if (is_infinite($value)) {
            return self::REPLACED_PREFIX.($value > 0 ? 'inf]' : '-inf]');
        }

        return $value;
    }

    private function string(string $value): string
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            // Binary never belongs in an audit payload, and it cannot be stored as
            // JSON — but its identity can still be recorded.
            return sprintf('%sbinary sha256:%s bytes:%d]', self::REPLACED_PREFIX, hash('sha256', $value), strlen($value));
        }

        if (mb_strlen($value) <= $this->maxStringLength) {
            return $value;
        }

        return mb_substr($value, 0, $this->maxStringLength)
            .sprintf('…%struncated from %d chars]', self::REPLACED_PREFIX, mb_strlen($value));
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private function array(array $value, string $path, int $depth): array
    {
        if ($depth >= $this->maxDepth) {
            return [self::REPLACED_PREFIX.'depth limit] '.sprintf('%d keys', count($value))];
        }

        $normalized = [];
        $seen = 0;

        foreach ($value as $key => $item) {
            if ($seen >= $this->maxArrayItems) {
                $normalized[self::REPLACED_PREFIX.'capped]'] = sprintf('%d more items', count($value) - $seen);

                break;
            }

            $childPath = $path === '' ? (string) $key : $path.'.'.$key;
            $normalized[$key] = $this->value($item, $childPath, $depth + 1);
            $seen++;
        }

        return $normalized;
    }

    /**
     * A model is recorded as an identity, never as a row of attributes: attributes
     * are exactly where message bodies and phone numbers live.
     *
     * @return array<string, mixed>
     */
    private function model(Model $model): array
    {
        $key = $model->getKey();

        return [
            'type' => $model::class,
            'id' => is_int($key) || is_string($key) ? $key : null,
        ];
    }
}
