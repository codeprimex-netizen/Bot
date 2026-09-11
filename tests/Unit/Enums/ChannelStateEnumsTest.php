<?php

declare(strict_types=1);

use App\Enums\ChannelCredentialStatus;
use App\Enums\ChannelSendResult;
use App\Enums\ChannelTemplateCategory;
use App\Enums\ChannelTemplateStatus;

/*
|--------------------------------------------------------------------------
| The four Channel Mode state vocabularies (Req 8.6, 8.12, 8.13 / A8)
|--------------------------------------------------------------------------
| One enum per column whose domain design.md enumerates: `channel_credentials.status`,
| `cloud_api_templates.status` and `.category`, `channel_send_log.result`. Each carries the
| one predicate its column exists to answer, and each of those is a design decision rather
| than a convenience — so they are pinned here rather than left to the first caller.
*/

it('is exactly the credential states design.md names, and only ACTIVE may send', function (): void {
    expect(array_map(fn (ChannelCredentialStatus $s): string => $s->value, ChannelCredentialStatus::cases()))
        ->toBe(['ACTIVE', 'INVALID', 'DISABLED'])
        ->and(ChannelCredentialStatus::default())->toBe(ChannelCredentialStatus::Active)
        ->and(ChannelCredentialStatus::Active->isUsable())->toBeTrue()
        ->and(ChannelCredentialStatus::Invalid->isUsable())->toBeFalse()
        ->and(ChannelCredentialStatus::Disabled->isUsable())->toBeFalse();
});

it('tells the tenant only about credentials that stopped working', function (): void {
    // The distinction the two unusable states exist for: "your token was revoked" needs
    // telling somebody (Req 8.13 — the mode becomes unselectable), "you switched this off"
    // does not.
    expect(ChannelCredentialStatus::Invalid->needsAttention())->toBeTrue()
        ->and(ChannelCredentialStatus::Disabled->needsAttention())->toBeFalse()
        ->and(ChannelCredentialStatus::Active->needsAttention())->toBeFalse();
});

it('is exactly the template states the provider reports, and only APPROVED sends', function (): void {
    expect(array_map(fn (ChannelTemplateStatus $s): string => $s->value, ChannelTemplateStatus::cases()))
        ->toBe(['PENDING', 'APPROVED', 'REJECTED', 'PAUSED'])
        ->and(ChannelTemplateStatus::default())->toBe(ChannelTemplateStatus::Pending)
        ->and(ChannelTemplateStatus::Approved->isSendable())->toBeTrue()
        // PAUSED is not sendable: the provider has stopped accepting it, so attempting the
        // send would turn a local block (Req 8.12) into a remote failure mid-campaign.
        ->and(ChannelTemplateStatus::Paused->isSendable())->toBeFalse()
        ->and(ChannelTemplateStatus::Pending->isSendable())->toBeFalse()
        ->and(ChannelTemplateStatus::Rejected->isSendable())->toBeFalse();
});

it('re-syncs every state the provider can still change, and not the one only the tenant can', function (): void {
    expect(ChannelTemplateStatus::Pending->isSyncable())->toBeTrue()
        ->and(ChannelTemplateStatus::Approved->isSyncable())->toBeTrue()
        ->and(ChannelTemplateStatus::Paused->isSyncable())->toBeTrue()
        // A rejection only moves when the tenant resubmits, which is a new submission.
        ->and(ChannelTemplateStatus::Rejected->isSyncable())->toBeFalse();
});

it('is exactly the three provider template categories, and knows which is promotional', function (): void {
    expect(array_map(fn (ChannelTemplateCategory $c): string => $c->value, ChannelTemplateCategory::cases()))
        ->toBe(['MARKETING', 'UTILITY', 'AUTHENTICATION'])
        ->and(ChannelTemplateCategory::Marketing->isPromotional())->toBeTrue()
        ->and(ChannelTemplateCategory::Utility->isPromotional())->toBeFalse()
        ->and(ChannelTemplateCategory::Authentication->isPromotional())->toBeFalse();
});

it('separates "did the provider hear about it" from "did it succeed"', function (): void {
    expect(array_map(fn (ChannelSendResult $r): string => $r->value, ChannelSendResult::cases()))
        ->toBe(['SENT', 'BLOCKED', 'FAILED', 'FAILED_OVER']);

    // The axis Req 8.3 / Property 26 rest on: a BLOCKED attempt never reached the provider,
    // so it left no side effect anywhere but the log row itself.
    expect(ChannelSendResult::Blocked->reachedProvider())->toBeFalse()
        ->and(ChannelSendResult::Sent->reachedProvider())->toBeTrue()
        ->and(ChannelSendResult::Failed->reachedProvider())->toBeTrue()
        ->and(ChannelSendResult::FailedOver->reachedProvider())->toBeTrue();

    // ...and the axis a report counts on: only SENT is a success, and only FAILED_OVER is
    // followed by another attempt for the same dispatch (Req 8.10, 8.11).
    expect(ChannelSendResult::Sent->isSuccess())->toBeTrue()
        ->and(ChannelSendResult::Blocked->isSuccess())->toBeFalse()
        ->and(ChannelSendResult::Failed->isSuccess())->toBeFalse()
        ->and(ChannelSendResult::FailedOver->isSuccess())->toBeFalse()
        ->and(ChannelSendResult::FailedOver->isTerminal())->toBeFalse()
        ->and(ChannelSendResult::Sent->isTerminal())->toBeTrue()
        ->and(ChannelSendResult::Blocked->isTerminal())->toBeTrue()
        ->and(ChannelSendResult::Failed->isTerminal())->toBeTrue();
});
