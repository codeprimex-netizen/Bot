<?php

declare(strict_types=1);

use App\Enums\ChannelCredentialStatus;
use App\Models\ChannelCredential;
use App\Support\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant, per-mode credentials for the official channel modes (Req 8.5 / A8;
 * Req 32.3 / NFR3; design § Channel Mode 2.7 and § Data Models `channel_credentials`).
 *
 * The column list, uniqueness, and index are design.md's verbatim. What follows is why
 * each one is shaped the way it is, and the one place this table deviates.
 *
 * ## Two config columns, because they have different threat models
 *
 * | Column | Holds | At rest |
 * |---|---|---|
 * | `config` | WABA id, phone-number id, endpoint, sender, api version | plain JSON — identifiers the provider itself puts in URLs |
 * | `secret_config` | access token, verify token, api key, webhook secret | one envelope-encrypted blob (`App\Casts\EncryptedArray`) |
 *
 * Splitting them is what makes "secret-redacted in UI/logs" implementable at all: a panel
 * that must show *which* WABA a session talks to, and an audit trail that must record that
 * credentials changed, can both read `config` freely, while `secret_config` has exactly one
 * sanctioned reader (`ChannelCredential::secrets()`, task 6.4's `ChannelCredentialStore`).
 * A single mixed JSON column would have forced every reader to filter by key name, and a
 * reader that forgot would leak a token.
 *
 * `secret_config` is a `text` column rather than a `blob` (design says "blob") for the
 * reason `encryption_keys.wrapped_dek` and `signing_secrets.sealed_secret` are text:
 * `FieldCipher`'s envelope is a printable `wac1.FIELD.{version}.…` string, so a binary
 * column would buy nothing and cost every dump, diff and console read its readability.
 * The bag is encrypted **whole**, which also hides the field *names* — so the ciphertext
 * does not advertise which provider a tenant uses — and lets a new provider field be added
 * without a migration.
 *
 * ## `uniq(tenant_id, mode, provider, label)`, and the NULL problem it has
 *
 * The intent is "one credential set per tenant per mode per provider per label", so a
 * tenant can keep two Twilio credential sets (`live`, `sandbox`) and cannot create two
 * rows that mean the same thing. But `provider` is legitimately NULL for the three modes
 * that have no provider (`ChannelMode::usesProvider()` is true only for `BSP_GATEWAY`),
 * and in MySQL — as in SQLite — **NULLs are distinct in a unique index**: `(t, CLOUD_API,
 * NULL, 'default')` could be inserted twice, and the constraint would silently not apply
 * to exactly the modes most tenants use.
 *
 * So the index is declared over a derived, never-null `provider_slot`, which
 * `ChannelCredential` keeps in lockstep with `provider` in a `saving` hook — the same
 * device `signing_secrets.active_flag` uses, and for the same reason: the database can only
 * enforce an invariant it can express. `provider` stays exactly as design.md declares it
 * (nullable, `BspProvider`-cast, the column code reads); `provider_slot` is storage
 * plumbing nobody queries by hand.
 *
 * ## Indexes
 *
 * - `idx(tenant_id, mode)` — from `TenantSchema::tenantId()`. Every read is already
 *   constrained by `tenant_id`, and the next predicate is always the mode: that *is* the
 *   `ChannelCredentialStore::for(Tenant, ChannelMode)` lookup (task 6.4), which is on the
 *   path of every official-mode send and every inbound webhook parse.
 * - `unique(tenant_id, mode, provider_slot, label)` — the constraint above.
 *
 * Deliberately **not** indexed: `status`. A tenant has a handful of credential rows, so
 * "the active ones" is a filter on a set the tenant_id index already narrowed to single
 * digits.
 *
 * ## Lifecycle
 *
 * `ON DELETE CASCADE` from `tenants` (via the helper): offboarding a tenant destroys its
 * credentials, which is the only correct behaviour for secret material. No soft deletes,
 * for the same reason — a deleted credential row must actually stop existing, and a
 * `deleted_at` row still holds a live provider token.
 *
 * Writing and rotating rows belongs to task 6.4 (`ChannelCredentialStore::put`) and task
 * 7.6 (validate-before-activate, retain the previous working row on failure). This
 * migration creates the table; `App\Models\ChannelCredential` owns its shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_credentials', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // idx(tenant_id, mode): the ChannelCredentialStore::for(tenant, mode) lookup.
            TenantSchema::tenantId($table, 'mode');

            // `App\Enums\ChannelMode` value.
            $table->string('mode', 16);

            // `App\Enums\BspProvider` value; NULL for every mode but BSP_GATEWAY.
            $table->string('provider', 16)->nullable();

            // Derived from `provider` on save so the unique index below can be enforced
            // for provider-less modes too. See the docblock.
            $table->string('provider_slot', 16)->default(ChannelCredential::NO_PROVIDER);

            // The tenant's own name for this credential set ("live", "sandbox"). Part of
            // the uniqueness, so a tenant may keep more than one per provider.
            $table->string('label', 120);

            // Non-secret provider identifiers: waba_id, phone_number_id, endpoint, sender,
            // api_version. Readable by the panel and quotable in an audit record.
            $table->json('config')->nullable();

            // The secret bag, envelope-encrypted whole by `App\Casts\EncryptedArray`.
            // NEVER returned raw to UI or logs; decrypted in-request only.
            $table->text('secret_config')->nullable();

            $table->string('status', 16)->default(ChannelCredentialStatus::default()->value);

            // When the driver last confirmed these credentials work (task 7.6). NULL means
            // never validated, which is distinguishable from "validated long ago" — the
            // distinction a `verified` boolean could not make.
            $table->timestamp('verified_at')->nullable();

            $table->timestamps();

            $table->unique(
                ['tenant_id', 'mode', 'provider_slot', 'label'],
                'channel_credentials_tenant_mode_provider_label_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_credentials');
    }
};
