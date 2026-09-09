<?php

declare(strict_types=1);

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
    'tenancy' => [
        'default_timezone' => env('WA_TENANT_DEFAULT_TIMEZONE', 'UTC'),
        'default_locale' => env('WA_TENANT_DEFAULT_LOCALE', 'en'),
        'trial_days' => (int) env('WA_TENANT_TRIAL_DAYS', 14),
        'storage_prefix' => 'tenants',
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
    ],

];
