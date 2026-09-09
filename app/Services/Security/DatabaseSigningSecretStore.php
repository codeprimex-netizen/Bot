<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Exceptions\Security\KeyUnavailableException;
use App\Models\SigningSecret;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The `SigningSecretStore` implementation: secrets in `signing_secrets`, sealed by
 * `KeyWrapper`, rotated with an overlap window (Req 32.6 / NFR3).
 *
 * Read `SigningSecretStore` for the contract. This class is where the three decisions
 * that make it hold live.
 *
 * ## 1. The overlap is a deadline in a column, not a state machine
 *
 * `accepted_until` is NULL on the signer and a timestamp on everything rotated out.
 * Verification asks for "NULL or in the future", so the window closes on the clock —
 * a purge job that never runs widens nothing, and a clock that jumps forward closes
 * windows early (fail closed) rather than late.
 *
 * ## 2. Verification is total: no throw, no early exit
 *
 * An inbound signature is untrusted input. It must be able to produce exactly one
 * outcome — valid or not — so an unknown scope, an unopenable secret, and a garbage
 * header all return `false` instead of choosing between a 403 and a 503 for the
 * attacker. Every candidate is tried even after a match, so the time taken does not
 * reveal which version the peer signed with.
 *
 * ## 3. Opened secrets live for one unit of work
 *
 * Opening a secret is a key-store round trip, so they are memoised per `scope|version`
 * on this instance — and `SecurityServiceProvider` binds the store with `scoped()`, so
 * the cache dies with the request or the job. A KMS-revoked key therefore stops working
 * within one unit of work rather than whenever a long-lived worker restarts.
 *
 * ## Signature presentation
 *
 * `sha256=<lowercase hex>` by default (`wa.security.hmac.prefix`), the shape Meta and
 * most payment gateways use. `verify()` accepts the value with or without the prefix so
 * a peer that sends a bare hex digest still works. The HMAC algorithm itself is
 * platform-wide config rather than per row: changing it is an algorithm migration that
 * invalidates in-flight signatures for every scope at once, which is a different
 * operation from rotating a secret and deliberately not something a rotation can smuggle
 * in.
 */
final class DatabaseSigningSecretStore implements SigningSecretStore
{
    /**
     * Additional authenticated data prefix binding a sealed secret to its scope and
     * version — so a `sealed_secret` blob moved into another scope's row does not open.
     * Distinct from `EnvelopeFieldCipher`'s contexts so the two can never be confused.
     */
    private const string SEAL_CONTEXT = 'wa:hmac-secret:v1';

    /**
     * Opened secrets for this unit of work, keyed `scope|version`. Never persisted,
     * never logged.
     *
     * @var array<string, string>
     */
    private array $secrets = [];

    /**
     * @param  int  $secretBytes  length of a newly issued secret
     * @param  string  $algorithm  HMAC hash, e.g. `sha256`
     * @param  string  $prefix  presentation prefix, e.g. `sha256=`
     * @param  int  $overlapSeconds  how long a rotated-out secret keeps verifying
     * @param  int  $rotateAfterDays  age at which `dueForRotation()` reports a scope
     */
    public function __construct(
        private readonly KeyWrapper $wrapper,
        private readonly int $secretBytes = 32,
        private readonly string $algorithm = 'sha256',
        private readonly string $prefix = 'sha256=',
        private readonly int $overlapSeconds = 172_800,
        private readonly int $rotateAfterDays = 30,
    ) {}

    public function sign(string $scope, string $payload): string
    {
        return $this->prefix.hash_hmac($this->assertedAlgorithm(), $payload, $this->signerSecret($scope));
    }

    public function verify(string $scope, string $payload, string $signature): bool
    {
        $provided = $this->normalize($signature);

        if ($provided === '' || $scope === '') {
            return false;
        }

        $algorithm = $this->assertedAlgorithm();
        $matched = false;

        foreach (SigningSecret::acceptableFor($scope) as $candidate) {
            try {
                $secret = $this->open($candidate);
            } catch (KeyUnavailableException) {
                // A secret we cannot open cannot authenticate anything. It is skipped
                // rather than raised: a broken row must not make a valid signature fail
                // *and* must not make an invalid one succeed.
                continue;
            }

            // No `break`: every acceptable version is tried so the time taken does not
            // depend on which one matched.
            $matched = hash_equals(hash_hmac($algorithm, $payload, $secret), $provided) || $matched;
        }

        return $matched;
    }

