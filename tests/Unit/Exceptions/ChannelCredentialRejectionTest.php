<?php

declare(strict_types=1);

use App\Enums\BspProvider;
use App\Enums\ChannelMode;
use App\Enums\ErrorClass;
use App\Exceptions\Channel\ChannelCredentialException;
use App\Services\Channel\ChannelCredentials;
use App\Services\Reliability\PlatformErrorClassifier;

/*
|--------------------------------------------------------------------------
| The *rejected* flavour task 7.6 adds (Req 8.6, 8.13 / A8)
|--------------------------------------------------------------------------
| `ChannelExceptionsTest` pins the *absent* flavour. This pins the other one, and above all
| the split between them: a panel has to choose between "enter your credentials" and "re-issue
| your token" from the exception alone, so the difference must be carried machine-readably and
| the original code must not have moved.
*/

it('carries a distinct error code without moving the one task 6.3 published', function (): void {
    $missing = ChannelCredentialException::missing(ChannelMode::CloudApi, 'tenant-1');
    $rejected = ChannelCredentialException::rejected(
        ChannelMode::CloudApi,
        'tenant-1',
        'The access token has expired.',
    );

    expect(ChannelCredentialException::ERROR_CODE)->toBe('channel_credentials_missing')
        ->and(ChannelCredentialException::ERROR_CODE_REJECTED)->toBe('channel_credentials_rejected')
        ->and($missing->errorCode())->toBe(ChannelCredentialException::ERROR_CODE)
        ->and($rejected->errorCode())->toBe(ChannelCredentialException::ERROR_CODE_REJECTED)
        ->and($missing->isRejection())->toBeFalse()
        ->and($rejected->isRejection())->toBeTrue()
        // A refusal has a reason; an absence has nothing to report, because no driver was asked.
        ->and($missing->detail())->toBeNull()
        ->and($rejected->detail())->toBe('The access token has expired.');
});

it('tells the tenant what the provider said and that its previous credentials still work', function (): void {
    $rejected = ChannelCredentialException::rejected(
        ChannelMode::BspGateway,
        'tenant-7',
        'This sender id is not on the account.',
        BspProvider::Twilio,
    );

    expect($rejected->mode)->toBe(ChannelMode::BspGateway)
        ->and($rejected->provider)->toBe(BspProvider::Twilio)
        ->and($rejected->getStatusCode())->toBe(422)
        ->and($rejected->publicMessage())->toContain(ChannelMode::BspGateway->label())
        ->and($rejected->publicMessage())->toContain('This sender id is not on the account.')
        // The reassurance Req 8.6 entitles them to: the rotation was refused, not applied.
        ->and($rejected->publicMessage())->toContain('still in use')
        // Correlatable in a log and useless to anybody else — `CrossTenantAccessException`'s
        // discipline, and the same one `missing()` applies.
        ->and($rejected->getMessage())->not->toContain('tenant-7')
        ->and($rejected->getMessage())->toContain('#'.substr(hash('sha256', 'tenant-7'), 0, 8))
        ->and($rejected->publicMessage())->not->toContain('#');
});

it('bounds a provider verdict to the same length every other channel type bounds it to', function (): void {
    $long = str_repeat('a', ChannelCredentials::MAX_DETAIL_LENGTH * 2);

    $rejected = ChannelCredentialException::rejected(ChannelMode::CloudApi, 'tenant-1', $long);

    // An unbounded provider body is how a response that happens to quote a token ends up copied
    // into a response payload in full.
    expect(mb_strlen((string) $rejected->detail()))
        ->toBeLessThanOrEqual(ChannelCredentials::MAX_DETAIL_LENGTH);
});

it('says something useful when a driver refuses without giving a reason', function (): void {
    $rejected = ChannelCredentialException::rejected(ChannelMode::CloudApi, 'tenant-1', '   ');

    expect($rejected->detail())->toBeNull()
        ->and($rejected->getMessage())->toContain('no reason was given')
        ->and($rejected->publicMessage())->toContain(ChannelMode::CloudApi->label());
});

it('classifies a rejection as non-retryable, because a credential appears when a human enters one', function (): void {
    $classifier = new PlatformErrorClassifier;

    expect($classifier->classify(ChannelCredentialException::rejected(
        ChannelMode::CloudApi,
        'tenant-1',
        'Meta rejected the access token.',
    )))->toBe(ErrorClass::Validation);
});
