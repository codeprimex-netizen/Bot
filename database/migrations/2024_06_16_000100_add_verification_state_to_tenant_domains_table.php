<?php

declare(strict_types=1);

use App\Support\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The challenge and evidence half of a domain claim (Req 9.7 / A9).
 *
 * Task 5.1 created `tenant_domains` with `host` + `verified_at` and said, in its own
 * docblock, that *"issuing the challenge, checking the certificate … belong to task
 * 5.5, which owns the write path and may add its own columns for challenge state"*.
 * This is that state. `verified_at` keeps its meaning exactly: **both** halves of
 * Req 9.7 held at that instant. Nothing here can make a domain usable — only
 * `verified_at` does that.
 *
 * ## Every column, and why it is not derivable
 *
 * | Column | Why it must be stored |
 * |---|---|
 * | `challenge_method` | the tenant's *choice* of proof (DNS TXT vs ACME-style HTTP file). The check that runs later must be the check the tenant was given instructions for, so the choice outlives the request that made it. |
 * | `challenge_token` | 43 random characters minted at issuance. Unguessable and unreproducible by construction — if it were derivable from the row, publishing the record would prove nothing. |
 * | `challenge_issued_at` | when the tenant received its instructions. `updated_at` cannot answer this: every failed check touches it. |
 * | `challenge_expires_at` | the deadline the tenant was *promised*, and null once the challenge becomes the standing proof (see below). Derivable from `challenge_issued_at` + config only until an operator changes the config, which would then silently extend or kill challenges already in flight. |
 * | `last_checked_at` | when a check last ran, pass or fail. Drives the re-check sweep's "stalest first" ordering and distinguishes "never checked" from "checked and failing" — a distinction `verified_at IS NULL` cannot make. |
 * | `last_failure_reason` | the `App\Enums\DomainVerificationFailure` code from that check. The outcome of a network probe that has already happened: not recomputable without repeating the probe, which is the expensive thing this column exists to avoid. |
 * | `tls_expires_at` | `notAfter` of the certificate seen at the last check. Lives in the remote certificate, so reading it costs a TLS handshake. Stored so the sweep can prioritise domains about to break, and the panel can warn *before* they do. |
 *
 * Deliberately **not** added: a `verified` boolean (`verified_at` already says when),
 * a challenge-attempt counter (a failed check is not rate-limiting state — the probe
 * guard owns that), and free text of any kind. `last_failure_reason` is an enum value
 * because a resolver or HTTP error string can quote a proxy URL or a request header,
 * and this column is rendered on a tenant-facing screen.
 *
 * ## Why the challenge survives a successful verification
 *
 * A row starts as a claim with no challenge (hence nullable) and gets one on issuance.
 * On success the *deadline* is dropped (`challenge_expires_at` → null) but the method
 * and token stay: they become the **standing proof**, which is what makes Req 9.7
 * re-checkable at all. A re-check re-runs the same challenge, so it re-proves ownership
 * rather than merely confirming that some certificate exists — the case that matters is
 * a domain that changes hands and is pointed back at the platform, where the TLS half
 * would still pass while the name is no longer the tenant's.
 *
 * Keeping the token is not keeping a secret: for the DNS method the tenant published it
 * in a public zone, and for the HTTP method it is served over plain HTTP on the tenant's
 * own host. Its only power is to prove control of that one host, which is exactly what
 * re-validation needs to keep asking.
 *
 * An expiry therefore only ever bounds the *initial grant*: an abandoned claim cannot be
 * verified a year later off a token nobody remembers publishing.
 *
 * ## The one new index
 *
 * `idx(verified_at, last_checked_at)` — the re-check sweep's whole query: verified rows,
 * stalest first, limited to a batch. Leading with `verified_at` because the sweep only
 * ever re-checks *verified* domains (an unverified claim is re-checked when its tenant
 * asks, not on the platform's clock). The existing `idx(tenant_id, verified_at)` cannot
 * serve it: the sweep is cross-tenant and names no tenant, which is the same reason
 * this table is not `BelongsToTenant` (see the creating migration).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_domains', function (Blueprint $table): void {
            // `App\Enums\DomainChallengeMethod` value: dns_txt | http_file.
            $table->string('challenge_method', 16)->nullable()->after('host');

            // The secret the tenant publishes. 64 leaves room above the 43 characters
            // `DomainVerifier::mintToken()` issues without inviting a longer one.
            $table->string('challenge_token', 64)->nullable()->after('challenge_method');

            $table->timestamp('challenge_issued_at')->nullable()->after('challenge_token');
            $table->timestamp('challenge_expires_at')->nullable()->after('challenge_issued_at');

            // Evidence from the most recent check. Both null on a claim never checked.
            $table->timestamp('last_checked_at')->nullable()->after('verified_at');

            // `App\Enums\DomainVerificationFailure` value; null when the last check passed.
            $table->string('last_failure_reason', 40)->nullable()->after('last_checked_at');

            // notAfter of the certificate observed at the last successful TLS check.
            $table->timestamp('tls_expires_at')->nullable()->after('last_failure_reason');

            $table->index(
                ['verified_at', 'last_checked_at'],
                TenantSchema::indexName('tenant_domains', ['verified_at', 'last_checked_at']),
            );
        });
    }

    public function down(): void
    {
        Schema::table('tenant_domains', function (Blueprint $table): void {
            $table->dropIndex(TenantSchema::indexName('tenant_domains', ['verified_at', 'last_checked_at']));

            $table->dropColumn([
                'challenge_method',
                'challenge_token',
                'challenge_issued_at',
                'challenge_expires_at',
                'last_checked_at',
                'last_failure_reason',
                'tls_expires_at',
            ]);
        });
    }
};
