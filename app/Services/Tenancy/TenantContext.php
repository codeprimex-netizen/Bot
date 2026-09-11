<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Enums\TenantResolutionSource;
use App\Models\Tenant;

/**
 * The single source of truth for "which tenant is this code running for?".
 *
 * Every tenant-owned read and write funnels through here: the global Eloquent
 * scope (`BelongsToTenant`, task 0.3), storage path namespacing (task 0.5), and
 * the plan/quota gates all ask this object rather than reading the request.
 *
 * Bound as a **singleton per request / per queued job**. The container instance
 * is deliberately mutable, but its lifetime is a single unit of work: the
 * `ResolveTenant` middleware populates it at the start of a request and clears
 * it on terminate, and the queue worker isolates and restores it around every
 * job so a tenant can never bleed from one job into the next.
 *
 * Two mutually exclusive modes exist:
 *
 * - **tenant mode** — `current()` returns a tenant; the global scope constrains
 *   every query to it.
 * - **platform mode** — `actingAsPlatform()` is true and `current()` is `null`;
 *   the tenant scope is bypassed. This is the *only* sanctioned bypass
 *   (Req 1.5 / A1) and it is announced on the event bus so the audit trail can
 *   record it.
 */
interface TenantContext
{
    /*
    |--------------------------------------------------------------------------
    | Design contract (design.md § Components and Interfaces → Tenancy layer)
    |--------------------------------------------------------------------------
    */

    /**
     * The tenant this unit of work belongs to.
     *
     * `null` means "no tenant bound": platform-admin/global context, an
     * unauthenticated public request, the console, or a job that has not bound
     * one yet.
     */
    public function current(): ?Tenant;

    /**
     * Bind a tenant to this unit of work.
     *
     * @param  TenantResolutionSource  $source  how the tenant was identified (defaults to code-initiated)
     *
     * @throws \LogicException when platform mode is active — use `runFor()` to read one tenant from platform mode
     */
    public function set(Tenant $tenant, TenantResolutionSource $source = TenantResolutionSource::Manual): void;

    /**
     * Whether the caller is running in the audited platform-admin mode that
     * bypasses the tenant global scope (Req 1.5 / A1).
     */
    public function actingAsPlatform(): bool;

    /**
     * Clear the context: no tenant, no platform mode.
     *
     * Called at request and job boundaries and between tests. If platform mode
     * was open it is closed first, so the audit trail always sees a matching
     * exit for every entry.
     */
    public function forget(): void;

    /*
    |--------------------------------------------------------------------------
    | Extensions
    |--------------------------------------------------------------------------
    */

    /**
     * The bound tenant's id without loading `current()` at the call site.
     */
    public function currentId(): ?string;

    /**
     * Whether a tenant is bound right now.
     */
    public function hasTenant(): bool;

    /**
     * Which door the bound tenant came through.
     */
    public function resolvedVia(): TenantResolutionSource;

    /**
     * Open platform mode: the tenant scope is bypassed until the matching
     * `exitPlatformMode()` (calls nest).
     *
     * Prefer `asPlatform()` — it cannot be left open by an exception.
     *
     * @param  string  $reason  non-empty, human-readable, and recorded for audit
     *
     * @throws \InvalidArgumentException on an empty reason
     */
    public function enterPlatformMode(string $reason): void;

    /**
     * Close the innermost platform-mode frame.
     *
     * @throws \LogicException when no frame is open
     */
    public function exitPlatformMode(): void;

    /**
     * Run a callback in platform mode, restoring the previous context
     * afterwards even if the callback throws.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function asPlatform(string $reason, callable $callback): mixed;

    /**
     * Run a callback bound to `$tenant`, restoring the previous context
     * afterwards even if the callback throws.
     *
     * Used by queued jobs, schedulers, and platform-admin cross-tenant reads.
     * Inside the callback platform mode is suspended, so the tenant scope
     * applies normally and the read really is limited to `$tenant`.
     *
     * @template TReturn
     *
     * @param  callable(Tenant): TReturn  $callback
     * @return TReturn
     */
    public function runFor(Tenant $tenant, callable $callback): mixed;

    /**
     * Capture the current context so it can be put back later.
     */
    public function snapshot(): TenantContextSnapshot;

    /**
     * Replace the current context with a previously captured one.
     */
    public function restore(TenantContextSnapshot $snapshot): void;

    /**
     * Stash the context under `$key` and start from empty — the worker
     * boundary for one queued job.
     *
     * Suspension is not an audited platform-mode exit: no platform event is
     * emitted, because `release()` will put the same frames back.
     */
    public function isolate(string $key): void;

    /**
     * Put back the context stashed under `$key`. Unknown keys are a no-op, so a
     * job that both throws and fails cannot double-restore.
     */
    public function release(string $key): void;
}