    public function currentSecret(string $scope): string
    {
        return $this->signerSecret($scope);
    }

    public function provision(string $scope, Tenant|string|null $tenant = null): int
    {
        return $this->signer($scope, $tenant)->version;
    }

    public function rotate(string $scope, ?int $overlapSeconds = null): SigningSecretRotation
    {
        $this->assertScope($scope);

        $overlap = max(0, $overlapSeconds ?? $this->overlapSeconds);

        // One transaction, so a scope is never observed with two signers (the deadline
        // and the insert land together) and never with none.
        $rotation = DB::transaction(function () use ($scope, $overlap): SigningSecretRotation {
            $current = SigningSecret::signerFor($scope);
            $acceptedUntil = null;

            if ($current !== null) {
                // A deadline, not a delete: the peer may still be signing with it.
                $acceptedUntil = Carbon::now()->addSeconds($overlap);
                $current->accepted_until = $acceptedUntil;
                $current->rotated_at = Carbon::now();
                $current->save();
            }

            $minted = $this->mint(
                $scope,
                SigningSecret::latestVersion($scope) + 1,
                $current?->tenant_id,
            );

            return new SigningSecretRotation(
                $scope,
                $minted->version,
                $current?->version,
                $acceptedUntil,
            );
        });

        // The next sign() in this process must use the new secret, and the demoted row's
        // cached copy must not keep claiming to be the signer.
        $this->forgetScope($scope);

        return $rotation;
    }

    /**
     * @return list<string>
     */
    public function dueForRotation(int $limit): array
    {
        $threshold = Carbon::now()->subDays(max(0, $this->rotateAfterDays));

        /** @var list<string> $scopes */
        $scopes = SigningSecret::query()
            ->whereNull('accepted_until')
            ->where('created_at', '<=', $threshold)
            // Oldest first: a sweep with a smaller limit than the backlog still makes
            // progress on the secrets that have been unrotated longest.
            ->orderBy('created_at')
            ->limit(max(1, $limit))
            ->pluck('scope')
            ->all();

        return $scopes;
    }

    public function purgeExpired(): int
    {
        // `accepted_until <= now` can never match a signer (its value is NULL), so this
        // cannot delete the secret a peer is currently using.
        return SigningSecret::query()
            ->whereNotNull('accepted_until')
            ->where('accepted_until', '<=', Carbon::now())
            ->delete();
    }

    public function forgetSecrets(): void
    {
        $this->secrets = [];
    }

    /**
     * The plaintext of the scope's signing secret, issuing the first one if needed.
     */
    private function signerSecret(string $scope): string
    {
        return $this->open($this->signer($scope));
    }

    /**
     * The scope's signing row, created on first use.
     */
    private function signer(string $scope, Tenant|string|null $tenant = null): SigningSecret
    {
        $this->assertScope($scope);

        $signer = SigningSecret::signerFor($scope);

        if ($signer !== null) {
            return $signer;
        }

        try {
            return $this->mint($scope, SigningSecret::latestVersion($scope) + 1, $tenant);
        } catch (QueryException) {
            // `uniq(scope, active_flag)` means only one of two racing callers can insert
            // a signer; the loser re-reads rather than retrying, so both end up signing
            // with the same secret.
            $signer = SigningSecret::signerFor($scope);

            if ($signer === null) {
                throw KeyUnavailableException::missingSigningSecret($scope);
            }

            return $signer;
        }
    }

    /**
     * Generate a secret, seal it, and persist the sealed form as the new signer.
     *
     * The plaintext exists only inside this method and in the in-process cache; what
     * reaches the database is `WrappedKey::$blob`. If sealing fails nothing is written.
     */
    private function mint(string $scope, int $version, Tenant|string|null $tenant): SigningSecret
    {
        $secret = random_bytes(max(16, $this->secretBytes));
        $wrapped = $this->wrapper->wrap($secret, self::context($scope, $version));

        $row = new SigningSecret;
        $row->forceFill([
            'tenant_id' => $tenant instanceof Tenant ? $tenant->id : $tenant,
            'scope' => $scope,
            'version' => $version,
            'accepted_until' => null,
            'kms_key_id' => $wrapped->keyId,
            'algorithm' => $wrapped->algorithm,
            'sealed_secret' => $wrapped->blob,
        ]);
        $row->save();

        $this->secrets[self::cacheKey($scope, $version)] = $secret;

        return $row;
    }

