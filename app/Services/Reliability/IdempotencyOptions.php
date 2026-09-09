<?php

declare(strict_types=1);

namespace App\Services\Reliability;

use App\Enums\IdempotencyMode;
use App\Models\IdempotencyKey;
use App\Models\Tenant;
use InvalidArgumentException;

/**
 * The per-call knobs of `IdempotencyStore::once()` (Req 31.2 / NFR2).
 *
 * The design's signature is `once(scope, key, op)`, and that is still the whole API for
 * the common case — the default options are the safe ones. This object exists because the
 * two callers that already dedup on `uniq(scope, key)` need *different* claim mechanics,
 * and neither may be weakened to fit one hard-coded policy:
 *
 * ```php
 * // A saga step / a webhook fire: claim a lease, run outside any transaction (default).
 * $store->once("saga:{$saga->id}", $saga->stepKey($step), fn () => $this->fire($step));
 *
 * // QuotaGuard: ledger row and counter increment must commit together, and a lease
 * // would only add a way for a crashed worker to block a counter.
 * $store->once($scope, $key, fn () => $this->apply(...), IdempotencyOptions::transactional());
 *
 * // QuotaNotifier: tell the tenant once per quota per period; a duplicate is worse
 * // than a miss, so the key is burned before the notice is dispatched.
 * $store->once($scope, $key, fn () => $payload, IdempotencyOptions::atMostOnce()->keptFor(45));
 *
 * // A client-supplied key, checked against the body it was first used with.
 * $store->once('api:orders', $header, $op, IdempotencyOptions::default()->matching($request->all()));
 * ```
 *
 * Every wither returns a new instance, so a configured set of options is safe to share
 * (a service can hold one as a field and derive per-call variants from it).
 */
