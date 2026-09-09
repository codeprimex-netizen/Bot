<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The scopes an API key carries (Req 32.1 / NFR3; design.md § STRIDE row "Public API" —
 * *"Sanctum tokens, per-token rate limits, tenant scope"*).
 *
 * Task 0.4 created `tenant_api_tokens` with **no** scope column: a key identified its
 * tenant and that was the whole of its authority, so any key could do anything the
 * tenant could. This adds the second half — *which* of the tenant's powers this key was
 * issued for — as a list of `App\Enums\TenantPermission` values, checked by
 * `App\Services\Rbac\RbacService::tokenAllows()`.
 *
 * ## Why nullable, and why that is still deny-by-default
 *
 * MySQL forbids a `DEFAULT` on a `JSON` column, so a NOT NULL column would have to be
 * backfilled in the same migration — and the only honest backfill for a key issued
 * before scopes existed is "no scopes", which is what NULL already means here.
 * `TenantApiToken::scopes()` maps both NULL and `[]` to the empty list, and an empty
 * list permits nothing. So every key that exists today keeps working as a *tenant
 * identifier* (nothing about resolution changes) and is refused by every scope gate
 * until somebody re-issues it with an explicit list.
 *
 * An unrecognised entry in a stored list is dropped rather than raised: a scope written
 * by a newer release and read by an older one must not make the credential unusable,
 * and dropping it denies.
 *
 * No index. Scopes are only ever read for the single row that authentication already
 * found by its unique token hash, never searched by.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_api_tokens', function (Blueprint $table): void {
            // A JSON list of TenantPermission values, e.g. ["messages.send","contacts.read"].
            // NULL = issued before scopes existed = no authority (see the docblock).
            $table->json('scopes')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_api_tokens', function (Blueprint $table): void {
            $table->dropColumn('scopes');
        });
    }
};
