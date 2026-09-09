<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Services\Tenancy\NewApiToken;
use Database\Factories\TenantApiTokenFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * An API key that lets a machine caller act as exactly one tenant.
 *
 * The plaintext is `{id}|{secret}` (the Sanctum convention: the id narrows the
 * lookup to one row, the secret is verified against its stored hash). Only the
 * hash is persisted, so the plaintext exists exactly once — in the
 * `NewApiToken` returned by `issue()`.
 *
 * Tenant-owned: `BelongsToTenant` scopes every read to the acting tenant, so the
 * panel's "my API keys" screen can never list somebody else's. The one query that
 * legitimately looks across tenants is the *authentication* lookup itself — it runs
 * before any tenant is bound, and says so with `withoutTenantScope()` in
 * `DatabaseTenantTokenRepository`.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $token
 * @property \Illuminate\Support\Carbon|null $last_used_at
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property \Illuminate\Support\Carbon|null $revoked_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class TenantApiToken extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<TenantApiTokenFactory> */
    use HasFactory;

    use HasUlids;

    /**
     * Bytes of entropy in the secret half of a generated token.
     */
    public const int SECRET_LENGTH = 48;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'name',
        'token',
        'last_used_at',
        'expires_at',
        'revoked_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Issue a new key for a tenant. The plaintext is returned once and never
     * recoverable afterwards.
     */
    public static function issue(Tenant $tenant, string $name, ?DateTimeInterface $expiresAt = null): NewApiToken
    {
        $secret = Str::random(self::SECRET_LENGTH);

        $token = static::query()->create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'token' => self::hashSecret($secret),
            'expires_at' => $expiresAt,
        ]);

        return new NewApiToken($token, $token->id.'|'.$secret);
    }

    /**
     * The stored form of a secret.
     */
    public static function hashSecret(string $secret): string
    {
        return hash('sha256', $secret);
    }

    /**
     * Split a presented plaintext into its optional id and its secret.
     *
     * A value without the `|` separator is treated as an opaque secret, so a
     * caller sending a bare key still works (the lookup then relies on the hash
     * index alone).
     *
     * @return array{0: string|null, 1: string}
     */
    public static function splitPlainText(string $plainTextToken): array
    {
        $plainTextToken = trim($plainTextToken);
        $position = strpos($plainTextToken, '|');

        if ($position === false) {
            return [null, $plainTextToken];
        }

        $id = substr($plainTextToken, 0, $position);
        $secret = substr($plainTextToken, $position + 1);

        return [$id === '' ? null : $id, $secret];
    }

    /**
     * Whether this key may still be used to act as its tenant.
     */
    public function isUsable(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    /**
     * Revoke the key. Idempotent.
     */
    public function revoke(): void
    {
        if ($this->revoked_at === null) {
            $this->forceFill(['revoked_at' => now()])->save();
        }
    }

    /**
     * Record that the key was just accepted, without touching `updated_at`.
     */
    public function markUsed(): void
    {
        static::withoutTimestamps(function (): void {
            $this->forceFill(['last_used_at' => now()])->saveQuietly();
        });
    }
}
