<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Models\QuotaHold;
use App\Services\Tenancy\QuotaResumer;
use RuntimeException;

/**
 * A `QuotaResumer` that records what it was asked to hand back — the stand-in for task
 * 26.2's `CampaignQuotaResumer` (Req 20.3 / C3).
 *
 * ```php
 * $resumer = QuotaHolds::registerResumer('campaign');
 * // ...sweep...
 * expect($resumer->resumed)->toBe([$hold->id]);
 * ```
 *
 * `$failWith` makes it throw, which is how the tests pin the guarantee that matters most:
 * a hand-back that fails leaves the work **parked and retried**, never closed.
 */
final class RecordingQuotaResumer implements QuotaResumer
{
    /**
     * Hold ids handed to `resume()`, in order — including repeats, so a test can prove the
     * sweep is idempotent rather than merely eventually correct.
     *
     * @var list<string>
     */
    public array $resumed = [];

    /**
     * The tenant bound to the context on each call: a resumer must always run inside its
     * hold's tenant, never platform-wide.
     *
     * @var list<string|null>
     */
    public array $tenantIds = [];

    public function __construct(public ?string $failWith = null) {}

    public function resume(QuotaHold $hold): void
    {
        $this->resumed[] = $hold->id;
        $this->tenantIds[] = app(\App\Services\Tenancy\TenantContext::class)->currentId();

        if ($this->failWith !== null) {
            throw new RuntimeException($this->failWith);
        }
    }

    public function calls(): int
    {
        return count($this->resumed);
    }
}
