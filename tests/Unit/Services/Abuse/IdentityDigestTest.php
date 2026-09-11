<?php

declare(strict_types=1);

use App\Services\Abuse\IdentityDigest;

/*
|--------------------------------------------------------------------------
| Identity digests (Req 32.7 / NFR3)
|--------------------------------------------------------------------------
| The counters and `abuse_events.subject_hash` hold digests, never identities — so the
| normalization in front of the digest is where bypass resistance lives, and the keying
| is where the privacy does.
*/

function digest(string $key = 'test-key'): IdentityDigest
{
    return new IdentityDigest($key);
}

it('collapses plus-tag email aliases onto one identity', function (): void {
    $digest = digest();

    expect($digest->email('ada+trial1@example.com'))->toBe($digest->email('ada@example.com'))
        ->and($digest->email('ada+trial2@example.com'))->toBe($digest->email('ada@example.com'));
});

it('collapses dots only for providers that ignore them', function (): void {
    $digest = digest();

    expect($digest->email('a.d.a@gmail.com'))->toBe($digest->email('ada@gmail.com'))
        // Other hosts really can treat these as two mailboxes, so they stay distinct.
        ->and($digest->email('a.d.a@example.com'))->not->toBe($digest->email('ada@example.com'));
});

it('normalizes phone numbers to their digits', function (): void {
    $digest = digest();

    expect($digest->phone('+91 98765-43210'))->toBe($digest->phone('919876543210'))
        ->and($digest->phone('0091 9876543210'))->toBe($digest->phone('919876543210'));
});

it('keys IPv4 counters by /24 and IPv6 by /64', function (): void {
    $digest = digest();

    expect($digest->subnet('203.0.113.7'))->toBe($digest->subnet('203.0.113.200'))
        ->and($digest->subnet('203.0.113.7'))->not->toBe($digest->subnet('203.0.114.7'))
        ->and($digest->subnet('2001:db8:1:2::a'))->toBe($digest->subnet('2001:db8:1:2::ffff'))
        ->and($digest->subnet('2001:db8:1:2::a'))->not->toBe($digest->subnet('2001:db8:1:3::a'));
});

it('never collides across kinds', function (): void {
    $digest = digest();

    expect($digest->email('x@example.com'))->not->toBe($digest->device('x@example.com'))
        ->and($digest->ip('10.0.0.1'))->not->toBe($digest->subnet('10.0.0.1'));
});

it('is keyed, so the same identity digests differently under a different key', function (): void {
    // The reason this matters: a bare sha256 of a phone number is reversible with a
    // laptop, which would make the abuse trail a phone-number database.
    expect(digest('key-a')->phone('919876543210'))->not->toBe(digest('key-b')->phone('919876543210'));
});

it('emits a 64-character hex digest and never the value', function (): void {
    $value = 'ada@example.com';
    $hash = digest()->email($value);

    expect($hash)->toMatch('/^[0-9a-f]{64}$/')
        ->and($hash)->not->toContain('ada');
});

it('falls back to a working key rather than to an unkeyed hash', function (): void {
    config()->set('wa.security.abuse.hash_key', null);
    config()->set('app.key', '');

    $digest = IdentityDigest::fromConfig();

    expect($digest->phone('919876543210'))->not->toBe(hash('sha256', 'phone:919876543210'));
});

it('reads the email domain for the disposable-domain check', function (): void {
    expect(digest()->domainOf('Ada@Mailinator.COM'))->toBe('mailinator.com')
        ->and(digest()->domainOf('not-an-email'))->toBe('');
});
