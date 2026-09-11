# STRIDE trust boundaries — enforcement register

> **Status: executable.** `tests/Feature/Security/StrideBoundaryCoverageTest.php` parses this
> file. Every boundary in `design.md`'s STRIDE table must appear below; every clause marked
> `enforced` must name code that exists and at least one test file that exists; every clause
> marked `unenforced` must name the task that owns it and must claim **no** test. A row you
> cannot back therefore fails the build rather than reading as coverage.

Spec of record: `.kiro/specs/whatsapp-chatbot-platform/design.md` § *Security & Compliance
Hardening (Deep Dive)* → *STRIDE threat model (main trust boundaries)*, plus Req 32.1,
32.6 / NFR3. This file is the same table with two columns added — *where the mitigation is
enforced* and *what proves it* — because a mitigation described in prose and a mitigation
that runs are different things, and only one of them stops an attacker.

## How to read the Status column

| Status | Means |
|---|---|
| `enforced` | the named code runs at this boundary today and the named test fails if it stops |
| `unenforced — task N.N` | the boundary (or this clause of it) does not exist yet; task N.N owns it. **No coverage is implied.** |
| `unenforced — no owning task` | a gap in the plan, not just in the code — see [Gaps](#gaps) |
| `infrastructure` | enforced outside the PHP application (nginx/WAF/database grants); not testable from the suite |

"Primitive" in the *Enforced at* column means the check is complete and proven, but the
route or job that calls it belongs to a later task — the clause below it says which. A
primitive is not a mitigation until something calls it, which is exactly why those clauses
are listed separately instead of one row claiming the whole boundary.

## Clause coverage

| Boundary | Mitigation clause | Enforced at | Proven by | Status |
|---|---|---|---|---|
| Bridge → webhook | HMAC verify is constant-time and **total** — an unknown scope, an unopenable secret, and a garbage header all return `false`, so an inbound signature cannot choose between a 403 and a 503 | `App\Services\Security\SigningSecretStore`, `App\Services\Security\DatabaseSigningSecretStore` | `tests/Feature/Security/SigningSecretRotationTest.php` | enforced |
| Bridge → webhook | Per-session shared secret: the scope string (`bridge:session:{id}`) is bound into the seal as AAD, so a secret row copied into another scope does not open | `App\Services\Security\DatabaseSigningSecretStore`, `App\Models\SigningSecret` | `tests/Feature/Security/SigningSecretRotationTest.php` | enforced |
| Bridge → webhook | Rotation cannot create a verification outage (dual-secret overlap, closed by the clock and not by a purge job) | `App\Services\Security\SigningSecretRotation` | `tests/Feature/Security/SigningSecretRotationTest.php` | enforced |
| Bridge → webhook | Reject-on-mismatch at the inbound endpoint: the controller that calls `verify()` and returns 4xx | `App\Services\Security\SigningSecretStore` (primitive) | — | unenforced — task 8.3 |
| Gateway → webhook | Signature verify on the gateway scope (`gateway:{slug}`), same total-boolean primitive | `App\Services\Security\SigningSecretStore` | `tests/Feature/Security/SigningSecretRotationTest.php` | enforced |
| Gateway → webhook | Exactly-once on `gateway_event_id`: a replayed event is recognised and its recorded result replayed, never re-applied | `App\Services\Reliability\IdempotencyStore`, `App\Services\Reliability\DatabaseIdempotencyStore` | `tests/Feature/Reliability/IdempotencyStoreTest.php`, `tests/Feature/Reliability/IdempotencyKeyTest.php` | enforced |
| Gateway → webhook | Side effects survive a crash between "processed" and "notified" (transactional outbox) | `App\Services\Reliability\Outbox` | `tests/Feature/Reliability/OutboxTest.php` | enforced |
| Gateway → webhook | Timestamp/freshness window: a correctly signed payload replayed days later is refused on age | — | — | unenforced — task 10.7 |
| Gateway → webhook | The gateway webhook controller that composes verify + idempotency + outbox | `App\Services\Reliability\IdempotencyStore` (primitive) | — | unenforced — task 10.7 |
| Panels (User/Admin) | RBAC role matrix, deny by default: an exhaustive `match` per permission, no wildcard, no config override, and an unknown role holds nothing | `App\Enums\TenantPermission`, `App\Services\Rbac\RbacService`, `App\Services\Rbac\DatabaseRbacService` | `tests/Unit/Enums/TenantPermissionTest.php`, `tests/Feature/Rbac/RbacServiceTest.php` | enforced |
| Panels (User/Admin) | The permission check is declared on the route, not remembered at the call site; an unaccepted invitation grants nothing, and a missing identity denies | `App\Http\Middleware\EnsurePermission` | `tests/Feature/Rbac/EnsurePermissionTest.php` | enforced |
| Panels (User/Admin) | Global `TenantScope` fail-closed: a tenant-owned query with no tenant bound refuses to run rather than dropping the constraint | `App\Models\Scopes\TenantScope`, `App\Models\Concerns\BelongsToTenant` | `tests/Feature/Tenancy/BelongsToTenantTest.php`, `tests/Feature/Tenancy/TenantOwnedModelsGuardTest.php` | enforced |
| Panels (User/Admin) | `CrossTenantAccessException` (403) on the five seams a query scope structurally cannot cover (instance writes, relations, explicit `tenant_id`, find-by-id, out-of-scope hydration) | `App\Services\Tenancy\TenantOwnershipGuard`, `App\Exceptions\Tenancy\CrossTenantAccessException` | `tests/Feature/Tenancy/CrossTenantAccessTest.php` | enforced |
| Panels (User/Admin) | Platform mode is not a tenant role: the audited bypass is refused by RBAC instead of permitting everything | `App\Services\Rbac\DatabaseRbacService`, `App\Exceptions\Security\PermissionDeniedException` | `tests/Feature/Rbac/RbacServiceTest.php` | enforced |
| Panels (User/Admin) | Platform-admin guard, admin IP allowlist, and admin throttle | — | — | unenforced — task 30.1 |
| Public API | Token verification: hash-matched by unique index (lookup time independent of the guess), revoked and expired refused, one verification per request | `App\Services\Tenancy\DatabaseTenantTokenRepository`, `App\Models\TenantApiToken` | `tests/Feature/Tenancy/ResolveTenantTest.php` | enforced |
| Public API | Per-token scope, deny by default: a key does only what it was issued for, an empty scope list permits nothing, and there is no wildcard | `App\Models\TenantApiToken`, `App\Services\Tenancy\ApiTokenIdentity`, `App\Services\Rbac\RbacService`, `App\Http\Middleware\EnsurePermission` | `tests/Feature/Rbac/ApiTokenScopeTest.php` | enforced |
| Public API | A key acts as exactly one tenant: a verified key whose tenant is not the bound one is refused, and an API-authenticated request never falls back to the session user | `App\Services\Rbac\DatabaseRbacService`, `App\Http\Middleware\EnsurePermission` | `tests/Feature/Rbac/ApiTokenScopeTest.php` | enforced |
| Public API | Tenant scope applies to API callers exactly as to panel callers | `App\Models\Scopes\TenantScope` | `tests/Feature/Tenancy/CrossTenantAccessTest.php` | enforced |
| Public API | Per-token HTTP rate limits | — | — | unenforced — no owning task |
| Public API | WAF / nginx request limits | nginx + WAF (deployment) | — | infrastructure |
| LLM egress | Reversible redaction before egress, with the token map living for one request and no PII in the returned reply path (Property 15) | `App\Services\Security\Pii\PiiRedactor`, `App\Services\Security\Pii\TokenizingPiiRedactor` | `tests/Unit/Services/Security/PiiRedactorTest.php`, `tests/Feature/Security/PiiRedactorLifetimeTest.php` | enforced |
| LLM egress | No body logging anywhere: phone numbers masked and bodies hashed on **every** channel, including ones built at runtime — plumbing, not a convention | `App\Logging\RedactingLogManager`, `App\Logging\PiiRedactionProcessor`, `App\Support\Pii\LogPiiScrubber` | `tests/Feature/Security/PiiLogRedactionTest.php` | enforced |
| LLM egress | The abuse-trail escalation stays readable after redaction: reason codes, vector, action, signals, hashes and counters survive; content does not | `App\Services\Abuse\DatabaseAbuseRecorder`, `App\Support\Pii\PiiKeyRules` | `tests/Feature/Abuse/AbuseEscalationRedactionTest.php` | enforced |
| LLM egress | Prompt-injection input/output inspection with the verdict recorded before the caller sees it | `App\Services\Abuse\Guardrail`, `App\Services\Abuse\LayeredGuardrail` | `tests/Feature/Abuse/GuardrailInputTest.php`, `tests/Feature/Abuse/GuardrailOutputTest.php` | enforced |
| LLM egress | Tenant content leaves only when the plan includes AI | `App\Enums\PlanFeature`, `App\Http\Middleware\EnsurePlanFeature` | `tests/Feature/Billing/PlanFeatureGateTest.php` | enforced |
| LLM egress | Redaction and guardrails wired into the actual LLM stage (there is no LLM provider yet) | `App\Services\Security\Pii\PiiRedactor` (primitive) | — | unenforced — task 15.2 |
| Vector store | A query without a tenant payload filter is **unrepresentable**: the store's methods take a `VectorFilter`, which has a private constructor, cannot be built without a tenant, refuses a foreign `tenant_id`, and writes `tenant_id` last so no caller input can shadow it | `App\Services\Chatbot\Rag\VectorFilter`, `App\Services\Chatbot\Rag\VectorStore` | `tests/Feature/Chatbot/VectorFilterTest.php` | enforced |
| Vector store | Per-tenant namespaces derived in one place, so two drivers cannot disagree about where a tenant's vectors live | `App\Services\Chatbot\Rag\VectorFilter` | `tests/Feature/Chatbot/VectorFilterTest.php` | enforced |
| Vector store | Drivers and the retriever that pass the filter to a real index | `App\Services\Chatbot\Rag\VectorStore` (contract) | — | unenforced — task 13.2 |
| Audit log | Hash chain per tenant: `row_hash = H(prev_hash ‖ canonical(payload))`, verified end to end, and a mutated row is detected (Property 17) | `App\Services\Audit\AuditService`, `App\Services\Audit\HashChainAuditService` | `tests/Feature/Audit/AuditChainIntegrityTest.php`, `tests/Feature/Audit/AuditServiceTest.php` | enforced |
| Audit log | Append-only in four layers: the model refuses writes, the builder refuses mass writes, database triggers refuse raw SQL, and production revokes UPDATE/DELETE from the app role | `App\Models\Concerns\AppendOnly`, `App\Models\AuditLog`, `App\Support\Database\AppendOnlyTable` | `tests/Feature/Audit/AuditLogAppendOnlyTest.php` | enforced |
| Audit log | An audit row is evidence of a decision, not a copy of the data: payloads are normalized, bounded, and PII-redacted | `App\Support\Audit\AuditPayloadRedactor`, `App\Support\Audit\AuditPayloadNormalizer` | `tests/Feature/Audit/AuditPayloadPrivacyTest.php` | enforced |
| Audit log | Concurrent appends cannot fork the chain (`unique(chain_key, sequence)` behind a lock) | `App\Services\Audit\HashChainAuditService` | `tests/Feature/Audit/AuditChainConcurrencyTest.php` | enforced |
| Audit log | The production `REVOKE UPDATE, DELETE` grants themselves | `App\Support\Database\AppendOnlyTable` (statements) | `tests/Feature/Audit/AuditLogAppendOnlyTest.php` | infrastructure |
| Queue/workers | Fair scheduling: a noisy tenant cannot starve others — deficit round-robin over per-tenant lanes, with the no-starvation property asserted over generated workloads | `App\Services\Dispatch\FairScheduler`, `App\Services\Dispatch\DeficitRoundRobinScheduler` | `tests/Feature/Dispatch/FairSchedulerTest.php`, `tests/Feature/Dispatch/FairSchedulingPropertyTest.php` | enforced |
| Queue/workers | Per-tenant caps: a suspended tenant and an exhausted quota are refused *before* work is dispatched, and a refusal is recorded rather than silent | `App\Services\Dispatch\DispatchEligibility`, `App\Services\Dispatch\Eligibility\CompositeDispatchEligibility`, `App\Services\Tenancy\QuotaGuard` | `tests/Feature/Dispatch/DispatchEligibilityTest.php`, `tests/Feature/Dispatch/QuotaDispatchEligibilityTest.php` | enforced |
| Queue/workers | Breakers: a failing dependency is shed instead of consuming every worker | `App\Services\Reliability\CircuitBreaker` | `tests/Feature/Reliability/CircuitBreakerTest.php`, `tests/Feature/Reliability/CircuitBreakerSafetyPropertyTest.php` | enforced |
| Queue/workers | Per-lane worker processes that consume through the scheduler with prefetch=1 and backpressure shedding | `App\Services\Dispatch\FairScheduler` (primitive) | — | unenforced — task 36.4 |

## Gaps

Findings from wiring this register, recorded here because a gap that lives only in a
commit message is a gap nobody closes.

1. **Per-token HTTP rate limits have no owning task.** design.md's *Public API* row claims
   "Sanctum tokens, per-token rate limits, tenant scope, WAF/nginx limits". Three of the
   four are owned: tokens and tenant scope are enforced above, WAF/nginx is deployment.
   Per-token rate limiting is not — `tasks.md` has throttles for panel login (24.2) and the
   admin panel (30.1, 30.7) and nothing for `/api/v1`. `TenantApiToken` now carries a stable
   `tokenId` in `ApiTokenIdentity`, which is the key such a limiter would use, so the
   primitive it needs exists. It needs a task.
2. **design.md's `VectorStore` sketch types the tenant as `int`.** Tenants are ULID strings
   in this build (`tenants.id`), so the sketch cannot be implemented as written. The
   deviation and its second reason — a bare tenant id next to a free `array $filter` puts the
   security-critical translation in the implementation, where forgetting it is silent — are
   documented on `App\Services\Chatbot\Rag\VectorStore`.
3. **The gateway freshness window is a real hole, not just unwired.** Signature + idempotency
   make a replay *harmless* (it replays the recorded result). They do not make it *refused*:
   a captured, correctly signed payload stays acceptable for ever, which matters for the
   idempotency key's retention window and for any endpoint that is idempotent-by-key rather
   than idempotent-by-nature. Task 10.7 should add the window, not just the controller.

## Maintaining this file

- Adding a boundary to design.md's STRIDE table **fails the build** until it is listed here.
- Wiring a primitive into its route or worker: move the clause's status from
  `unenforced — task N.N` to `enforced` and name the test that proves it. Do not delete the
  clause — the point of the split is that a reader can see which half is done.
- Never mark a clause `enforced` without a test file. The guard test checks that the file
  exists, and a reviewer should check that it asserts the clause.
