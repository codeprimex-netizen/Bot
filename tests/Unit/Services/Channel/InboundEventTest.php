<?php

declare(strict_types=1);

use App\Enums\ChannelMode;
use App\Enums\InboundEventKind;
use App\Exceptions\Channel\WebhookVerificationException;
use App\Services\Channel\InboundEvent;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| InboundEvent — the one shape four wire formats converge on (Req 8.4 / A8; Req 10.1 / B1)
|--------------------------------------------------------------------------
| Bridge HMAC, Meta's Cloud API webhook, the On-Premise callback and a BSP partner's signed
| POST agree on nothing — not the envelope, not the field names, not how many logical events
| one request carries. What they share is that none may be believed unverified, so the
| verification promise is stated as a property of this type: an `InboundEvent` exists only if
| the payload it came from proved its origin, and the alternative is an exception rather than
| a verdict a caller can forget to read.
|
| The invariants below are the ones that make the type worth having: an event nothing can
| correlate, a message with no sender, or a failure reason on a success would each be a
| downstream bug with no local symptom.
*/

it('is exactly the five meanings a verified payload can have', function (): void {
    expect(InboundEventKind::keys())->toBe([
        'MESSAGE',
        'DELIVERY_RECEIPT',
        'READ_RECEIPT',
        'SEND_FAILURE',
        'UNSUPPORTED',
    ]);

    expect(InboundEventKind::Message->isMessage())->toBeTrue()
        ->and(InboundEventKind::Message->isReceipt())->toBeFalse()
        ->and(InboundEventKind::DeliveryReceipt->isReceipt())->toBeTrue()
        ->and(InboundEventKind::ReadReceipt->isReceipt())->toBeTrue()
        ->and(InboundEventKind::SendFailure->isReceipt())->toBeTrue()
        ->and(InboundEventKind::Unsupported->isReceipt())->toBeFalse();

    // Only the inert case is not acted on, and it is still a successful parse.
    foreach (InboundEventKind::cases() as $kind) {
        expect($kind->isActionable())->toBe($kind !== InboundEventKind::Unsupported, $kind->value);
    }
});

it('carries a customer message with everything the engine needs and nothing it does not', function (): void {
    $event = InboundEvent::message(
        mode: ChannelMode::CloudApi,
        tenantId: 'tenant-1',
        providerMessageId: 'wamid.HBgL',
        from: '15550001111',
        text: 'Where is my order?',
        channelIdentity: '109876543210',
        occurredAt: CarbonImmutable::parse('2025-01-01T10:00:00Z'),
        payload: ['type' => 'text', 'context' => ['id' => 'wamid.prev']],
    );

    expect($event->kind)->toBe(InboundEventKind::Message)
        ->and($event->mode)->toBe(ChannelMode::CloudApi)
        ->and($event->tenantId)->toBe('tenant-1')
        ->and($event->providerMessageId)->toBe('wamid.HBgL')
        ->and($event->from)->toBe('15550001111')
        ->and($event->text)->toBe('Where is my order?')
        ->and($event->isActionable())->toBeTrue()
        // The extension seam: later phases read inbound media, interactive reply ids,
        // reactions and location from the verified payload rather than by re-parsing a request
        // that no longer exists (task 12.4, § B7).
        ->and($event->payloadValue('type'))->toBe('text')
        ->and($event->payloadValue('absent', 'fallback'))->toBe('fallback')
        ->and($event->payload())->toHaveKey('context');
});

it('has no session id, because the route already established it', function (): void {
    // The (tenant, session, driver) tuple comes from channel_webhook_routes.route_key before
    // parsing (task 8.3). A copy inside the event would be a second source for one fact, and
    // the interesting case is when the payload's claim disagrees with the route's answer — at
    // which point a consumer reading the event's copy would act on the claim.
    $properties = array_map(
        static fn (ReflectionProperty $property): string => $property->getName(),
        (new ReflectionClass(InboundEvent::class))->getProperties(),
    );

    expect($properties)->not->toContain('sessionId')
        // What *is* here: the tenant (from the credentials the driver was handed) and the
        // number the payload says it is about (what a driver compares against its credentials
        // before believing a shared-account payload).
        ->and($properties)->toContain('tenantId')
        ->and($properties)->toContain('channelIdentity');
});

it('refuses an event nothing could ever be correlated with', function (): void {
    // For a message the provider id is what makes inbound processing idempotent; for a receipt
    // it is the only thing that says which outbound message the receipt is about.
    expect(fn (): InboundEvent => new InboundEvent(
        kind: InboundEventKind::DeliveryReceipt,
        mode: ChannelMode::CloudApi,
        tenantId: 'tenant-1',
    ))->toThrow(InvalidArgumentException::class, 'provider message id');

    expect(fn (): InboundEvent => new InboundEvent(
        kind: InboundEventKind::Message,
        mode: ChannelMode::Baileys,
        tenantId: 'tenant-1',
        providerMessageId: 'wamid.1',
    ))->toThrow(InvalidArgumentException::class, 'who sent it');

    expect(fn (): InboundEvent => new InboundEvent(
        kind: InboundEventKind::Message,
        mode: ChannelMode::Baileys,
        tenantId: '',
        providerMessageId: 'wamid.1',
        from: '15550001111',
    ))->toThrow(InvalidArgumentException::class, 'tenant');
});

