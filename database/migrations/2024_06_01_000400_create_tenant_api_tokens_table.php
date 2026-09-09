<?php

declare(strict_types=1);

use App\Support\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * API keys through which a machine caller acts as exactly one tenant.
 *
 * Only the SHA-256 of the secret is stored, so a database leak yields no usable
 * key. The plaintext (`{id}|{secret}`) is shown once at issue time.
 *
 * When the public API adopts Sanctum this table stays valid — `TenantTokenRepository`
 * is rebound to Sanctum's `personal_access_tokens` and this one is migrated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_api_tokens', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // "This tenant's live keys" — the query the panel's API-keys screen runs.
            TenantSchema::tenantId($table, 'revoked_at', 'tenant_api_tokens_tenant_active_index');

            $table->string('name');

            // SHA-256 hex of the secret half of the plaintext token.
            $table->char('token', 64)->unique();

            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_api_tokens');
    }
};
