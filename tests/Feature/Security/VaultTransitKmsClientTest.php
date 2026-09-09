<?php

declare(strict_types=1);

use App\Exceptions\Security\KeyUnavailableException;
use App\Services\Security\VaultTransitKmsClient;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| The real KMS adapter: Vault transit over HTTP (Req 32.6 / NFR3)
|--------------------------------------------------------------------------
| A KMS cannot be reached from a test run, so the *HTTP layer* is faked and the
| adapter is not: every request it builds and every response it parses is asserted
| against Vault's documented transit API —
|
|   POST /v1/{mount}/encrypt/{key}   plaintext + associated_data (base64) -> data.ciphertext
|   POST /v1/{mount}/decrypt/{key}   ciphertext + associated_data         -> data.plaintext
|   GET  /v1/{mount}/keys/{key}                                           -> data.latest_version, data.type
|   POST /v1/{mount}/keys/{key}/rotate
|
| The four things worth pinning: the wire format, that the AAD really travels (drop it
| and cross-tenant ciphertext becomes decryptable), that every failure mode is
| fail-closed and leaks nothing, and that key ids carry the version the sweep needs.
*/

const VAULT_ADDR = 'https://vault.test:8200';

function vaultClient(string $mount = 'transit', string $key = 'wa-master', ?string $tokenFile = null): VaultTransitKmsClient
{
    return new VaultTransitKmsClient(
        app(HttpFactory::class),
        VAULT_ADDR,
        'hvs.test-token',
        $tokenFile,
        $mount,
        $key,
        null,
        5,
        3,
    );
}

/**
 * The key-metadata response the client reads `latest_version` and `type` from.
 *
 * @return array<string, mixed>
 */
function vaultKeyRead(int $latestVersion = 1, string $type = 'aes256-gcm96'): array
{
    return ['data' => ['latest_version' => $latestVersion, 'type' => $type, 'name' => 'wa-master']];
}

it('reports the active key id with its version', function (): void {
    Http::fake(['vault.test:8200/v1/transit/keys/wa-master' => Http::response(vaultKeyRead(3))]);

    expect(vaultClient()->activeKeyId())->toBe('vault:transit/wa-master:v3');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === VAULT_ADDR.'/v1/transit/keys/wa-master'
        && $request->header('X-Vault-Token') === ['hvs.test-token']);
});

it('seals through the encrypt endpoint with the context as associated data', function (): void {
    Http::fake([
        'vault.test:8200/v1/transit/keys/wa-master' => Http::response(vaultKeyRead(2)),
        'vault.test:8200/v1/transit/encrypt/wa-master' => Http::response(['data' => ['ciphertext' => 'vault:v2:c2VhbGVk']]),
    ]);

    $seal = vaultClient()->encrypt('data-key-bytes', 'wa:dek-wrap:v1|tenant|FIELD|1');

    expect($seal->ciphertext)->toBe('vault:v2:c2VhbGVk')
        // The version comes from the ciphertext, not from `latest_version`: a key rotated
        // between the two calls must not be recorded as the wrong version.
        ->and($seal->keyId)->toBe('vault:transit/wa-master:v2')
        ->and($seal->algorithm)->toBe(VaultTransitKmsClient::ALGORITHM);

    Http::assertSent(function (Request $request): bool {
        if ($request->url() !== VAULT_ADDR.'/v1/transit/encrypt/wa-master') {
            return false;
        }

        return $request['plaintext'] === base64_encode('data-key-bytes')
            && $request['associated_data'] === base64_encode('wa:dek-wrap:v1|tenant|FIELD|1');
    });
});

it('opens through the decrypt endpoint, addressing the key the blob was sealed with', function (): void {
    Http::fake([
        'vault.test:8200/v1/legacy/decrypt/old-master' => Http::response([
            'data' => ['plaintext' => base64_encode('data-key-bytes')],
        ]),
    ]);

    // The stored id names another mount and key name — a remount or rename must not
    // orphan data, so the *id* wins over the configured key.
    $plaintext = vaultClient()->decrypt('vault:legacy/old-master:v1', 'vault:v1:c2VhbGVk', 'ctx');

    expect($plaintext)->toBe('data-key-bytes');

    Http::assertSent(fn (Request $request): bool => $request->url() === VAULT_ADDR.'/v1/legacy/decrypt/old-master'
        && $request['ciphertext'] === 'vault:v1:c2VhbGVk'
        && $request['associated_data'] === base64_encode('ctx'));
});