    /**
     * Open one stored secret.
     *
     * @throws KeyUnavailableException when the master key is gone or the row does not
     *                                 authenticate — never a guess, never an empty string
     */
    private function open(SigningSecret $row): string
    {
        $cacheKey = self::cacheKey($row->scope, $row->version);

        if (isset($this->secrets[$cacheKey])) {
            return $this->secrets[$cacheKey];
        }

        $wrapped = $row->toWrappedKey();

        try {
            $secret = $this->wrapper->unwrap(
                $wrapped->keyId,
                $wrapped->blob,
                self::context($row->scope, $row->version),
            );
        } catch (Throwable) {
            // Includes a KMS SDK throwing its own type: the failure is reported without
            // the provider's message, which may quote request payloads.
            throw KeyUnavailableException::signingSecretUnreadable($row->scope, $row->version);
        }

        if ($secret === '') {
            throw KeyUnavailableException::signingSecretUnreadable($row->scope, $row->version);
        }

        return $this->secrets[$cacheKey] = $secret;
    }

    /**
     * Drop every opened secret of one scope — after a rotation, so nothing keeps
     * signing with a secret this process has just demoted.
     */
    private function forgetScope(string $scope): void
    {
        foreach (array_keys($this->secrets) as $cacheKey) {
            if (str_starts_with($cacheKey, $scope.'|')) {
                unset($this->secrets[$cacheKey]);
            }
        }
    }

    /**
     * Reduce a presented signature to the bare lowercase hex digest.
     */
    private function normalize(string $signature): string
    {
        $value = trim($signature);

        if ($this->prefix !== '' && str_starts_with($value, $this->prefix)) {
            $value = substr($value, strlen($this->prefix));
        } elseif (($equals = strpos($value, '=')) !== false) {
            // Tolerate a peer that labels the algorithm differently (`sha256 =`, `hmac-sha256=`);
            // the digest is what is compared, and a wrong label simply will not match.
            $value = substr($value, $equals + 1);
        }

        $value = strtolower(trim($value));

        return preg_match('/^[0-9a-f]+$/', $value) === 1 ? $value : '';
    }

    /**
     * Refuse a hash that this build cannot compute — a silent `hash_hmac()` failure
     * would produce an empty signature that verifies against nothing.
     */
    private function assertedAlgorithm(): string
    {
        if (! in_array($this->algorithm, hash_hmac_algos(), true)) {
            throw KeyUnavailableException::unsupportedAlgorithm($this->algorithm);
        }

        return $this->algorithm;
    }

    /**
     * A scope has to be a usable, index-sized key: an empty one would collapse every
     * unnamed secret into a single row, and an over-long one would be truncated by
     * MySQL and merge two peers.
     */
    private function assertScope(string $scope): void
    {
        if ($scope === '' || mb_strlen($scope) > 191) {
            throw KeyUnavailableException::missingSigningSecret($scope);
        }
    }

    /**
     * Additional authenticated data binding a sealed secret to exactly one scope and
     * version.
     *
     * Public because `SigningSecretRewrapStore` must present the *identical* string when
     * it re-seals a row under a new master key, and duplicating the format is how those
     * two drift apart — the same reason `EnvelopeFieldCipher::wrapContext()` is public.
     */
    public static function sealContext(string $scope, int $version): string
    {
        return implode('|', [self::SEAL_CONTEXT, $scope, (string) $version]);
    }

    private static function context(string $scope, int $version): string
    {
        return self::sealContext($scope, $version);
    }

    private static function cacheKey(string $scope, int $version): string
    {
        return $scope.'|'.$version;
    }

    /**
     * Keep opened secrets out of dumps, stack traces, and log context.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'wrapper' => $this->wrapper::class,
            'algorithm' => $this->algorithm,
            'openedSecrets' => count($this->secrets).' (redacted)',
        ];
    }
}
