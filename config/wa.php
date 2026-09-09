<?php

declare(strict_types=1);

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
