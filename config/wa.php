<?php

declare(strict_types=1);

use App\Enums\CircuitScope;
use App\Enums\ErrorClass;
use App\Enums\QuotaKind;
use App\Enums\TenantTier;
use App\Services\Bridge\BridgeErrorClassifier;
use App\Services\Dispatch\Eligibility\QuotaDispatchEligibility;
use App\Services\Reliability\HttpOutboxTransport;
use App\Services\Security\ConfigMasterKeyWrapper;
use App\Services\Security\EncryptionKeyRewrapStore;
use App\Services\Security\Pii\ConfigTenantPiiPatternSource;
use App\Services\Security\SigningSecretRewrapStore;
use App\Services\Tenancy\Provisioning\Steps\AssignOwnerMembershipStep;
use App\Services\Tenancy\Provisioning\Steps\AssignSeedPlanStep;
use App\Services\Tenancy\Provisioning\Steps\AssignTenantTierStep;
use App\Services\Tenancy\Provisioning\Steps\CreateTenantRecordStep;
use App\Services\Tenancy\Provisioning\Steps\EnsureStoragePrefixStep;
use App\Services\Tenancy\Provisioning\Steps\ProvisionEncryptionKeyStep;
use App\Services\Tenancy\Provisioning\Steps\RecordProvisioningAuditStep;
use App\Services\Tenancy\Resolvers\ApiTokenTenantResolver;
use App\Services\Tenancy\Resolvers\CustomDomainTenantResolver;
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
    /*
    | The sidecar holds the protocol socket and nothing else: no business logic,
    | no database, no decisions (ROADMAP § "WA Bridge"). `App\Services\Bridge\HttpBridgeClient`
    | is the only reader of these keys, and `App\Providers\BridgeServiceProvider`
    | composes it into the chain every caller actually gets:
    |
    |   TenantScopedBridgeClient -> GuardedBridgeClient -> HttpBridgeClient
    |
    | Nothing below can move the first of those. Ownership scoping — every session
    | id resolved against the acting tenant before a byte leaves the process — is
    | structural, because the sidecar has no tenant column and could not check it.
    */
    'bridge' => [
        'url' => env('WA_BRIDGE_URL', 'http://127.0.0.1:3000'),
        'token' => env('WA_BRIDGE_TOKEN'),
        'timeout' => (int) env('WA_BRIDGE_TIMEOUT', 15),

        // Seconds to wait for the TCP connection alone, separate from the whole
        // request. Small: the sidecar is a local process, so a slow *connect* means
        // it is down rather than busy, and waiting out the full request timeout to
        // learn that is a stalled worker.
        'connect_timeout' => (int) env('WA_BRIDGE_CONNECT_TIMEOUT', 5),

        // Path segment between the host and the routes (`/v1/sessions/...`). Empty
        // mounts the routes at the root. A string, so a sidecar behind a shared
        // ingress can be given a mount point without touching the client.
        'prefix' => env('WA_BRIDGE_PREFIX', ''),

        /*
        |----------------------------------------------------------------------
        | The network seam (Req 31.1, 31.3 / NFR2)
        |----------------------------------------------------------------------
        | Same guard the KMS client and the domain probe get, and composed the
        | same way — retry loop outside, breaker inside. The breaker is keyed
        | **per session** (`CircuitScope::Bridge`, name = session id), so one
        | tenant's flapping number fences off that session and nothing else; a
        | platform-wide bridge breaker would have made one bad number an outage
        | for everybody.
        |
        | `enabled => false` is for a deployment that fences egress some other
        | way. It removes the breaker and the inline retry; it cannot remove the
        | ownership check, which is not a resilience feature.
        */
        'guard' => [
            'enabled' => (bool) env('WA_BRIDGE_GUARD', true),

            // Inline attempts per operation, and the longest single inline wait.
            // Both deliberately tiny: this waits inside the request or job that
            // asked, and the real budget is the queue's (`ErrorClass::Bridge`,
            // five attempts on jittered backoff).
            'attempts' => (int) env('WA_BRIDGE_ATTEMPTS', 2),
            'max_delay_ms' => (int) env('WA_BRIDGE_MAX_DELAY_MS', 250),
        ],
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
        | 2. Verified custom domain — an exact host in `tenant_domains`, and only
        |    a row whose ownership challenge *and* TLS check passed (Req 9.7 / A9).
        |    Ahead of the subdomain door because it is the more specific claim: an
        |    exact verified host rather than a pattern under an apex. An
        |    **unverified** claim resolves nothing at all — if it did, a tenant
        |    could name a host it does not own and receive requests as that tenant.
        | 3. Subdomain — `{slug}.{apex}`. Weakest signal (it comes from the
        |    request host), so it only ever acts as a lookup key for a known
        |    tenant and never for URL generation (Req 9.1 / A9).
        | 4. API key — machine callers on /api/v1. Last because a human panel
        |    session and an API key never co-occur on the same request; ordering
        |    it last keeps a stray header from overriding a logged-in user.
        |
        | Doors 2 and 3 are both host-derived, and the rule is the same for both:
        | the host is a **lookup key** into rows the platform already trusts,
        | never an assertion about the caller. The inverse also holds — URL
        | *generation* never reads a host at all (Property 27).
        */
        'resolvers' => [
            SessionTenantResolver::class,
            CustomDomainTenantResolver::class,
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

        // Platform-owned labels that can never be a tenant subdomain — nor a
        // custom-domain claim under an apex. `App\Services\Domains\PlatformHosts`
        // is the single reader, so the resolver and the registrar cannot disagree
        // about which labels belong to the platform.
        'reserved_subdomains' => [
            'www', 'app', 'admin', 'api', 'assets', 'static', 'cdn',
            'mail', 'smtp', 'bridge', 'webhooks', 'status', 'billing', 'support',
        ],

        /*
        |----------------------------------------------------------------------
        | Per-tenant custom domains (Req 9.3, 9.7 / A9)
        |----------------------------------------------------------------------
        | A tenant may map its own host (`chat.acme.example`) and, once **both**
        | halves of Req 9.7 hold — an ownership challenge succeeded and a trusted
        | TLS certificate covering the host was confirmed — that host resolves the
        | tenant and becomes the base its URLs are built from.
        |
        | What is tunable here is *how* the two halves are checked: where the
        | challenge is published, how long a caller may wait, how often the proof
        | is re-tested. What is **not** tunable, anywhere, is whether they are
        | checked. There is no flag below that verifies a domain, skips the
        | certificate, or admits an unverified claim to the resolver chain —
        | `verified_at` is written only by `App\Services\Domains\DomainVerifier`
        | and only on evidence.
        */
        'domains' => [
            // DNS label the TXT challenge is published under, prefixed to the
            // claimed host: `_wa-challenge.chat.acme.example`. Leading underscore
            // by convention (RFC 8552) so it can never collide with a real
            // hostname the tenant also wants to serve.
            'dns_record_prefix' => env('WA_DOMAIN_DNS_PREFIX', '_wa-challenge'),

            // Path the ACME-style HTTP challenge is served and read at. The route
            // in routes/web.php is registered *from this value*, so the URL the
            // verifier fetches and the URL the platform answers on are one string.
            'http_challenge_path' => env('WA_DOMAIN_HTTP_CHALLENGE_PATH', '.well-known/wa-domain-challenge'),

            // How long a freshly issued challenge stays satisfiable. Bounds the
            // *initial* grant only: once verified, the challenge becomes the
            // standing proof re-checks re-run, and has no deadline.
            'challenge_ttl_hours' => (int) env('WA_DOMAIN_CHALLENGE_TTL_HOURS', 72),

            // Port the TLS half connects to. Configurable for a deployment whose
            // custom domains terminate TLS somewhere unusual; 443 otherwise.
            'tls_port' => (int) env('WA_DOMAIN_TLS_PORT', 443),

            /*
            |------------------------------------------------------------------
            | The network seam (Req 31.3 / NFR2)
            |------------------------------------------------------------------
            | DNS, HTTP and TLS all reach hosts *tenants* supply, so they are the
            | one dependency on the platform whose targets are attacker-chosen.
            | `network` is the shipped, real implementation; any other value must
            | name an `App\Services\Domains\DomainProbe` (which is how the test
            | suite binds its fake, and nowhere else — the fake is not
            | autoloadable in production).
            */
            'probe' => [
                'driver' => env('WA_DOMAIN_PROBE_DRIVER', 'network'),

                // Both bounded, and both small: a verification runs inside a
                // request a tenant is watching or inside the re-check sweep, and
                // a hanging lookup there is a stalled worker caused by somebody
                // else's typo.
                'connect_timeout' => (int) env('WA_DOMAIN_PROBE_CONNECT_TIMEOUT', 3),
                'timeout' => (int) env('WA_DOMAIN_PROBE_TIMEOUT', 5),

                // Circuit breaker + inline retry budget. Only the platform's own
                // inability to look counts as a failure here; a tenant's dead host
                // returns "not served" and cannot trip the shared breaker.
                'guard' => [
                    'enabled' => (bool) env('WA_DOMAIN_PROBE_GUARD', true),
                    'breaker' => 'domains',
                    'attempts' => (int) env('WA_DOMAIN_PROBE_ATTEMPTS', 2),
                    'max_delay_ms' => (int) env('WA_DOMAIN_PROBE_MAX_DELAY_MS', 250),
                ],
            ],

            /*
            |------------------------------------------------------------------
            | Scheduled re-validation (`wa:domains:recheck`)
            |------------------------------------------------------------------
            | A verification is a statement about one instant. Certificates
            | expire, records get tidied away, domains change hands — and the last
            | of those is the dangerous one, because a name pointed back at the
            | platform under a new owner would keep routing as the old tenant. So
            | the proof is re-tested, cheaply: stale rows only, stalest first, a
            | bounded batch per tick.
            */
            'recheck' => [
                'interval_hours' => (int) env('WA_DOMAIN_RECHECK_INTERVAL_HOURS', 24),
                'batch' => (int) env('WA_DOMAIN_RECHECK_BATCH', 25),
            ],
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
                // The WA Bridge's own refusals (task 6.x's substrate). It reads the
                // sidecar's error `code` first and its HTTP status second, so a
                // `409 session_not_connected` is retried on the BRIDGE budget while a
                // `422 not_on_whatsapp` fails fast. Returns null for anything that is
                // not a bridge exception, so the chain continues.
                BridgeErrorClassifier::class,
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

            // The canonical base URL and the platform settings it is resolved from
            // (`App\Services\Url\BaseUrlCache`, `App\Services\Platform\PlatformSettings`).
            // Both namespaces are version-bumped by their writers, so the TTL only bounds
            // how long an *orphaned* entry occupies the store.
            'base_url' => (int) env('WA_CACHE_TTL_BASE_URL', 300),
            'platform_settings' => (int) env('WA_CACHE_TTL_PLATFORM_SETTINGS', 300),

            // The host -> tenant lookup every request on a verified custom domain
            // performs, plus the verified-host list task 5.4's allowlist reads
            // (`App\Services\Domains\VerifiedDomainDirectory`). Version-bumped by
            // `TenantDomain`'s save/delete hooks, so a verification or a revocation
            // lands on the next request rather than after this TTL.
            'tenant_domains' => (int) env('WA_CACHE_TTL_TENANT_DOMAINS', 300),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Canonical base URL (Req 9.1, 9.3 / A9)
    |--------------------------------------------------------------------------
    | The origin every absolute link, webhook callback, signed URL and OAuth
    | redirect is built from. It is **not** configured here: the chain is
    |
    |   per-tenant verified custom domain (`tenant_domains`)
    |     -> `platform_settings['base_url']` (admin-editable, no redeploy)
    |     -> `APP_URL` (config/app.php)
    |
    | resolved by `App\Services\Url\BaseUrl`. What lives here is the one *policy*
    | around that chain that an environment legitimately differs on.
    |
    | Two things this file cannot do, by design. It cannot introduce a fourth
    | source of the origin — a config key holding a host would be a fourth place
    | to look and a fourth thing to get wrong. And nothing here can make URL
    | generation read the request `Host`/`X-Forwarded-Host` header: that is
    | Property 27, and it is structural (no implementation of `BaseUrl` takes a
    | request at all), not a flag.
    |
    | Note on tenant subdomains: `tenant.{slug}.{apex}` is a *derivation* of the
    | platform apex, produced by `UrlBuilder::tenantSubdomain()` (task 5.2) for
    | callers that want it, and is deliberately not a link in the precedence
    | chain — Req 9.3 lists three sources, and a tenant with no subdomain must
    | still have a base.
    */
    'url' => [
        // Force emitted URLs to HTTPS. A webhook callback or signed link served
        // over http:// puts a credential-grade token in cleartext, so the answer
        // is yes everywhere except `local`/`testing`.
        //
        // Leave unset (the default) and the environment decides. Set it only for a
        // deployment the environment name gets wrong: `false` for an internal
        // staging box with no certificate, `true` for a node behind a proxy that
        // terminates TLS while APP_URL is still written as http.
        'force_https' => env('WA_URL_FORCE_HTTPS'),

        /*
        |----------------------------------------------------------------------
        | Signed / expiring URLs (Req 9.6 / A9)
        |----------------------------------------------------------------------
        | Export downloads and payment links are signed against the **canonical
        | host** (`App\Services\Url\SignedUrlSigner`): the authority is a field
        | inside the HMAC, so a valid signature cannot be lifted onto another host.
        |
        | Two things deliberately absent from this group, because neither is an
        | operator's decision to make:
        |
        |   - **the 3600-second ceiling.** Req 9.6 names it, and it is a constant
        |     (`SignedUrlSigner::MAX_WINDOW_SECONDS`). A config key would be a way
        |     to raise a security ceiling from an env file; the key below sets the
        |     default *under* it and is refused, loudly, if it exceeds it.
        |   - **the signing secret.** It comes from `SigningSecretStore` under the
        |     platform-wide scope `url:signed`, so it is sealed by the same KMS as
        |     every other HMAC secret and inherits the dual-secret rotation in
        |     `security.hmac` below. That overlap window (48h) is far longer than any
        |     link's life, so rotating it never breaks a link already in an inbox —
        |     which a secret derived from APP_KEY would.
        */
        'signed' => [
            // Lifetime of a signed link when the caller names no explicit expiry.
            // Must be between 1 and 3600 seconds; anything else is a deployment error
            // and is refused at the first link rather than clamped, so nobody hands
            // out a URL they believe lives longer than the platform allows.
            'ttl_seconds' => (int) env('WA_URL_SIGNED_TTL', 900),
        ],

        /*
        |----------------------------------------------------------------------
        | Accepted request hosts (Req 9.4, 9.5 / A9)
        |----------------------------------------------------------------------
        | The *other* half of Property 27. URL generation never reads the request
        | host (above); this decides which hosts the platform will answer on at
        | all. `App\Services\Domains\HostAllowlist` is the single reader, and it
        | builds the list from data rather than from config: the apex(es), one
        | label under each (tenant subdomains), and every **verified** custom
        | domain — the same rows, through the same cache version, that tenant
        | resolution reads, so "accepted" and "resolves a tenant" cannot answer
        | differently. Nothing below can add an unverified domain to it.
        |
        | Wired into Laravel's `TrustHosts` *and* into
        | `App\Http\Middleware\EnforceAllowedHost` (see `bootstrap/app.php`): the
        | first restricts Symfony's `Request::getHost()`, the second turns an
        | unlisted host into the typed 400 of Req 9.5 before routing dispatches.
        */
        'hosts' => [
            // Whether an unrecognised host is refused. Unset (the default) follows
            // the environment — enforced everywhere except `local`/`testing`, which
            // is what Laravel's own `TrustHosts` does: a dev box is reached by
            // 127.0.0.1, a tunnel name, or whatever a container published, and none
            // of those is the configured base. Set it to `true` on a staging
            // environment to rehearse production's refusals.
            'enforce' => env('WA_URL_ENFORCE_HOSTS'),

            // Extra exact hosts to accept. For an internal name a load balancer,
            // service mesh, or uptime probe dials the node by — not a place to put
            // a tenant domain, which belongs in `tenant_domains` and must be
            // verified. Comma-separated in the environment. Empty in normal
            // operation.
            'additional' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('WA_URL_ADDITIONAL_HOSTS', ''))
            ), static fn (string $host): bool => $host !== '')),

            // Paths served on **any** host, because their whole point is to answer
            // before a host is recognised. `up` is the framework health endpoint
            // (an orchestrator dials a pod by IP, so its `Host` matches no
            // allowlist, and a rejected liveness probe is a restart loop that looks
            // like an application crash).
            //
            // The domain-ownership challenge path is *not* listed here: it is added
            // by `HostAllowlist` from `wa.tenancy.domains.http_challenge_path`, the
            // same key `routes/web.php` registers the route from, so the exemption
            // and the route cannot drift apart. An http-01 challenge is fetched at
            // the host under verification *before* it is verified — the one state in
            // which that host is legitimately not on the allowlist.
            //
            // Add to this list only for a path that binds no tenant, reads nothing
            // tenant-scoped, and emits no URL.
            'unrestricted_paths' => ['up'],
        ],

        /*
        |----------------------------------------------------------------------
        | Trusted proxies (Req 9.4 / A9; request attribution, NFR3)
        |----------------------------------------------------------------------
        | Empty by default, and that is a security decision:
        | `X-Forwarded-For`/`-Proto` are client-supplied strings, so trusting them
        | from an untrusted source lets any caller pick its own IP address — which
        | the platform believes when it rate-limits, when the anti-fraud engine
        | scores a device/IP, and when the audit trail records where a request came
        | from. A deployment behind a load balancer lists it here and only then are
        | its headers believed.
        |
        | `*` (trust the immediate peer, whatever it is) is accepted for a managed
        | load balancer with no stable address, but it is never a default and never
        | inferred. `App\Http\Middleware\TrustProxies` is the only reader, and it
        | trusts the four standard forwarded headers — not `X-Forwarded-Prefix`,
        | which would let a header rewrite the framework's notion of the
        | application root.
        |
        | None of this can move an emitted URL: `ConfiguredBaseUrl` reads no request
        | state at all (Property 27). It affects *attribution*, and the host the
        | accepted-host check sees.
        */
        'proxies' => [
            'trusted' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('WA_TRUSTED_PROXIES', ''))
            ), static fn (string $proxy): bool => $proxy !== '')),
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

            /*
            |------------------------------------------------------------------
            | Scheduled rotation (Req 32.6 / NFR3, task 4.2)
            |------------------------------------------------------------------
            | Two rotations, both non-destructive, run by `wa:security:rotate-deks`
            | and `wa:security:rotate-master-key` (see `routes/console.php`):
            |
            |   - a **DEK** rotation adds a version and demotes the previous one to
            |     RETIRING, which still decrypts — so nothing stored becomes
            |     unreadable and re-encryption stays lazy;
            |   - a **master key** rotation re-seals stored DEKs and signing secrets
            |     under the new key. Only the *seal* changes, never the DEK, so no
            |     ciphertext anywhere is touched.
            |
            | Nothing here can retire a key version: RETIRED refuses to decrypt, so
            | retiring one that is still referenced would be silent data loss. That
            | step needs a registry of every encrypted column and is deliberately
            | absent until one exists (see `App\Services\Security\DekRotator`).
            */
            'rotation' => [
                // Age of a lineage's ACTIVE version at which it is rotated. 90 days is
                // the usual compliance answer; lowering it costs one row per lineage
                // per rotation and nothing else.
                'dek_after_days' => (int) env('WA_DEK_ROTATE_AFTER_DAYS', 90),

                // Lineages per DEK sweep, and rows per store per re-wrap pass. Both
                // are bounded because every one is a key-store round trip: a large
                // estate drains over successive ticks (or `--passes`) rather than in
                // one unbounded run.
                'batch' => (int) env('WA_KEY_ROTATE_BATCH', 100),

                /*
                | Tables holding master-key-sealed material, which a master-key
                | rotation must therefore re-seal. Every entry implements
                | `App\Services\Security\RewrapStore`.
                |
                | This list is the difference between a complete rotation and a
                | time-bomb: a table missing from it stays sealed under the retired
                | key, and becomes permanently unopenable the moment an operator
                | removes that key from the key store. A phase that seals anything new
                | adds one class and one line here — and an entry that cannot be
                | resolved is fatal, because a silently skipped store is a silently
                | un-rotated table.
                */
                'stores' => [
                    EncryptionKeyRewrapStore::class,
                    SigningSecretRewrapStore::class,
                ],
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | The KMS / Vault seam (Req 32.6 / NFR3; NFR4.2)
        |----------------------------------------------------------------------
        | Read **only** when `encryption.wrapper` is `KmsKeyWrapper`; the default
        | `ConfigMasterKeyWrapper` never touches any of it, so a single-node install
        | can ignore this block entirely (design § Optional dependency matrix).
        |
        | Switching the platform onto a KMS is four environment values:
        |
        |   WA_KEY_WRAPPER="App\Services\Security\KmsKeyWrapper"
        |   WA_KMS_DRIVER=vault
        |   WA_VAULT_ADDR=https://vault.internal:8200
        |   WA_VAULT_TOKEN=…            # or WA_VAULT_TOKEN_FILE for an injected token
        |
        | Nothing above the seam changes: the ciphertext format, the DEK lifecycle,
        | and every existing test stay as they are.
        */
        'kms' => [
            // `vault` for the Vault transit engine, or a class implementing
            // App\Services\Security\KmsClient. A misconfiguration is a startup error,
            // never a silently unencrypted platform.
            'driver' => env('WA_KMS_DRIVER', 'vault'),

            /*
            | Vault transit (App\Services\Security\VaultTransitKmsClient):
            |   vault secrets enable transit
            |   vault write -f transit/keys/wa-master type=aes256-gcm96
            | The key type must be an AEAD one — the tenant binding is carried in
            | transit's `associated_data`, and a non-AEAD key would ignore it.
            */
            'vault' => [
                'address' => env('WA_VAULT_ADDR', ''),

                // The token itself, or a file it is read from on every request so an
                // agent-renewed token needs no restart. Never logged.
                'token' => env('WA_VAULT_TOKEN', ''),
                'token_file' => env('WA_VAULT_TOKEN_FILE'),

                // Enterprise / HCP namespace, if any.
                'namespace' => env('WA_VAULT_NAMESPACE'),

                'mount' => env('WA_VAULT_TRANSIT_MOUNT', 'transit'),
                'key' => env('WA_VAULT_TRANSIT_KEY', 'wa-master'),

                // Tight on purpose: a KMS call sits inside every encrypted read and
                // write, so a slow key store must fail fast (and open its breaker)
                // rather than hold a request open.
                'timeout' => (int) env('WA_VAULT_TIMEOUT', 5),
                'connect_timeout' => (int) env('WA_VAULT_CONNECT_TIMEOUT', 3),
            ],

            /*
            | A KMS is a fallible network dependency on the hot path, so it gets the
            | same treatment as every other one: the circuit breaker of task 3.2 and
            | the retry matrix of task 3.6 (`App\Services\Security\GuardedKmsClient`).
            |
            | The retry budget is deliberately tiny because it waits *inline*, inside
            | whatever request asked to encrypt something. `KeyUnavailableException`
            | is retryable, so the queue worker or the HTTP client retries the whole
            | unit of work — which is cheaper and safer than sleeping here.
            */
            'guard' => [
                // False removes the breaker and the inline retry, leaving the raw
                // client. For a test or a diagnosis, not for production.
                'enabled' => (bool) env('WA_KMS_GUARD', true),

                // `circuit_breakers.name` under CircuitScope::Provider. One name for
                // the key store as a whole: it is a platform dependency, not a
                // per-tenant one, and a per-tenant breaker would let one tenant's
                // failures hide a platform-wide outage.
                'breaker' => env('WA_KMS_BREAKER', 'kms'),

                'attempts' => (int) env('WA_KMS_ATTEMPTS', 2),
                'max_delay_ms' => (int) env('WA_KMS_MAX_DELAY_MS', 250),
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | HMAC signing secrets & dual-secret rotation (Req 32.6 / NFR3)
        |----------------------------------------------------------------------
        | Webhook secrets are shared with a peer, and the two of you cannot swap one
        | at the same instant — so rotation keeps the **old secret verifying** while
        | only the new one signs (`App\Services\Security\SigningSecretStore`,
        | `wa:security:rotate-hmac`). Without that window, rotating rejects every
        | signature the peer has in flight, and the pressure that creates ("skip
        | verification for a minute") is the spoofing the signature exists to stop.
        |
        | Secrets are sealed by the same `KeyWrapper` DEKs use, so they inherit the
        | KMS above and are re-sealed by the same master-key rotation.
        */
        'hmac' => [
            // Length of a newly issued secret. 32 bytes is a full SHA-256 block of
            // entropy; the floor in code is 16.
            'secret_bytes' => (int) env('WA_HMAC_SECRET_BYTES', 32),

            // HMAC hash, and how a signature is presented (`sha256=<hex>` — the shape
            // Meta and most gateways use). Verification also accepts a bare digest.
            //
            // Changing the algorithm is an *algorithm migration*, not a rotation: it
            // invalidates in-flight signatures for every scope at once, which is why
            // it is platform config rather than something a rotation can smuggle in.
            'algorithm' => env('WA_HMAC_ALGORITHM', 'sha256'),
            'prefix' => env('WA_HMAC_PREFIX', 'sha256='),

            // Age at which a scope's signing secret is rotated.
            'rotate_after_days' => (int) env('WA_HMAC_ROTATE_AFTER_DAYS', 30),

            // How long the previous secret keeps verifying. Must comfortably exceed
            // the time it takes to reconfigure a peer — 0 is a hard cut-over and
            // rejects everything already signed.
            'overlap_hours' => (int) env('WA_HMAC_OVERLAP_HOURS', 48),

            // Scopes rotated per sweep.
            'batch' => (int) env('WA_HMAC_BATCH', 100),
        ],

        /*
        |----------------------------------------------------------------------
        | Vector-store tenant isolation (Req 32.1 / NFR3 — Correctness Property 20)
        |----------------------------------------------------------------------
        | The STRIDE row for the vector store is *"payload filter `tenant_id` on every
        | query; per-tenant namespaces"*. Both halves live in
        | `App\Services\Chatbot\Rag\VectorFilter` — the filter because it is the type
        | `VectorStore`'s methods accept (so an unfiltered query does not compile), the
        | namespace because two drivers must not invent two namings for one tenant.
        |
        | There is deliberately **no switch here for either of them**. An external ANN
        | index has no equivalent of `TenantScope`: a query that omits the tenant term
        | returns other tenants' neighbours silently, with a plausible answer and no
        | error, so "filter by tenant" cannot be an operator preference. The only knob
        | is what the per-tenant namespace is called.
        |
        | Driver selection, endpoints, and credentials belong to task 13.2 and land in
        | their own config section — this section is the isolation contract, nothing else.
        */
        'vector' => [
            // Prefix of a tenant's collection/namespace: `{prefix}_{tenantId}`. Change it
            // only alongside a re-index — existing vectors do not move themselves.
            'namespace_prefix' => env('WA_VECTOR_NAMESPACE_PREFIX', 'wacb'),
        ],

        /*
        |----------------------------------------------------------------------
        | PII redaction (Req 7.3 / A7; Req 32.2 / NFR3 — Correctness Property 15)
        |----------------------------------------------------------------------
        | Two consumers, one definition of what PII is (`App\Support\Pii\PiiScanner`):
        |
        |   - `App\Services\Security\Pii\PiiRedactor` — **reversible**, on the way to an
        |     LLM/embedding provider. Values become tokens; a request-scoped, in-memory
        |     token map turns them back for the customer-facing reply.
        |   - `App\Support\Pii\LogPiiScrubber` — **irreversible**, wired as a Monolog
        |     processor onto every channel (`App\Logging\RedactingLogManager`), so phone
        |     numbers are masked and message bodies are hashed on every log line.
        |
        | Nothing here can switch either of them off. Detection thresholds are not
        | configurable either: whether 16 digits are a card number is a Luhn check, not
        | an operator preference. What *is* configurable is the tenant-specific pattern
        | list and the cost bounds around it.
        */
        'pii' => [
            /*
            | Implementation of `App\Services\Security\Pii\TenantPiiPatternSource`. The
            | default reads the list below; a tenant-settings screen replaces it with a
            | database-backed source and nothing else changes.
            */
            'pattern_source' => env('WA_PII_PATTERN_SOURCE', ConfigTenantPiiPatternSource::class),

            /*
            | Extra identifiers a tenant considers PII, as **regex bodies** (no
            | delimiters, no flags — both are chosen in code):
            |
            |   'tenant_patterns' => [
            |       '*'             => ['\bPOL-\d{8}\b'],   // every tenant
            |       'acme'          => ['\bORD[A-Z]{2}\d{6}\b'],
            |       '01HZY8Q0J9...' => ['\b[A-Z]{2}\d{7}\b'],
            |   ],
            |
            | Keyed by `*`, tenant slug, or tenant id. Every entry is validated before
            | it is ever run (`TenantPatternCompiler`): one that does not compile, that
            | matches the empty string, that would swallow most of an ordinary sentence,
            | or that has the shape of a catastrophically backtracking regex is
            | **skipped with a logged reason**, never applied and never fatal — a typo
            | in this list must not be able to stop a tenant's messages from being
            | redacted by the built-in detectors.
            */
            'tenant_patterns' => [],

            // Bounds on that list. A pattern is per-message work on the egress path, so
            // both the count and the length are capped rather than trusted.
            'max_patterns_per_tenant' => (int) env('WA_PII_MAX_PATTERNS', 16),
            'max_pattern_length' => (int) env('WA_PII_MAX_PATTERN_LENGTH', 200),

            /*
            | Backtracking steps PCRE may spend on one tenant pattern before giving up
            | (`App\Support\Pii\BoundedPcre`). This is the *real* ReDoS defence — the
            | validator's heuristics only catch the obvious shapes. A pattern that
            | exhausts the budget contributes nothing for that text and is not retried
            | on it; the built-in detectors have already run, so the message is still
            | redacted. Raising it buys more expressive patterns at the cost of a
            | larger worst case per message.
            */
            'backtrack_limit' => (int) env('WA_PII_BACKTRACK_LIMIT', 100_000),

            /*
            | Cost bounds for the log processor, which runs on *every* log line and so
            | must never be the slowest thing in a request. Exceeding a bound truncates
            | or caps — it never emits unredacted text.
            */
            'log' => [
                'max_string_length' => (int) env('WA_PII_LOG_MAX_STRING_LENGTH', 4000),
                'max_depth' => (int) env('WA_PII_LOG_MAX_DEPTH', 8),
                'max_items' => (int) env('WA_PII_LOG_MAX_ITEMS', 100),
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | Prompt guardrails / jailbreak defence (Req 13.8 / B4; Req 32.7 / NFR3)
        |----------------------------------------------------------------------
        | The four layers of design § AI 1.3 — instruction hierarchy, input
        | classifier, delimiter fencing, output validation — read by
        | `App\Services\Abuse\LayeredGuardrail` and by nothing else.
        |
        | **There is no key here that disables inspection**, and that is deliberate:
        | design's abuse table lists prompt injection as a hard-enforced defence, so
        | what is configurable is *how strict* and *how loud*, never *whether*. In
        | particular there is no "fail open" switch — an input the classifier cannot
        | classify blocks (fail closed) and a reply that fails validation is
        | suppressed (fail safe), in code, with no bypass parameter.
        */
        'guardrail' => [
            /*
            | Characters above which an inbound text is refused as OVERSIZED rather
            | than partially examined. Twice WhatsApp's own 4 096-character message
            | limit, so a legitimate customer message cannot reach it and exceeding it
            | is itself anomalous. Lowering it makes the guardrail stricter; raising it
            | widens the window an unexamined payload could hide in.
            */
            'max_input_chars' => (int) env('WA_GUARDRAIL_MAX_INPUT_CHARS', 8192),

            /*
            | Whether FLAG-level findings (obfuscation, a degraded auxiliary classifier)
            | get an `abuse_events` row. BLOCKs are always recorded — that is not a
            | tunable — so turning this off only trades reviewer signal for write volume.
            */
            'record_flags' => (bool) env('WA_GUARDRAIL_RECORD_FLAGS', true),

            /*
            | **Optional** additional `App\Services\Abuse\InjectionClassifier`
            | implementations, e.g. a model-backed one once the LLM layer lands (task
            | 15.x). They can only make a verdict stricter: an optional classifier that
            | throws degrades to the deterministic verdict (recorded as
            | CLASSIFIER_DEGRADED), never to "allow". The deterministic
            | `HeuristicInjectionClassifier` is *not* in this list because it is not
            | optional — emptying this array leaves it running.
            */
            'classifiers' => [],

            /*
            | Extra platform/tenant policy patterns, as full regexes (delimiters
            | included). A match raises CUSTOM_PATTERN, which blocks. An unusable
            | pattern is a startup error rather than a rule that silently never fires.
            */
            'patterns' => [
                'injection' => [],
            ],

            'fence' => [
                /*
                | The sentence prepended to the platform layer telling the model that the
                | fenced block is untrusted data. `%s` twice = the opening and closing
                | delimiters. Overridable per deployment (wording and language matter to
                | how well a model follows it); null uses
                | `InstructionHierarchy::DEFAULT_NOTICE`. It cannot be removed — fencing
                | without the notice leaves the model no reason to treat the block as data.
                */
                'notice' => env('WA_GUARDRAIL_FENCE_NOTICE'),
            ],

            'output' => [
                /*
                | Length of the verbatim word run that counts as a system-prompt leak.
                | Floored at 4 in code: a two- or three-word window would flag ordinary
                | phrases ("how can i help"). Eight consecutive words are something a model
                | reproduces by copying, not by paraphrase.
                */
                'leak_shingle_words' => (int) env('WA_GUARDRAIL_LEAK_SHINGLE_WORDS', 8),

                /*
                | Replies matching any of these are suppressed (OUTPUT_POLICY_VIOLATION) —
                | for compliance phrasing a business may not use. Full regexes; one that
                | does not compile is skipped rather than fatal, because output validation
                | must keep running on the built-in checks.
                */
                'banned_patterns' => [],
            ],

            /*
            | The per-conversation rate limit design § AI 1.3 asks for: repeated blocked
            | messages in one conversation trip a **bounded** kill-switch on the session.
            | Bounded because this arm is automatic, and an unbounded automatic kill would
            | be a denial-of-service an attacker could aim at a tenant by sending injection
            | payloads to their number. `block_limit` of 0 disables the arm; only an
            | operator flip may be indefinite.
            */
            'conversation' => [
                'block_window_seconds' => (int) env('WA_GUARDRAIL_BLOCK_WINDOW_SECONDS', 900),
                'block_limit' => (int) env('WA_GUARDRAIL_BLOCK_LIMIT', 5),
                'auto_kill_seconds' => (int) env('WA_GUARDRAIL_AUTO_KILL_SECONDS', 3600),
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | Abuse trail & per-session kill-switch (Req 32.7 / NFR3)
        |----------------------------------------------------------------------
        | `abuse_events` is append-only (triggers + grants, like `audit_logs`) and
        | tenant-scoped on read; rows written on the anonymous signup path carry
        | `tenant_id = NULL`. Nothing here can disable recording: a block that is not
        | recorded is indistinguishable from a block that never happened.
        */
        'abuse' => [
            /*
            | Key for the identity digests (`App\Services\Abuse\IdentityDigest`) that let
            | the platform *count* an email/phone/device without *storing* one. Keyed
            | rather than a bare hash because phone numbers and email addresses are
            | enumerable. Defaults to APP_KEY; set this separately so rotating the app key
            | does not break the abuse trail's correlations.
            */
            'hash_key' => env('WA_ABUSE_HASH_KEY'),

            /*
            | Cache namespace for kill-switch state and the velocity counters. The TTL is
            | the *upper bound on a counting window*, so it must comfortably exceed the
            | longest window configured under `anti_fraud` (the device counter's day) or a
            | counter would expire mid-window and reset itself.
            */
            'cache' => [
                'store' => env('WA_ABUSE_CACHE_STORE'),
                'ttl' => (int) env('WA_ABUSE_CACHE_TTL', 172_800),
            ],

            'kill_switch' => [
                /*
                | How often an attempt to use a killed session is recorded. A campaign
                | hitting a stopped session must leave a trail without flooding it; the
                | refusal itself is never throttled, only its recording.
                */
                'attempt_record_seconds' => (int) env('WA_KILL_SWITCH_ATTEMPT_RECORD_SECONDS', 300),
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | Anti-fraud: signup velocity, OTP, device/IP (Req 32.7 / NFR3)
        |----------------------------------------------------------------------
        | design § Abuse / anti-fraud row 1 — free-trial farming. Read by
        | `App\Services\Abuse\HeuristicAntiFraudGuard`, which runs on the anonymous
        | registration path and therefore keys every counter on a keyed digest of an
        | identity or an address, never on a tenant.
        |
        | Every limit is `{max, window_seconds}`; `max = 0` switches that counter off.
        | **Every refusal expires with its window** — there is no persistent block flag
        | anywhere in this layer, because signup traffic arrives through carrier NAT and
        | corporate egress, so a permanent address-based block would eventually lock out
        | real customers. The address limits are deliberately looser than the
        | identity/device ones for the same reason, and `trusted_ips` is the operator
        | break-glass for a known shared egress.
        */
        'anti_fraud' => [
            'signup' => [
                // One mailbox / phone number: three attempts an hour.
                'identity' => [
                    'max' => (int) env('WA_SIGNUP_IDENTITY_MAX', 3),
                    'window_seconds' => (int) env('WA_SIGNUP_IDENTITY_WINDOW', 3600),
                ],

                // One browser profile: three trials a day. Defeated by clearing storage —
                // which is why it is one of four counters, not the only one.
                'device' => [
                    'max' => (int) env('WA_SIGNUP_DEVICE_MAX', 3),
                    'window_seconds' => (int) env('WA_SIGNUP_DEVICE_WINDOW', 86_400),
                ],

                // One address: looser, because an office shares one.
                'ip' => [
                    'max' => (int) env('WA_SIGNUP_IP_MAX', 8),
                    'window_seconds' => (int) env('WA_SIGNUP_IP_WINDOW', 3600),
                ],

                // One /24 (or /64): the counter an attacker with a range has to burn.
                'subnet' => [
                    'max' => (int) env('WA_SIGNUP_SUBNET_MAX', 20),
                    'window_seconds' => (int) env('WA_SIGNUP_SUBNET_WINDOW', 3600),
                ],
            ],

            'otp' => [
                // Sends per identity — protects the SMS bill as much as the account.
                'request_identity' => [
                    'max' => (int) env('WA_OTP_REQUEST_IDENTITY_MAX', 5),
                    'window_seconds' => (int) env('WA_OTP_REQUEST_IDENTITY_WINDOW', 3600),
                ],

                'request_ip' => [
                    'max' => (int) env('WA_OTP_REQUEST_IP_MAX', 20),
                    'window_seconds' => (int) env('WA_OTP_REQUEST_IP_WINDOW', 3600),
                ],

                // Wrong codes before verification pauses. This is what makes a 6-digit
                // code unguessable; cleared by a correct code, so an honest customer who
                // mistypes twice is not locked out for the rest of the window.
                'failure' => [
                    'max' => (int) env('WA_OTP_FAILURE_MAX', 5),
                    'window_seconds' => (int) env('WA_OTP_FAILURE_WINDOW', 900),
                ],
            ],

            /*
            | Addresses (or dotted/colon prefixes like `203.0.113.`) whose *address*
            | counters are skipped: a customer's office range, the platform's own test
            | runners. Identity and device counting stay in force, so an allowlisted
            | address is not an unlimited signup source.
            */
            'trusted_ips' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('WA_ANTI_FRAUD_TRUSTED_IPS', ''))
            ), static fn (string $ip): bool => $ip !== '')),

            /*
            | Email domains refused outright (design: "disposable-email/velocity block").
            | A short, high-signal list plus whatever `WA_DISPOSABLE_EMAIL_DOMAINS` adds —
            | not a mirror of a 100 000-entry blocklist, which would belong in a table and
            | would misfire on domains that stop being disposable.
            */
            'disposable_email_domains' => array_values(array_unique([
                ...[
                    'mailinator.com', 'guerrillamail.com', 'sharklasers.com', '10minutemail.com',
                    'tempmail.com', 'temp-mail.org', 'yopmail.com', 'throwawaymail.com',
                    'getnada.com', 'trashmail.com', 'dispostable.com', 'maildrop.cc',
                    'fakeinbox.com', 'mailnesia.com', 'discard.email', 'mohmal.com',
                ],
                ...array_filter(array_map(
                    static fn (string $domain): string => strtolower(trim($domain)),
                    explode(',', (string) env('WA_DISPOSABLE_EMAIL_DOMAINS', ''))
                ), static fn (string $domain): bool => $domain !== ''),
            ])),
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
