<?php

declare(strict_types=1);

use App\Exceptions\Url\InvalidBaseUrlException;
use App\Models\AuditLog;
use App\Models\PlatformSetting;
use App\Services\Platform\PlatformSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Platform-wide settings (Req 9.3 / A9; Req 24.2 / D1; Req 30.4 / NFR1)
|--------------------------------------------------------------------------
| The admin-editable override layer under the canonical base URL: cached so the
| base-URL resolver is not a query per emitted link, audited because changing it
| redirects every webhook callback and signed link the platform emits, and validated
| on write so a typo cannot do that.
*/

beforeEach(function (): void {
    Cache::flush();
});

it('reads, writes and removes a setting', function (): void {
    $settings = app(PlatformSettings::class);

    expect($settings->get('support_email'))->toBeNull()
        ->and($settings->has('support_email'))->toBeFalse();

    $settings->set('support_email', 'ops@example.com');

    expect($settings->get('support_email'))->toBe('ops@example.com')
        ->and($settings->has('support_email'))->toBeTrue();

    $settings->set('support_email', 'help@example.com');

    expect($settings->get('support_email'))->toBe('help@example.com')
        // One row per setting: unique(key) makes "read the setting" a single-row lookup.
        ->and(PlatformSetting::query()->where('key', 'support_email')->count())->toBe(1);

    $settings->forget('support_email');

    expect($settings->get('support_email'))->toBeNull();
});

it('round-trips values that are not strings', function (): void {
    $settings = app(PlatformSettings::class);

    $settings->set('maintenance', true);
    $settings->set('retry_after', 30);
    $settings->set('branding', ['colour' => '#25D366', 'logo' => null]);

    expect($settings->get('maintenance'))->toBeTrue()
        ->and($settings->get('retry_after'))->toBe(30)
        ->and($settings->get('branding'))->toBe(['colour' => '#25D366', 'logo' => null])
        ->and($settings->all())->toBe([
            'branding' => ['colour' => '#25D366', 'logo' => null],
            'maintenance' => true,
            'retry_after' => 30,
        ]);
});

it('stores the base URL in its canonical form', function (): void {
    $settings = app(PlatformSettings::class);

    $settings->set(PlatformSettings::BASE_URL, 'HTTPS://Panel.Example.COM:443/app/');

    // What is stored is what will be used, so the settings screen shows the operator the
    // origin their links will actually carry.
    expect($settings->baseUrl())->toBe('https://panel.example.com/app');
});

it('refuses a base URL it cannot use, before persisting anything', function (mixed $value): void {
    $settings = app(PlatformSettings::class);

    expect(fn () => $settings->set(PlatformSettings::BASE_URL, $value))
        ->toThrow(InvalidBaseUrlException::class)
        ->and(PlatformSetting::query()->where('key', PlatformSettings::BASE_URL)->exists())->toBeFalse();
})->with([
    'bare host' => ['panel.example.com'],
    'non-http scheme' => ['ftp://panel.example.com'],
    'with credentials' => ['https://admin:hunter2@panel.example.com'],
    'CRLF injection' => ["https://panel.example.com\r\nX-Injected: 1"],
    'not a string' => [['host' => 'panel.example.com']],
]);

it('treats a blank base URL as no override at all', function (): void {
    $settings = app(PlatformSettings::class);

    $settings->set(PlatformSettings::BASE_URL, '   ');

    expect($settings->baseUrl())->toBeNull()
        // Blank is stored as null so "unset" has exactly one representation.
        ->and($settings->get(PlatformSettings::BASE_URL))->toBeNull();
});

it('audits a change on the platform chain, with the values that changed', function (): void {
    $settings = app(PlatformSettings::class);

    $settings->set(PlatformSettings::BASE_URL, 'https://panel.example.com');
    $settings->set(PlatformSettings::BASE_URL, 'https://links.example.com');

    $entries = AuditLog::withoutTenantScope()->where('action', 'platform.setting.changed')->orderBy('sequence')->get();

    expect($entries)->toHaveCount(2)
        ->and($entries[0]->payload['key'] ?? null)->toBe(PlatformSettings::BASE_URL)
        // A setting that had no previous value records none (the trail drops nulls).
        ->and($entries[0]->payload['from'] ?? null)->toBeNull()
        ->and($entries[0]->payload['to'] ?? null)->toBe('https://panel.example.com')
        ->and($entries[1]->payload['from'] ?? null)->toBe('https://panel.example.com')
        ->and($entries[1]->payload['to'] ?? null)->toBe('https://links.example.com')
        // Platform configuration is nobody's tenant history.
        ->and($entries[0]->tenant_id)->toBeNull();

    $settings->forget(PlatformSettings::BASE_URL);

    expect(AuditLog::withoutTenantScope()->where('action', 'platform.setting.removed')->count())->toBe(1);
});

it('keeps the value of an unlisted setting out of the append-only trail', function (): void {
    $settings = app(PlatformSettings::class);

    // platform_settings is also where the payment-gateway keys of §Security & Compliance
    // live, and an audit row cannot be removed once written — so a value is only recorded
    // verbatim for keys reviewed as safe.
    $settings->set('razorpay_key_secret', 'rzp_live_supersecret');

    $entry = AuditLog::withoutTenantScope()->where('action', 'platform.setting.changed')->firstOrFail();

    expect($entry->payload['key'] ?? null)->toBe('razorpay_key_secret')
        ->and($entry->payload['to'] ?? null)->toBe(PlatformSettings::REDACTED)
        ->and(json_encode($entry->payload))->not->toContain('supersecret');
});

it('does not audit removing a setting that was never set', function (): void {
    app(PlatformSettings::class)->forget('never_set');

    expect(AuditLog::withoutTenantScope()->where('action', 'platform.setting.removed')->count())->toBe(0);
});

it('serves repeat reads from the cache and invalidates them on write', function (): void {
    $settings = app(PlatformSettings::class);
    $settings->set('support_email', 'ops@example.com');
    $settings->get('support_email');

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $settings->get('support_email');

    expect($queries)->toBe(0);

    $versionBefore = $settings->version();

    $settings->set('support_email', 'help@example.com');

    expect($settings->version())->toBeGreaterThan($versionBefore)
        ->and($settings->get('support_email'))->toBe('help@example.com');
});

it('can be invalidated by hand after a write that bypasses model events', function (): void {
    $settings = app(PlatformSettings::class);
    $settings->set('support_email', 'ops@example.com');
    $settings->get('support_email');

    PlatformSetting::query()->where('key', 'support_email')->update(['value' => json_encode('raw@example.com')]);

    expect($settings->get('support_email'))->toBe('ops@example.com');

    $settings->flush();

    expect($settings->get('support_email'))->toBe('raw@example.com');
});
