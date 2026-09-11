<?php

declare(strict_types=1);

use App\Support\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How an inbound provider webhook finds its way back to one tenant, one session, and one
 * driver (Req 8.4 / A8; Req 9.2 / A9; design § Channel Mode 2.5, § Base URL — *"the
 * `route_key` is stored in `channel_webhook_routes` so inbound webhooks map back to
 * `(tenant, session, driver)`"*).
 *
 * ## The read this table exists for happens before a tenant exists
 *
 * A Meta or BSP callback arrives with no panel session, no subdomain, and no API key — the
 * three doors `config('wa.tenancy.resolvers')` knows. What it carries is the URL it was
 * registered with, and the only tenant-bearing thing in that URL is the `route_key`. So
 * this table is not read *within* a tenant's context; it is what **establishes** the
 * context, and then the driver named by `mode` parses the payload.
 *
 * That is why `route_key` is **globally** unique rather than unique per tenant. The same
 * argument `tenant_domains.host` makes one level up: a lookup key that resolves the tenant
 * cannot be scoped by the answer it provides, and two tenants holding one key would be an
 * ambiguity resolvable only by guessing. `App\Models\ChannelWebhookRoute` therefore does
 * **not** use `BelongsToTenant`, and the decision is recorded as a reviewed exemption in
 * `TenantOwnedModelsGuardTest::tenantScopeExemptions()`, next to `TenantDomain`'s.
 *
 * Isolation is carried by the key instead of by a scope, and the key is built to carry it:
 * `route_key` is unguessable (task 5.6 generates it; `UrlBuilder::webhook()` already pins
 * its shape to `[A-Za-z0-9_-]{1,128}`), so an attacker cannot enumerate route keys to reach
 * another tenant's session — and even a leaked key only reaches the one session it names,
 * whose payload is still signature-verified by that session's driver before anything is
 * believed.
 *
 * ## Columns
 *
 * | Column | Why |
 * |---|---|
 * | `session_id` | the session this callback belongs to; `ON DELETE CASCADE`, because a route to a deleted session is a URL that can only 404 |
 * | `mode` | which driver parses the payload. Denormalised from `sessions_wa.channel_mode` **on purpose**: it is the mode the route was *registered for*, and during a mode switch (task 8.6) the session's column has already moved while callbacks registered under the old mode are still arriving. A join would parse them with the new driver and reject them all |
 * | `verify_token_hash` | Meta's webhook handshake echoes a `verify_token` the platform chose. Stored as a hash so a leaked table row cannot complete somebody else's handshake — the comparison is an equality check, so a digest is sufficient |
 * | `signing_secret_ref` | the `signing_secrets.scope` string whose HMAC secret verifies this route's payloads (task 4.2's `SigningSecretStore`). A reference rather than the secret itself: rotation is dual-secret and lives in that table, so copying a secret here would create a second copy to rotate |
 * | `active` | a route can be retired without being deleted, which is what lets task 5.6 rotate a `route_key` while the old URL is still registered with the provider |
 *
 * ## Indexes
 *
 * - `unique(route_key)` — global, and the only lookup an inbound request performs.
 * - `idx(tenant_id, mode)` — design.md's index, from `TenantSchema::tenantId()`. The
 *   tenancy column is still indexed leading-first even though the model is scope-exempt,
 *   because every *outbound* read (the panel listing a session's callbacks, task 8.6's
 *   drain) names its tenant explicitly.
 * - `idx(session_id, active)` — "the live routes of this session", which is the question
 *   registration (task 5.6) and the mode switch (task 8.6) both ask.
 *
 * Deliberately **no** `unique(session_id, mode)`: a rotation issues the new route before
 * retiring the old one, so a session legitimately has two rows for one mode for as long as
 * the provider takes to pick the new URL up. Uniqueness that forbade the overlap would make
 * rotation a moment of downtime.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const array SESSION_INDEX_COLUMNS = ['session_id', 'active'];

    public function up(): void
    {
        Schema::create('channel_webhook_routes', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // idx(tenant_id, mode). The column is present and indexed even though the
            // model is scope-exempt: see the docblock.
            TenantSchema::tenantId($table, 'mode');

            $table->foreignUlid('session_id')
                ->constrained('sessions_wa')
                ->cascadeOnDelete();

            // `App\Enums\ChannelMode` value — the mode this route was registered for.
            $table->string('mode', 16);

            // The opaque key in the callback URL. Globally unique: it resolves the tenant,
            // so it cannot be scoped by one. 128 is the ceiling
            // `UrlBuilder::webhook()` accepts.
            $table->string('route_key', 128)->unique();

            // SHA-256 of the verify token Meta echoes during the webhook handshake.
            $table->string('verify_token_hash', 64)->nullable();

            // A `signing_secrets.scope`, not a secret.
            $table->string('signing_secret_ref', 190)->nullable();

            $table->boolean('active')->default(true);

            $table->timestamps();

            $table->index(
                self::SESSION_INDEX_COLUMNS,
                TenantSchema::indexName('channel_webhook_routes', self::SESSION_INDEX_COLUMNS),
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_webhook_routes');
    }
};
