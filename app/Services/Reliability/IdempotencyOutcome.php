<?php

declare(strict_types=1);

namespace App\Services\Reliability;

use App\Enums\IdempotencyMode;

/**
 * What `IdempotencyStore::once()` actually did (Req 31.2 / NFR2).
 *
 * The design's signature returns `mixed`. It cannot usefully stay `mixed`, for the same
 * reason `QuotaGuard::consume()` could not stay `void`: a caller that gets `['status' =>
 * 'captured']` back has no way to tell *"I have just captured this payment"* from *"this
 * payment was captured on an earlier delivery — here is what the first caller answered"*,
 * and those two demand different behaviour almost everywhere. A webhook replies 200 to
 * both but must not re-notify on the second; a saga step advances on the first and skips
 * on the second; an operator log wants to know which happened.
 *
 * ```php
 * $outcome = $store->once("gateway:{$provider}", $event->id, fn () => $this->capture($event));
 *
 * if ($outcome->isReplay()) {
 *     // Nothing ran now. $outcome->value is the original caller's answer, read back
 *     // from idempotency_keys.result.
 * }
 * ```
 *
 * ## What `value` is, exactly
 *
 * On a fresh run it is the operation's own return value, untouched. On a replay it is that
 * value **round-tripped through JSON**, because that is what the ledger stores — so an
 * `array` comes back as an `array`, a scalar as the same scalar, and an object never comes
 * back at all (returning one is refused up front with `UnrecordableResultException`). A
 * caller that wants a typed object rebuilds it from the data, exactly as
 * `QuotaConsumption::fromLedger()` does.
 */
final readonly class IdempotencyOutcome
{
    /**
     * @param  string  $scope  the dedup namespace the key lives in
     * @param  string  $key  the key the operation was deduplicated on
     * @param  mixed  $value  the operation's return value — fresh, or replayed from `idempotency_keys.result`
     * @param  IdempotencyMode  $mode  how the key was claimed
     * @param  bool  $replayed  true when an earlier caller had already completed this key, so nothing ran now
     */
    private function __construct(
        public string $scope,
        public string $key,
        public mixed $value,
        public IdempotencyMode $mode,
        private bool $replayed,
    ) {}

    /**
     * This call claimed the key and ran the operation.
     */
    public static function executed(string $scope, string $key, mixed $value, IdempotencyMode $mode): self
    {
        return new self($scope, $key, $value, $mode, false);
    }

    /**
     * This call found the key already completed; `$value` is the first caller's recorded
     * result and the operation did not run.
     */
    public static function replayed(string $scope, string $key, mixed $value, IdempotencyMode $mode): self
    {
        return new self($scope, $key, $value, $mode, true);
    }

    /**
     * Whether the operation had already been performed, so this call ran nothing.
     */
    public function isReplay(): bool
    {
        return $this->replayed;
    }

    /**
     * Whether this call is the one that performed the operation.
     */
    public function wasExecuted(): bool
    {
        return ! $this->replayed;
    }

    /**
     * The recorded result as an array — `[]` for anything that was not an array.
     *
     * A convenience for the common case of an operation that returns a payload, so callers
     * do not each have to re-narrow `mixed` for static analysis.
     *
     * @return array<array-key, mixed>
     */
    public function payload(): array
    {
        return is_array($this->value) ? $this->value : [];
    }

    /**
     * Log / audit shape. The value itself is **not** included: it is arbitrary caller data
     * (a captured amount, a customer reference) and this shape is meant to be safe to log.
     *
     * @return array{scope: string, key: string, mode: string, replayed: bool}
     */
    public function toArray(): array
    {
        return [
            'scope' => $this->scope,
            'key' => $this->key,
            'mode' => $this->mode->value,
            'replayed' => $this->replayed,
        ];
    }
}
