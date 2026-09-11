<?php

declare(strict_types=1);

use App\Enums\QuotaKind;
use Illuminate\Support\Carbon;

it('exposes the six wire values the schema stores', function (): void {
    expect(QuotaKind::values())->toBe([
        'MESSAGES_MONTHLY',
        'MESSAGES_DAILY',
        'SESSIONS',
        'CONTACTS',
        'AI_CREDITS',
        'CAMPAIGNS_CONCURRENT',
    ]);
});

it('resolves each wire value back to its case', function (): void {
    foreach (QuotaKind::cases() as $case) {
        expect(QuotaKind::from($case->value))->toBe($case);
    }
});

it('buckets monthly quotas by year-month', function (): void {
    $at = Carbon::parse('2025-06-14 10:30:00', 'UTC');

    expect(QuotaKind::MessagesMonthly->periodKey($at))->toBe('2025-06');
    expect(QuotaKind::AiCredits->periodKey($at))->toBe('2025-06');
});

it('buckets daily quotas by calendar date', function (): void {
    $at = Carbon::parse('2025-06-14 10:30:00', 'UTC');

    expect(QuotaKind::MessagesDaily->periodKey($at))->toBe('2025-06-14');
});

it('rolls the daily bucket over at the tenant local midnight', function (): void {
    // 2025-06-14 23:30 UTC is already 2025-06-15 in Asia/Kolkata (+05:30).
    $at = Carbon::parse('2025-06-14 23:30:00', 'UTC');

    expect(QuotaKind::MessagesDaily->periodKey($at, 'UTC'))->toBe('2025-06-14');
    expect(QuotaKind::MessagesDaily->periodKey($at, 'Asia/Kolkata'))->toBe('2025-06-15');
});

it('keeps gauge quotas in a single standing bucket', function (): void {
    $gauges = [QuotaKind::Sessions, QuotaKind::Contacts, QuotaKind::CampaignsConcurrent];

    foreach ($gauges as $kind) {
        expect($kind->isGauge())->toBeTrue();
        expect($kind->periodFormat())->toBeNull();
        expect($kind->periodKey(Carbon::parse('2025-06-14')))->toBe(QuotaKind::GAUGE_PERIOD_KEY);
        expect($kind->periodKey(Carbon::parse('2031-01-02')))->toBe(QuotaKind::GAUGE_PERIOD_KEY);
    }
});

it('marks accruing quotas as non-gauges', function (): void {
    foreach ([QuotaKind::MessagesMonthly, QuotaKind::MessagesDaily, QuotaKind::AiCredits] as $kind) {
        expect($kind->isGauge())->toBeFalse();
        expect($kind->periodFormat())->not->toBeNull();
    }
});

it('always produces a period key that fits the period_key column', function (): void {
    // period_key is varchar(32); every kind/date combination must stay inside it.
    foreach (QuotaKind::cases() as $kind) {
        foreach (['1999-12-31 23:59:59', '2025-06-14 12:00:00', '2099-01-01 00:00:00'] as $date) {
            $key = $kind->periodKey(Carbon::parse($date, 'UTC'));

            expect(mb_strlen($key))->toBeLessThanOrEqual(32)->and($key)->not->toBe('');
        }
    }
});