it('rotates the transit key and reports the new version', function (): void {
    $reads = 0;

    Http::fake([
        'vault.test:8200/v1/transit/keys/wa-master/rotate' => Http::response('', 204),
        'vault.test:8200/v1/transit/keys/wa-master' => function () use (&$reads) {
            $reads++;

            return Http::response(vaultKeyRead($reads === 1 ? 1 : 2));
        },
    ]);

    $client = vaultClient();

    expect($client->activeKeyId())->toBe('vault:transit/wa-master:v1')
        ->and($client->rotate())->toBe('vault:transit/wa-master:v2')
        // Memoised afterwards: the new version is not re-read on every call.
        ->and($client->activeKeyId())->toBe('vault:transit/wa-master:v2');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === VAULT_ADDR.'/v1/transit/keys/wa-master/rotate');
});

it('refuses a transit key whose type cannot authenticate associated data', function (): void {
    // A non-AEAD key would accept `associated_data` and ignore it, silently dropping the
    // cryptographic tenant binding. Refusing is the only safe answer.
    Http::fake(['vault.test:8200/v1/transit/keys/wa-master' => Http::response(vaultKeyRead(1, 'rsa-2048'))]);

    expect(fn () => vaultClient()->encrypt('dek', 'ctx'))->toThrow(KeyUnavailableException::class);

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/encrypt/'));
});

it('reads the token from a file on every request, for an injected token', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'vault-token');
    expect($path)->toBeString();
    file_put_contents((string) $path, "hvs.rotated-by-agent\n");

    Http::fake(['vault.test:8200/*' => Http::response(vaultKeyRead(1))]);

    vaultClient(tokenFile: (string) $path)->activeKeyId();

    Http::assertSent(fn (Request $request): bool => $request->header('X-Vault-Token') === ['hvs.rotated-by-agent']);

    @unlink((string) $path);
});

it('fails closed when Vault refuses, is unreachable, or answers with nonsense', function (array $fake, string $needle): void {
    Http::fake(['vault.test:8200/*' => $fake['response']]);

    try {
        vaultClient()->activeKeyId();
        thisTest()->fail('an unusable key store must not produce a key id');
    } catch (KeyUnavailableException $e) {
        expect($e->getStatusCode())->toBe(503)
            ->and($e->isRetryable())->toBeTrue()
            ->and($e->getMessage())->toContain($needle)
            // Never the token, never the address, never a response body.
            ->and($e->getMessage())->not->toContain('hvs.test-token')
            ->and($e->getMessage())->not->toContain('vault.test');
    }
})->with([
    'permission denied' => [['response' => fn () => Http::response(['errors' => ['permission denied']], 403)], 'rejected'],
    'sealed' => [['response' => fn () => Http::response(['errors' => ['Vault is sealed']], 503)], 'rejected'],
    'unreachable' => [['response' => fn () => throw new Illuminate\Http\Client\ConnectionException('cURL error 7')], 'could not be reached'],
    'not json' => [['response' => fn () => Http::response('<html>gateway</html>', 200, ['Content-Type' => 'text/html'])], 'unusable response'],
    'no version' => [['response' => fn () => Http::response(['data' => ['type' => 'aes256-gcm96']])], 'unusable response'],
]);

it('refuses a key id it cannot address', function (string $keyId): void {
    Http::fake();

    expect(fn (): string => vaultClient()->decrypt($keyId, 'vault:v1:blob', 'ctx'))
        ->toThrow(KeyUnavailableException::class);

    Http::assertNothingSent();
})->with([
    'foreign format' => ['aws:kms:key/1234'],
    'no mount' => ['vault:wa-master:v1'],
    'empty name' => ['vault:transit/:v1'],
]);

it('refuses a sealed value that is not a transit ciphertext', function (): void {
    Http::fake([
        'vault.test:8200/v1/transit/keys/wa-master' => Http::response(vaultKeyRead(1)),
        'vault.test:8200/v1/transit/encrypt/wa-master' => Http::response(['data' => ['ciphertext' => 'not-a-vault-blob']]),
    ]);

    // Recording it would attribute the blob to a version that did not seal it, and the
    // re-wrap sweep would then skip material that has to move.
    expect(fn () => vaultClient()->encrypt('dek', 'ctx'))->toThrow(KeyUnavailableException::class);
});

it('refuses a decrypt response whose plaintext is not base64', function (): void {
    Http::fake(['vault.test:8200/v1/transit/decrypt/wa-master' => Http::response(['data' => ['plaintext' => 'not base64!!']])]);

    expect(fn (): string => vaultClient()->decrypt('vault:transit/wa-master:v1', 'vault:v1:blob', 'ctx'))
        ->toThrow(KeyUnavailableException::class);
});

it('fails closed when the key store is not configured at all', function (): void {
    Http::fake();

    $unconfigured = new VaultTransitKmsClient(app(HttpFactory::class), '', '', null);

    expect(fn (): string => $unconfigured->activeKeyId())->toThrow(KeyUnavailableException::class);

    Http::assertNothingSent();
});