final readonly class IdempotencyOptions
{
    /**
     * `retentionDays` value that means "no horizon": `expires_at` is left null and the
     * pruner will never delete the row.
     *
     * Use it only where the key must outlive any conceivable retry — an offboarding step,
     * a one-off migration. Every retained row is a row somebody has to store for ever, and
     * `scopePrunable()` cannot help.
     */
    public const int KEEP_FOREVER = 0;

    /**
     * @param  IdempotencyMode  $mode  how the key is claimed; see the enum's table
     * @param  array<array-key, mixed>|string|null  $request  the request this key is being used for, fingerprinted so a reuse with a different payload is refused; null opts out of the check
     * @param  string|null  $tenantId  tenant to attribute the row to; null means "ask the tenant context", which is itself null during pre-resolution webhook intake
     * @param  int|null  $retentionDays  how long the row is kept; null = configured default, `KEEP_FOREVER` = never pruned
     * @param  int|null  $staleSeconds  how long an `IN_FLIGHT` lease is honoured; null = `IdempotencyKey::STALE_LOCK_SECONDS`
     * @param  int|null  $waitMilliseconds  how long to wait for a live holder before refusing with 409; null = configured default, 0 = fail fast
     * @param  bool  $attributeToContext  whether a null `$tenantId` should be filled from the tenant context at all
     */
    public function __construct(
        public IdempotencyMode $mode = IdempotencyMode::Lease,
        public array|string|null $request = null,
        public ?string $tenantId = null,
        public ?int $retentionDays = null,
        public ?int $staleSeconds = null,
        public ?int $waitMilliseconds = null,
        public bool $attributeToContext = true,
    ) {
        if ($retentionDays !== null && $retentionDays < 0) {
            throw new InvalidArgumentException(sprintf(
                'Idempotency retention days cannot be negative, got %d. Use IdempotencyOptions::KEEP_FOREVER (0) for no horizon.',
                $retentionDays,
            ));
        }

        if ($staleSeconds !== null && $staleSeconds < 1) {
            throw new InvalidArgumentException(sprintf(
                'An idempotency lease must be honoured for at least a second, got %d: a zero window would let two '
                .'callers run the same operation concurrently.',
                $staleSeconds,
            ));
        }

        if ($waitMilliseconds !== null && $waitMilliseconds < 0) {
            throw new InvalidArgumentException(sprintf('An idempotency wait budget cannot be negative, got %d.', $waitMilliseconds));
        }
    }

    /**
     * The safe default: an `IN_FLIGHT` lease, the operation run outside any transaction.
     */
    public static function default(): self
    {
        return new self;
    }

    /**
     * Claim, run and settle in one transaction — for operations that are purely database
     * writes (`IdempotencyMode::Transactional`).
     */
    public static function transactional(): self
    {
        return new self(mode: IdempotencyMode::Transactional);
    }

    /**
     * Burn the key before the operation runs, so a failure is never retried
     * (`IdempotencyMode::AtMostOnce`).
     */
    public static function atMostOnce(): self
    {
        return new self(mode: IdempotencyMode::AtMostOnce);
    }

    public function using(IdempotencyMode $mode): self
    {
        return $this->copy(mode: $mode);
    }

    /**
     * Bind this key to the request it is being used for.
     *
     * The payload is fingerprinted (`IdempotencyKey::fingerprint()`, recursively
     * key-sorted), so a re-serialised retry of the *same* request still matches while a
     * genuinely different payload is refused with `IdempotencyKeyReuseException`.
     *
     * @param  array<array-key, mixed>|string  $request
     */
    public function matching(array|string $request): self
    {
        return $this->copy(request: $request);
    }

    /**
     * Attribute the row to a tenant explicitly.
     *
     * Note what this does *not* do: attribution is for debugging, cost and cascade — it is
     * **not** isolation. `idempotency_keys` is deliberately not tenant-scoped, so a key
     * that could collide across tenants must carry the tenant id in its **scope**, exactly
     * as `QuotaGuard::idempotencyScope()` and `QuotaNotifier::noticeScope()` do.
     */
    public function forTenant(Tenant|string|null $tenant): self
    {
        return $this->copy(
            tenantId: $tenant instanceof Tenant ? $tenant->id : $tenant,
            attributeToContext: false,
        );
    }

    /**
     * Record the row with no tenant at all — pre-resolution webhook intake, where the
     * dedup happens *before* the lookup that decides whose event it is.
     */
    public function withoutTenant(): self
    {
        return $this->copy(tenantId: null, attributeToContext: false);
    }

    /**
     * How long the row is kept. `KEEP_FOREVER` (0) leaves `expires_at` null.
     *
     * Must comfortably outlive the longest retry window of the guarded operation: once the
     * row is pruned, the next retry is indistinguishable from a first attempt and the side
     * effect happens again.
     */
    public function keptFor(?int $days): self
    {
        return $this->copy(retentionDays: $days, retentionGiven: true);
    }

    /**
     * Never prune this row.
     */
    public function keptForever(): self
    {
        return $this->copy(retentionDays: self::KEEP_FOREVER, retentionGiven: true);
    }

    /**
     * How long an `IN_FLIGHT` lease is honoured before its holder is presumed dead.
     */
    public function leasedFor(?int $seconds): self
    {
        return $this->copy(staleSeconds: $seconds, staleGiven: true);
    }

    /**
     * How long to wait for a live holder to finish before refusing with 409.
     */
    public function waitingFor(int $milliseconds): self
    {
        return $this->copy(waitMilliseconds: $milliseconds);
    }

    /**
     * Do not wait for a live holder at all — refuse immediately.
     *
     * The right choice inside a queue worker: the job's own backoff is a better place to
     * wait than a blocked worker slot, and the work is released rather than dropped.
     */
    public function failingFast(): self
    {
        return $this->copy(waitMilliseconds: 0);
    }

    /**
     * The fingerprint of the bound request, or null when the caller opted out of request
     * checking.
     */
    public function requestFingerprint(): ?string
    {
        return $this->request === null ? null : IdempotencyKey::fingerprint($this->request);
    }

    /**
     * Whether `expires_at` should be left null.
     */
    public function keepsForever(): bool
    {
        return $this->retentionDays === self::KEEP_FOREVER;
    }

    /**
     * Clone with overrides.
     *
     * `null` is a meaningful *value* for four of these fields (it means "use the
     * configured default", or "no tenant"), so a plain `?? $this->field` would make
     * `keptFor(null)` and `withoutTenant()` silently no-ops. The two nullable-with-meaning
     * fields therefore carry an explicit `…Given` flag, and tenant attribution is decided
     * by `attributeToContext` rather than by whether the id is null.
     *
     * @param  array<array-key, mixed>|string|null  $request
     */
    private function copy(
        ?IdempotencyMode $mode = null,
        array|string|null $request = null,
        ?string $tenantId = null,
        ?int $retentionDays = null,
        bool $retentionGiven = false,
        ?int $staleSeconds = null,
        bool $staleGiven = false,
        ?int $waitMilliseconds = null,
        ?bool $attributeToContext = null,
    ): self {
        return new self(
            mode: $mode ?? $this->mode,
            request: $request ?? $this->request,
            tenantId: $tenantId ?? ($attributeToContext === false ? null : $this->tenantId),
            retentionDays: $retentionGiven ? $retentionDays : $this->retentionDays,
            staleSeconds: $staleGiven ? $staleSeconds : $this->staleSeconds,
            waitMilliseconds: $waitMilliseconds ?? $this->waitMilliseconds,
            attributeToContext: $attributeToContext ?? $this->attributeToContext,
        );
    }
}
