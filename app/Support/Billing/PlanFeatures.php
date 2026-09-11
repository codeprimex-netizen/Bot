<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Exceptions\Billing\MalformedPlanException;
use InvalidArgumentException;

/**
 * The validated `plans.features` map: which feature flags a plan includes
 * (Req 25.1 / D2).
 *
 * `PlanGate` (task 2.2) and every feature-gated screen ask this object rather
 * than reading the JSON column, so the shape is checked in exactly one place.
 *
 * ## Shape
 *
 * A JSON **object** of `"feature": true|false` pairs:
 *
 * ```json
 * { "ai": true, "campaigns": true, "white_label": false }
 * ```
 *
 * Feature keys are free-form strings (snake_case by convention) rather than an
 * enum: each later phase introduces its own gate keys, and a closed enum would
 * force every phase to edit a shared file. Anything that is *not* the shape above
 * is a `MalformedPlanException`, never a lenient guess:
 *
 * | Input                    | Result                                            |
 * |--------------------------|---------------------------------------------------|
 * | `{}`                     | valid — a plan with no features                    |
 * | `{"ai": true}`           | valid                                              |
 * | `["ai"]`                 | **malformed** — a list would read as `0 => "ai"`   |
 * | `{"ai": 1}`              | **malformed** — truthy-but-not-bool is ambiguous   |
 * | `{" ai": true}`          | **malformed** — a padded key never matches a gate  |
 * | `null` / `"{}"` (string) | **malformed** — the column is a non-null JSON map  |
 *
 * ## Absence is denial
 *
 * A key that is not in the map is `false`. Adding a new gate key in a later phase
 * therefore leaves existing plans *without* the feature until an admin grants it —
 * the safe direction, and the same fail-closed posture as `TenantScope`.
 */
final class PlanFeatures
{
    /**
     * @param  array<string, bool>  $flags
     */
    private function __construct(private readonly array $flags) {}

    /**
     * A plan with no feature flags at all.
     */
    public static function none(): self
    {
        return new self([]);
    }

    /**
     * Validate a decoded `plans.features` value into a usable map.
     *
     * @param  mixed  $raw  the decoded JSON column, straight from the database
     * @param  string  $context  which plan is being read, for the error message
     *
     * @throws MalformedPlanException
     */
    public static function fromRaw(mixed $raw, string $context = 'plan'): self
    {
        if ($raw === null) {
            throw MalformedPlanException::features($context, 'the column is null — write `{}` for a plan with no features');
        }

        if (! is_array($raw)) {
            throw MalformedPlanException::features($context, sprintf('expected a JSON object, got %s', get_debug_type($raw)));
        }

        $flags = [];

        foreach ($raw as $feature => $enabled) {
            // PHP casts numeric JSON-object keys to int, so this also catches a JSON
            // *array* of feature names — which would otherwise silently read as the
            // features "0", "1", ... and gate nothing.
            if (! is_string($feature)) {
                throw MalformedPlanException::features($context, sprintf(
                    'key %s is not a string — a JSON array of feature names is not accepted, use {"feature": true}',
                    json_encode($feature),
                ));
            }

            if ($feature === '' || trim($feature) !== $feature) {
                throw MalformedPlanException::features($context, sprintf(
                    'key %s must be non-empty and free of surrounding whitespace, or it can never match a gate key',
                    json_encode($feature),
                ));
            }

            if (! is_bool($enabled)) {
                throw MalformedPlanException::features($context, sprintf(
                    'feature "%s" is %s, but only true or false are accepted',
                    $feature,
                    get_debug_type($enabled),
                ));
            }

            $flags[$feature] = $enabled;
        }

        return new self($flags);
    }

    /**
     * Whether the plan includes $feature — false when the flag is absent.
     *
     * @throws InvalidArgumentException when asked about an empty feature key, which
     *                                  is a caller bug rather than plan data
     */
    public function allows(string $feature): bool
    {
        $feature = trim($feature);

        if ($feature === '') {
            throw new InvalidArgumentException('A plan feature key cannot be empty.');
        }

        return $this->flags[$feature] ?? false;
    }

    /**
     * The whole map, including explicitly disabled flags.
     *
     * @return array<string, bool>
     */
    public function all(): array
    {
        return $this->flags;
    }

    /**
     * Just the granted feature keys — what an admin screen or plan comparison
     * table lists.
     *
     * @return list<string>
     */
    public function enabled(): array
    {
        return array_keys(array_filter($this->flags));
    }

    public function isEmpty(): bool
    {
        return $this->flags === [];
    }
}
