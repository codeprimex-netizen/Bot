<?php

declare(strict_types=1);

use App\Enums\SessionLoginMethod;
use App\Enums\SessionStatus;
use App\Exceptions\Bridge\BridgeRequestFailedException;
use App\Exceptions\Bridge\BridgeUnreachableException;
use App\Services\Bridge\BridgeClient;
use Tests\Fixtures\Bridge\FakeBridgeClient;

/*
|--------------------------------------------------------------------------
| The test double is a real double, not a yes-man (Req 36.2 / NFR7, Property 28)
|--------------------------------------------------------------------------
| Every later phase's tests are built on this fake, so its own behaviour is load-bearing:
| if it acknowledged every send, every fail-closed test in Phase 5 onwards would pass
| vacuously while the platform shipped broken. So it models the session state machine and
| refuses what the sidecar would refuse — and this file is what keeps it honest.
*/

it('refuses a send on a session it does not hold a live socket for', function (): void {
    $bridge = new FakeBridgeClient;
    $id = FakeBridgeClient::sessionId();

    try {
        $bridge->sendText($id, 'x@s.whatsapp.net', 'Hi');
        $failure = null;
    } catch (BridgeRequestFailedException $e) {
        $failure = $e;
    }

    expect($failure?->bridgeCode)->toBe(BridgeRequestFailedException::CODE_SESSION_NOT_CONNECTED)
        ->and($bridge->sentTo($id))->toBe([]);
});

it('refuses a send on a throttled session, because the anti-ban gate holds those back', function (): void {
    $bridge = (new FakeBridgeClient)->status($id = FakeBridgeClient::sessionId(), SessionStatus::Throttled);

    expect(fn () => $bridge->sendText($id, 'x@s.whatsapp.net', 'Hi'))
        ->toThrow(BridgeRequestFailedException::class);
});

it('names a logged-out session for what it is, so it is not retried as a dropped socket', function (): void {
    $bridge = (new FakeBridgeClient)->status($id = FakeBridgeClient::sessionId(), SessionStatus::LoggedOut);

    try {
        $bridge->sendText($id, 'x@s.whatsapp.net', 'Hi');
        $failure = null;
    } catch (BridgeRequestFailedException $e) {
        $failure = $e;
    }

    expect($failure?->bridgeCode)->toBe(BridgeRequestFailedException::CODE_SESSION_LOGGED_OUT)
        ->and($failure?->isSessionUnavailable())->toBeFalse();
});

it('issues deterministic message ids so an assertion never depends on randomness', function (): void {
    $bridge = (new FakeBridgeClient)->connect($id = FakeBridgeClient::sessionId());

    $first = $bridge->sendText($id, 'x@s.whatsapp.net', 'one');
    $second = $bridge->sendText($id, 'x@s.whatsapp.net', 'two');

    expect($first->waMessageId)->toBe(FakeBridgeClient::MESSAGE_ID_PREFIX.'1')
        ->and($second->waMessageId)->toBe(FakeBridgeClient::MESSAGE_ID_PREFIX.'2')
        ->and($bridge->sentTo($id))->toHaveCount(2);
});

it('counts a call even when it is arranged to fail, so a fenced-off call is distinguishable', function (): void {
    $bridge = (new FakeBridgeClient)->connect($id = FakeBridgeClient::sessionId())->unreachable();

    expect(fn () => $bridge->startSession($id))->toThrow(BridgeUnreachableException::class)
        // Without this, "the breaker fenced it off" and "it was attempted and failed" would
        // be indistinguishable in a test.
        ->and($bridge->calls('session.start'))->toBe(1);
});

it('stops refusing after the arranged number of calls', function (): void {
    $bridge = (new FakeBridgeClient)->connect($id = FakeBridgeClient::sessionId());
    $bridge->refuseWith(BridgeRequestFailedException::CODE_SESSION_NOT_CONNECTED, 409, times: 2);

    expect(fn () => $bridge->startSession($id))->toThrow(BridgeRequestFailedException::class)
        ->and(fn () => $bridge->startSession($id))->toThrow(BridgeRequestFailedException::class);

    $bridge->startSession($id);

    expect($bridge->calls('session.start'))->toBe(3);
});

it('answers a number check with three states, unknown for a number it was told nothing about', function (): void {
    $bridge = (new FakeBridgeClient)->answerNumbers([
        '919812345678' => true,
        '919800000000' => false,
    ]);

    $checks = $bridge->checkNumbers(FakeBridgeClient::sessionId(), ['919812345678', '919800000000', '919899999999']);

    expect($checks['919812345678']->isOnWhatsApp())->toBeTrue()
        ->and($checks['919812345678']->jid)->toBe('919812345678@s.whatsapp.net')
        ->and($checks['919800000000']->exists)->toBeFalse()
        ->and($checks['919899999999']->isUnknown())->toBeTrue();
});

it('lands a provisioned session in the state its login method implies', function (): void {
    $bridge = new FakeBridgeClient;

    $qr = $bridge->provisionSession(FakeBridgeClient::sessionId(), SessionLoginMethod::Qr);
    $pairing = $bridge->provisionSession(FakeBridgeClient::sessionId(), SessionLoginMethod::PairingCode, '919812345678');

    expect($qr->status)->toBe(SessionStatus::QrPending)
        ->and($qr->pairingCode)->toBeNull()
        ->and($pairing->status)->toBe(SessionStatus::Connecting)
        ->and($pairing->pairingCode)->not->toBeNull();
});

it('replaces the container binding when bound, and only when a test asks', function (): void {
    expect(app(BridgeClient::class))->not->toBeInstanceOf(FakeBridgeClient::class);

    $fake = FakeBridgeClient::bind();

    expect(app(BridgeClient::class))->toBe($fake);
});
