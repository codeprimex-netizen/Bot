<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Enums\QuotaKind;

/**
 * What `QuotaGuard::consume()` / `observe()` actually did to a `tenant_usage` bucket
 * (Req 3.5 / A3, Correctness Property 4).
 *
 * The design's signature returns `void`. It cannot stay `void` once consumption is
 * consume-once: a retried send job needs to be able to tell *"I have just counted your
 * message"* from *"this message was already counted — here is what it cost the first
 * time"*, and a void method can only say nothing at all. So the increment reports
 * itself, and `isReplay()` is the difference:
 *
 * ```php
 * $receipt = $quota->consume($tenant, QuotaKind::MessagesMonthly, $message->idempotency_key);
 *
 * if ($receipt->isReplay()) {
 *     // This job already consumed for this message on an earlier attempt. Nothing was
 *     // written now; $receipt describes the original increment.
 * }
 * ```
 *
 * ## `requested` vs `applied`
 *
 * They differ in exactly two situations, and both are worth knowing about:
 *
 * - **a replay** — `applied` is what the *first* call applied, and nothing was written
 *   now;
 * - **a capped consume** — the confirmed units did not all fit under the plan ceiling,
 *   so `applied` is what fitted and `refused()` is the remainder. `QuotaGuard::consume()`
 *   does not push `used` past `limit` unless the plan sells overage (Property 4), and it
 *   reports the difference here rather than swallowing it, so the send pipeline can log
 *   or audit that a confirmed send landed outside the tenant's allowance.
 */
final readonly class QuotaConsumption
{
    /**
     * @param  string|null  $idempotencyKey  the key the consume was deduplicated on; null for a gauge measurement, which needs none
     * @param  int  $requested  units the caller asked to record
     * @param  int  $applied  units this bucket's counter actually moved by
     * @param  int  $used  the counter after the write (or after the original write, on a replay)
     * @param  int|null  $limit  the plan ceiling in force; null = unlimited
     * @param  bool  $replayed  true when an earlier call had already consumed this key
     * @param  bool  $measured  true when this was a gauge recount rather than a spend
     */
    private function __construct(
        public QuotaKind $kind,
        public string $periodKey,
        public ?string $idempotencyKey,
        public int $requested,
        public int $applied,
        public int $used,
        public ?int $limit,
        private bool $replayed,
        private bool $measured,
    ) {}

    /**
     * A first-time consume: `$applied` units were added to the bucket.
     */
    public static function applied(
        QuotaKind $kind,
        string $periodKey,
        string $idempotencyKey,
        int $requested,
        int $applied,
        int $used,
        ?int $limit,
    ): self {
        return new self($kind, $periodKey, $idempotencyKey, $requested, $applied, $used, $limit, false, false);
    }

    /**
     * A duplicate consume: nothing was written, and this describes the original.
     */
    public static function replayed(
        QuotaKind $kind,
        string $periodKey,
        string $idempotencyKey,
        int $requested,
        int $applied,
        int $used,
        ?int $limit,
    ): self {
        return new self($kind, $periodKey, $idempotencyKey, $requested, $applied, $used, $limit, true, false);
    }

    /**
     * A gauge recount: the counter was *set* to an authoritative current count rather
     * than incremented. See `QuotaGuard::observe()` for why gauges are metered that way.
     */
    public static function measured(QuotaKind $kind, string $periodKey, int $count, ?int $limit): self
    {
        return new self($kind, $periodKey, null, $count, $count, $count, $limit, false, true);
    }

    /**
     * Rebuild the receipt of an earlier consume from its ledger entry.
     *
     * @param  array<array-key, mixed>  $result  the `idempotency_keys.result` payload written by the first call
     */
    public static function fromLedger(QuotaKind $kind, string $idempotencyKey, int $requested, array $result): self
    {
        $limit = $result['limit'] ?? null;

        return self::replayed(
            $kind,
            is_string($result['period_key'] ?? null) ? $result['period_key'] : '',
            $idempotencyKey,
            $requested,
            self::intFrom($result['applied'] ?? null),
            self::intFrom($result['used'] ?? null),
            is_numeric($limit) ? (int) $limit : null,
        );
    }

    /**
     * Whether an earlier call had already consumed this idempotency key, so this call
     * wrote nothing.
     */
    public function isReplay(): bool
    {
        return $this->replayed;
    }

    /**
     * Whether this was a gauge recount rather than a spend.
     */
    public function isMeasurement(): bool
    {
        return $this->measured;
    }

    /**
     * Whether the ceiling stopped some of the requested units from being recorded.
     *
     * Never true on a replay — a replay applied nothing *now* by design, which is not
     * the same thing as being capped.
     */
    public function wasCapped(): bool
    {
        return ! $this->replayed && $this->applied < $this->requested;
    }

    /**
     * Units the ceiling refused, i.e. confirmed work that fell outside the allowance.
     */
    public function refused(): int
    {
        return $this->wasCapped() ? $this->requested - $this->applied : 0;
    }

    /**
     * Allowance left after this write — `QuotaVerdict::UNLIMITED` when there is no
     * ceiling.
     */
    public function remaining(): int
    {
        if ($this->limit === null) {
            return QuotaVerdict::UNLIMITED;
        }

        return max(0, $this->limit - $this->used);
    }

    /**
     * The payload recorded in `idempotency_keys.result`, and the thing a replay is
     * rebuilt from.
     *
     * @return array{period_key: string, requested: int, applied: int, used: int, limit: int|null}
     */
    public function toLedger(): array
    {
        return [
            'period_key' => $this->periodKey,
            'requested' => $this->requested,
            'applied' => $this->applied,
            'used' => $this->used,
            'limit' => $this->limit,
        ];
    }

    /**
     * Log / audit shape.
     *
     * @return array{kind: string, period_key: string, idempotency_key: string|null, requested: int, applied: int, used: int, limit: int|null, replayed: bool, measured: bool}
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'period_key' => $this->periodKey,
            'idempotency_key' => $this->idempotencyKey,
            'requested' => $this->requested,
            'applied' => $this->applied,
            'used' => $this->used,
            'limit' => $this->limit,
            'replayed' => $this->replayed,
            'measured' => $this->measured,
        ];
    }

    private static function intFrom(mixed $value): int
    {
        return is_numeric($value) ? max(0, (int) $value) : 0;
    }
}
