<?php

declare(strict_types=1);

namespace App\Observers;

use App\Exceptions\Url\InvalidBaseUrlException;
use App\Models\PlatformSetting;
use App\Services\Platform\PlatformSettings;
use App\Services\Url\BaseUrlCache;
use App\Support\Url\CanonicalBase;

/**
 * The two things that must happen around every platform-settings write
 * (Req 9.3 / A9; Req 30.4 / NFR1):
 *
 *  1. `saving` — refuse a value the platform cannot interpret, and store the
 *     canonical spelling of the ones it can, so a bad `base_url` is caught at the
 *     admin edit that caused it rather than on the next webhook registration;
 *  2. `saved`/`deleted` — bump both cache namespaces the setting feeds, so no reader
 *     can be served the platform's configuration as it was before the edit.
 *
 * Attached with `#[ObservedBy]` on the model rather than registered in a provider: the
 * guarantee then travels with the model, including in code paths (seeders, console
 * commands, tests) that never boot a URL provider. The same shape as `PlanObserver`, for
 * the same reason — the values here are what every emitted link is derived from, so a
 * malformed one must fail where it is entered, not where it is used.
 *
 * **Not covered:** writes that bypass Eloquent events — `PlatformSetting::query()->update()`,
 * raw SQL, a restore. Those must call `PlatformSettings::flush()` and
 * `BaseUrlCache::flush()` themselves.
 */
final class PlatformSettingObserver
{
    /**
     * @throws InvalidBaseUrlException when `base_url` is set to something unusable
     */
    public function saving(PlatformSetting $setting): void
    {
        if ($setting->key !== PlatformSettings::BASE_URL) {
            return;
        }

        $value = $setting->value;

        if ($value === null || (is_string($value) && trim($value) === '')) {
            // Blank is "no override": an admin clearing the field on the settings screen
            // means *use `APP_URL`*, which is a legitimate edit and must not be refused as
            // a malformed URL. It is stored as null so "unset" has one representation —
            // `PlatformSettings::baseUrl()` then falls through to the next link in the
            // chain exactly as it does for a row that never existed.
            $setting->value = null;

            return;
        }

        if (! is_string($value)) {
            throw InvalidBaseUrlException::malformed(
                PlatformSettings::settingSource(PlatformSettings::BASE_URL),
                get_debug_type($value),
                'it is not a string',
            );
        }

        // Canonicalising on the way in rather than only on the way out means the stored
        // value is the value that will be used, so the settings screen shows the operator
        // the origin their links will actually carry.
        $setting->value = CanonicalBase::parse(
            $value,
            PlatformSettings::settingSource(PlatformSettings::BASE_URL),
        )->value();
    }

    public function saved(PlatformSetting $setting): void
    {
        $this->invalidate();
    }

    public function deleted(PlatformSetting $setting): void
    {
        $this->invalidate();
    }

    /**
     * Both namespaces, always: the settings cache holds the raw value and the base-URL
     * cache holds strings resolved *from* it, so dropping only the first would leave the
     * resolved base stale until its TTL — which is exactly the manual-cache-clear
     * requirement Req 9.3's runtime override exists to avoid.
     */
    private function invalidate(): void
    {
        app(PlatformSettings::class)->flush();
        app(BaseUrlCache::class)->flush();
    }
}
