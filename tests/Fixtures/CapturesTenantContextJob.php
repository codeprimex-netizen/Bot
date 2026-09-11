<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;

/**
 * Test double standing in for a real queued job, so the worker-boundary
 * behaviour of `TenantContext` can be asserted against the actual queue events
 * instead of a hand-rolled simulation.
 *
 * Records the tenant it *inherited* (which must always be none), then optionally
 * binds its own tenant the way production jobs do.
 */
final class CapturesTenantContextJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Tenant ids observed on entry to each execution, in order.
     *
     * @var list<string|null>
     */
    public static array $inherited = [];

    /**
     * Tenant ids observed after the job bound its own tenant.
     *
     * @var list<string|null>
     */
    public static array $bound = [];

    public function __construct(
        private readonly ?Tenant $tenant = null,
        private readonly bool $fail = false,
    ) {}

    public static function reset(): void
    {
        self::$inherited = [];
        self::$bound = [];
    }

    public function handle(TenantContext $context): void
    {
        self::$inherited[] = $context->currentId();

        if ($this->tenant instanceof Tenant) {
            $context->set($this->tenant);
            self::$bound[] = $context->currentId();
        }

        if ($this->fail) {
            throw new RuntimeException('job blew up while holding a tenant');
        }
    }
}
