<?php

declare(strict_types=1);

use App\Console\Commands\RotateMasterKey;
use App\Console\Commands\RotateSigningSecrets;
use App\Console\Commands\RotateTenantKeys;
use App\Services\Security\ConfigMasterKeyWrapper;
use App\Services\Security\DatabaseSigningSecretStore;
use App\Services\Security\EncryptionKeyRewrapStore;
use App\Services\Security\KeyWrapper;
use App\Services\Security\KmsClient;
use App\Services\Security\MasterKeyRewrapper;
use App\Services\Security\SigningSecretRewrapStore;
use App\Services\Security\SigningSecretStore;
use App\Services\Security\VaultTransitKmsClient;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| Wiring the rotation layer (Req 32.6 / NFR3; Property 28 / Req 36)
|--------------------------------------------------------------------------
| Three things that are only true if nobody breaks them:
|
|   1. the KMS is **opt-in** — a deployment that configures none keeps the working
|      config master key, and resolving the KMS costs nothing until it is asked for;
|   2. `FakeKms` is reachable from tests and from nowhere else;
|   3. every rotation is actually on the scheduler, guarded against overlap and against
|      running on two app servers at once.
*/

it('keeps the config master key as the default and the KMS opt-in', function (): void {
    expect(config('wa.security.encryption.wrapper'))->toBe(ConfigMasterKeyWrapper::class)
        ->and(app(KeyWrapper::class))->toBeInstanceOf(ConfigMasterKeyWrapper::class)
        // Configured but unreachable is fine: nothing resolves it until a KMS is selected.
        ->and(config('wa.security.kms.driver'))->toBe('vault');
});

it('builds the Vault client from config when the KMS is selected', function (): void {
    config([
        'wa.security.encryption.wrapper' => App\Services\Security\KmsKeyWrapper::class,
        'wa.security.kms.guard.enabled' => false,
        'wa.security.kms.vault.address' => 'https://vault.test:8200',
        'wa.security.kms.vault.token' => 'hvs.test',
    ]);
    app()->forgetInstance(KmsClient::class);
    app()->forgetInstance(KeyWrapper::class);

    expect(app(KmsClient::class))->toBeInstanceOf(VaultTransitKmsClient::class)
        ->and(app(KeyWrapper::class))->toBeInstanceOf(App\Services\Security\KmsKeyWrapper::class);
});

it('refuses a KMS driver that is not a KmsClient', function (): void {
    // Resolvable, but not a key store: the type check must be what refuses it.
    config(['wa.security.kms.driver' => EncryptionKeyRewrapStore::class]);
    app()->forgetInstance(KmsClient::class);

    expect(fn () => app(KmsClient::class))->toThrow(InvalidArgumentException::class);
});

it('sweeps both stores holding master-key-sealed material', function (): void {
    expect(config('wa.security.encryption.rotation.stores'))
        ->toBe([EncryptionKeyRewrapStore::class, SigningSecretRewrapStore::class]);

    // A store that is not one is fatal, not skipped: a silently skipped store leaves rows
    // sealed under a key an operator is about to retire.
    config(['wa.security.encryption.rotation.stores' => [App\Services\Security\DekRotator::class]]);
    app()->forgetInstance(MasterKeyRewrapper::class);

    expect(fn () => app(MasterKeyRewrapper::class))->toThrow(InvalidArgumentException::class);
});

it('scopes the signing secret store to one unit of work', function (): void {
    $first = app(SigningSecretStore::class);

    expect($first)->toBeInstanceOf(DatabaseSigningSecretStore::class)
        ->and(app(SigningSecretStore::class))->toBe($first);

    // Opened secrets must not survive the request or the job that opened them, or a
    // revoked master key would keep working inside a long-lived worker.
    app()->forgetScopedInstances();

    expect(app(SigningSecretStore::class))->not->toBe($first);
});

it('never references a test double from code under app/', function (): void {
    $offenders = [];

    foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
        // Comments are stripped first: docblocks *should* be able to explain where the fake
        // lives and why, and a scan that cannot tell a prose mention from a reference is a
        // scan that gets disabled the first time it cries wolf. What must not exist is a
        // *code* reference — a class name, a string, an import.
        foreach (token_get_all((string) file_get_contents($file->getRealPath())) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML], true)) {
                continue;
            }

            $code = is_array($token) ? $token[1] : $token;

            if (preg_match('/\b(Fake|Stub)[A-Z]\w*/', $code, $matches) === 1) {
                $offenders[$file->getRelativePathname()] = $matches[0];

                break;
            }
        }
    }

    // FakeKms lives under tests/ and is mapped by autoload-dev only, so there is nothing in
    // a production install to bind by accident (Property 28 / Req 36; task 39.4 widens this
    // scan to the whole build).
    expect($offenders)->toBe([])
        ->and(class_exists('Tests\Fixtures\Security\FakeKms'))->toBeTrue()
        ->and(config('wa.security.kms.driver'))->not->toContain('Fake');
});

it('puts every rotation on the scheduler, guarded against overlap and duplicate servers', function (string $command, string $expression, int $expected): void {
    $events = array_values(array_filter(
        app(Schedule::class)->events(),
        fn (ScheduledEvent $event): bool => str_contains($event->command ?? '', $command),
    ));

    expect($events)->toHaveCount($expected);

    $matching = array_values(array_filter(
        $events,
        fn (ScheduledEvent $event): bool => $event->expression === $expression,
    ));

    expect($matching)->not->toBeEmpty()
        ->and($matching[0]->withoutOverlapping)->toBeTrue()
        ->and($matching[0]->onOneServer)->toBeTrue();
})->with([
    // Daily, because the interval is measured in months.
    'DEK rotation' => ['wa:security:rotate-deks', '10 3 * * *', 1],
    // Monthly mint + hourly catch-up, so a large estate can finish re-wrapping.
    'master key rotation' => ['wa:security:rotate-master-key', '30 2 1 * *', 2],
    'HMAC rotation' => ['wa:security:rotate-hmac', '0 * * * *', 1],
]);

it('schedules an hourly re-wrap catch-up that mints nothing', function (): void {
    $hourly = array_values(array_filter(
        app(Schedule::class)->events(),
        fn (ScheduledEvent $event): bool => str_contains($event->command ?? '', 'wa:security:rotate-master-key')
            && $event->expression === '0 * * * *',
    ));

    expect($hourly)->toHaveCount(1)
        // Minting a master key version every hour would burn versions for nothing; the
        // hourly pass exists purely so the previous key can be retired promptly.
        ->and($hourly[0]->command)->toContain('--rewrap-only');
});

it('registers the rotation commands with the console', function (): void {
    $commands = array_keys(Illuminate\Support\Facades\Artisan::all());

    expect($commands)->toContain('wa:security:rotate-master-key')
        ->and($commands)->toContain('wa:security:rotate-deks')
        ->and($commands)->toContain('wa:security:rotate-hmac')
        ->and(app(RotateMasterKey::class))->toBeInstanceOf(RotateMasterKey::class)
        ->and(app(RotateTenantKeys::class))->toBeInstanceOf(RotateTenantKeys::class)
        ->and(app(RotateSigningSecrets::class))->toBeInstanceOf(RotateSigningSecrets::class);
});
