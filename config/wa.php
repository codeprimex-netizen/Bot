<?php

declare(strict_types=1);

use App\Enums\QuotaKind;
use App\Enums\TenantTier;
use App\Services\Dispatch\Eligibility\QuotaDispatchEligibility;
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