it('normalises the three receipts through one constructor and refuses a fourth', function (): void {
    foreach ([
        InboundEventKind::DeliveryReceipt,
        InboundEventKind::ReadReceipt,
        InboundEventKind::SendFailure,
    ] as $kind) {
        $receipt = InboundEvent::receipt(
            kind: $kind,
            mode: ChannelMode::BspGateway,
            tenantId: 'tenant-1',
            providerMessageId: 'wamid.out',
        );

        expect($receipt->kind)->toBe($kind)
            ->and($receipt->providerMessageId)->toBe('wamid.out')
            // A receipt is about a recipient the platform already chose, so requiring a sender
            // would invite a driver to invent one.
            ->and($receipt->from)->toBeNull();
    }

    expect(fn (): InboundEvent => InboundEvent::receipt(
        kind: InboundEventKind::Message,
        mode: ChannelMode::CloudApi,
        tenantId: 'tenant-1',
        providerMessageId: 'wamid.out',
    ))->toThrow(InvalidArgumentException::class, 'receipt event');
});

it('allows a failure reason only where a failure happened', function (): void {
    $failure = InboundEvent::receipt(
        kind: InboundEventKind::SendFailure,
        mode: ChannelMode::CloudApi,
        tenantId: 'tenant-1',
        providerMessageId: 'wamid.out',
        failureReason: 'Recipient has not accepted the new terms.',
    );

    expect($failure->failureReason)->toBe('Recipient has not accepted the new terms.');

    // A reason on a successful receipt would be read downstream as a partial failure.
    expect(fn (): InboundEvent => InboundEvent::receipt(
        kind: InboundEventKind::DeliveryReceipt,
        mode: ChannelMode::CloudApi,
        tenantId: 'tenant-1',
        providerMessageId: 'wamid.out',
        failureReason: 'why',
    ))->toThrow(InvalidArgumentException::class, 'SEND_FAILURE');
});

it('acknowledges a verified payload it has no use for instead of calling it an attack', function (): void {
    // A contacts update, a template-status change, a kind the provider added after this
    // release: a successful parse whose consumer is nobody, which must get a 200 so the
    // provider stops retrying. Collapsing it into a verification failure would turn every
    // provider feature announcement into a 403 storm.
    $event = InboundEvent::unsupported(
        mode: ChannelMode::CloudApi,
        tenantId: 'tenant-1',
        payload: ['field' => 'message_template_status_update'],
    );

    expect($event->kind)->toBe(InboundEventKind::Unsupported)
        ->and($event->isActionable())->toBeFalse()
        ->and($event->providerMessageId)->toBeNull()
        ->and($event->payloadValue('field'))->toBe('message_template_status_update');
});

it('offers a digest of the body and keeps the body out of every dump', function (): void {
    // Req 7.3 / A7 permits a content hash and nothing more.
    $event = InboundEvent::message(
        mode: ChannelMode::Baileys,
        tenantId: 'tenant-1',
        providerMessageId: 'wamid.1',
        from: '15550001111',
        text: 'my card number is 4111111111111111',
        payload: ['conversation' => 'my card number is 4111111111111111'],
    );

    $debug = $event->__debugInfo();
    $encoded = json_encode($debug, JSON_THROW_ON_ERROR);

    expect($event->contentHash())->toBe(hash('sha256', 'my card number is 4111111111111111'))
        ->and($encoded)->not->toContain('4111111111111111')
        ->and($encoded)->not->toContain('15550001111')
        // Fingerprinted, so two refusals about one number can still be correlated.
        ->and($debug['from'])->toStartWith('#')
        ->and($debug['contentHash'])->toBe($event->contentHash())
        // Raw payload keys, never raw payload values.
        ->and($debug['payloadKeys'])->toBe(['conversation']);

    // No body means no digest, so a caller cannot correlate every bodyless event with every
    // other one through the hash of the empty string.
    expect(InboundEvent::unsupported(ChannelMode::Baileys, 'tenant-1')->contentHash())->toBeNull();
});

it('refuses an unverified payload by not producing an event at all', function (): void {
    // The contract parseWebhook() states: two outcomes, and no third. What is pinned here is
    // that the refusal is a 403 that names no credential and no identity.
    $exception = WebhookVerificationException::badSignature(ChannelMode::CloudApi, 'X-Hub-Signature-256');

    expect($exception->getStatusCode())->toBe(403)
        ->and($exception->publicMessage())->toBe(WebhookVerificationException::PUBLIC_MESSAGE)
        ->and($exception->getMessage())->toContain('CLOUD_API')
        ->and($exception->getMessage())->toContain('X-Hub-Signature-256');

    // A signature proves who sent the body, not whose number it is about — and a BSP account
    // fronting several tenants can legitimately sign a payload for any of them (Property 23).
    $wrongRecipient = WebhookVerificationException::wrongRecipient(
        ChannelMode::BspGateway,
        expected: '109876543210',
        presented: '555000111222',
    );

    expect($wrongRecipient->getMessage())->not->toContain('109876543210')
        ->and($wrongRecipient->getMessage())->not->toContain('555000111222')
        ->and($wrongRecipient->getMessage())->toContain('#'.substr(hash('sha256', '109876543210'), 0, 8));

    // Every flavour answers the same public sentence, so an unauthenticated caller learns
    // nothing about which check refused it.
    foreach ([
        $exception,
        $wrongRecipient,
        WebhookVerificationException::missingSignature(ChannelMode::Baileys, 'X-Bridge-Signature'),
        WebhookVerificationException::badVerifyToken(ChannelMode::CloudApi, 'r0ut3-k3y'),
        WebhookVerificationException::malformedPayload(ChannelMode::OnPremise, 'no messages array'),
    ] as $flavour) {
        expect($flavour->publicMessage())->toBe(WebhookVerificationException::PUBLIC_MESSAGE)
            ->and($flavour->getStatusCode())->toBe(WebhookVerificationException::STATUS)
            ->and($flavour->getHeaders())->toBe([]);
    }
});
