<?php

declare(strict_types=1);

use App\Enums\CircuitScope;
use App\Enums\ErrorClass;
use App\Enums\QuotaKind;
use App\Enums\TenantTier;
use App\Services\Dispatch\Eligibility\QuotaDispatchEligibility;
use App\Services\Reliability\HttpOutboxTransport;
use App\Services\Security\ConfigMasterKeyWrapper;
use App\Services\Tenancy\Provisioning\Steps\AssignOwnerMembershipStep;
use App\Services\Tenancy\Provisioning\Steps\AssignSeedPlanStep;
use App\Services\Tenancy\Provisioning\Steps\AssignTenantTierStep;
use App\Services\Tenancy\Provisioning\Steps\CreateTenantRecordStep;
use App\Services\Tenancy\Provisioning\Steps\EnsureStoragePrefixStep;
use App\Services\Tenancy\Provisioning\Steps\ProvisionEncryptionKeyStep;
use App\Services\Tenancy\Provisioning\Steps\RecordProvisioningAuditStep;
use App\Services\Tenancy\Resolvers\ApiTokenTenantResolver;
use App\Services\Tenancy\Resolvers\SessionTenantResolver;
use App\Services\Tenancy\Resolvers\SubdomainTenantResolver;

/*
|--------------------------------------------------------------------------
| WhatsApp Chatbot Platform tunables
|--------------------------------------------------------------------------
|
| Platform-wide operational limits. Per-plan quotas (see `plans.limits`) and
| these config caps are both enforced: whichever is stricter wins. Nothing in
| here can bypass opt-out enforcement or the anti-ban gate on web-protocol
| channel modes — those are hard-enforced in code with no bypass parameter.
|
| Later phases extend this file (anti-ban, media, AI, channel modes). Keys are
| grouped so additions stay additive.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | WA Bridge (Node + Baileys sidecar)
    |--------------------------------------------------------------------------
    */
    'bridge' => [
        'url' => env('WA_BRIDGE_URL', 'http://127.0.0.1:3000'),
        'token' => env('WA_BRIDGE_TOKEN'),
        'timeout' => (int) env('WA_BRIDGE_TIMEOUT', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenancy defaults
    |--------------------------------------------------------------------------
    | Applied by TenantLifecycle::provision when the caller supplies no value.
    */
    /*
    |--------------------------------------------------------------------------
    | Tenant-owned tables — the rule for every phase (Req 1.1, 1.2 / A1)
    |--------------------------------------------------------------------------
    | Row-level isolation only holds if it is applied *everywhere*, so adding a
    | tenant-owned table is two mandatory steps and nothing else:
    |
    |   1. migration: `TenantSchema::tenantId($table, ['status', ...]);`
    |      — adds the `tenant_id` ULID FK (cascade on delete) plus the index that
    |        leads with `tenant_id`, in one call.
    |   2. model: `use App\Models\Concerns\BelongsToTenant;`
    |      — global scope on every read, `tenant_id` stamped on every create.
    |
    | Both halves are enforced by `TenantOwnedModelsGuardTest`, which walks the live
    | schema: a table with a `tenant_id` column whose model lacks the trait, or that
    | has no index leading with `tenant_id`, fails the suite. The only sanctioned
    | bypasses are the audited `TenantContext::asPlatform()` and an explicit
    | `Model::withoutTenantScope()` at a call site; an unresolved context fails
    | closed with `MissingTenantContextException`.
    */

    'tenancy' => [
        'default_timezone' => env('WA_TENANT_DEFAULT_TIMEZONE', 'UTC'),
        'default_locale' => env('WA_TENANT_DEFAULT_LOCALE', 'en'),
        'trial_days' => (int) env('WA_TENANT_TRIAL_DAYS', 14),
        'storage_prefix' => 'tenants',

        // Plan a newly provisioned tenant starts on, by `plans.slug` (seeded by
        // `Database\Seeders\PlanSeeder`). Resolved through
        // `PlanRepository::defaultPlan()`; a tenant with no plan is gated to no
        // features and no allowance rather than to everything.
        'default_plan_slug' => env('WA_TENANT_DEFAULT_PLAN_SLUG', 'starter'),

        /*
        |----------------------------------------------------------------------
        | Provisioning pipeline (Req 1.8 / A1)
        |----------------------------------------------------------------------
        | Req 1.8: provisioning a tenant creates its tenant record, wallet, default
        | chatbot, per-tenant DEK, storage prefix, and seed plan **atomically**. Those
        | six things belong to five different phases of the build, so the list of what
        | provisioning does is data rather than a method body: `TenantLifecycle::provision`
        | runs the classes below, in order, inside one transaction, and never has to
        | change when a phase adds one.
        |
        | Every entry implements `App\Services\Tenancy\Provisioning\TenantProvisioningStep`.
        | Order matters twice: the tenant record must come first, and any step with
        | effects the database cannot roll back (the filesystem, a key store, a cache)
        | belongs near the end, after the cheap failures have had their chance. Failure
        | compensation runs in exact reverse.
        |
        | An entry that cannot be resolved is fatal, unlike `resolvers` above: a skipped
        | resolver means one fewer door and fails closed, while a skipped provisioning
        | step means a tenant created without its plan, its key or its storage — which is
        | indistinguishable afterwards from a tenant that legitimately has none.
        |
        | **Req 1.8 is not fully satisfied yet.** Two of its six elements have no table:
        | the wallet arrives with task 10.1 (`wallets`) and the default chatbot with task
        | 11.1 (`chatbots`), and each of those tasks must append its own step here. They
        | are absent rather than stubbed — an empty "creates the wallet" class would hide
        | the gap — and `TenantProvisioningStepRegistryTest` pins this list verbatim so
        | the omission fails a test instead of being forgotten.
        */
        'provisioning' => [
            'steps' => [
                CreateTenantRecordStep::class,
                AssignSeedPlanStep::class,
                AssignOwnerMembershipStep::class,
                AssignTenantTierStep::class,
                ProvisionEncryptionKeyStep::class,
                EnsureStoragePrefixStep::class,
                RecordProvisioningAuditStep::class,
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | Tenant lifecycle (Req 1.1 / A1; Req 28.2 / D5)
        |----------------------------------------------------------------------
        | `App\Services\Tenancy\TenantLifecycle` owns the TRIAL -> ACTIVE ->
        | SUSPENDED -> CANCELLED state machine. The transitions themselves are not
        | configurable — they are the design's lifecycle diagram, encoded in
        | `App\Enums\TenantStatus::allowedNext()` — but how long a cancelled tenant's
        | data survives is a policy, and policies belong here.
        */
        'lifecycle' => [
            // Days between cancellation and the verified hard delete (task 34.3).
            // Read, never stored per tenant, so shortening or extending the window
            // applies to tenants already inside it. Must be > 0: a zero window would
            // make data purgeable the instant a tenant cancels, which is the one thing
            // a retention window exists to prevent.
            'retention_days' => (int) env('WA_TENANT_RETENTION_DAYS', 30),
        ],

        /*
        |----------------------------------------------------------------------
        | Tenant resolution (Req 1.1 / A1)
        |----------------------------------------------------------------------
        | Doors a request may identify its tenant through, in precedence order:
        | first match wins, and no match means no tenant (never a guess).
        |
        | 1. Panel session — an authenticated human's active tenant, validated
        |    against `tenant_users` on every request. Most specific: it is the
        |    only door that knows *who* is acting, so it outranks the host.
        | 2. Subdomain — `{slug}.{apex}`. Weakest signal (it comes from the
        |    request host), so it only ever acts as a lookup key for a known
        |    tenant and never for URL generation (Req 9.1 / A9).
        | 3. API key — machine callers on /api/v1. Last because a human panel
        |    session and an API key never co-occur on the same request; ordering
        |    it last keeps a stray header from overriding a logged-in user.
        |
        | Verified per-tenant custom domains (Req 9.3, tasks 5.1–5.7) join this
        | list as another resolver ahead of the subdomain one.
        */
        'resolvers' => [
            SessionTenantResolver::class,
            SubdomainTenantResolver::class,
            ApiTokenTenantResolver::class,
        ],

        // Session key holding the panel's active tenant id (written on login
        // and by the tenant switcher).
        'session_key' => 'active_tenant_id',

        // Header carrying an API key when it is not sent as a bearer token.
        'api_key_header' => env('WA_TENANT_API_KEY_HEADER', 'X-Api-Key'),

        // Hosts that tenant subdomains hang off, e.g. "app.bot.example.com"
        // makes "acme.app.bot.example.com" resolve tenant "acme". Empty falls
        // back to the host of APP_URL. Comma-separated in the environment.
        'apexes' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('WA_TENANT_APEXES', ''))
        ), static fn (string $host): bool => $host !== '')),

        // Platform-owned labels that can never be a tenant subdomain.
        'reserved_subdomains' => [
            'www', 'app', 'admin', 'api', 'assets', 'static', 'cdn',
            'mail', 'smtp', 'bridge', 'webhooks', 'status', 'billing', 'support',
        ],

        /*
        |----------------------------------------------------------------------
        | Tenant tiers & fair scheduling (Req 1.6, 1.7 / A1; Req 30.2, 30.6 / NFR1)
        |----------------------------------------------------------------------
        | The isolation ladder — SHARED → DEDICATED_WORKER → DEDICATED_DB — is a
        | value, never a branch in code. `App\Services\Tenancy\TierResolver` is the
        | only reader: it layers a tenant's optional `tenant_tiers` row over these
        | defaults, so escalating a tenant, reshaping the fairness curve, or cutting
        | a tenant over to its own database are all config/row changes.
        */
        'tiers' => [
            // What a tenant with no `tenant_tiers` row runs on — i.e. almost all of
            // them. Raising this escalates the whole platform in one flip.
            'default' => env('WA_TENANT_DEFAULT_TIER', TenantTier::Shared->value),

            // Operator break-glass: `tenant id or slug => tier`. Outranks the row, so
            // a tenant can be drained off a failing shard, or a tier reproduced in
            // staging, without writing to the database. Empty in normal operation.
            'overrides' => [],

            // Weighted-fair (deficit round-robin) dispatch share per tier, used when
            // a tenant's row pins no `lane_weight` of its own.
            'lane_weights' => [
                TenantTier::Shared->value => (int) env('WA_LANE_WEIGHT_SHARED', 1),
                TenantTier::DedicatedWorker->value => (int) env('WA_LANE_WEIGHT_DEDICATED_WORKER', 5),
                TenantTier::DedicatedDb->value => (int) env('WA_LANE_WEIGHT_DEDICATED_DB', 10),
            ],

            // Upper bound on any single tenant's share of a dispatch window — the
            // noisy-neighbour ceiling (Req 30.6 / NFR1). The floor is always 1, in
            // code: a weight can be lowered but never zeroed, so no tenant starves.
            'lane_weight_max' => (int) env('WA_LANE_WEIGHT_MAX', 1000),

            /*
            |------------------------------------------------------------------
            | Shard routing for the DEDICATED_DB tier
            |------------------------------------------------------------------
            | Both keys name connections that must already exist in
            | config/database.php — a shard row can move a tenant between
            | *configured* databases, never introduce a new host. Leave both empty
            | (the default) and every tenant stays on the shared connection, which
            | is what a single-database install does today.
            */
            'shard' => [
                // Explicit pins: `tenant_tiers.shard_key => connection name`.
                'connections' => [],

                // Consistent-hash ring for DEDICATED_DB tenants with no explicit
                // pin. Comma-separated connection names in the environment.
                'ring' => array_values(array_filter(array_map(
                    'trim',
                    explode(',', (string) env('WA_TENANT_SHARD_RING', ''))
                ), static fn (string $connection): bool => $connection !== '')),
            ],

            // Only the `tenant_tiers` lookup is cached — the config layer above is
            // read on every call, so a config flip needs no cache clear. Row writes
            // invalidate themselves (`TenantTierAssignment::booted()`); `ttl` of 0
            // caches forever and relies on that invalidation alone.
            'cache' => [
                'enabled' => (bool) env('WA_TENANT_TIER_CACHE', true),
                'store' => env('WA_TENANT_TIER_CACHE_STORE'),
                'ttl' => (int) env('WA_TENANT_TIER_CACHE_TTL', 300),
                'prefix' => 'tenancy:tier',
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | Quota metering (Req 3.4, 3.5 / A3)
        |----------------------------------------------------------------------
        | `App\Services\Tenancy\QuotaGuard` is the only reader. Nothing here is a
        | ceiling: **allowances live in `plans.limits`** and nowhere else, and no
        | value below can raise, lower, or bypass one. These are the mechanics of
        | counting — how consumption is serialized, and how long the consume-once
        | ledger is kept.
        */
        'quota' => [
            /*
            |------------------------------------------------------------------
            | Per-counter lock: `quota:{tenant}:{kind}`
            |------------------------------------------------------------------
            | Serializes concurrent consumption of one counter so the ceiling check
            | and the increment are one step. It is a *fast path*, not the
            | correctness guarantee — `uniq(scope, key)` on `idempotency_keys`, the
            | `used = used + n` SQL increment, and the unsigned column hold on their
            | own (Correctness Property 4). A store without lock support, or a wait
            | that times out, therefore proceeds **unlocked** rather than refusing:
            | the send has already happened and declining to record it would lose
            | usage. See `QuotaGuard` for the bounded cost.
            */
            'lock' => [
                'store' => env('WA_QUOTA_LOCK_STORE'),
                'seconds' => (int) env('WA_QUOTA_LOCK_SECONDS', 10),
                'wait_seconds' => (int) env('WA_QUOTA_LOCK_WAIT_SECONDS', 5),
            ],

            // `idempotency_keys.scope` prefix for consume-once entries, which are
            // written as `{prefix}:{tenantId}:{QUOTA_KIND}`. Task 3.4's
            // `IdempotencyStore::once()` takes over these rows unchanged.
            'scope_prefix' => 'quota',

            // How long a consume-once entry is kept. Must comfortably outlive the
            // longest bucket (a month) or a late retry would no longer be
            // recognised as a duplicate and would consume a second time.
            'retention_days' => (int) env('WA_QUOTA_RETENTION_DAYS', 45),

            /*
            |------------------------------------------------------------------
            | Quota-paused work (Req 20.3 / C3, Req 31.1 / NFR2)
            |------------------------------------------------------------------
            | Work refused by a *deferrable* verdict is parked in `quota_holds`
            | (`QUOTA_PAUSED`) and handed back when the allowance returns — at the
            | next period reset, or immediately after an upgrade/top-up. The
            | scheduled sweep is `wa:quota:resume-paused`, registered in
            | `routes/console.php`; see `App\Services\Tenancy\QuotaParkingLot`.
            */
            'holds' => [
                /*
                | Who hands each kind of parked work back: `{key} => QuotaResumer`.
                | The key is what `QuotaHoldSubject::for($work, resumer: 'campaign')`
                | records on the hold, and adding a subsystem is exactly this one
                | line plus one `resume()` method:
                |
                |   - task 26.2 — 'campaign' => CampaignQuotaResumer::class
                |   - task 26.5 — 'import'   => ContactImportQuotaResumer::class
                |   - task 27.x — 'sequence' => SequenceQuotaResumer::class
                |
                | A hold with no resumer is handed back by the `QuotaHoldResumed`
                | event alone, which is enough for an owner that re-reads its own
                | state. A hold naming a key that is *not* registered here stays
                | paused with the error recorded — never silently resumed, because
                | that would drop the work (Req 31.1).
                */
                'resumers' => [
                ],

                // Holds considered per sweep. The sweep runs every minute, so this is
                // a fairness/latency knob rather than a cap on throughput: a backlog
                // drains over successive ticks instead of in one long transaction.
                'batch' => (int) env('WA_QUOTA_RESUME_BATCH', 200),

                // How long a `RESUMING` claim is honoured before another worker may
                // retake it. A worker killed mid-hand-back delays its hold by this
                // much; retaking a *live* claim would risk resuming the same campaign
                // twice, which is the worse failure.
                'lease_seconds' => (int) env('WA_QUOTA_RESUME_LEASE_SECONDS', 300),

                // How long to wait before re-checking a hold whose refusal has stopped
                // being transient (a downgrade, a limit edited to 0). Such work is
                // never auto-resumed by the clock — an upgrade or top-up pulls it
                // forward — so this only bounds how stale the recorded reason gets.
                'blocked_recheck_seconds' => (int) env('WA_QUOTA_BLOCKED_RECHECK_SECONDS', 3600),

                // Base backoff after a failed hand-back, multiplied by the attempt
                // count. A broken resumer therefore backs off instead of retrying
                // every minute for a month, and a fixed deployment recovers promptly.
                'failure_backoff_seconds' => (int) env('WA_QUOTA_RESUME_BACKOFF_SECONDS', 300),

                // How long *finished* holds are kept for the audit/operator trail.
                // Open holds are never pruned at any age: they are work somebody is
                // still owed.
                'retention_days' => (int) env('WA_QUOTA_HOLD_RETENTION_DAYS', 30),
            ],

            /*
            |------------------------------------------------------------------
            | Telling the tenant (Req 3.4 / A3 — "and notify the tenant")
            |------------------------------------------------------------------
            | `QuotaNotifier` dispatches `TenantQuotaExhausted` / `TenantQuotaRestored`
            | **once per tenant per quota per period** — not once per refused job. The
            | notifications inbox (task 29.2) subscribes; nothing in the quota layer
            | knows a mailbox exists.
            */
            'notify' => [
                // False parks and resumes work exactly as before but tells nobody —
                // for a load test or a migration replay, never for production.
                'enabled' => (bool) env('WA_QUOTA_NOTIFY', true),

                // `idempotency_keys.scope` prefix the "told once" claims are written
                // under: `{prefix}:{tenantId}:{QUOTA_KIND}`.
                'scope_prefix' => 'quota-notice',

                // How long a claim is kept. Floored at 32 days in code: shorter than
                // the longest period (a month) and the same period could be announced
                // twice.
                'retention_days' => (int) env('WA_QUOTA_NOTICE_RETENTION_DAYS', 45),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-tenant file storage
    |--------------------------------------------------------------------------
    | Every tenant file lives at `{tenancy.storage_prefix}/{tenantId}/...` on
    | the disk named below (see `App\Services\Tenancy\TenantStorage`). All three
    | disks are private and outside the web root — auth state is never served at
    | all, exports and media only through signed, expiring URLs. Filenames are
    | server-generated ULIDs; extensions come from `finfo` sniffing, never from
    | the client. The disks themselves are defined in `config/filesystems.php`.
    */
    'storage' => [
        'disks' => [
            'auth' => env('WA_AUTH_DISK', 'wa_auth'),
            'exports' => env('WA_EXPORTS_DISK', 'wa_exports'),
            'media' => env('WA_MEDIA_DISK', 'wa_media'),
        ],

        // Extensions the platform may generate for exports/invoices. Export
        // bytes are produced server-side, so the extension is declared by the
        // caller and validated against this list rather than sniffed.
        'export_extensions' => ['csv', 'txt', 'json', 'xlsx', 'vcf', 'pdf', 'zip'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Weighted-fair dispatch (Req 1.7 / A1; Req 30.2, 30.6 / NFR1)
    |--------------------------------------------------------------------------
    | `App\Services\Dispatch\FairScheduler` shares each queue lane out across the
    | tenants with pending work by **deficit round robin**, in proportion to the
    | `lane_weight` that `TierResolver` resolves (see `tenancy.tiers` above). Every
    | tenant's weight is at least 1, so no tenant can be starved; every tenant's
    | share per round is capped, so no tenant can monopolise a lane however large
    | its backlog (Correctness Property 19).
    |
    | The knobs below change the *granularity* of fairness, never who gets what:
    | shares are set by weight and by weight alone.
    */
    'dispatch' => [
        'fair' => [
            // Units of work one weight point buys per round. A tenant's quantum is
            // `lane_weight * quantum`, so raising this makes rounds coarser (fewer,
            // larger claims per lane lock) without changing any tenant's share.
            'quantum' => (int) env('WA_DISPATCH_QUANTUM', 1),

            // Extra quanta of unspent credit a tenant may carry into later rounds —
            // the burst allowance, and the constant in the noisy-neighbour error
            // bound: a tenant gets at most `(1 + this) * quantum` units in one round.
            // 0 is perfectly flat but never lets a tenant make up a fractional share
            // it repeatedly missed.
            'max_carry_quanta' => (int) env('WA_DISPATCH_MAX_CARRY_QUANTA', 1),

            // Safety bound on crediting rounds inside a single claim, so a caller that
            // asks for a very large budget cannot hold a lane's lock for long.
            'max_rounds' => (int) env('WA_DISPATCH_MAX_ROUNDS', 1024),

            /*
            |------------------------------------------------------------------
            | Deficit counters
            |------------------------------------------------------------------
            | Soft state: one cache entry per queue lane holding that lane's
            | deficit counters and rotation cursor, claimed under a short lock so
            | concurrent workers share one rotation instead of each running its
            | own. Losing it (TTL, flush, a fresh Redis) restarts every lane from
            | zero credit — fair, and no work is lost, because the work itself
            | lives in the queue tables. Redis is the natural home for it after
            | Req 30.3's drop-in upgrade (task 36.3); `store` is the only change.
            */
            'state' => [
                'store' => env('WA_DISPATCH_STATE_STORE'),
                'prefix' => 'dispatch:fair',

                // Long enough to span an idle period between campaigns, short
                // enough that a lane nobody dispatches on stops being remembered.
                'ttl' => (int) env('WA_DISPATCH_STATE_TTL', 3600),

                // Claim lock: how long it is held (the lock's own TTL is twice
                // this) and how long a worker waits for it. On timeout the claim
                // proceeds *unlocked* rather than refusing to dispatch — a
                // momentarily imprecise share is a far better failure than a
                // platform-wide send stall. See `CacheDeficitLedger`.
                'lock_seconds' => (int) env('WA_DISPATCH_LOCK_SECONDS', 5),
                'lock_wait_seconds' => (int) env('WA_DISPATCH_LOCK_WAIT_SECONDS', 3),
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | Dispatch eligibility — "can this tenant be dispatched right now?"
        |----------------------------------------------------------------------
        | Quota-aware dispatch (Req 30.6 / NFR1): a tenant that cannot be
        | dispatched is **skipped in the loop** rather than granted a share it
        | would waste, and picked up again the moment it clears. Each gate below
        | is one reason to skip, ANDed in order, and appending a class here is the
        | entire cost of adding one — the scheduler does not change.
        |
        | The suspension gate (`Eligibility\LifecycleDispatchEligibility`,
        | `TenantLifecycle::canSendOutbound()`) is applied **unconditionally in
        | code** and is deliberately absent from this list: "a suspended tenant is
        | not dispatched" is not a tunable.
        |
        | Each owning task adds its own gate:
        |
        |   - task 2.3 (done) — `Eligibility\QuotaDispatchEligibility`: has the
        |                 tenant any allowance left? Reads `QuotaGuard::verdict()`
        |                 only — the question is asked speculatively and must never
        |                 *consume*.
        |   - task 9.6  — the anti-ban gate: per-minute/hour/day pacing, warm-up
        |                 caps, quiet hours.
        |   - task 36.5 — per-tenant in-flight AI concurrency cap (the second half
        |                 of Req 30.6).
        |
        | A gate must only ever withhold work for a reason that clears by itself or
        | by an operator action: a skip means "later", so a refusal that nothing can
        | ever lift belongs at the send gate where the tenant sees an error. An
        | entry that cannot be resolved is fatal (`InvalidDispatchGateException`) —
        | a silently skipped gate is a silently removed cap.
        */
        'eligibility' => [
            'gates' => [
                QuotaDispatchEligibility::class,
            ],

            /*
            |------------------------------------------------------------------
            | Which quotas pace dispatch
            |------------------------------------------------------------------
            | The kinds `QuotaDispatchEligibility` requires one free unit of before
            | a tenant enters the rotation. Message counters do pace throughput;
            | `AI_CREDITS` deliberately does not (an exhausted AI allowance must not
            | stop plain outbound messages — task 36.5 gates AI work on its own),
            | and the gauges do not either, since they cap *creation* rather than
            | sending. An empty list disables quota-aware dispatch without removing
            | the gate; the send gate still enforces every kind either way.
            */
            'quota' => [
                'kinds' => [
                    QuotaKind::MessagesMonthly->value,
                    QuotaKind::MessagesDaily->value,
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Reliability primitives (Req 31 / NFR2)
    |--------------------------------------------------------------------------
    | One group for the whole of Phase 2's reliability layer. Sibling concerns —
    | retry/backoff (task 3.6), the idempotency store (3.4), the outbox relay (3.3)
    | — belong **inside this array** as further keys (`retry`, `idempotency`,
    | `outbox`), not as second top-level `reliability` entries, which PHP would
    | silently collapse into whichever literal came last.
    */
    'reliability' => [

        /*
        |----------------------------------------------------------------------
        | Circuit breakers (Req 31.3 / NFR2; Req 13.13 / B4)
        |----------------------------------------------------------------------
        | The thresholds of Algorithm 7, read by `App\Services\Reliability\
        | PersistedCircuitBreaker` and by nothing else. They are here rather than
        | as literals in that class because design.md § Reliability → Circuit
        | breakers gives each breaker *family* different tolerances, and because an
        | operator riding out a provider incident needs to widen a cool-down
        | without a deploy.
        |
        | **Resolution order** for every knob, most specific first:
        |
        |   1. `scopes.{scope}.{knob}`   — the family override below
        |   2. `defaults.{knob}`         — the platform default
        |   3. `CircuitBreakerThresholds::DEFAULT_*` — the compiled-in fallback,
        |      so deleting a key cannot disable a safety threshold
        |
        | A value that cannot describe a working breaker (a probe limit of 0, an
        | error rate of 1.5, a negative window) is **fatal** on the first guarded
        | call rather than silently ignored — see `CircuitBreakerThresholds`. A
        | quietly dropped threshold is a quietly removed safety net, the same
        | reasoning as `wa.dispatch.eligibility.gates`.
        */
        'circuit' => [

            /*
            | Platform defaults = design.md's **LLM provider** row, which is also
            | the one Req 13.13 states normatively: open after ≥5 failures within a
            | 30s window **or** an error rate above 50% over the last 20 calls; stay
            | OPEN 30s; admit 3 HALF_OPEN probes.
            */
            'defaults' => [
                // Failures **within the current window** that open the breaker.
                'failure_threshold' => (int) env('WA_CIRCUIT_FAILURE_THRESHOLD', 5),

                // Length of the rolling failure window, in seconds. Rotating it is
                // what keeps Algorithm 7's loop invariant true: the counters
                // describe recent behaviour, never all history.
                'window_seconds' => (int) env('WA_CIRCUIT_WINDOW_SECONDS', 30),

                // The second, independent trip arm: an error *rate* above this,
                // once the window holds at least `error_rate_sample` calls. `null`
                // disables the arm for a family whose design row names only a count
                // threshold. Must be in `[0, 1)` — a rate can never exceed 1, so a
                // value of 1 would be an arm that can never fire.
                'error_rate' => 0.5,

                // Minimum in-window calls before the rate arm may fire — the "over
                // the last 20 calls" half of Req 13.13, and what stops a breaker
                // that has seen one failed call from opening at a 100% error rate.
                'error_rate_sample' => (int) env('WA_CIRCUIT_ERROR_RATE_SAMPLE', 20),

                // How long the breaker fails fast before a probe is admitted.
                'open_seconds' => (int) env('WA_CIRCUIT_OPEN_SECONDS', 30),

                // Concurrent probes admitted in HALF_OPEN, in total, across every
                // worker (the budget is claimed with a conditional UPDATE, so this
                // is a real cap and not a per-process one).
                'probes' => (int) env('WA_CIRCUIT_PROBES', 3),

                // Probe successes required to close. 1 is Algorithm 7's postcondition
                // ("success in HALF_OPEN closes the breaker"); a family that wants
                // more proof of recovery may raise it, and it is capped at `probes`
                // so a breaker can always reach CLOSED.
                'probe_successes' => (int) env('WA_CIRCUIT_PROBE_SUCCESSES', 1),
            ],

            /*
            | Per-family overrides — the remaining rows of design.md's table. Only
            | the knobs that differ are listed; everything else falls through to
            | `defaults` above. `CircuitScope::Provider` and `::Tenant` are absent
            | on purpose: the defaults *are* the provider row.
            */
            'scopes' => [

                // Payment gateway (per gateway): ≥5 fails / 60s, open 60s, 3 probes.
                // No rate arm in the design's row — a gateway that answers half the
                // time is still taking payments, and queue-and-retry (its documented
                // fallback) is the right response to the failures themselves.
                CircuitScope::Gateway->value => [
                    'window_seconds' => (int) env('WA_CIRCUIT_GATEWAY_WINDOW_SECONDS', 60),
                    'open_seconds' => (int) env('WA_CIRCUIT_GATEWAY_OPEN_SECONDS', 60),
                    'error_rate' => null,
                ],

                // Bridge (per session): ≥3 fails / 30s, open 15s, 2 probes. Tighter
                // and twitchier than the rest — a WA session that is refusing sends
                // needs the reconnect flow quickly, and reconnecting is cheap.
                CircuitScope::Bridge->value => [
                    'failure_threshold' => (int) env('WA_CIRCUIT_BRIDGE_FAILURE_THRESHOLD', 3),
                    'open_seconds' => (int) env('WA_CIRCUIT_BRIDGE_OPEN_SECONDS', 15),
                    'probes' => (int) env('WA_CIRCUIT_BRIDGE_PROBES', 2),
                    'error_rate' => null,
                ],
            ],

            /*
            |------------------------------------------------------------------
            | Snapshot cache
            |------------------------------------------------------------------
            | The row is the source of truth; this is a short-lived snapshot in
            | front of it so a breaker that is OPEN sheds load **without touching
            | the database at all** — the case that matters, because that is when
            | every worker is hitting the same failing dependency at once.
            |
            | The cache is only ever allowed to make the breaker *more*
            | conservative: a snapshot can send a call straight to fail-fast, but
            | it can never authorise one. Admission is always settled against the
            | row (see `PersistedCircuitBreaker`), which is why Correctness
            | Property 13 does not depend on this TTL, on the store being shared,
            | or on the cache existing at all.
            |
            | The TTL lives here rather than in `wa.cache.ttl` because it is a
            | property of the breaker's own clocks (15–60s) and must stay well
            | under them; `store` = null uses `wa.cache.store`, then the default
            | store from `config/cache.php`.
            */
            'cache' => [
                'enabled' => (bool) env('WA_CIRCUIT_CACHE', true),
                'store' => env('WA_CIRCUIT_CACHE_STORE'),
                'ttl' => (int) env('WA_CIRCUIT_CACHE_TTL', 5),
                'namespace' => 'reliability:circuit',
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | Retry / backoff matrix (Req 31.1 / NFR2; Req 7.1 / A7)
        |----------------------------------------------------------------------
        | design.md § Error Handling: *"exponential base `250ms`, **full jitter**
        | `delay = rand(0, base·2^attempt)`, capped at `30s`"*, per error class.
        | `App\Services\Reliability\RetryMatrix` is the only reader, and
        | `RetryPolicy` the only thing that draws a delay from it.
        |
        | **What is tunable here and what is not.** Every *number* the design
        | states is below, because these are the knobs an operator reaches for
        | during an incident — a flapping bridge may deserve more attempts today,
        | and a provider that has started 429-ing deserves a wider first window.
        | Every *behaviour* it states lives in `App\Enums\ErrorClass`: whether a
        | class retries, defers, or fails fast, and which backoff shape it uses.
        |
        | So `attempts` has exactly one direction of travel. It can take a
        | retryable class's budget away — `attempts: 0` makes that class fail fast
        | — and it cannot hand a budget to a class that has nothing to wait for.
        | Setting `attempts: 5` on `PERMISSION` does not make a plan-feature
        | refusal or a suspended tenant retryable; it is ignored. That asymmetry is
        | the point: a config file should be able to make the platform more
        | cautious, never able to turn a clean 403 into a silent five-minute stall.
        |
        | Nothing here can drop work either (Req 31.1). Exhausting a budget is an
        | explicit give-up the caller must surface (`RetryDecision::giveUp()`), and
        | `QUOTA` has no numeric budget at all — design.md records its max attempts
        | as *"until reset"*, so `attempts => null` means "wait as long as it takes".
        |
        | A malformed value degrades one class to the documented default instead of
        | throwing: this code runs while something is already failing, which is the
        | one place an exception is least useful. See `RetryMatrix`.
        */
        'retry' => [

            /*
            | The series every computed backoff is drawn from: the first window is
            | `base_ms` wide and doubles per attempt until it reaches `cap_ms`,
            | where it stays. Both are design.md's numbers.
            |
            | The delay itself is `rand(0, window)` — **full** jitter, drawn from
            | the whole window including 0. That is not politeness, it is
            | decorrelation: an outage fails every in-flight job at the same
            | instant, so a deterministic backoff wakes all N workers at the same
            | instant too and re-hammers the recovering dependency in lockstep.
            | Independent draws spread the same retries evenly and keep the fleet
            | decorrelated. There is deliberately **no** knob to switch jitter off.
            */
            'base_ms' => (int) env('WA_RETRY_BASE_MS', 250),
            'cap_ms' => (int) env('WA_RETRY_CAP_MS', 30_000),

            // Attempts a retryable class gets when `classes` names no budget for
            // it. Modest on purpose: an unclassified failure retried three times
            // is a nuisance, retried fifty times is an outage amplifier.
            'default_attempts' => (int) env('WA_RETRY_DEFAULT_ATTEMPTS', 3),

            /*
            | What an unclassified `Throwable` is treated as. `UNKNOWN` retries on
            | the modest default budget above; a deployment that would rather
            | surface everything it has not explicitly classified can set
            | `VALIDATION` and get a fail-fast instead. Never "no policy" — an
            | unmapped throwable still gets a budget, a decision, and an
            | `err_class` in the log.
            */
            'default_class' => ErrorClass::Unknown->value,

            /*
            |------------------------------------------------------------------
            | The matrix, per error class
            |------------------------------------------------------------------
            | `attempts` = total attempts **including the first**, so 5 means one
            | try and four retries; `null` = until it succeeds (`QUOTA` only);
            | `0` = never retried. `base_ms` / `cap_ms` override the group values
            | for that class alone.
            |
            | Sources, row by row: design.md's matrix gives transient
            | network/5xx = 5, rate-limited (429) = 8 honouring `Retry-After`,
            | timeout = 3 with a shorter cap, quota = until reset, and 0 for
            | validation / auth / circuit-open. ROADMAP Phase 23 (the single-tenant
            | engine's `ErrorReporter`) gives `NETWORK 5`, `BRIDGE 5`,
            | `NOT_ON_WHATSAPP 0` — and `RATE_LIMIT 3` with a cool-down, which the
            | design's 8 supersedes here: it is the spec of record and it is the
            | row with a `Retry-After` to honour. The engine's "cool-down" survives
            | as this class's wider `base_ms` (a second, not a quarter of one) plus
            | its deferring disposition, which keeps a provider 429 from eating a
            | retry budget meant for real faults.
            */
            'classes' => [

                // Transient infrastructure — connection reset, 5xx, lock
                // contention. Repeated failures also trip a circuit breaker
                // (`reliability.circuit` above), which is what stops attempt 5
                // from being attempted at all.
                ErrorClass::Network->value => ['attempts' => (int) env('WA_RETRY_ATTEMPTS_NETWORK', 5)],

                // The bridge itself: not connected, session dropped, bridge 5xx.
                ErrorClass::Bridge->value => ['attempts' => (int) env('WA_RETRY_ATTEMPTS_BRIDGE', 5)],

                // design.md: *"shorter cap; may escalate model tier"*. A call that
                // already spent its timeout budget has burned wall-clock time no
                // backoff should double, so this class re-attempts sooner and
                // gives up earlier.
                ErrorClass::Timeout->value => [
                    'attempts' => (int) env('WA_RETRY_ATTEMPTS_TIMEOUT', 3),
                    'cap_ms' => (int) env('WA_RETRY_CAP_MS_TIMEOUT', 5_000),
                ],

                // A media *transfer* that failed (download, upload, transcode). A
                // media payload that was **refused** — wrong sniffed MIME, over
                // the byte cap — is `VALIDATION`: those bytes will not become
                // acceptable on the fourth attempt.
                ErrorClass::Media->value => ['attempts' => (int) env('WA_RETRY_ATTEMPTS_MEDIA', 3)],

                // A provider 429. Defers rather than retries (it is not our
                // fault and not our budget), honours `Retry-After` verbatim when
                // one was sent, and starts from a one-second window when one was
                // not — the engine's cool-down, expressed as a wider base rather
                // than as a floor on the jitter, so the lower edge of the window
                // stays decorrelated.
                ErrorClass::RateLimit->value => [
                    'attempts' => (int) env('WA_RETRY_ATTEMPTS_RATE_LIMIT', 8),
                    'base_ms' => (int) env('WA_RETRY_BASE_MS_RATE_LIMIT', 1_000),
                ],

                // Req 31.1: a quota refusal is **never** a drop. A deferrable one
                // waits for the period to roll — `QuotaVerdict::secondsUntilPeriodReset()`
                // is the wait, honoured exactly — for as long as that takes, which
                // is why there is no number here. A *non-deferrable* one (not
                // priced, metered to zero, larger than a whole period, a gauge at
                // capacity) is the requirement's other half and is blocked
                // explicitly by `RetryPolicy` rather than released, because no
                // reset is coming.
                ErrorClass::Quota->value => ['attempts' => null],

                // Unclassified. Same budget as `default_attempts`, stated
                // explicitly so the matrix is complete when read as data.
                ErrorClass::Unknown->value => ['attempts' => (int) env('WA_RETRY_ATTEMPTS_UNKNOWN', 3)],

                /*
                | The fail-fast classes. `0` is documentation rather than
                | mechanism — `ErrorClass::disposition()` already refuses to retry
                | them, and raising these numbers changes nothing — but a matrix
                | that omitted the rows would read as if it had no opinion, which
                | is the opposite of the truth: waiting is *known* to be useless.
                | An expired credential does not renew itself, a number that is not
                | on WhatsApp does not join, a plan does not grow a feature, a
                | suspended tenant does not un-suspend on a timer (task 1.3: a
                | suspension is a block, never a defer), and retrying through an
                | OPEN circuit breaker is exactly what it exists to prevent — the
                | fallback chain handles that one.
                */
                ErrorClass::Auth->value => ['attempts' => 0],
                ErrorClass::Permission->value => ['attempts' => 0],
                ErrorClass::Validation->value => ['attempts' => 0],
                ErrorClass::NotOnWhatsApp->value => ['attempts' => 0],
                ErrorClass::CircuitOpen->value => ['attempts' => 0],
            ],

            /*
            |------------------------------------------------------------------
            | Classifying somebody else's errors
            |------------------------------------------------------------------
            | The platform's own typed exceptions are classified in code
            | (`PlatformErrorClassifier`, appended to the chain in code so no
            | deployment can configure away "a suspended tenant is not retried").
            | These two keys are how everything else joins in — later phases
            | (Channel Mode drivers, LLM providers, payment gateways) register
            | their provider errors here instead of editing that class:
            |
            |   'exceptions' => [TwilioRateLimited::class => 'RATE_LIMIT'],
            |   'classifiers' => [CloudApiErrorClassifier::class],
            |
            | `exceptions` is the no-logic case: one exception (or base class), one
            | error-class name. `classifiers` is for the cases that need logic — a
            | vendor error number, a status code — and each entry implements
            | `App\Services\Reliability\ErrorClassifier`, returning `null` for
            | anything it does not recognise so the chain continues. Both are asked
            | *before* the platform map, so an operator can reclassify one
            | exception during an incident without a release.
            |
            | An unusable entry is skipped rather than fatal, unlike
            | `wa.dispatch.eligibility.gates`: a missing gate silently removes a
            | platform-wide cap, whereas a missing classifier only means one
            | exception falls through to `default_class` — and this code runs while
            | something is already failing, so it must not be able to convert a
            | handled failure into a crashed worker.
            */
            'exceptions' => [
            ],

            'classifiers' => [
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | Idempotency store (Req 31.2 / NFR2; Req 25.2 / D2)
        |----------------------------------------------------------------------
        | Read by `App\Services\Reliability\DatabaseIdempotencyStore` and by
        | nothing else. Nothing here is the guarantee: dedup is `uniq(scope, key)`
        | on `idempotency_keys`, and every value below has a compiled-in fallback
        | in the store, so deleting a key degrades the *mechanics* (how long a
        | duplicate waits, how long a row is kept) and never the "at most once".
        |
        | Per-call overrides live in `IdempotencyOptions`; these are the platform
        | defaults for callers that pass none.
        */
        'idempotency' => [
            // How long a completed key is kept before the pruner may delete it.
            // Must comfortably outlive the longest retry window of anything it
            // guards: once the row is gone, the next retry is indistinguishable
            // from a first attempt and the side effect happens again. Matches the
            // quota ledger's horizon (`tenancy.quota.retention_days`), whose rows
            // this store now owns.
            'retention_days' => (int) env('WA_IDEMPOTENCY_RETENTION_DAYS', 45),

            // How long a duplicate waits for the caller that holds the key before
            // it is refused with 409 + `Retry-After`. Small on purpose: the usual
            // duplicate is a gateway retry arriving milliseconds behind the
            // original, and waiting that long turns it into a correct replay —
            // while a long wait would let one dead worker pin every duplicate
            // behind it. Queue jobs should pass `IdempotencyOptions::failingFast()`
            // and let their own backoff do the waiting.
            'wait_ms' => (int) env('WA_IDEMPOTENCY_WAIT_MS', 250),

            // Poll interval inside that wait budget.
            'poll_ms' => (int) env('WA_IDEMPOTENCY_POLL_MS', 25),

            // `Retry-After` on the 409, in seconds.
            'retry_after_seconds' => (int) env('WA_IDEMPOTENCY_RETRY_AFTER', 1),

            // How long an `IN_FLIGHT` lease is honoured before its holder is
            // presumed dead and the key may be retaken. Generous relative to any
            // single guarded operation (circuit breakers and HTTP timeouts already
            // bound those), because breaking a lease that is still live risks the
            // double execution the ledger exists to prevent. Defaults to
            // `IdempotencyKey::STALE_LOCK_SECONDS`.
            'stale_lock_seconds' => (int) env('WA_IDEMPOTENCY_STALE_LOCK_SECONDS', 300),

            // Rows per delete statement in `wa:idempotency:prune` (scheduled in
            // `routes/console.php`). Batched so the pruner never holds a long
            // delete against what is, in steady state, one of the largest tables.
            'prune_batch' => (int) env('WA_IDEMPOTENCY_PRUNE_BATCH', 1_000),
        ],

        /*
        |----------------------------------------------------------------------
        | Transactional outbox & relay (Req 31.4 / NFR2)
        |----------------------------------------------------------------------
        | Read by `App\Services\Reliability\DatabaseOutbox` and its transport
        | (Algorithm 6, Correctness Property 16). Nothing here is the guarantee:
        | atomicity comes from the row being written inside the caller's own
        | transaction, and exactly-once from `uniq(outbox.dedup_key)` plus the
        | consumer deduping on the `X-Dedup-Key` header. Every value below has a
        | compiled-in fallback (`DatabaseOutbox::DEFAULT_*`), so deleting a key
        | degrades the mechanics and never the "never lose the row".
        */
        'outbox' => [

            /*
            | How an effect leaves the platform. The default really delivers —
            | it POSTs the payload to the row's `destination` with the dedup
            | header — so the outbox works from the moment the relay is
            | scheduled. Phase 5+ channel drivers replace it here (NFR4.2).
            |
            | An unusable value is **fatal** when the transport is resolved,
            | never quietly replaced by the default: the relay marks a row SENT
            | when `deliver()` returns, so a transport that silently did nothing
            | would report the whole queue delivered with nothing sent. Same
            | posture as `wa.dispatch.eligibility.gates`, and for a worse
            | failure mode.
            */
            'transport' => env('WA_OUTBOX_TRANSPORT', HttpOutboxTransport::class),

            // Rows claimed per relay pass — design.md's `relay(int $batch = 200)`.
            // A fairness/latency knob rather than a throughput cap: the relay runs
            // every minute (`routes/console.php`) and `--passes` drains a backlog.
            'batch' => (int) env('WA_OUTBOX_BATCH', 200),

            // How long a claimed row is hidden from other claimers. Claiming pushes
            // `next_attempt_at` this far ahead, which is what keeps two workers off
            // one row *after* the `FOR UPDATE SKIP LOCKED` transaction has committed
            // (and what covers SQLite, where the lock clause compiles away). Must
            // comfortably outlive one delivery attempt: breaking a live lease costs a
            // duplicate call — harmless, because the consumer dedups, but wasteful.
            // A worker killed mid-attempt delays its row by this much.
            'lease_seconds' => (int) env('WA_OUTBOX_LEASE_SECONDS', 300),

            /*
            | Total attempts (including the first) before a row **parks**: kept,
            | FAILED, with its `attempts` and `last_error` intact, held out of the
            | claim by its spent budget, and handed back only by an operator
            | (`wa:outbox:relay --requeue=<id>`). There is no DEAD status to drop it
            | into — Req 31.4 forbids losing the row — so this is a budget, not a
            | bin.
            |
            | Modest on purpose. At the retry matrix's 30-second cap, 12 attempts is
            | minutes of wall clock, which keeps every redelivery far inside the
            | dedup horizon below; a budget of hundreds would let a row still be
            | retrying long after the consumer forgot its dedup key.
            */
            'max_attempts' => (int) env('WA_OUTBOX_MAX_ATTEMPTS', 12),

            /*
            | Days after enqueue beyond which a row is never redelivered
            | automatically. This exists because Property 16's exactly-once is a
            | *joint* property: the relay may deliver twice only for as long as the
            | consumer still remembers the dedup key. Inside this platform that
            | memory is `idempotency_keys`, pruned after
            | `reliability.idempotency.retention_days` — so a redelivery after that
            | window would be indistinguishable from a first delivery and the effect
            | would be applied a second time.
            |
            | `null` (the default) tracks that retention window exactly. A value is
            | clamped to it in code and can therefore only ever be *shorter*: a
            | horizon longer than the ledger it depends on would be a silent
            | double-effect, so config cannot ask for one. A row that reaches the
            | horizon is parked, not deleted, and `Outbox::requeue()` refuses it —
            | redelivering it is a decision for the owning subsystem, which can
            | enqueue a fresh intent with a fresh dedup key.
            */
            'dedup_horizon_days' => env('WA_OUTBOX_DEDUP_HORIZON_DAYS') === null
                ? null
                : (int) env('WA_OUTBOX_DEDUP_HORIZON_DAYS'),

            /*
            | The default (HTTP) transport's own limits. Bounded because the claim
            | lease has to outlive a whole attempt, so an unbounded read is not an
            | option. The client's own `retry()` is deliberately unused — the relay
            | owns the attempt budget and the jittered backoff, and a transport that
            | retried internally would multiply the two and hide attempts from the
            | row.
            */
            'http' => [
                'timeout' => (int) env('WA_OUTBOX_HTTP_TIMEOUT', 10),
                'connect_timeout' => (int) env('WA_OUTBOX_HTTP_CONNECT_TIMEOUT', 5),
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | Saga orchestration (Req 15.5 / B6; Req 31.5 / NFR2)
        |----------------------------------------------------------------------
        | Read by `App\Services\Reliability\SagaDefinitionRegistry` and
        | `PersistedSagaOrchestrator` (Algorithm 8, Correctness Property 18).
        */
        'saga' => [

            /*
            | The ordered list of `SagaDefinition` classes, each claiming one
            | `sagas.type`. This is the extension seam: a later phase adds a saga by
            | writing a definition and appending it here, and nothing in the
            | orchestrator changes.
            |
            | **Empty is legal and currently correct** — the order → payment →
            | fulfilment saga belongs to Phase 5. Unlike
            | `wa.tenancy.provisioning.steps`, an empty list here is not fatal at
            | boot; instead, running a saga whose `type` no entry claims raises
            | `InvalidSagaDefinitionException::unknownType()`, so the failure lands
            | at the moment it means something. Every other misconfiguration (a
            | missing class, two definitions claiming one type, a definition with no
            | steps or a duplicated step name) is fatal when the registry is asked,
            | before any forward action runs — a skipped step would produce a saga
            | that reserves stock and takes payment but never fulfils, and reports
            | COMPLETED.
            |
            | Phase 5 appends:
            |   App\Services\Commerce\Sagas\OrderFulfilmentSaga::class,
            */
            'definitions' => [
                //
            ],

            // How long a saga's forward (`{sagaId}:{step}`) and compensation
            // (`{sagaId}:{step}:compensate`) idempotency keys are kept. Longer than
            // `idempotency.retention_days` on purpose: these must outlive the longest
            // a saga can sit COMPENSATING waiting for a dependency to return, because
            // a pruned compensation key makes the next unwind attempt
            // indistinguishable from a first one — and re-running a compensation that
            // already succeeded is the double effect the ledger exists to prevent.
            // Falls back to `PersistedSagaOrchestrator::DEFAULT_KEY_RETENTION_DAYS`.
            'key_retention_days' => (int) env('WA_SAGA_KEY_RETENTION_DAYS', 90),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Versioned caches (Req 30.4 / NFR1)
    |--------------------------------------------------------------------------
    | Hot reads go through `App\Support\Cache\VersionedCache`: entries live under
    | `{namespace}:v{n}:{key}` and a write bumps `{n}`, so invalidating a whole
    | namespace is one atomic increment instead of a key scan — and a reader always
    | composes its key from the *current* version, which makes a stale read
    | unreachable rather than merely short-lived. See that class for the pattern;
    | design.md §"Caching layers & invalidation" lists the layers that use it.
    |
    | `store` = null uses the default store from `config/cache.php`.
    */
    'cache' => [
        'store' => env('WA_CACHE_STORE'),

        // Per-namespace lifetimes, in seconds. Superseded entries are left to expire
        // on their own rather than deleted, so every namespace needs one.
        'ttl' => [
            'default' => (int) env('WA_CACHE_TTL_DEFAULT', 300),

            // design.md: config / feature flags — 5 min.
            'plans' => (int) env('WA_CACHE_TTL_PLANS', 300),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Anti-ban / send pacing (web-protocol modes only)
    |--------------------------------------------------------------------------
    */
    'anti_ban' => [
        'delay_seconds' => [
            'min' => (int) env('WA_DELAY_MIN', 6),
            'max' => (int) env('WA_DELAY_MAX', 20),
        ],
        'rate_limit' => [
            'per_minute' => (int) env('WA_RATE_PER_MINUTE', 8),
            'per_hour' => (int) env('WA_RATE_PER_HOUR', 250),
            'per_day' => (int) env('WA_RATE_PER_DAY', 1000),
        ],
        'warm_up' => [
            'enabled' => (bool) env('WA_WARMUP_ENABLED', true),
            'day_caps' => [50, 100, 200, 400, 700, 1000],
        ],
        'quiet_hours' => [
            'enabled' => (bool) env('WA_QUIET_HOURS_ENABLED', true),
            'start' => env('WA_QUIET_HOURS_START', '21:00'),
            'end' => env('WA_QUIET_HOURS_END', '08:00'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Security — envelope encryption & the KMS seam (Req 32.5 / NFR3)
    |--------------------------------------------------------------------------
    | Sensitive columns are encrypted with a **per-tenant DEK** which is itself
    | sealed ("wrapped") by a master key and stored in `encryption_keys`. Two
    | keys, two jobs: the DEK isolates one tenant's data cryptographically, the
    | master key never leaves the key store and makes the whole set rotatable.
    | See `App\Services\Security\FieldCipher`.
    |
    | `wrapper` is the swap point (NFR4.2): the default resolves master keys from
    | this config and really works, and task 4.2's cloud-KMS/Vault adapter drops
    | in by naming another `KeyWrapper` here — no code change anywhere else.
    |
    | Everything here fails **closed**. If no master key can be resolved, writes
    | and reads raise `KeyUnavailableException` (503, retryable); they never fall
    | back to plaintext.
    */
    'security' => [
        'encryption' => [
            // Implementation of App\Services\Security\KeyWrapper that seals DEKs.
            'wrapper' => env('WA_KEY_WRAPPER', ConfigMasterKeyWrapper::class),

            // AEAD for field values. Must be an authenticated (GCM) mode — a
            // non-AEAD cipher would silently drop tamper and tenant binding, so
            // anything else is refused at first use.
            'cipher' => 'aes-256-gcm',

            // DEK length in bytes (32 = AES-256).
            'data_key_bytes' => 32,

            // Master key id new DEKs are sealed under; recorded per row in
            // `encryption_keys.kms_key_id`.
            'master_key_id' => env('WA_MASTER_KEY_ID', 'app'),

            // Master key material by id. `WA_MASTER_KEYS` carries additional ids as
            // `id=material,id2=material2` so a rotated-out master key stays
            // *unwrappable* while its DEKs are re-wrapped (task 4.2).
            'master_keys' => ConfigMasterKeyWrapper::parseKeyList((string) env('WA_MASTER_KEYS', ''))
                + array_filter(
                    ['app' => (string) env('WA_MASTER_KEY', '')],
                    static fn (string $material): bool => $material !== '',
                ),

            // The one key id allowed to fall back to APP_KEY when no material is
            // configured for it — a real, working default for dev and single-node
            // deployments. Set WA_MASTER_KEY in production so rotating the app key
            // does not orphan every tenant's DEK.
            'app_key_id' => 'app',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit trail (Req 24.2, 24.5 / D1; Req 34.1 / NFR5)
    |--------------------------------------------------------------------------
    | `audit_logs` is append-only and hash-chained: each row stores `prev_hash`
    | plus `row_hash = SHA-256(prev_hash || canonical(entry))`, chained per tenant
    | (plus one `platform` chain for actions no tenant owns). Nothing here can
    | disable the chain or the append-only guards — those are structural, in the
    | schema and the model. These are the tunables around them.
    |
    | Production additionally revokes UPDATE/DELETE on the table from the
    | application role; the exact statements live in
    | `App\Support\Database\AppendOnlyTable::revokeStatements()` so the runbook
    | cannot drift from the code.
    */
    'audit' => [
        // How long an append waits for its chain's write lock before giving up
        // (and raising, rather than dropping the entry). Appends are also protected
        // by `unique(chain_key, sequence)`, so the lock is the fast path, not the
        // guarantee.
        'lock_seconds' => (int) env('WA_AUDIT_LOCK_SECONDS', 5),

        // Payload caps. An audit row is evidence of a decision, not a copy of a
        // table: oversized payloads are truncated (and marked as truncated) rather
        // than rejected, because a write that can be made to fail by its own input
        // is a write an attacker can suppress.
        'max_payload_depth' => (int) env('WA_AUDIT_MAX_PAYLOAD_DEPTH', 6),
        'max_string_length' => (int) env('WA_AUDIT_MAX_STRING_LENGTH', 2000),
        'max_array_items' => (int) env('WA_AUDIT_MAX_ARRAY_ITEMS', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Media caps
    |--------------------------------------------------------------------------
    */
    'media' => [
        'max_bytes' => (int) env('WA_MEDIA_MAX_BYTES', 16 * 1024 * 1024),
        'allowed_mimes' => [
            'image/jpeg', 'image/png', 'image/webp',
            'video/mp4', 'audio/ogg', 'audio/mpeg',
            'application/pdf',
        ],

        // Stored extension per *sniffed* MIME type. Anything not listed falls
        // back to the platform MIME database; an allowed type should always be
        // mapped here so the stored name is predictable.
        'extensions' => [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4',
            'audio/ogg' => 'ogg',
            'audio/mpeg' => 'mp3',
            'application/pdf' => 'pdf',
        ],
    ],

];
