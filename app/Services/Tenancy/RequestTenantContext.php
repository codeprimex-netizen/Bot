<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Enums\TenantResolutionSource;
use App\Events\Tenancy\PlatformModeEntered;
use App\Events\Tenancy\PlatformModeExited;
use App\Models\Tenant;
use Illuminate\Contracts\Events\Dispatcher;
use InvalidArgumentException;
use LogicException;

/**
 * In-memory `TenantContext` scoped to one unit of work.
 *
 * Registered as a container singleton by `TenancyServiceProvider`, which also
 * isolates it around every queued job — so "singleton" means *per request /
 * per job*, never "shared across jobs".
 *
 * Platform mode is a stack rather than a flag so nested admin operations
 * (a Livewire action inside an already-platform-mode request) can't be closed
 * early by an inner `exitPlatformMode()`.
 */
final class RequestTenantContext implements TenantContext
{
    private ?Tenant $tenant = null;

    private TenantResolutionSource $source = TenantResolutionSource::None;

    /**
     * Open platform-mode frames, outermost first.
     *
     * @var list<array{reason: string, startedAt: float}>
     */
    private array $platformFrames = [];

    /**
     * Contexts stashed at a worker boundary, keyed by job identity.
     *
     * @var array<string, TenantContextSnapshot>
     */
    private array $isolated = [];

    public function __construct(private readonly Dispatcher $events) {}

    public function current(): ?Tenant
    {
        // Platform mode is *global* by definition: there is no acting tenant,
        // and task 0.3's scope reads that as "do not constrain".
        return $this->actingAsPlatform() ? null : $this->tenant;
    }

    public function currentId(): ?string
    {
        return $this->current()?->id;
    }

    public function hasTenant(): bool
    {
        return $this->current() !== null;
    }

    public function set(Tenant $tenant, TenantResolutionSource $source = TenantResolutionSource::Manual): void
    {
        if ($this->actingAsPlatform()) {
            throw new LogicException(
                'Cannot bind a tenant while acting as platform: use TenantContext::runFor() to scope a read to one tenant.'
            );
        }

        $this->tenant = $tenant;
        $this->source = $source;
    }

    public function resolvedVia(): TenantResolutionSource
    {
        return $this->actingAsPlatform() ? TenantResolutionSource::None : $this->source;
    }

    public function actingAsPlatform(): bool
    {
        return $this->platformFrames !== [];
    }

    public function enterPlatformMode(string $reason): void
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('Entering platform mode requires a non-empty reason (it is audited).');
        }

        $tenantIdBefore = $this->tenant?->id;
        $this->platformFrames[] = ['reason' => $reason, 'startedAt' => microtime(true)];

        $this->events->dispatch(new PlatformModeEntered($reason, $tenantIdBefore, count($this->platformFrames)));
    }

    public function exitPlatformMode(): void
    {
        if ($this->platformFrames === []) {
            throw new LogicException('exitPlatformMode() called while not acting as platform.');
        }

        $this->closeInnermostFrame(forced: false);
    }

    public function asPlatform(string $reason, callable $callback): mixed
    {
        $snapshot = $this->snapshot();
        $this->enterPlatformMode($reason);

        try {
            return $callback();
        } finally {
            // Anything the callback left open is closed as forced...
            while (count($this->platformFrames) > count($snapshot->platformFrames) + 1) {
                $this->closeInnermostFrame(forced: true);
            }

            // ...and the frame this call opened closes as a normal exit.
            if (count($this->platformFrames) > count($snapshot->platformFrames)) {
                $this->closeInnermostFrame(forced: false);
            }

            $this->restore($snapshot);
        }
    }

    public function runFor(Tenant $tenant, callable $callback): mixed
    {
        $snapshot = $this->snapshot();

        // Suspend platform mode: the point of runFor() is that the scope really
        // does apply to this one tenant, rather than being bypassed.
        $this->platformFrames = [];
        $this->tenant = $tenant;
        $this->source = TenantResolutionSource::Manual;

        try {
            return $callback($tenant);
        } finally {
            $this->restore($snapshot);
        }
    }

    public function forget(): void
    {
        while ($this->platformFrames !== []) {
            $this->closeInnermostFrame(forced: true);
        }

        $this->clearState();
    }

    public function snapshot(): TenantContextSnapshot
    {
        return new TenantContextSnapshot($this->tenant, $this->source, $this->platformFrames);
    }

    public function restore(TenantContextSnapshot $snapshot): void
    {
        $this->tenant = $snapshot->tenant;
        $this->source = $snapshot->source;
        $this->platformFrames = $snapshot->platformFrames;
    }

    public function isolate(string $key): void
    {
        $this->isolated[$key] = $this->snapshot();

        // Silent clear: a suspended frame is not an audited exit, because
        // release() puts the very same frames back.
        $this->clearState();
    }

    public function release(string $key): void
    {
        $snapshot = $this->isolated[$key] ?? null;
        unset($this->isolated[$key]);

        if ($snapshot instanceof TenantContextSnapshot) {
            $this->restore($snapshot);
        }
    }

    /**
     * Pop the innermost platform frame and announce it for the audit trail.
     */
    private function closeInnermostFrame(bool $forced): void
    {
        $frame = array_pop($this->platformFrames);

        if ($frame === null) {
            return;
        }

        $this->events->dispatch(new PlatformModeExited(
            $frame['reason'],
            round((microtime(true) - $frame['startedAt']) * 1000, 3),
            $forced,
        ));
    }

    private function clearState(): void
    {
        $this->tenant = null;
        $this->source = TenantResolutionSource::None;
        $this->platformFrames = [];
    }
}
