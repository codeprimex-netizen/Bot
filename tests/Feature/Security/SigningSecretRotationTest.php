<?php

declare(strict_types=1);

use App\Console\Commands\RotateSigningSecrets;
use App\Exceptions\Security\KeyUnavailableException;
use App\Models\SigningSecret;
use App\Models\Tenant;
use App\Services\Security\SigningSecretStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Security\FakeKms;

/*
|--------------------------------------------------------------------------
| Dual-secret HMAC rotation (Req 32.6 / NFR3)
|--------------------------------------------------------------------------
| "Dual-secret" is one specific behaviour, and it is the reason this exists:
|
|     during the overlap window, BOTH secrets verify — but only the new one signs.
|
| Without it, rotating a webhook secret rejects every signature the peer has in flight,
| which is a self-inflicted outage on the one path an attacker would love to see fail open.
| So the tests below pin all three halves of that sentence, plus the boundary: a secret
| **past** the window is refused, whether or not its row has been purged.
*/

const HMAC_SCOPE = 'bridge:session:01JABCDEFGH';

const HMAC_PAYLOAD = '{"event":"messages.upsert","id":"3EB0"}';

afterEach(function (): void {
    Carbon::setTestNow();
});

it('signs and verifies a payload', function (): void {
    FakeKms::bind();
    $secrets = app(SigningSecretStore::class);

    $signature = $secrets->sign(HMAC_SCOPE, HMAC_PAYLOAD);

    expect($signature)->toStartWith('sha256=')
        ->and($secrets->verify(HMAC_SCOPE, HMAC_PAYLOAD, $signature))->toBeTrue()
        // A different body under the same secret is a different signature.
        ->and($secrets->verify(HMAC_SCOPE, HMAC_PAYLOAD.' ', $signature))->toBeFalse();
});

it('accepts both secrets during the overlap window, and signs only with the new one', function (): void {
    FakeKms::bind();
    $secrets = app(SigningSecretStore::class);

    $secrets->provision(HMAC_SCOPE);
    $signedWithOld = $secrets->sign(HMAC_SCOPE, HMAC_PAYLOAD);

    $rotation = $secrets->rotate(HMAC_SCOPE);
    $signedWithNew = $secrets->sign(HMAC_SCOPE, HMAC_PAYLOAD);

    expect($rotation->version)->toBe(2)
        ->and($rotation->previousVersion)->toBe(1)
        ->and($rotation->hasOverlap())->toBeTrue()
        // Only the newest signs, so the two signatures differ...
        ->and($signedWithNew)->not->toBe($signedWithOld)
        // ...and *both* verify, which is the entire point of the window.
        ->and($secrets->verify(HMAC_SCOPE, HMAC_PAYLOAD, $signedWithOld))->toBeTrue()
        ->and($secrets->verify(HMAC_SCOPE, HMAC_PAYLOAD, $signedWithNew))->toBeTrue()
        ->and(SigningSecret::signerFor(HMAC_SCOPE)?->version)->toBe(2);
});

it('refuses a secret whose overlap window has closed, even before its row is purged', function (): void {
    FakeKms::bind();
    Carbon::setTestNow('2025-06-01 12:00:00');

    $secrets = app(SigningSecretStore::class);
    $secrets->provision(HMAC_SCOPE);
    $signedWithOld = $secrets->sign(HMAC_SCOPE, HMAC_PAYLOAD);

    $secrets->rotate(HMAC_SCOPE, overlapSeconds: 3600);

    // Inside the window.
    Carbon::setTestNow('2025-06-01 12:59:00');
    expect($secrets->verify(HMAC_SCOPE, HMAC_PAYLOAD, $signedWithOld))->toBeTrue();

    // One second past it. The row is still there — the clock is what refuses it.
    Carbon::setTestNow('2025-06-01 13:00:01');

    expect($secrets->verify(HMAC_SCOPE, HMAC_PAYLOAD, $signedWithOld))->toBeFalse()
        ->and(SigningSecret::atVersion(HMAC_SCOPE, 1))->not->toBeNull()
        // The current secret is unaffected by the boundary.
        ->and($secrets->verify(HMAC_SCOPE, HMAC_PAYLOAD, $secrets->sign(HMAC_SCOPE, HMAC_PAYLOAD)))->toBeTrue();
});

