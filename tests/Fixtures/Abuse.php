<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Models\AbuseEvent;
use App\Services\Abuse\AbuseRecorder;
use App\Services\Abuse\AntiFraudGuard;
use App\Services\Abuse\Guardrail;
use App\Services\Abuse\InjectionClassifier;
use App\Services\Abuse\OutputValidator;
use App\Services\Abuse\SessionKillSwitch;
use App\Services\Abuse\TextNormalizer;
use Illuminate\Support\Facades\DB;

/**
 * Shared entry points for the abuse-layer tests (task 4.5; Req 13.8 / B4,
 * Req 32.7 / NFR3).
 *
 * A class rather than Pest helper functions, for the reason `Tests\Fixtures\Breakers`
 * gives: Pest loads every test file into one process, so a global `guardrail()` helper
 * would be a name the whole suite has to keep free for ever.
 */
final class Abuse
{
    /**
     * The container's guardrail, rebuilt so it picks up config a test has just changed.
     */
    public static function guardrail(): Guardrail
    {
        self::forget();

        return app(Guardrail::class);
    }

    public static function antiFraud(): AntiFraudGuard
    {
        self::forget();

        return app(AntiFraudGuard::class);
    }

    public static function killSwitch(): SessionKillSwitch
    {
        self::forget();

        return app(SessionKillSwitch::class);
    }

    public static function recorder(): AbuseRecorder
    {
        self::forget();

        return app(AbuseRecorder::class);
    }

    public static function normalizer(): TextNormalizer
    {
        self::forget();

        return app(TextNormalizer::class);
    }

    public static function classifier(): InjectionClassifier
    {
        self::forget();

        return app(InjectionClassifier::class);
    }

    /**
     * Drop the memoised instances so the next resolution reads current config.
     */
    public static function forget(): void
    {
        foreach ([
            Guardrail::class,
            AntiFraudGuard::class,
            SessionKillSwitch::class,
            AbuseRecorder::class,
            InjectionClassifier::class,
            OutputValidator::class,
            TextNormalizer::class,
        ] as $abstract) {
            app()->forgetInstance($abstract);
        }
    }

    /**
     * Every stored abuse event, oldest first.
     *
     * Read with the tenant scope removed so a test can assert on platform-level
     * (pre-tenant) rows as well as a tenant's own.
     *
     * @return list<AbuseEvent>
     */
    public static function events(): array
    {
        return AbuseEvent::withoutTenantScope()->orderBy('created_at')->orderBy('id')->get()->all();
    }

    /**
     * The most recently stored abuse event.
     */
    public static function lastEvent(): ?AbuseEvent
    {
        $events = self::events();

        return $events === [] ? null : $events[count($events) - 1];
    }

    /**
     * The raw stored rows, straight from the driver — for assertions that must not go
     * through casts or accessors (e.g. "no message content anywhere in the table").
     *
     * @return list<object>
     */
    public static function rawRows(): array
    {
        return DB::table('abuse_events')->orderBy('created_at')->get()->all();
    }
}
