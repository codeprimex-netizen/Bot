<?php

declare(strict_types=1);

use App\Enums\BspProvider;
use App\Enums\ChannelCapability;
use App\Enums\ChannelMode;
use App\Enums\ErrorClass;
use App\Exceptions\Channel\ChannelCredentialException;
use App\Exceptions\Channel\ModeCapabilityException;
use App\Services\Reliability\PlatformErrorClassifier;

/*
|--------------------------------------------------------------------------
| The two refusals task 6.3 owns (Req 8.3, 8.13 / A8)
|--------------------------------------------------------------------------
| `ModeCapabilityException` is thrown by the router, by three of the drivers of tasks 7.2–7.4,
| and by Algorithm 9's capability arm, so its *shape* is a contract in its own right — every
| raiser must produce the same sentence, carry the pair that was refused, and be classified as
| non-retryable without anybody having to remember to say so.
|
| `ChannelCredentialException` is the other half of "exactly one driver": the refusal that
| exists so a missing credential set cannot become a silent reroute onto another number.
*/

it('names the capability and the mode from the enums, so a renamed matrix row cannot drift', function (): void {
    $exception = ModeCapabilityException::for(ChannelMode::CloudApi, ChannelCapability::Groups);

    expect($exception->getMessage())
        ->toContain(ChannelCapability::Groups->label())
        ->and($exception->getMessage())->toContain(ChannelMode::CloudApi->label())
        // The pair is carried, not only described: task 8.8's property test and the panel both
        // read it rather than parsing the sentence.
        ->and($exception->mode)->toBe(ChannelMode::CloudApi)
        ->and($exception->capability)->toBe(ChannelCapability::Groups)
        ->and($exception->getStatusCode())->toBe(422)
        ->and($exception->publicMessage())->toBe($exception->getMessage());
});

it('says where a Baileys-only capability does exist, so the panel can suggest the remedy', function (): void {
    $groups = ModeCapabilityException::for(ChannelMode::CloudApi, ChannelCapability::Groups);

    expect($groups->availableOn())->toBe([ChannelMode::Baileys])
        ->and($groups->isAvailableElsewhere())->toBeTrue()
        ->and($groups->getMessage())->toContain('only on the Baileys bridge');

    // A capability that is not Baileys-only says nothing about the bridge.
    $template = ModeCapabilityException::for(ChannelMode::Baileys, ChannelCapability::Template);

    expect($template->getMessage())->not->toContain('only on the Baileys bridge');
});

it('carries no identifier a capability refusal has no business naming', function (): void {
    foreach (ChannelMode::cases() as $mode) {
        foreach (ChannelCapability::cases() as $capability) {
            $message = ModeCapabilityException::for($mode, $capability)->getMessage();

            // Only platform vocabulary: the matrix's own row and column headings. Nothing
            // here is tenant input, so there is nothing to redact — and equally nothing to
            // leak into a response body.
            expect($message)->not->toContain('tenant')
                ->and($message)->not->toContain('@s.whatsapp.net');
        }
    }
});

it('classifies a capability refusal as non-retryable without a typed map entry', function (): void {
    $classifier = new PlatformErrorClassifier;

    // No amount of waiting adds GROUPS to Cloud API. The HTTP-status fallback already gives
    // the only sensible answer, which is why this class needs no entry in the typed map —
    // an entry would be a second place to keep in step.
    expect($classifier->classify(ModeCapabilityException::for(ChannelMode::CloudApi, ChannelCapability::Groups)))
        ->toBe(ErrorClass::Validation)
        ->and($classifier->classify(ChannelCredentialException::missing(ChannelMode::CloudApi, 'tenant-1')))
        ->toBe(ErrorClass::Validation);
});

it('fingerprints the tenant in a credential refusal and tells the tenant what to do', function (): void {
    $exception = ChannelCredentialException::missing(ChannelMode::CloudApi, 'tenant-1', BspProvider::Twilio);

    expect($exception->mode)->toBe(ChannelMode::CloudApi)
        ->and($exception->provider)->toBe(BspProvider::Twilio)
        ->and($exception->getStatusCode())->toBe(422)
        // Correlatable in a log, and useless to anybody else — the discipline
        // `CrossTenantAccessException` sets.
        ->and($exception->getMessage())->not->toContain('tenant-1')
        ->and($exception->getMessage())->toContain('#'.substr(hash('sha256', 'tenant-1'), 0, 8))
        // And the internal message says *why* it is not a reroute, because that is the
        // decision a reader of this log line will want to question.
        ->and($exception->getMessage())->toContain('rather than rerouted')
        ->and($exception->publicMessage())->toContain(ChannelMode::CloudApi->label())
        ->and($exception->publicMessage())->not->toContain('#');
});