it('purges closed windows and never the signing secret', function (): void {
    FakeKms::bind();
    Carbon::setTestNow('2025-06-01 12:00:00');

    $secrets = app(SigningSecretStore::class);
    $secrets->provision(HMAC_SCOPE);
    $secrets->rotate(HMAC_SCOPE, overlapSeconds: 3600);

    expect($secrets->purgeExpired())->toBe(0);

    Carbon::setTestNow('2025-06-01 14:00:00');

    expect($secrets->purgeExpired())->toBe(1)
        ->and(SigningSecret::atVersion(HMAC_SCOPE, 1))->toBeNull()
        ->and(SigningSecret::signerFor(HMAC_SCOPE)?->version)->toBe(2)
        // Signing still works: the surviving row is the one a peer is using.
        ->and($secrets->verify(HMAC_SCOPE, HMAC_PAYLOAD, $secrets->sign(HMAC_SCOPE, HMAC_PAYLOAD)))->toBeTrue();
});

it('rejects untrusted input without ever raising', function (string $signature): void {
    FakeKms::bind();
    $secrets = app(SigningSecretStore::class);
    $secrets->provision(HMAC_SCOPE);

    // An inbound signature must produce exactly one of two outcomes. Letting it choose
    // between a 403 and a 503 would hand an attacker a probe.
    expect($secrets->verify(HMAC_SCOPE, HMAC_PAYLOAD, $signature))->toBeFalse();
})->with([
    'empty' => [''],
    'prefix only' => ['sha256='],
    'not hex' => ['sha256=not-a-digest'],
    'right length wrong value' => ['sha256='.str_repeat('a', 64)],
    'unrelated scheme' => ['Bearer abc123'],
]);

it('rejects a signature for a scope that has no secret', function (): void {
    FakeKms::bind();

    expect(app(SigningSecretStore::class)->verify('gateway:nobody', HMAC_PAYLOAD, 'sha256='.str_repeat('b', 64)))
        ->toBeFalse()
        // ...and does not quietly issue one along the way.
        ->and(SigningSecret::query()->where('scope', 'gateway:nobody')->count())->toBe(0);
});

it('accepts a bare digest as well as a prefixed one', function (): void {
    FakeKms::bind();
    $secrets = app(SigningSecretStore::class);

    $signature = $secrets->sign(HMAC_SCOPE, HMAC_PAYLOAD);
    $bare = substr($signature, strlen('sha256='));

    expect($secrets->verify(HMAC_SCOPE, HMAC_PAYLOAD, $bare))->toBeTrue()
        ->and($secrets->verify(HMAC_SCOPE, HMAC_PAYLOAD, strtoupper($bare)))->toBeTrue();
});

it('never stores a secret in plaintext', function (): void {
    FakeKms::bind();
    $secrets = app(SigningSecretStore::class);

    $plaintext = $secrets->currentSecret(HMAC_SCOPE);
    $row = SigningSecret::signerFor(HMAC_SCOPE);
    $stored = (string) DB::table('signing_secrets')->where('id', $row?->id)->value('sealed_secret');

    expect($stored)->not->toContain($plaintext)
        ->and($stored)->not->toContain(base64_encode($plaintext))
        ->and($stored)->toStartWith('fake:v1:')
        // And the model cannot serialise it into a response, a log line, or a payload.
        ->and($row?->toArray())->not->toHaveKey('sealed_secret')
        ->and(json_encode($row))->not->toContain($stored);
});

it('keeps one signer per scope and separate lineages per scope', function (): void {
    FakeKms::bind();
    $secrets = app(SigningSecretStore::class);

    $secrets->provision('gateway:razorpay');
    $secrets->provision('gateway:stripe');
    $secrets->rotate('gateway:razorpay');

    $signature = $secrets->sign('gateway:razorpay', HMAC_PAYLOAD);

    expect(SigningSecret::query()->where('scope', 'gateway:razorpay')->whereNull('accepted_until')->count())->toBe(1)
        // A signature is bound to its scope: the same payload does not verify elsewhere.
        ->and($secrets->verify('gateway:stripe', HMAC_PAYLOAD, $signature))->toBeFalse();
});

it('attributes a tenant secret to its tenant and keeps it through a rotation', function (): void {
    FakeKms::bind();
    $tenant = Tenant::factory()->create();
    $secrets = app(SigningSecretStore::class);

    $secrets->provision('tenant:'.$tenant->id.':api', $tenant);
    $secrets->rotate('tenant:'.$tenant->id.':api');

    expect(SigningSecret::query()->where('scope', 'tenant:'.$tenant->id.':api')->pluck('tenant_id')->unique()->all())
        ->toBe([$tenant->id]);
});

it('fails closed when the key store cannot seal or open a secret', function (): void {
    $kms = FakeKms::bind();
    $secrets = app(SigningSecretStore::class);

    $signature = $secrets->sign(HMAC_SCOPE, HMAC_PAYLOAD);
    $kms->fail();
    $secrets->forgetSecrets();

    expect(fn (): string => $secrets->sign('gateway:new', HMAC_PAYLOAD))->toThrow(KeyUnavailableException::class)
        // Verification of an unreadable secret is a rejection, not an error page.
        ->and($secrets->verify(HMAC_SCOPE, HMAC_PAYLOAD, $signature))->toBeFalse()
        ->and(SigningSecret::query()->where('scope', 'gateway:new')->count())->toBe(0);
});

