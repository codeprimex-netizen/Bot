<?php

declare(strict_types=1);

use App\Enums\PresenceState;
use App\Enums\SessionLoginMethod;
use App\Enums\SessionStatus;

it('exposes the two pairing methods the engine supports', function (): void {
    expect(SessionLoginMethod::values())->toBe(['QR', 'PAIRING_CODE']);
});

it('knows which method needs the number in advance', function (): void {
    // A pairing code is issued *for* a number, so a provision request naming this method
    // without a phone is invalid — and the caller should be told before a request is made.
    expect(SessionLoginMethod::PairingCode->requiresPhone())->toBeTrue()
        ->and(SessionLoginMethod::Qr->requiresPhone())->toBeFalse();
});

it('lands each method in the state it actually waits in', function (): void {
    // QR parks until a human scans; a pairing code is accepted or rejected by the phone
    // without an intermediate wait state of ours.
    expect(SessionLoginMethod::Qr->initialStatus())->toBe(SessionStatus::QrPending)
        ->and(SessionLoginMethod::PairingCode->initialStatus())->toBe(SessionStatus::Connecting);
});

it('gives both methods a label for the session screen', function (): void {
    foreach (SessionLoginMethod::cases() as $method) {
        expect($method->label())->not->toBe('');
    }
});

it('uses the protocol\'s own presence spellings', function (): void {
    // Strings would let the anti-ban engine and a channel driver disagree about "composing",
    // which is a silently ineffective typing simulation rather than an error.
    expect(PresenceState::values())->toBe(['available', 'unavailable', 'composing', 'recording', 'paused']);
});

it('names the presence states whatsapp expires on its own', function (): void {
    // The typing simulation has to re-publish these across a long delay to keep the
    // indicator alive; the online/offline pair needs no refresh.
    expect(PresenceState::Composing->isTransient())->toBeTrue()
        ->and(PresenceState::Recording->isTransient())->toBeTrue()
        ->and(PresenceState::Available->isTransient())->toBeFalse()
        ->and(PresenceState::Paused->isTransient())->toBeFalse();

    foreach (PresenceState::cases() as $state) {
        expect($state->label())->not->toBe('');
    }
});
