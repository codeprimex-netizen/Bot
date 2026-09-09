<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Tests\Fixtures\CapturesTenantContextJob;

/*
|--------------------------------------------------------------------------
| Worker-boundary isolation (Req 1.1–1.3 / A1)
|--------------------------------------------------------------------------
| The context is a container singleton and a queue worker is long-lived, so the
| dangerous case is a job silently inheriting the previous job's tenant. These
| tests run real jobs on the `sync` connection, which fires the same
| JobProcessing/JobProcessed/JobExceptionOccurred events a real worker does.
*/

beforeEach(function (): void {
    CapturesTenantContextJob::reset();
});

it('gives each job an empty context instead of the previous job\'s tenant', function (): void {
    $acme = Tenant::factory()->create();
    $globex = Tenant::factory()->create();

    CapturesTenantContextJob::dispatch($acme);
    CapturesTenantContextJob::dispatch($globex);
    CapturesTenantContextJob::dispatch();

    expect(CapturesTenantContextJob::$inherited)->toBe([null, null, null])
        ->and(CapturesTenantContextJob::$bound)->toBe([$acme->id, $globex->id]);
});

it('does not leak a tenant out of a job into the caller', function (): void {
    $acme = Tenant::factory()->create();

    CapturesTenantContextJob::dispatch($acme);

    expect(app(TenantContext::class)->current())->toBeNull();
});

it('restores the caller\'s own tenant after dispatching a job inline', function (): void {
    $caller = Tenant::factory()->create();
    $inJob = Tenant::factory()->create();
    $context = app(TenantContext::class);
    $context->set($caller);

    CapturesTenantContextJob::dispatch($inJob);

    expect(CapturesTenantContextJob::$inherited)->toBe([null])
        ->and($context->currentId())->toBe($caller->id);
});

it('restores the caller\'s context even when the job throws', function (): void {
    $caller = Tenant::factory()->create();
    $inJob = Tenant::factory()->create();
    $context = app(TenantContext::class);
    $context->set($caller);

    expect(fn () => CapturesTenantContextJob::dispatch($inJob, true))->toThrow(RuntimeException::class);

    expect($context->currentId())->toBe($caller->id);
});

it('keeps a platform-mode caller in platform mode across a job dispatch', function (): void {
    $inJob = Tenant::factory()->create();
    $context = app(TenantContext::class);
    $context->enterPlatformMode('admin: backfill');

    CapturesTenantContextJob::dispatch($inJob);

    expect(CapturesTenantContextJob::$inherited)->toBe([null])
        ->and(CapturesTenantContextJob::$bound)->toBe([$inJob->id])
        ->and($context->actingAsPlatform())->toBeTrue();

    $context->exitPlatformMode();
});