it('leaves the current secret signing when a rotation cannot be sealed', function (): void {
    $kms = FakeKms::bind();
    $secrets = app(SigningSecretStore::class);

    $signature = $secrets->sign(HMAC_SCOPE, HMAC_PAYLOAD);
    $kms->failWith(1);

    expect(fn () => $secrets->rotate(HMAC_SCOPE))->toThrow(KeyUnavailableException::class);

    $secrets->forgetSecrets();

    expect(SigningSecret::query()->where('scope', HMAC_SCOPE)->count())->toBe(1)
        ->and(SigningSecret::signerFor(HMAC_SCOPE)?->version)->toBe(1)
        ->and(SigningSecret::signerFor(HMAC_SCOPE)?->accepted_until)->toBeNull()
        // The peer never notices: the secret it holds is still the signer.
        ->and($secrets->verify(HMAC_SCOPE, HMAC_PAYLOAD, $signature))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The scheduled command
|--------------------------------------------------------------------------
*/

it('rotates scopes past the interval and prints no secret material', function (): void {
    FakeKms::bind();
    Carbon::setTestNow('2025-01-01 00:00:00');

    $secrets = app(SigningSecretStore::class);
    $secrets->provision(HMAC_SCOPE);
    $plaintext = $secrets->currentSecret(HMAC_SCOPE);

    Carbon::setTestNow('2025-06-01 00:00:00');

    thisTest()->artisan(RotateSigningSecrets::class)
        ->expectsOutputToContain('v2 signs; v1 still verifies until')
        ->doesntExpectOutputToContain(bin2hex($plaintext))
        ->assertSuccessful();

    expect(SigningSecret::signerFor(HMAC_SCOPE)?->version)->toBe(2);
});

it('leaves a freshly issued scope alone', function (): void {
    FakeKms::bind();
    app(SigningSecretStore::class)->provision(HMAC_SCOPE);

    thisTest()->artisan(RotateSigningSecrets::class)
        ->expectsOutputToContain('No signing secrets are due for rotation.')
        ->assertSuccessful();

    expect(SigningSecret::query()->where('scope', HMAC_SCOPE)->count())->toBe(1);
});

it('rotates a single named scope on demand, with a shorter window if asked', function (): void {
    FakeKms::bind();
    Carbon::setTestNow('2025-06-01 12:00:00');

    app(SigningSecretStore::class)->provision('gateway:razorpay');

    thisTest()->artisan(RotateSigningSecrets::class, ['--scope' => 'gateway:razorpay', '--overlap' => '600'])
        ->assertSuccessful();

    expect(SigningSecret::atVersion('gateway:razorpay', 1)?->accepted_until?->toDateTimeString())
        ->toBe('2025-06-01 12:10:00');
});

it('purges closed windows on every run unless told not to', function (): void {
    FakeKms::bind();
    Carbon::setTestNow('2025-06-01 12:00:00');

    $secrets = app(SigningSecretStore::class);
    $secrets->provision(HMAC_SCOPE);
    $secrets->rotate(HMAC_SCOPE, overlapSeconds: 60);

    Carbon::setTestNow('2025-06-01 13:00:00');

    thisTest()->artisan(RotateSigningSecrets::class, ['--no-purge' => true])->assertSuccessful();
    expect(SigningSecret::atVersion(HMAC_SCOPE, 1))->not->toBeNull();

    thisTest()->artisan(RotateSigningSecrets::class)
        ->expectsOutputToContain('purged')
        ->assertSuccessful();

    expect(SigningSecret::atVersion(HMAC_SCOPE, 1))->toBeNull();
});

it('fails loudly when an explicitly named scope cannot be rotated', function (): void {
    $kms = FakeKms::bind();
    app(SigningSecretStore::class)->provision('gateway:razorpay');
    $kms->fail();

    thisTest()->artisan(RotateSigningSecrets::class, ['--scope' => 'gateway:razorpay'])->assertFailed();
});

it('rejects nonsense options', function (array $options): void {
    FakeKms::bind();

    thisTest()->artisan(RotateSigningSecrets::class, $options)->assertExitCode(RotateSigningSecrets::INVALID);
})->with([
    'bad limit' => [['--limit' => '0']],
    'negative overlap' => [['--overlap' => '-1']],
    'empty scope' => [['--scope' => ' ']],
]);
