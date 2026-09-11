<?php

declare(strict_types=1);

use App\Enums\SessionStatus;

/*
|--------------------------------------------------------------------------
| The reused engine session state machine (Req 2.1–2.5 / A2)
|--------------------------------------------------------------------------
| `sessions_wa.status` is the platform's state of record, and connection events arrive
| out of order (the bridge is a separate process; webhooks are at-least-once). So the
| transition map is the thing that turns an out-of-order event into a loud refusal
| instead of a silently corrupted row, and these tests pin the three properties the
| refusal depends on: which edges exist, that the terminal states really are terminal,
| and that "online" and "may send" are not the same question.
*/

it('exposes the twelve wire values the engine schema stores', function (): void {
    expect(SessionStatus::values())->toBe([
        'INITIALIZING', 'QR_PENDING', 'QR_TIMEOUT', 'CONNECTING', 'CONNECTED',
        'RECONNECTING', 'THROTTLED', 'CLOSING', 'CLOSED', 'LOGGED_OUT', 'REPLACED', 'FAILED',
    ]);
});

it('resolves each wire value back to its case', function (): void {
    foreach (SessionStatus::cases() as $case) {
        expect(SessionStatus::from($case->value))->toBe($case);
    }
});

it('reads a status name case-insensitively and declines an unknown one', function (): void {
    // A bridge running a newer build must not be able to crash the intake path with a
    // state name this release does not know: the caller decides what null means.
    expect(SessionStatus::tryFromName('connected'))->toBe(SessionStatus::Connected)
        ->and(SessionStatus::tryFromName('  CONNECTED '))->toBe(SessionStatus::Connected)
        ->and(SessionStatus::tryFromName('TELEPORTING'))->toBeNull()
        ->and(SessionStatus::tryFromName(null))->toBeNull();
});

it('treats only LOGGED_OUT and REPLACED as terminal', function (): void {
    $terminal = array_values(array_filter(
        SessionStatus::cases(),
        static fn (SessionStatus $status): bool => $status->isTerminal(),
    ));

    // FAILED is deliberately *not* terminal: the reconnect budget running out is
    // recoverable (the network came back), while void credentials are not.
    expect($terminal)->toBe([SessionStatus::LoggedOut, SessionStatus::Replaced])
        ->and(SessionStatus::Failed->isTerminal())->toBeFalse()
        ->and(SessionStatus::Closed->isTerminal())->toBeFalse();
});

it('lets a terminal state go nowhere at all', function (): void {
    foreach ([SessionStatus::LoggedOut, SessionStatus::Replaced] as $terminal) {
        expect($terminal->allowedNext())->toBe([]);

        foreach (SessionStatus::cases() as $target) {
            expect($terminal->canTransitionTo($target))->toBe(
                $target === $terminal,
                sprintf('%s must not reach %s: its credentials are void.', $terminal->value, $target->value),
            );
        }
    }
});

it('re-enters the machine at the top from the two restartable end states', function (): void {
    expect(SessionStatus::Closed->allowedNext())->toBe([SessionStatus::Initializing])
        ->and(SessionStatus::Failed->allowedNext())->toBe([SessionStatus::Initializing]);
});

it('lets a stale credential announce itself on the first connect', function (): void {
    // A stored credential the server rejects surfaces during CONNECTING, not after a
    // disconnect — so if these edges were missing, a void credential would loop through
    // RECONNECTING until the budget ran out instead of being named.
    expect(SessionStatus::Connecting->canTransitionTo(SessionStatus::LoggedOut))->toBeTrue()
        ->and(SessionStatus::Connecting->canTransitionTo(SessionStatus::Replaced))->toBeTrue();
});

it('lets an abandoned pairing attempt re-issue a code without a new session', function (): void {
    expect(SessionStatus::QrTimeout->canTransitionTo(SessionStatus::QrPending))->toBeTrue();
});

it('treats a same-state transition as an idempotent no-op everywhere', function (): void {
    // Webhook delivery is at-least-once, so a redelivered "CONNECTED" for a connected
    // session is normal traffic rather than an error.
    foreach (SessionStatus::cases() as $status) {
        expect($status->canTransitionTo($status))->toBeTrue();
    }
});

it('refuses the transitions the state diagram has no edge for', function (): void {
    expect(SessionStatus::Initializing->canTransitionTo(SessionStatus::Connected))->toBeFalse()
        ->and(SessionStatus::Closed->canTransitionTo(SessionStatus::Connected))->toBeFalse()
        ->and(SessionStatus::QrPending->canTransitionTo(SessionStatus::Throttled))->toBeFalse()
        ->and(SessionStatus::Closing->canTransitionTo(SessionStatus::Connected))->toBeFalse();
});

it('separates having a socket from being allowed to send', function (): void {
    // THROTTLED is the anti-ban gate holding sends back on a live socket. Collapsing the
    // two predicates would let a pool query quietly defeat the gate.
    expect(SessionStatus::Connected->isOnline())->toBeTrue()
        ->and(SessionStatus::Connected->canSend())->toBeTrue()
        ->and(SessionStatus::Throttled->isOnline())->toBeTrue()
        ->and(SessionStatus::Throttled->canSend())->toBeFalse();

    foreach (SessionStatus::cases() as $status) {
        if ($status !== SessionStatus::Connected) {
            expect($status->canSend())->toBeFalse($status->value.' must not be sendable');
        }
    }
});

it('lists exactly the states a live socket may be in', function (): void {
    expect(SessionStatus::online())->toBe([SessionStatus::Connected, SessionStatus::Throttled]);
});

it('names the states in which a tenant is waiting on a code', function (): void {
    $pairing = array_values(array_filter(
        SessionStatus::cases(),
        static fn (SessionStatus $status): bool => $status->isPairing(),
    ));

    expect($pairing)->toBe([
        SessionStatus::Initializing,
        SessionStatus::QrPending,
        SessionStatus::QrTimeout,
    ]);
});

it('gives every case a label and never lists itself as its own next state', function (): void {
    foreach (SessionStatus::cases() as $status) {
        expect($status->label())->not->toBe('')
            // A self-edge in the map would make `canTransitionTo` say "legal" for two
            // different reasons, and the no-op rule already covers the same-state case.
            ->and($status->allowedNext())->not->toContain($status);
    }
});
