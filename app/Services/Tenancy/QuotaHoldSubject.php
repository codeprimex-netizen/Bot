<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * *What* is being parked — the argument `QuotaParkingLot::park()` takes alongside the
 * verdict (Req 20.3 / C3).
 *
 * ```php
 * // task 26.2 — a bulk campaign, parked with its own row as the subject
 * $subject = QuotaHoldSubject::for($campaign, resumer: 'campaign');
 *
 * // work that is not a row: a queued batch identified only by its key
 * $subject = QuotaHoldSubject::named('import:'.$batchId, resumer: 'import', units: 500);
 * ```
 *
 * Two things are decided here and nowhere else:
 *
 * 1. **Identity.** `dedupKey` is what makes one unit of work exactly one hold. Derived
 *    from the model (`{morphClass}:{key}`) when there is one, so the 900 refused jobs of a
 *    1 000-message campaign all park the *same* campaign instead of 900 near-identical
 *    rows. `uniq(tenant_id, dedup_key)` in the schema is the enforcement.
 * 2. **Who hands the work back.** `resumer` names a handler registered in
 *    `wa.tenancy.quota.holds.resumers`. Null means "announce it and stop": the
 *    `TenantQuotaRestored` event *is* the hand-back, which is enough for an owner that
 *    re-reads its own state when the event arrives. Naming a resumer that is not
 *    registered does **not** silently resume — the hold stays parked with the error
 *    recorded, because a hand-back nobody performed is a dropped campaign
 *    (Req 31.1 / NFR2).
 *
 * `units` is how much the parked work still needs, and it is why the re-check is honest:
 * a 400-message batch must not resume onto an allowance with 3 units left.
 */
final readonly class QuotaHoldSubject
{
    /**
     * Cap matching `quota_holds.dedup_key`.
     */
    public const int MAX_DEDUP_KEY_LENGTH = 191;

    /**
     * Cap matching `quota_holds.resumer`.
     */
    public const int MAX_RESUMER_LENGTH = 64;

    /**
     * @param  string  $dedupKey  the unit of work's identity within its tenant
     * @param  Model|null  $holdable  the parked row, when the work is one
     * @param  string|null  $resumer  key of the registered handler that hands the work back
     * @param  array<string, mixed>  $payload  small pointer the resumer cannot re-derive
     * @param  int  $units  how much allowance the parked work still needs
     */
    private function __construct(
        public string $dedupKey,
        public ?Model $holdable,
        public ?string $resumer,
        public array $payload,
        public int $units,
    ) {}

    /**
     * Park a row: a campaign, a sequence enrollment, an import batch.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws InvalidArgumentException when the model has no key yet — an unsaved row has
     *                                  no identity to park
     */
    public static function for(
        Model $work,
        ?string $resumer = null,
        array $payload = [],
        int $units = 1,
    ): self {
        $key = $work->getKey();

        if ($key === null || $key === '') {
            throw new InvalidArgumentException(sprintf(
                'A %s must be saved before it can be parked: an unsaved row has no identity to resume.',
                $work::class,
            ));
        }

        return new self(
            self::normalizeKey($work->getMorphClass().':'.$key),
            $work,
            self::normalizeResumer($resumer),
            $payload,
            max(1, $units),
        );
    }

    /**
     * Park work that is not a row — a queued batch, a scheduled run — under a key the
     * caller guarantees is unique within the tenant.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws InvalidArgumentException on a blank key: without identity, parking twice
     *                                  would accumulate holds and resuming would be
     *                                  ambiguous
     */
    public static function named(
        string $dedupKey,
        ?string $resumer = null,
        array $payload = [],
        int $units = 1,
    ): self {
        return new self(
            self::normalizeKey($dedupKey),
            null,
            self::normalizeResumer($resumer),
            $payload,
            max(1, $units),
        );
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function normalizeKey(string $dedupKey): string
    {
        $key = trim($dedupKey);

        if ($key === '') {
            throw new InvalidArgumentException(
                'Parked work needs a non-empty dedup key: it is what makes one unit of work exactly one hold.',
            );
        }

        return mb_substr($key, 0, self::MAX_DEDUP_KEY_LENGTH);
    }

    private static function normalizeResumer(?string $resumer): ?string
    {
        if ($resumer === null) {
            return null;
        }

        $key = trim($resumer);

        return $key === '' ? null : mb_substr($key, 0, self::MAX_RESUMER_LENGTH);
    }
}
