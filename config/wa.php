<?php

declare(strict_types=1);

use App\Enums\TenantTier;
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
