<?php

declare(strict_types=1);

use App\Support\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant custom domains (Req 9.3, 9.7 / A9; design § Base URL §U.2: *"a tenant
 * may map a `custom_domain` (verified via DNS/ACME); when present, that tenant's
 * absolute/subdomain URLs and webhook callbacks use the custom domain"*).
 *
 * A row is a **claim**; `verified_at` is what turns it into an origin the platform
 * will emit links to.
 *
 * ## `verified_at` is the whole security boundary
 *
 * Only a verified row is ever read for URL generation (`TenantDomain::canonicalHost()`
 * returns null without it, and `canonicalHostFor()` filters on it in SQL). An
 * unverified row that counted would let a tenant type in a domain it does not own and
 * have the platform emit that tenant's webhook callbacks, export links, and payment
 * redirects to a host somebody else controls — the attacker then receives another
 * party's traffic, signed by us. So resolution fails **down** the precedence chain to
 * the platform base and never **up** to an unverified claim.
 *
 * `verified_at` is set only when both halves of Req 9.7 hold: the ownership challenge
 * succeeded **and** a TLS certificate covering the host was confirmed. Issuing the
 * challenge, checking the certificate, rejecting reserved names, and routing inbound
 * requests for these hosts belong to task 5.5, which owns the write path and may add
 * its own columns for challenge state. This migration and the read path deliberately
 * do not anticipate their shape.
 *
 * ## Why `unique(host)` is global rather than per tenant
 *
 * One domain has one owner. A per-tenant uniqueness constraint would let two tenants
 * each hold `chat.acme.example`, and whichever verified first would have the other's
 * callbacks pointed at it — a hijack the database can simply forbid. The uniqueness is
 * only meaningful because `App\Models\TenantDomain` normalises every write through
 * `CanonicalBase::host()`: without one spelling per host, `ACME.example.com.` and
 * `acme.example.com` would be two rows for one name.
 *
 * Multiple rows *per tenant* are allowed, which is what makes a domain change
 * possible: the new host can be added and verified while the old one still serves.
 * `canonicalHostFor()` picks the most recently verified row (host as tiebreak) so the
 * choice is deterministic and a cutover is an ordinary verification.
 *
 * ## Tenancy: `tenant_id`, deliberately **not** `BelongsToTenant`
 *
 * This is a resolution-tier table, like `tenant_users`: it is read to answer *"which
 * tenant does this host belong to?"*, before any tenant is bound, so it cannot also be
 * filtered by the answer. Scoping it would make resolution circular and force a
 * `withoutTenantScope()` bypass onto the path that establishes the scope. It would
 * also hide the very rows the global `unique(host)` exists to expose — a tenant
 * checking whether a host is free must see another tenant's claim on it, or the
 * "already in use" refusal of Req 9.7 could not be explained.
 *
 * Isolation comes from the access path instead: every read names its tenant
 * explicitly (`TenantDomain::canonicalHostFor($tenantId)`), and
 * `TenantOwnedModelsGuardTest::tenantScopeExemptions()` records the decision so it is
 * reviewed rather than omitted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_domains', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // idx(tenant_id, verified_at) — exactly the read the base-URL resolver
            // issues: this tenant's rows, verified ones only, newest first.
            TenantSchema::tenantId($table, 'verified_at');

            // 253 is the maximum length of a DNS name; a longer value cannot resolve,
            // so the column refuses what the resolver would have to reject anyway.
            $table->string('host', 253)->unique();

            // Null until ownership *and* TLS are confirmed (Req 9.7). Never a boolean:
            // when a domain became usable is evidence an operator needs, and a signed
            // link emitted before a cutover is explained by this timestamp.
            $table->timestamp('verified_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_domains');
    }
};
