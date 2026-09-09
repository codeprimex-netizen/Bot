<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Exceptions\Security\KeyUnavailableException;
use App\Services\Audit\AuditService;

/**
 * Master-key rotation, end to end: advance the key, then re-seal everything that was
 * sealed under the old one (Req 32.6 / NFR3; design § Key rotation — "KMS master key
 * rotated on schedule").
 *
 * ## The two halves, and why only one of them is dangerous
 *
 * ```
 *   1. rotateMasterKey()   the key store mints a new version        (nothing re-read)
 *   2. rewrap()            every sealed blob is opened under its    (batched, resumable)
 *                          recorded key and sealed under the new one
 * ```
 *
 * Step 1 is safe because the previous version keeps *opening* what it sealed — that is a
 * contract of both `KmsClient::rotate()` and the operator-managed `master_keys` list. Step
 * 2 is safe because it changes only the seal: DEK bytes, key versions, key statuses and
 * HMAC overlap deadlines are all untouched, so **no ciphertext anywhere becomes
 * undecryptable** and no peer's webhook signature stops verifying. That is the property a
 * scheduled rotation lives or dies by, and it is asserted directly: encrypt, rotate,
 * decrypt the *old* ciphertext.
 *
 * The one genuinely unsafe act is not performed here at all: **removing** a retired master
 * key from the key store. That must wait until a sweep reports `isComplete()`, and it stays
 * an operator decision (`RotateMasterKey` prints exactly what is outstanding).
 *
 * ## Batching, and why the sweep is resumable
 *
 * Every re-wrap is two KMS calls, so a platform with a hundred thousand tenants cannot do
 * this in one transaction or one command run. The sweep takes a bounded batch, reports what
 * is left, and is safe to run again: "sealed under a key that is not the active one" is a
 * predicate, not a cursor, so an interrupted sweep loses nothing and repeats nothing. Rows
 * that fail to open are left untouched and counted rather than retried forever.
 *
 * Both halves are audited on the platform chain (Property 17), with key **ids** and counts
 * only — never material.
 */
final readonly class MasterKeyRewrapper
{
    /**
     * @param  list<RewrapStore>  $stores  every table holding master-key-sealed material
     * @param  int  $batch  rows per store per sweep
     */
    public function __construct(
        private KeyWrapper $wrapper,
        private AuditService $audit,
        private array $stores,
        private int $batch = 100,
    ) {}

    /**
     * The master key new material is sealed under right now.
     *
     * @throws KeyUnavailableException when the key store has none
     */
    public function activeKeyId(): string
    {
        return $this->wrapper->activeKeyId();
    }

    /**
     * Whether the configured wrapper can mint a new master key version itself.
     *
     * False for `ConfigMasterKeyWrapper`, whose material is operator-supplied: rotating it
     * means adding an id to `wa.security.encryption.master_keys` and pointing
     * `master_key_id` at it, after which the re-wrap sweep below does exactly the same work.
     */
    public function canRotateMasterKey(): bool
    {
        return $this->wrapper instanceof MasterKeyRotator;
    }

    /**
     * Ask the key store for a new master key version.
     *
     * @return array{0: string, 1: string} the previous and the new active key id; equal when
     *                                     the store had nothing to advance
     *
     * @throws KeyUnavailableException when the store refuses, or cannot rotate at all
     */
    public function rotateMasterKey(): array
    {
        if (! $this->wrapper instanceof MasterKeyRotator) {
            throw KeyUnavailableException::kmsNotConfigured(sprintf(
                'the configured wrapper [%s] cannot mint master keys; rotate by configuring a new '
                .'wa.security.encryption.master_key_id and keeping the old id in master_keys',
                $this->wrapper::class,
            ));
        }

        $previous = $this->wrapper->activeKeyId();
        $rotated = $this->wrapper->rotateMasterKey();

        if ($rotated !== $previous) {
            $this->audit->writeForPlatform('security.master_key.rotated', [
                'previous_key_id' => $previous,
                'key_id' => $rotated,
                'wrapper' => $this->wrapper::class,
            ]);
        }

        return [$previous, $rotated];
    }

    /**
     * Rows across every store that are not yet sealed under the active master key.
     *
     * @throws KeyUnavailableException
     */
    public function pending(): int
    {
        $activeKeyId = $this->activeKeyId();
        $pending = 0;

        foreach ($this->stores as $store) {
            $pending += $store->pending($activeKeyId);
        }

        return $pending;
    }

    /**
     * Re-seal a bounded batch from every store under the active master key.
     *
     * @param  int|null  $limit  rows per store (default `wa.security.encryption.rotation.batch`)
     *
     * @throws KeyUnavailableException when the active master key cannot be resolved — in
     *                                 which case nothing is touched
     */
    public function rewrap(?int $limit = null): RewrapReport
    {
        // Resolved once, up front: a sweep that re-sealed half its rows under one key and
        // half under another would report a completion that is not true.
        $activeKeyId = $this->activeKeyId();
        $batch = max(1, $limit ?? $this->batch);
        $report = new RewrapReport;

        foreach ($this->stores as $store) {
            $outcome = $store->rewrap($activeKeyId, $batch);

            $report->record(
                $store->label(),
                $outcome['rewrapped'],
                $outcome['failed'],
                // Counted *after* the pass, so `pending` is what is genuinely left rather
                // than what was there when the sweep started.
                $store->pending($activeKeyId),
            );
        }

        if (! $report->isEmpty()) {
            $this->audit->writeForPlatform('security.master_key.rewrapped', [
                'key_id' => $activeKeyId,
            ] + $report->toArray());
        }

        return $report;
    }
}
