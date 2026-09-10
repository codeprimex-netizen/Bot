<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ChannelMode;
use Database\Factories\ChannelWebhookRouteFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * The map from one inbound provider webhook to one `(tenant, session, driver)` tuple
 * (Req 8.4 / A8; Req 9.2 / A9; design § Channel Mode 2.5).
 *
 * ```php
 * // what task 8.3's webhook controller does first, with no tenant bound
 * $route = ChannelWebhookRoute::resolve($routeKey);   // ?ChannelWebhookRoute
 * ```
 *
 * ## Not `BelongsToTenant` — a reviewed exemption
 *
 * A Meta or BSP callback arrives with no panel session, no subdomain, and no API key. The
 * `route_key` in its URL is the only tenant-bearing thing about it, so this table is what
 * *establishes* the tenant context rather than something read inside it — and a table cannot
 * be filtered by the answer it provides. The same argument `TenantDomain` makes for
 * `unique(host)`, one level up, and `TenantOwnedModelsGuardTest::tenantScopeExemptions()`
 * records the decision.
 *
 * `route_key` is therefore **globally** unique, not unique per tenant. Two tenants holding
 * one key would be an ambiguity resolvable only by guessing which one a callback meant.
 *
 * Isolation is carried by the key instead of by a scope, and three things make that hold:
 *
 * 1. the key is **unguessable** — task 5.6 mints it, and `shapeOf()` below pins it to the
 *    URL-safe alphabet `UrlBuilder::webhook()` accepts, so it can be a full-entropy token
 *    rather than anything derived from the tenant;
 * 2. a leaked key reaches exactly the one session it names — never a tenant's whole estate;
 * 3. the payload is still **signature-verified** by that session's driver (bridge HMAC, Meta
 *    verify-token + signature, BSP signature) before anything is believed, so the key alone
 *    is not authority to inject an inbound message.
 *
 * Every *outbound* read here names its tenant explicitly (`forSession()` runs inside a bound
 * context; `resolve()` is the one deliberate cross-tenant lookup, and it is the one an
 * unauthenticated request performs).
 *
 * ## What this model does not do
 *
 * | Concern | Owner |
 * |---|---|
 * | minting a `route_key` and registering the callback with the provider | task 5.6, and `ChannelDriver::register()` (task 6.2) |
 * | verifying the signature and parsing the payload | task 8.3, via the session's driver |
 * | retiring routes when a session changes mode | task 8.6 |
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $session_id
 * @property ChannelMode $mode
 * @property string $route_key
 * @property string|null $verify_token_hash
 * @property string|null $signing_secret_ref
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant $tenant
 * @property-read Session $session
 */
class ChannelWebhookRoute extends Model
{
    /** @use HasFactory<ChannelWebhookRouteFactory> */
    use HasFactory;

    use HasUlids;

    /**
     * The shape a `route_key` must have.
     *
     * The URL-safe base64 alphabet, 1–128 characters — deliberately the same shape
     * `App\Services\Url\CanonicalUrlBuilder` validates its `$routeKey` argument against, so
     * a key this model accepts is a key `UrlBuilder::webhook()` can build a callback URL
     * from. `ChannelWebhookRouteTest` round-trips a stored key through the builder rather
     * than trusting the two patterns to stay equal by eye.
     */
    public const string ROUTE_KEY_PATTERN = '/^[A-Za-z0-9_-]{1,128}$/';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'active' => true,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'session_id',
        'mode',
        'route_key',
        'verify_token_hash',
        'signing_secret_ref',
        'active',
    ];

    /**
     * The verify-token digest is hidden from every serialisation.
     *
     * It is a digest, so exposing it is not immediately fatal — but its only purpose is to
     * be compared against a value a provider echoes, and a digest in an API response is a
     * digest an attacker can grind offline. Nothing outside this model needs it.
     *
     * @var list<string>
     */
    protected $hidden = [
        'verify_token_hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mode' => ChannelMode::class,
            'active' => 'boolean',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Lookup
    |--------------------------------------------------------------------------
    */

    /**
     * The live route for `$routeKey`, or null — the read an inbound webhook performs.
     *
     * Cross-tenant by necessity (see the class docblock) and deliberately narrow: it matches
     * on the key alone, returns only `active` rows, and eager-loads nothing. A retired route
     * resolves to null, which is what makes rotating a key an actual revocation rather than
     * a rename.
     */
    public static function resolve(string $routeKey): ?self
    {
        if (preg_match(self::ROUTE_KEY_PATTERN, $routeKey) !== 1) {
            // A malformed key cannot be in the table — the mutator refuses to store one — so
            // this saves a query on the shape of request an attacker sends most of.
            return null;
        }

        return static::query()
            ->where('route_key', $routeKey)
            ->where('active', true)
            ->first();
    }

    /**
     * Routes belonging to one session, newest first.
     *
     * @param  Builder<ChannelWebhookRoute>  $query
     * @return Builder<ChannelWebhookRoute>
     */
    public function scopeForSession(Builder $query, string $sessionId): Builder
    {
        return $query->where('session_id', $sessionId)->orderByDesc('created_at');
    }

    /**
     * @param  Builder<ChannelWebhookRoute>  $query
     * @return Builder<ChannelWebhookRoute>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * Routes of one tenant — named explicitly, because there is no global scope to do it.
     *
     * @param  Builder<ChannelWebhookRoute>  $query
     * @return Builder<ChannelWebhookRoute>
     */
    public function scopeForTenant(Builder $query, string $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    /*
    |--------------------------------------------------------------------------
    | What the row says
    |--------------------------------------------------------------------------
    */

    /**
     * Whether `$token` is the verify token this route was registered with.
     *
     * Constant-time comparison of digests, so the handshake cannot be probed byte by byte.
     * `false` when no token was stored — a route with no handshake cannot complete one.
     */
    public function matchesVerifyToken(string $token): bool
    {
        $stored = $this->verify_token_hash;

        if ($stored === null || $stored === '' || $token === '') {
            return false;
        }

        return hash_equals($stored, self::hashVerifyToken($token));
    }

    /**
     * The digest form a verify token is stored as.
     *
     * A plain SHA-256, not a password hash: the input is a high-entropy token the platform
     * generated, so there is nothing to slow a dictionary attack down for — and the
     * comparison happens on every webhook handshake.
     */
    public static function hashVerifyToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Refuse to store a `route_key` that could not appear in a callback URL.
     *
     * A mutator rather than a service check, so every writer — task 5.6, a console command,
     * a seeder, a test — is held to the same shape. A key the URL builder would reject is a
     * key no provider can ever call back on, which would look like a silently dead session.
     *
     * @throws InvalidArgumentException when the key is not URL-safe
     */
    public function setRouteKeyAttribute(string $routeKey): void
    {
        if (preg_match(self::ROUTE_KEY_PATTERN, $routeKey) !== 1) {
            throw new InvalidArgumentException(
                'A channel webhook route_key must match '.self::ROUTE_KEY_PATTERN.'; got ['.$routeKey.'].'
            );
        }

        $this->attributes['route_key'] = $routeKey;
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<Session, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class, 'session_id');
    }
}
