# Implementation Plan — WhatsApp Chatbot Platform (Multi-Tenant SaaS)

## Overview

This is a multi-tenant SaaS build on the **PHP 8.3 / Laravel 12 / MySQL 8 / Livewire 3 / Supervisor** stack (with the **Node + Baileys "WA Bridge"** as one channel driver), wrapping and extending the existing single-tenant `whatsapp-auto-messenger` engine. The plan is organized into ordered, incremental phases: each phase is testable before the phases that depend on it, and every task builds on prior tasks and ends by wiring the new code into the running system. Every requirement (1–36 / A1–NFR7), design subsystem, and Correctness Property (1–28) is traced to at least one task, giving **100% requirement + correctness-property coverage** (see the **Task → Requirement / Property Coverage Summary** at the end).

This plan converts the full `design.md` into an ordered, test-driven series of coding tasks that a code-generation LLM can execute incrementally. Each phase is testable before the phases that depend on it, and every task builds on prior tasks and ends by wiring the new code into the running system — no hanging or orphaned code. The platform is built on **PHP 8.3 / Laravel 12 / MySQL 8 / Livewire 3 / Supervisor**, wrapping the existing single-tenant `whatsapp-auto-messenger` engine, with the **Node + Baileys "WA Bridge"** as one channel driver among several.

This is a **regeneration** for 100% alignment with the upgraded design: it keeps and expands the original phases and adds the net-new subsystems — **Channel Mode** (pluggable messaging backends), **Base URL**, the deepened **AI/LLM engine** (RAG/memory/guardrails/routing/resilience), fully itemized **Group** and **Channel** management, the full **User Panel (28 features)** and **Admin Panel (28 features)**, and all advanced non-functional areas (scalability, reliability, security, observability, data architecture, tenant tiers). Every requirement (1–36 / A1–NFR7), every design subsystem, and every Correctness Property (1–28) is traced to at least one task; see the **Task → Requirement / Property Coverage Summary** at the end.

> **Conventions.**
> - PBT = property-based test (Pest + generator helper). Each PBT sub-task names the Correctness **Property N** it validates and the requirement clause it checks.
> - Sub-tasks postfixed with `*` are **optional** test tasks (unit / property / integration / feature) and MAY be skipped for a faster MVP; core implementation sub-tasks are never optional and MUST be implemented.
> - `Fake*` doubles (`FakeBridgeClient`, `FakeChannelDriver`, `FakeLlmProvider`, `FakeGateway`, `FakeVectorStore`, `FakeEmbedder`, `FakeStt`, `FakeKms`) are bound **only** in the testing container — never in production (Property 28 / Req 36).
> - Every requirement reference uses the form `_(Req N.x / BlockID)_` where `BlockID` is the retained design tag (A1–NFR7).
> - All work is tenant-scoped, plan-gated, quota-metered, capability-aware, error-handled with a typed `AppException`, and observable, per the Definition of Done (Req 36 / NFR7).

---

## Tasks

## Phase 0 — Tenancy Foundation

- [ ] 0. Establish row-level multi-tenancy, context resolution, and per-tenant storage
  - [x] 0.1 Create `tenants`, `tenant_users`, `tenant_usage` migrations and Eloquent models with backed enums (`TenantStatus`, `QuotaKind`)
    - Model relationships, unique constraints (`tenant_users(tenant_id,user_id)`, `tenant_usage(tenant_id,kind,period_key)`), status index
    - _(Req 1.1 / A1)_
  - [~] 0.2 Implement `TenantContext` (`current`/`set`/`actingAsPlatform`/`forget`) with per-request resolution by panel session, subdomain, and API key
    - _(Req 1.1, 1.5 / A1)_
  - [~] 0.3 Implement `BelongsToTenant` trait: global `TenantScope` + auto-fill `tenant_id` on `creating`; add the trait, `tenant_id` column, and `idx(tenant_id, ...)` to all 22 reused engine tables via migrations
    - _(Req 1.1, 1.2 / A1)_
  - [~] 0.4 Add `CrossTenantAccessException` (403) and a defense-in-depth ownership check on find-by-id / relation-load paths
    - _(Req 1.3 / A1)_
  - [~] 0.5 Namespace WhatsApp auth-state, exports, and media under `storage/tenants/{tenantId}/` with ULID filenames
    - _(Req 1.4 / A1)_
  - [ ]* 0.6 **PBT — Property 1 (Tenant isolation):** seed 2 tenants; assert every read path returns only rows where `tenant_id = t.id` and cross-tenant reads raise `CrossTenantAccessException`
    - **Validates: Requirements 1.1, 1.2, 1.3 / A1**

- [ ] 1. Tenant tiers, weighted-fair scheduling & lifecycle
  - [~] 1.1 Create `tenant_tiers` migration/model and implement `TierResolver` (`tierOf`/`laneWeight`/`connection`) for `SHARED`/`DEDICATED_WORKER`/`DEDICATED_DB` as a config flip (shard router via `connection`)
    - _(Req 1.6 / A1)_
  - [~] 1.2 Implement `TenantLifecycle::provision` — atomically create tenant record, wallet, default chatbot, per-tenant DEK, storage prefix, and seed plan
    - _(Req 1.8 / A1)_
  - [~] 1.3 Implement `TenantLifecycle::suspend` (block outbound, keep inbound log, panels read-only) and the state machine TRIAL→ACTIVE→SUSPENDED→CANCELLED transitions
    - _(Req 1.1 / A1)_
  - [~] 1.4 Implement a weighted-fair (deficit round-robin) dispatch scheduler keyed by `tenant_tiers.lane_weight`, skipping rate-capped tenants in the loop
    - _(Req 1.7 / A1; Req 30.2, 30.6 / NFR1)_
  - [ ]* 1.5 **PBT — Property 19 (Fair scheduling / noisy-neighbor bound):** random per-tenant backlogs; assert dispatched share is proportional to `lane_weight` within a bounded error and no tenant starves others
    - **Validates: Requirements 1.7 / A1, 30.2, 30.6 / NFR1**

---

## Phase 1 — Plans, Quota & Feature Gating

- [ ] 2. Plan gating and quota metering from a single place
  - [~] 2.1 Create `plans` migration/model (feature flags JSON, limits JSON per `QuotaKind`) with active/sort indexes and version-bump cache invalidation
    - _(Req 25.1 / D2)_
  - [~] 2.2 Implement `PlanGate::allows/authorize` + `FeatureNotInPlanException` (402/403)
    - _(Req 11.3 / B2; Req 22.2 / C5)_
  - [~] 2.3 Implement `QuotaGuard::verdict/consume/remaining` with atomic `Cache::lock("quota:{tenant}:{kind}")` updates on `tenant_usage`; monthly/daily period bucketing; consume-once keyed by idempotency key
    - _(Req 3.4, 3.5 / A3)_
  - [~] 2.4 Add `QuotaExceededException` (429, defer-until-reset) and a period-reset scheduled command that auto-resumes `QUOTA_PAUSED` work
    - _(Req 3.4 / A3; Req 20.3 / C3; Req 31.1 / NFR2)_
  - [ ]* 2.5 **PBT — Property 4 (Quota never negative / never double-counted):** random retry interleavings; assert `used` increases by exactly confirmed sends and never exceeds `limit`
    - **Validates: Requirements 3.5 / A3**
  - [ ]* 2.6 **PBT — Property 7 (Plan gating):** features not in a tenant's plan are inaccessible and their pipeline stages are skipped
    - **Validates: Requirements 11.3 / B2, 22.2 / C5**

---

## Phase 2 — Reliability & Idempotency Primitives

- [ ] 3. Circuit breaker, outbox, idempotency store, saga orchestrator, retry policy
  - [~] 3.1 Create `circuit_breakers`, `outbox`, `idempotency_keys`, `sagas`, `saga_steps` migrations/models with enums (`CircuitState`, `OutboxStatus`, `SagaStatus`)
    - _(Req 31.3, 31.4, 31.5 / NFR2)_
  - [~] 3.2 Implement `CircuitBreaker::call/state/trip` (Algorithm 7): `(scope,name)` scoped + persisted + cached; open after ≥5 fails/30s or >50% err over 20; 30s open; 3 HALF_OPEN probes; never invoke while OPEN
    - _(Req 31.3 / NFR2; Req 13.13 / B4)_
  - [~] 3.3 Implement `Outbox::enqueue/relay` (Algorithm 6) + a relay worker: enqueue inside the state-change transaction; deliver with `dedup_key` header; `FOR UPDATE SKIP LOCKED`; backoff-with-jitter retries
    - _(Req 31.4 / NFR2; Req 25.2 / D2)_
  - [~] 3.4 Implement `IdempotencyStore::once(scope,key,op)` for generic side-effect dedup
    - _(Req 31.2 / NFR2)_
  - [~] 3.5 Implement `SagaOrchestrator::run` (Algorithm 8) with persisted steps, idempotent forward actions, and reverse-order idempotent compensations
    - _(Req 31.5 / NFR2; Req 15.5 / B6)_
  - [~] 3.6 Implement `RetryPolicy` (exponential base 250ms + full jitter, cap 30s) and the per-error-class retry/backoff matrix
    - _(Req 31.1 / NFR2)_
  - [ ]* 3.7 **PBT — Property 13 (Circuit-breaker safety):** random failure/success sequences; assert guarded op is never invoked while OPEN and transitions obey thresholds/probe limits
    - **Validates: Requirements 31.3 / NFR2, 13.13 / B4**
  - [ ]* 3.8 **PBT — Property 16 (Outbox exactly-once effect):** random crash/retry interleavings; assert downstream effect applied exactly once and no row lost
    - **Validates: Requirements 25.2 / D2, 31.4 / NFR2**
  - [ ]* 3.9 **PBT — Property 18 (Saga atomicity):** inject failure at each step index; assert all-complete or all-compensated with no orphaned side effect and idempotent re-run
    - **Validates: Requirements 15.5 / B6, 31.5 / NFR2**

---

## Phase 3 — Security, Encryption & Audit Foundation

- [ ] 4. Envelope encryption, KMS, hash-chained audit, PII redaction
  - [~] 4.1 Create `encryption_keys` migration/model and implement `FieldCipher` (per-tenant KMS-wrapped DEK; `encrypt`/`decrypt`/`rotate`; fail-closed `KeyUnavailableException`, no plaintext fallback)
    - _(Req 32.5 / NFR3)_
  - [~] 4.2 Implement a `KmsClient` interface + real KMS/Vault adapter and `FakeKms` (test only); scheduled master-key + per-tenant DEK rotation and dual-secret HMAC rotation
    - _(Req 32.6 / NFR3)_
  - [~] 4.3 Create append-only `audit_logs` (revoke UPDATE/DELETE grants for the app role) with hash-chaining `row_hash = H(prev_hash || canonical(payload))`; implement `AuditService::write/verify`
    - _(Req 24.2, 24.5 / D1; Req 34.1 / NFR5)_
  - [~] 4.4 Implement `PiiRedactor::redact/rehydrate` (mask phone/email/card-like + configurable tenant patterns; reversible in-request token map) and log redaction (phone-redacted, bodies hashed only)
    - _(Req 7.3 / A7; Req 32.2 / NFR3)_
  - [~] 4.5 Implement `Guardrail::inspectInput/inspectOutput` (instruction hierarchy, injection classifier, delimiter fencing, output validation) writing to `abuse_events`; anti-fraud signup velocity/OTP/device-IP heuristics and per-session kill-switch
    - _(Req 32.7 / NFR3; Req 13.8 / B4)_
  - [~] 4.6 Document + implement STRIDE per-boundary mitigations (webhook HMAC, gateway signature+idempotency, RBAC+scope, PII egress, vector filter, audit chain, fair scheduling)
    - _(Req 32.1, 32.6 / NFR3)_
  - [ ]* 4.7 **PBT — Property 17 (Audit-log hash-chain integrity):** build a chain, mutate a random row, assert verification fails
    - **Validates: Requirements 24.2, 24.5 / D1**
  - [ ]* 4.8 **PBT — Property 15 (PII never egresses raw):** seed messages with PII; assert redacted payload matches no PII regex and `rehydrate(redact(x)) == x`
    - **Validates: Requirements 32.2 / NFR3, 13.7 / B4**

---

## Phase 4 — Base URL / Deployment Domain

- [ ] 5. Canonical-host URL generation with no host-header injection
  - [~] 5.1 Implement `BaseUrl` (`platform`/`forTenant`) resolving precedence: per-tenant verified custom domain → `platform_settings['base_url']` → `config('app.url')`
    - _(Req 9.3 / A9)_
  - [~] 5.2 Implement `UrlBuilder` (`absolute`/`webhook`/`signed`/`tenantSubdomain`); build all URLs from the canonical base, never from `Host`/`X-Forwarded-Host`
    - _(Req 9.1 / A9)_
  - [~] 5.3 Implement signed/expiring URL generation binding the canonical host into the signature; reject expired, >3600s window, or host-mismatched signatures
    - _(Req 9.6 / A9)_
  - [~] 5.4 Configure Laravel `TrustHosts`/`TrustProxies` allowlist (platform apex + verified tenant subdomains + verified custom domains); reject non-allowlisted hosts
    - _(Req 9.4, 9.5 / A9)_
  - [~] 5.5 Implement subdomain routing (`tenant.{slug}.{apex}`) and verified custom-domain routing (DNS/ACME ownership challenge + TLS check; unverified excluded; collision/reserved-name rejection)
    - _(Req 9.3, 9.7 / A9)_
  - [~] 5.6 Wire per-mode + payment-gateway webhook callback registration through `UrlBuilder::webhook`, storing `route_key` in `channel_webhook_routes`
    - _(Req 9.2 / A9)_
  - [ ]* 5.7 **PBT — Property 27 (Canonical-host URL generation / no host-header injection):** vary the incoming Host header arbitrarily; assert generated URLs and signature host are unchanged and injection is impossible
    - **Validates: Requirements 9.1, 9.4 / A9, 32.2 / NFR3**

---

## Phase 5 — Channel Mode: Pluggable Messaging Backends

- [ ] 6. Channel Mode data model, driver interface & routing
  - [~] 6.1 Add `sessions_wa.channel_mode` column (enum, default `BAILEYS`, `idx(tenant_id, channel_mode)`); create `channel_credentials`, `cloud_api_templates`, `channel_webhook_routes`, `channel_send_log` migrations/models; add `ChannelMode`, `ChannelCapability`, `BspProvider` enums
    - _(Req 8.1 / A8; Req 2.6 / A2)_
  - [~] 6.2 Define `ChannelDriver` interface extending `BridgeClient` (`mode`/`supports`/`requiresAntiBan`/`send`/`parseWebhook`/`sendTemplate`/`register`/`healthCheck`)
    - _(Req 8.1 / A8; Req 33.1 / NFR4)_
  - [~] 6.3 Implement `ChannelRouter` (`driverFor`/`driverForMode`/`failoverChain`/`assertSupported`) — exactly-one-driver routing by `session.channel_mode`
    - _(Req 8.4 / A8)_
  - [~] 6.4 Implement `ChannelCredentialStore` (`for`/`put`) — per-tenant per-mode credentials, envelope-encrypted via `FieldCipher`, secret-redacted, decrypted in-request only
    - _(Req 8.5 / A8; Req 32.3 / NFR3)_
  - [ ]* 6.5 **PBT — Property 22 (Exactly-one-driver routing):** seed sessions across all modes; assert each message/webhook lands on the driver matching its session's `channel_mode`
    - **Validates: Requirements 8.3 / A8, 2.6 / A2, 10.1 / B1**
  - [ ]* 6.6 **PBT — Property 23 (Per-session mode isolation & credential scoping):** 2 tenants × multiple modes; assert credential resolution is disjoint by (tenant, mode) and secrets masked
    - **Validates: Requirements 8.4 / A8, 19.3 / C2, 32.3 / NFR3**

- [ ] 7. Concrete channel drivers
  - [~] 7.1 Implement `BaileysChannelDriver` wrapping the existing `HttpBridgeClient` (full capability set, `requiresAntiBan()=true`), unchanged behaviour for existing tenants
    - _(Req 8.1, 8.8 / A8)_
  - [~] 7.2 Implement `CloudApiChannelDriver` (Meta Cloud API: single/bulk/media/template/interactive, verify-token webhook, delivery receipts, WABA registration; no groups/channels/extraction → `ModeCapabilityException`)
    - _(Req 8.2, 8.4 / A8)_
  - [~] 7.3 Implement `OnPremiseChannelDriver` (legacy self-hosted; `requiresAntiBan()=true`; marked deprecated with documented `ON_PREMISE→CLOUD_API` migration path)
    - _(Req 8.9 / A8)_
  - [~] 7.4 Implement `BspGatewayChannelDriver` with per-provider adapters (Twilio/360dialog/Gupshup/Vonage/MessageBird/Infobip/WATI/Kaleyra) resolving a per-provider capability sub-matrix at runtime
    - _(Req 8.1, 8.2 / A8)_
  - [~] 7.5 Implement `FakeChannelDriver` (test-only, configurable capabilities, deterministic) bound only in the testing container
    - _(Req 36.1 / NFR7)_
  - [~] 7.6 Implement per-mode credential validation on save/rotate: validate with driver before activating; retain previous working creds on failure with `ChannelCredentialException`; keep platform on `BAILEYS` default when creds missing/invalid
    - _(Req 8.6, 8.13 / A8)_
  - [ ]* 7.7 **PBT — Property 21 (Capability gating; unsupported op never dispatched):** random (mode, capability) pairs; assert unsupported ⇒ `ModeCapabilityException` before any driver call and `send`/`manage` not invoked
    - **Validates: Requirements 8.1, 8.2 / A8, 6.5 / A6**

- [ ] 8. Mode-aware send gate, inbound routing, templates & failover
  - [~] 8.1 Extend the send pipeline into `channelSendGate` (Algorithm 9): resolve driver → capability gate → branch anti-ban (web-protocol) vs provider template/24h-window/rate rules (official)
    - _(Req 8.4, 8.8 / A8; Req 3.1 / A3)_
  - [~] 8.2 Implement the capability handshake: on create/mode-change, invoke `supports()` once and persist the authoritative capability set used for all subsequent gating
    - _(Req 8.2 / A8)_
  - [~] 8.3 Implement mode-aware inbound webhook routing: resolve `(tenant, session, driver)` via `channel_webhook_routes.route_key`, parse via that driver (Baileys HMAC / Meta verify-token+signature / BSP signature) into canonical `InboundEvent`
    - _(Req 8.4 / A8; Req 10.1 / B1)_
  - [~] 8.4 Implement official-mode template flow: sync/approval status against `cloud_api_templates`; block free-form outside the 24h window without an approved template with `TemplateRequiredException`
    - _(Req 8.12 / A8)_
  - [~] 8.5 Implement multi-mode failover: advance the ordered `failoverChain` on primary breaker OPEN or exhausted-retry; re-enable anti-ban when falling back to web-protocol; attempt each mode at most once; mark failed + audit exhaustion; audit each failover with source/target/reason
    - _(Req 8.10, 8.11 / A8)_
  - [~] 8.6 Implement mode-switch queue drain: stop admitting to old driver, drain/fail queued sends, re-run `supports()`, re-evaluate anti-ban vs official gate, audit old/new mode + drained/failed counts
    - _(Req 8.7 / A8)_
  - [ ]* 8.7 **PBT — Property 24 (Anti-ban applies iff web-protocol mode):** random sends across modes; assert anti-ban gate called exactly on `BAILEYS`/`ON_PREMISE` and provider template/window rule enforced on `CLOUD_API`/`BSP_GATEWAY`, with no bypass on web-protocol
    - **Validates: Requirements 8.8 / A8, 4.2 / A4**
  - [ ]* 8.8 **PBT — Property 26 (Channel/group op unsupported by mode fails typed, never crashes):** random (mode, op) pairs; assert unsupported ⇒ typed `ModeCapabilityException`, no driver/bridge invocation, no partial side effect
    - **Validates: Requirements 8.2 / A8, 6.5 / A6, 5.1 / A5**

---

## Phase 6 — Multi-Tenant Engine Integration (Sessions, Messaging, Anti-Ban)

- [ ] 9. Sessions, plan-gated send, anti-ban per tenant, opt-out
  - [~] 9.1 Enforce per-tenant `SESSIONS` quota in `SessionManager::create`; record `channel_mode`; call `driver->register()` for official modes before marking live; map each session to exactly one tenant
    - _(Req 2.1, 2.4, 2.6 / A2)_
  - [~] 9.2 Ensure auto-reconnect policy runs per session without affecting other tenants; scope all bridge/driver calls to owned session IDs
    - _(Req 2.3, 2.5 / A2)_
  - [~] 9.3 Implement `tenantSendGate` (Algorithm 3) composed with `channelSendGate`: plan gate → monthly/daily quota → anti-ban (mode-branched); consume quota once after confirm, keyed by idempotency key; release (never drop) on defer
    - _(Req 3.1, 3.2, 3.4, 3.5 / A3; Req 31.1 / NFR2)_
  - [~] 9.4 Implement single/dual/auto-bulk/bulk-media/scheduled (once + recurring)/versioned-template sends, all tenant-scoped and mode-aware
    - _(Req 3.2 / A3)_
  - [~] 9.5 Track delivery status (sent/delivered/read) with `rank()`-based out-of-order-ack suppression (no downgrade)
    - _(Req 3.3 / A3)_
  - [~] 9.6 Apply anti-ban per tenant on web-protocol modes (gaussian delay, typing sim, warm-up ramp, quiet hours, spintax, batch cool-down, risk scoring); no bypass parameter; stricter of plan vs config; throttle+alert on risk threshold
    - _(Req 4.1, 4.2, 4.3, 4.4 / A4)_
  - [~] 9.7 Enforce opt-out per tenant inside `RecipientResolver` on every outbound path with no bypass
    - _(Req 3.1 / A3; Req 21.2 / C4; Req 28.3 / D5)_
  - [ ]* 9.8 **PBT — Property 3 (Opt-out is absolute):** contact with an `opt_outs` row never receives a marketing/campaign/bot reply on any path
    - **Validates: Requirements 11.4 / B2, 21.2 / C4, 28.3 / D5**
  - [ ]* 9.9 **PBT — Property 9 (Status monotonicity):** message status never downgrades under random out-of-order acks
    - **Validates: Requirements 3.3 / A3**
  - [ ]* 9.10 **Feature test:** two tenants run campaigns concurrently; assert independent rate limits/quotas, fair lane scheduling (no starvation), zero duplicate `wa_message_id`
    - _(Req 3.1 / A3; Req 30.2 / NFR1)_

---

## Phase 7 — Billing Subsystem

- [ ] 10. Plans, subscriptions, wallet, invoices, coupons, gateway webhooks
  - [~] 10.1 Create `subscriptions`, `wallets`, `wallet_txns`, `invoices`, `coupons`, `payment_events` migrations/models with enums (`SubscriptionStatus`)
    - _(Req 25.1 / D2)_
  - [~] 10.2 Implement `PaymentGateway` interface + `RazorpayGateway`, `StripeGateway`, `UpiLinkGateway`, and `FakeGateway` (test only)
    - _(Req 15.4 / B6; Req 25.1 / D2; Req 33.2 / NFR4)_
  - [~] 10.3 Implement `SubscriptionService` (subscribe/changePlan+prorate/cancel/onGatewayEvent) idempotent on `payment_events.gateway_event_id`; dunning/PAST_DUE handling
    - _(Req 25.2 / D2)_
  - [~] 10.4 Implement `WalletService` (balance/topUp idempotent by source ref/debit never-below-zero under `Cache::lock`) + `InsufficientFundsException`
    - _(Req 25.3 / D2)_
  - [~] 10.5 Implement `InvoiceService` (sequential number, PDF via dompdf, signed-URL delivery) + revenue reports from `metrics_rollup`
    - _(Req 23.1 / C6; Req 25.1 / D2)_
  - [~] 10.6 Implement `CouponService` redemption math, limits, and expiry
    - _(Req 25.1 / D2)_
  - [~] 10.7 Implement the gateway webhook controller with signature verification and exactly-once processing via `IdempotencyStore` + outbox
    - _(Req 25.2 / D2; Req 31.2 / NFR2)_
  - [ ]* 10.8 **PBT — Property 5 (Wallet non-negativity):** random debit sequences; assert balance ≥ 0 at all times
    - **Validates: Requirements 25.3 / D2**
  - [ ]* 10.9 **PBT — Property 8 (Gateway idempotency):** webhook delivered N≥1 times; assert subscription/wallet effect applied exactly once
    - **Validates: Requirements 25.2 / D2**

---

## Phase 8 — Conversational AI: Inbound & Resolution Pipeline

- [ ] 11. Inbound handling and the deterministic resolution pipeline
  - [~] 11.1 Create `chatbots`, `conversations`, `conversation_states`, `messages_inbound`, `handoff_events` migrations/models with enums (`ConversationMode`)
    - _(Req 10.1 / B1; Req 17.1 / B8)_
  - [~] 11.2 Implement `HandleInboundMessageJob` (`ShouldBeUnique` on `wa_message_id`) dispatched on the `ai-reply` lane after driver signature verify + tenant/session resolution
    - _(Req 10.1, 10.4 / B1; Req 30.1 / NFR1)_
  - [~] 11.3 Implement `ConversationEngine::handle` + `ResolverStage` contract and the fixed-order pipeline (Algorithm 1) with single-reply guarantee and suspended-tenant halt (log, no reply)
    - _(Req 10.2, 10.3 / B1; Req 11.1, 11.2 / B2)_
  - [~] 11.4 Implement `OptOutStage` (STOP/UNSUBSCRIBE → record + confirm + HALT), `LiveAgentStage`, `BusinessHoursStage` (away message), `FallbackStage`; each plan-gated/skippable
    - _(Req 11.4 / B2; Req 15.1 / B6; Req 17.2 / B8)_
  - [ ]* 11.5 **PBT — Property 2 (Single reply per inbound):** random stage configurations; assert at most one outbound reply per inbound
    - **Validates: Requirements 10.2 / B1**

---

## Phase 9 — Keyword, Intent/FAQ, Rich Messages

- [ ] 12. Rule-based stages and rich interactive messages with degradation
  - [~] 12.1 Create `keyword_triggers`, `intents`, `knowledge_base` migrations/models
    - _(Req 12.1 / B3)_
  - [~] 12.2 Implement `KeywordTriggerStage` (EXACT/CONTAINS/REGEX, priority-ordered; highest-priority-only on multi-match)
    - _(Req 12.1, 12.3 / B3)_
  - [~] 12.3 Implement `IntentFaqStage` (rule-based intent detection → configured FAQ answer)
    - _(Req 12.2 / B3)_
  - [~] 12.4 Implement rich `OutboundContent` variants (reply buttons, list message, product/catalog) with degradation to numbered-text menu when the session/mode can't render them
    - _(Req 12.4 / B3)_

---

## Phase 10 — AI/LLM Engine Deep Dive (RAG, Memory, Guardrails, Routing, Resilience)

- [ ] 13. RAG ingestion & retrieval
  - [~] 13.1 Create `kb_chunks` (FULLTEXT on content), `embeddings`, `semantic_cache`, `llm_usage`, `prompt_templates` migrations/models with `RagDriver` enum
    - _(Req 13.1 / B4)_
  - [~] 13.2 Implement `VectorStore` interface (`upsert`/`search`/`delete`/`driver`) + `QdrantVectorStore`, `PgvectorVectorStore`, `MysqlVectorStore` (brute-force cosine fallback), and `FakeVectorStore` (test only)
    - _(Req 13.15 / B4; Req 33.2 / NFR4)_
  - [~] 13.3 Implement `Embedder` interface + real provider (batch 96) and `FakeEmbedder` (test only)
    - _(Req 13.1 / B4)_
  - [~] 13.4 Implement `KbIngestionJob`: loaders → normalize → recursive structural chunking (512 tok / 64 overlap) → embed → upsert to vector store + FULLTEXT mirror; checksum de-dup, re-embed changed only
    - _(Req 13.1 / B4)_
  - [~] 13.5 Implement `Retriever` (hybrid dense ANN top-k=20 + lexical FULLTEXT top-k=20 → RRF → rerank to top-n=5 via cross-encoder or LLM-as-reranker) with tenant_id payload filter on every query
    - _(Req 13.1 / B4; Req 32.1 / NFR3)_
  - [~] 13.6 Implement query-time degradation: embedding provider down → FULLTEXT-only + `rag.degraded`; vector store down → FULLTEXT-only + `rag.degraded`; never crash the job
    - _(Req 13.14, 13.15 / B4)_
  - [ ]* 13.7 **PBT — Property 11 (RAG citation grounding):** random KB + query; assert citations ⊆ retrieved set, no cross-tenant citation, ungrounded factual claims suppressed/escalated
    - **Validates: Requirements 13.6 / B4**
  - [ ]* 13.8 **PBT — Property 20 (Tenant-scoped retrieval isolation):** 2-tenant KB seed; assert every returned/cited chunk has `tenant_id = t.id`
    - **Validates: Requirements 1.2 / A1, 13.1 / B4, 32.1 / NFR3**

- [ ] 14. Conversation memory, token budgeting & compaction
  - [~] 14.1 Implement `ConversationMemory::assemble` (system+guardrails+summary+recent verbatim ≤8+RAG top-n) within the per-request token budget with bucketed trimming
    - _(Req 13.1, 13.7, 13.16 / B4)_
  - [~] 14.2 Implement `MemoryCompactor::compact` (Algorithm 5): slide oldest turns into the rolling summary (cheap model), no turn dropped-and-unsummarized; `remember` episodic upsert
    - _(Req 13.7 / B4)_
  - [~] 14.3 Implement context-budget trimming: trim RAG chunks by descending score then oldest turns until fit, never dropping system prompt/guardrails
    - _(Req 13.16 / B4)_

- [ ] 15. Prompt templating, guardrails, structured output & PII
  - [~] 15.1 Implement `PromptTemplate` rendering + versioning (rollback-able, cached)
    - _(Req 13.1 / B4)_
  - [~] 15.2 Wire `Guardrail` input/output inspection + `PiiRedactor` egress redaction into the LLM stage; suppress + log prompt-injection to `abuse_events`
    - _(Req 13.8 / B4; Req 32.2 / NFR3)_
  - [~] 15.3 Implement structured output (JSON-schema validation + exactly one repair retry → rule-based fallback)
    - _(Req 13.9 / B4)_

- [ ] 16. Semantic cache, model routing & LLM fallback chain
  - [~] 16.1 Implement semantic reply cache: lookup normalized+embedded query at cosine ≥ 0.95 within same (tenant, chatbot, KB version, template version); return cached reply, skip LLM
    - _(Req 13.11 / B4)_
  - [~] 16.2 Implement cache invalidation on KB re-ingest or prompt-template version bump (no stale-version or cross-tenant hit)
    - _(Req 13.12 / B4; Req 30.4 / NFR1)_
  - [~] 16.3 Implement `ModelRouter` cheap→strong escalation (escalate only on confidence < 0.55, empty RAG grounding, or repair-retry failure)
    - _(Req 13.10 / B4)_
  - [~] 16.4 Implement `LlmProvider` interface + `OpenAiProvider`, `GeminiProvider`, `FakeLlmProvider` (reply/detectIntent/detectLanguage/sentiment)
    - _(Req 13.1 / B4; Req 33.2 / NFR4)_
  - [~] 16.5 Implement the fallback chain (primary → secondary → local heuristic FAQ/templated) guarded by per-provider + per-tenant `CircuitBreaker`; token accounting to `llm_usage` idempotent per `request_id`
    - _(Req 13.5, 13.13 / B4)_
  - [~] 16.6 Implement `LlmReplyStage`: AI-enabled + `AI_CREDITS` gate (0 → skip, no LLM/embedding call); confidence <0.55 or handoff-intent → escalate (no AI reply); 0.55–0.70 grounded → hedged reply + "type AGENT"; provider error/timeout → skip, record breaker failure, never crash
    - _(Req 13.1, 13.2, 13.3, 13.4, 13.5 / B4)_
  - [~] 16.7 Implement multi-language reply (detect + reply in detected language) and per-message sentiment with rolling average per conversation
    - _(Req 16.2, 16.3 / B7)_
  - [ ]* 16.8 **PBT — Property 12 (Semantic-cache soundness):** random tenants/queries; assert no cross-tenant hit and no stale KB/template-version hit
    - **Validates: Requirements 13.11 / B4, 30.4 / NFR1**
  - [ ]* 16.9 **Feature test:** `FakeLlmProvider` — credit exhaustion skips LLM, low confidence escalates, provider error does not crash the job, breaker opens then half-opens
    - _(Req 13.2, 13.4, 13.5 / B4)_

---

## Phase 11 — Visual Flow Builder & Runtime

- [ ] 17. No-code flow authoring, validation & runtime
  - [~] 17.1 Create `flows` migration/model (graph JSON, versions, status) with `FlowNodeType` enum
    - _(Req 14.1 / B5)_
  - [~] 17.2 Implement `FlowValidator::validate` (Algorithm 4): exactly one entry, reachability, default/else edges on branch nodes, termination; block publish on invalid
    - _(Req 14.2 / B5)_
  - [~] 17.3 Implement `FlowRuntime` (start/resume/evaluateNode, Algorithm 2) for all node types; persist current node + variables in `conversation_states` (stateless runtime)
    - _(Req 14.1, 14.3, 14.4 / B5)_
  - [~] 17.4 Implement `ActiveFlowStage` (resume mid-flow at correct pipeline priority)
    - _(Req 11.1 / B2)_
  - [~] 17.5 Build the Livewire + Alpine drag-and-drop canvas producing graph JSON with live validation feedback and failing-node highlight
    - _(Req 14.1, 14.2 / B5; Req 22.1 / C5)_
  - [ ]* 17.6 **PBT — Property 6 (Flow termination):** randomly generated valid graphs; assert every traversal reaches an end/handoff node in finite steps
    - **Validates: Requirements 14.4 / B5**

---

## Phase 12 — Business Hours, Lead Capture, Orders, Payments, Saga & Drip

- [ ] 18. Lead/order/booking flows with atomic multi-step saga and drip sequences
  - [~] 18.1 Create `leads`, `lead_forms`, `orders`, `catalog_items`, `campaign_sequences`, `sequence_enrollments` migrations/models
    - _(Req 15.2, 15.3 / B6; Req 15.6 / B6)_
  - [~] 18.2 Implement lead-capture form nodes writing to `leads`
    - _(Req 15.2 / B6)_
  - [~] 18.3 Implement the order/booking bot (catalog browse → cart → order)
    - _(Req 15.3 / B6)_
  - [~] 18.4 Wire order payment to `PaymentGateway::createPaymentLink` (built from canonical Base URL); include link in reply; reconcile on gateway webhook
    - _(Req 15.4 / B6; Req 25.2 / D2)_
  - [~] 18.5 Implement the order→payment→fulfilment saga via `SagaOrchestrator` with per-step idempotent compensations (release reservation, void link, cancel+refund)
    - _(Req 15.5 / B6)_
  - [~] 18.6 Implement the drip/re-engagement engine advancing `sequence_enrollments` at `next_run_at`, respecting opt-out, quiet hours, and quota (no bypass)
    - _(Req 15.6 / B6)_
  - [ ]* 18.7 **Feature test:** end-to-end order → payment link → fake gateway paid webhook → order PAID exactly once; saga failure at each step compensates cleanly
    - _(Req 15.3, 15.4, 15.5 / B6)_

---

## Phase 13 — Integrations, Agents, Tools, A/B, STT & Search

- [ ] 19. Advanced chatbot capabilities and analytics events
  - [~] 19.1 Create `agents_registry`, `tools_registry`, `ab_tests`, `ab_assignments`, `flow_analytics_events`, `media_transcripts` migrations/models with enums (`AgentKind`, `FlowAnalyticsEvent`)
    - _(Req 16.5, 16.6 / B7)_
  - [~] 19.2 Implement action nodes (webhook / CRM / Google Sheets) as retry-safe queued work
    - _(Req 16.1 / B7)_
  - [~] 19.3 Implement `AgentRouter` + `Skill` multi-agent orchestration (router classifies → specialist), falling back to single-agent LLM when no router configured
    - _(Req 16.5 / B7)_
  - [~] 19.4 Implement `ToolRegistry` (`schemas`/`invoke`) with JSON-Schema validation and idempotent invocation
    - _(Req 16.5 / B7)_
  - [~] 19.5 Implement `AbTester` (sticky per-unit variant assignment + metric recording; control for everyone when disabled) and flow funnels from `flow_analytics_events` rolled into `metrics_rollup`
    - _(Req 16.4, 16.6 / B7)_
  - [~] 19.6 Implement `SpeechToText` interface + real provider and `FakeStt` (test only); transcribe inbound voice notes to `media_transcripts` into the pipeline; degrade to "please type" when unbound; optional `TextToSpeech` degrading to text
    - _(Req 16.7 / B7; Req 33.2 / NFR4)_
  - [~] 19.7 Implement conversation search: MySQL FULLTEXT (always on) + semantic vector search where available (degrade to FULLTEXT)
    - _(Req 16.8 / B7; Req 34.3 / NFR5)_
  - [~] 19.8 Implement per-node conversation analytics/reports (volume, resolution, handoff rate, sentiment, funnels) scoped to tenant
    - _(Req 16.4 / B7)_

---

## Phase 14 — Human Handoff / Live Agent

- [ ] 20. Handoff lifecycle and live inbox
  - [~] 20.1 Implement `HandoffService` (request/assign/release/agentSend) with mode transitions + `handoff_events`
    - _(Req 17.1, 17.4 / B8)_
  - [~] 20.2 Enforce agent-only silence: bot enqueues zero replies while `mode = AGENT`
    - _(Req 17.2 / B8)_
  - [~] 20.3 Route agent sends through the same anti-ban/plan-gated pipeline (no exemption)
    - _(Req 17.3 / B8)_
  - [~] 20.4 Build the Live Inbox Livewire component (`wire:poll`), assignment, SLA/unassigned-timeout auto-escalation
    - _(Req 17.1 / B8)_
  - [ ]* 20.5 **PBT — Property 10 (Handoff silence):** conversation in `AGENT` mode enqueues zero automatic replies
    - **Validates: Requirements 17.2 / B8**

---

## Phase 15 — WhatsApp Groups: Full Management

- [ ] 21. GroupService lifecycle, admin management & audited settings
  - [~] 21.1 Implement `GroupAdminGuard` (`assertBotIsAdmin`/`botIsAdmin`) throwing `NotGroupAdminException` before any bridge mutation; gate all group ops with `ChannelRouter::assertSupported($s, Groups)`
    - _(Req 5.2 / A5)_
  - [~] 21.2 Implement `GroupService::create/delete/updateMetadata` (name/description/icon), tenant-scoped, capability-gated
    - _(Req 5.1 / A5)_
  - [~] 21.3 Implement invite-link ops: `inviteLink` (get), `revokeInviteLink` (rotate), `joinViaLink`
    - _(Req 5.4 / A5)_
  - [~] 21.4 Implement `addParticipants/removeParticipants/promote/demote` with deterministic WA status-code mapping to `ADDED`/`INVITE_SENT`/`ALREADY_MEMBER`/`FAILED`; idempotent on request key (apply at most once, return original per-target status on retry)
    - _(Req 5.5, 5.16 / A5)_
  - [~] 21.5 Implement `setSettings` (announcement/locked/ephemeral/approval) requiring bot-admin and writing `{group, field, from, to, actor}` audit records
    - _(Req 5.6 / A5)_
  - [~] 21.6 Implement transport-error handling: bridge unreachable → retryable error, state unchanged, job released (no partial result); per-group serialization of mutating ops (lock)
    - _(Req 5.14, 5.15 / A5)_

- [ ] 22. Join requests, reconciliation, bulk, welcome, extraction, export, tagging
  - [~] 22.1 Implement join-request inbox (`pendingJoinRequests`/`resolveJoinRequest`) and `evaluateAutoApprove` in fixed first-decisive order blocklist → country-code → regex → manual
    - _(Req 5.7 / A5)_
  - [~] 22.2 Implement `reconcileMembers` (bridge ground-truth → DB; cache bot-admin status)
    - _(Req 5.8 / A5)_
  - [~] 22.3 Implement `bulkAddParticipants` as chunked (default 50/chunk), retry-safe, anti-ban-paced queued jobs
    - _(Req 5.9 / A5)_
  - [~] 22.4 Implement `sendWelcome`: per-member vs combined, media welcome, dynamic card with text fallback, exactly-once-per-join via `(group_id, jid, join_epoch)` guard (rejoin no-op), template rotation / sticky A-B
    - _(Req 5.3, 5.10 / A5)_
  - [~] 22.5 Implement `extractMembers` (streaming generator, temp-table de-dup on normalized JID, active-number filter) requiring `Extraction` capability + bot-admin
    - _(Req 5.11 / A5)_
  - [~] 22.6 Implement `export` to CSV/TXT/JSON/XLSX/vCard under the tenant prefix via a signed expiring URL (canonical Base URL)
    - _(Req 5.12 / A5)_
  - [~] 22.7 Implement `tagAll/tagSelective`/custom tagging using hidden mentions, 200 mentions/chunk, per-group cooldown, admin-only guarded
    - _(Req 5.13 / A5)_
  - [ ]* 22.8 **PBT — Property 25 (Admin-op rejected before bridge; welcome exactly once per join):** random bot-admin true/false → assert bridge mutation not invoked when false; random join/rejoin sequences → assert exactly one welcome per distinct join epoch
    - **Validates: Requirements 5.2, 5.3 / A5**

---

## Phase 16 — WhatsApp Channels: Full Management

- [ ] 23. ChannelService lifecycle, posting, subscribers & delta analytics
  - [~] 23.1 Create `channel_snapshots` (and reuse `channels`/`newsletters`, `channel_posts`) migrations/models; gate every op with `ChannelRouter::assertSupported($s, Channels)` (Baileys-only)
    - _(Req 6.5 / A6)_
  - [~] 23.2 Implement `ChannelService::create/delete/updateMetadata` (name/description/picture), tenant-scoped
    - _(Req 6.1 / A6)_
  - [~] 23.3 Implement `subscribers` (streamed), `addAdmin`/`removeAdmin`, `follow`/`mute`
    - _(Req 6.2 / A6)_
  - [~] 23.4 Implement `post` (text/media/poll), `schedulePost` (once + recurring), and `calendar` (content-calendar over a date range), anti-ban paced
    - _(Req 6.3 / A6)_
  - [~] 23.5 Implement `analytics` (subscribers/reach/engagement) computed as deltas between stored periodic snapshots, rolled into `metrics_rollup`
    - _(Req 6.4 / A6)_
  - [~] 23.6 Implement channel member extraction, active-number filtering, and export (CSV/TXT/JSON/XLSX/vCard) via signed expiring URL under the tenant prefix
    - _(Req 6.6, 6.7 / A6)_
  - [ ]* 23.7 **Feature test:** official-mode channel op raises `ModeCapabilityException` before any driver call (reuses Property 26); Baileys post → calendar update path works
    - _(Req 6.5 / A6)_

---

## Phase 17 — User Panel (28 Tenant Self-Service Features)

- [ ] 24. Group A — Account & Security (C1)
  - [~] 24.1 Registration (email + phone OTP) via `Panel\Auth\Register` provisioning tenant+wallet+default chatbot+DEK on verify, with disposable-email/velocity anti-fraud
    - _(Req 18.1 / C1)_
  - [~] 24.2 Login (email + phone OTP) via `Panel\Auth\Login` with throttle+lockout, binding `TenantContext`
    - _(Req 18.2 / C1)_
  - [~] 24.3 Profile management (`Panel\Account\Profile`) with avatar via signed tenant-prefixed URL
    - _(Req 18.3 / C1)_
  - [~] 24.4 Subscription/plan view (`Panel\Account\Subscription`) with upgrade/downgrade + dunning state
    - _(Req 18.4 / C1)_
  - [~] 24.5 Wallet/credits view + gateway top-up (`Panel\Account\Wallet`), balance ≥ 0
    - _(Req 18.4 / C1)_
  - [~] 24.6 Two-factor auth (`Panel\Account\TwoFactor`, TOTP + recovery codes) enforced on next login
    - _(Req 18.5 / C1)_

- [ ] 25. Group B — WhatsApp Connection Self-Service (C2)
  - [~] 25.1 Connect own number (`Panel\Sessions\Connect`) QR/pairing or per-mode credential entry, `SESSIONS` quota-gated
    - _(Req 19.1, 19.2 / C2)_
  - [~] 25.2 View sessions / live status (`Panel\Sessions\Index`, `wire:poll`), own sessions only
    - _(Req 19.1 / C2)_
  - [~] 25.3 Reconnect / disconnect (`Panel\Sessions\Manage`), ownership-checked
    - _(Req 19.1 / C2)_
  - [~] 25.4 Choose / view channel mode + per-mode credential entry (`Panel\Sessions\ChannelMode`), secret-redacted, envelope-encrypted, disabled-with-hint when creds absent
    - _(Req 19.3 / C2)_

- [ ] 26. Group C — Messaging Within Quota (C3)
  - [~] 26.1 Single/dual send (`Panel\Messaging\Compose`), quota + opt-out enforced, over-quota defer notice
    - _(Req 20.1 / C3)_
  - [~] 26.2 Bulk campaign (`Panel\Messaging\Campaigns`), `CAMPAIGNS_CONCURRENT` + message quota; would-exceed blocked; mid-run exhaustion → `QUOTA_PAUSED` + auto-resume
    - _(Req 20.1, 20.2, 20.3 / C3)_
  - [~] 26.3 Scheduler once + recurring (`Panel\Messaging\Scheduler`), quiet-hours defer, recurrence validated
    - _(Req 20.1 / C3)_
  - [~] 26.4 Media library / send (`Panel\Messaging\Media`), tenant-prefixed storage; `Media` capability per mode → disabled-with-reason when unsupported
    - _(Req 20.1, 20.4 / C3)_
  - [~] 26.5 Templates use/create/version (`Panel\Messaging\Templates`); Cloud API/BSP approval status; Baileys text-templated
    - _(Req 20.1, 20.4 / C3)_
  - [~] 26.6 Delivery status board (`Panel\Messaging\Delivery`, `wire:poll`), status never downgrades
    - _(Req 20.1 / C3)_

- [ ] 27. Group D — Contacts & Groups (C4)
  - [~] 27.1 Contacts manage (`Panel\Contacts\Index`), `CONTACTS` quota
    - _(Req 21.1 / C4)_
  - [~] 27.2 Import CSV/vCard (`Panel\Contacts\Import`, queued/streamed) with de-dup + validation; malformed rows reported, valid rows imported; quota-checked
    - _(Req 21.1, 21.3 / C4)_
  - [~] 27.3 Groups manage (own) (`Panel\Groups\Index`) via `GroupService`; official mode → group ops disabled
    - _(Req 21.1 / C4)_
  - [~] 27.4 Own-group number extraction (`Panel\Groups\Extract`), admin-guarded, `Extraction` capability, streamed
    - _(Req 21.1 / C4)_
  - [~] 27.5 Export own data (`Panel\Contacts\Export`) CSV/TXT/JSON/XLSX/vCard via signed expiring URL
    - _(Req 21.1 / C4)_

- [ ] 28. Group E — Chatbot Self-Service (C5)
  - [~] 28.1 Flow builder (`Panel\Chatbot\FlowBuilder`), publish blocked when invalid with failing node highlighted
    - _(Req 22.1 / C5)_
  - [~] 28.2 Keyword / FAQ manager (`Panel\Chatbot\Keywords`), priority-ordered
    - _(Req 22.1 / C5)_
  - [~] 28.3 AI auto-reply toggle (`Panel\Chatbot\AiSettings`); credits 0 → LLM skipped → FAQ/fallback; not-in-plan → hidden/disabled
    - _(Req 22.1, 22.2, 22.3 / C5)_
  - [~] 28.4 Away / business-hours (`Panel\Chatbot\BusinessHours`), timezone-aware
    - _(Req 22.1 / C5)_

- [ ] 29. Group F — Reports & Support (C6)
  - [~] 29.1 Campaign analytics + conversation reports (`Panel\Reports\Analytics`) from `metrics_rollup`, own aggregates only
    - _(Req 23.1 / C6)_
  - [~] 29.2 Error logs (phone-redacted) + notifications inbox (`Panel\Reports\Errors`, `Panel\Notifications\Inbox`); retry updates original row
    - _(Req 23.1 / C6; Req 7.1, 7.2, 7.3 / A7)_
  - [~] 29.3 Support ticket / help center + billing history & invoices (`Panel\Support\Tickets`, `Panel\Account\Invoices`); ticket routed to platform support; invoices via signed URL
    - _(Req 23.1, 23.2 / C6)_
  - [ ]* 29.4 **HTTP/Livewire tests:** every one of the 28 User Panel routes is tenant-scoped, plan-gated + quota-enforced, opt-out enforced, and degrades to a disabled-with-reason state (never crashes)
    - **Validates: Requirements 18–23 / C1–C6 (Property 1, 3, 7)**

---

## Phase 18 — Admin Panel (28 Platform Super-Admin Features)

- [ ] 30. Group A — User & Access Management (D1)
  - [~] 30.1 Distinct `platform-admin` guard + `actingAsPlatform()` mode + middleware `[auth:platform-admin, ip.allowlist, admin.throttle, audit]`
    - _(Req 24.3 / D1; Req 32.1 / NFR3)_
  - [~] 30.2 CRUD/suspend all users (`Admin\Users\Index`) cross-tenant, audited
    - _(Req 24.1 / D1)_
  - [~] 30.3 CRUD/suspend all tenants (`Admin\Tenants\Index`) via `TenantLifecycle`, audited
    - _(Req 24.1 / D1)_
  - [~] 30.4 RBAC roles owner/admin/operator/viewer/agent (`Admin\Access\Roles`) via `RbacService`, audited
    - _(Req 24.1 / D1)_
  - [~] 30.5 Impersonate / login-as (`Admin\Users\Impersonate`) — banner, time-boxed, destructive-op block, fully audited
    - _(Req 24.1, 24.4 / D1)_
  - [~] 30.6 Audit-log viewer (`Admin\Audit\Index`) with hash-chain verification
    - _(Req 24.2, 24.5 / D1)_
  - [~] 30.7 Login security (`Admin\Security\Login`) — IP allowlist / throttle / lockout for the admin panel
    - _(Req 24.3 / D1)_

- [ ] 31. Group B — Plans & Billing (D2)
  - [~] 31.1 Create/manage plans & pricing (`Admin\Plans\Index`), version-bump cache invalidation
    - _(Req 25.1 / D2)_
  - [~] 31.2 Feature limits per plan (`Admin\Plans\Limits`) driving `PlanGate`/`QuotaGuard`
    - _(Req 25.1 / D2)_
  - [~] 31.3 Payment gateway config (`Admin\Billing\Gateways`), secrets redacted, never exposed to tenants
    - _(Req 25.1 / D2; Req 32.3 / NFR3)_
  - [~] 31.4 Wallet/credit top-ups (`Admin\Billing\Wallets`), idempotent, logged
    - _(Req 25.1 / D2)_
  - [~] 31.5 Coupons/discounts (`Admin\Billing\Coupons`) with redemption limits
    - _(Req 25.1 / D2)_
  - [~] 31.6 Invoices & revenue reports (`Admin\Billing\Revenue`) from replica/rollup
    - _(Req 25.1 / D2)_
  - [~] 31.7 Per-tenant cost attribution (`llm_usage` + message counts + storage) rolled up for margin analysis, optionally billed against `AI_CREDITS`/wallet
    - _(Req 25.4 / D2)_

- [ ] 32. Group C — Platform Control (D3)
  - [~] 32.1 Global session monitor (`Admin\Monitor\Sessions`, `wire:poll`)
    - _(Req 26.1 / D3)_
  - [~] 32.2 Global campaign & queue monitor (`Admin\Monitor\Queues`) with backpressure/shedding controls
    - _(Req 26.1 / D3)_
  - [~] 32.3 Global anti-ban / rate-limit floor (`Admin\Control\AntiBan`) applied as a floor tenants cannot loosen
    - _(Req 26.1, 26.2 / D3)_
  - [~] 32.4 Broadcast announcements (`Admin\Control\Announcements`) with audience segment targeting
    - _(Req 26.1 / D3)_
  - [~] 32.5 Feature flags (`Admin\Control\FeatureFlags`) global or per-tenant override
    - _(Req 26.1, 26.3 / D3)_
  - [~] 32.6 System settings (`Admin\Control\Settings`: SMTP/API/LLM/gateway keys, defaults, base URL), secret-redacted, never returned raw
    - _(Req 26.1, 26.4 / D3)_

- [ ] 33. Group D — Monitoring & Health (D4)
  - [~] 33.1 Health dashboard (`Admin\Health\Dashboard`: server/bridge/queue traffic-light)
    - _(Req 27.1 / D4)_
  - [~] 33.2 Global error center & dedup alerts (`Admin\Health\Errors`, `alerts.fingerprint`) linked to runbooks
    - _(Req 27.1, 27.2 / D4)_
  - [~] 33.3 Per-user & platform usage analytics (`Admin\Analytics\Usage`) from replica/warehouse, no live-table scans
    - _(Req 27.1, 27.3 / D4)_
  - [~] 33.4 Prometheus metrics endpoint (`MetricsController`, `GET /metrics`, `wacb_*`) scrape-auth restricted
    - _(Req 27.1 / D4)_

- [ ] 34. Group E — Content & Compliance (D5)
  - [~] 34.1 Global templates library (`Admin\Content\Templates`)
    - _(Req 28.1 / D5)_
  - [~] 34.2 Opt-out / blocklist management (`Admin\Compliance\Blocklist`), non-bypassable across tenants
    - _(Req 28.1, 28.3 / D5)_
  - [~] 34.3 Data retention & right-to-delete (`Admin\Compliance\Retention`): scheduled purge across OLTP, vector store, object storage, analytics + verification pass + signed deletion certificate to audit log
    - _(Req 28.1, 28.2 / D5)_
  - [~] 34.4 ToS enforcement (`Admin\Compliance\Tos`) with per-session risk kill-switch
    - _(Req 28.1 / D5)_

- [ ] 35. Group F — Support (D6)
  - [~] 35.1 Ticket management (`Admin\Support\Tickets`) assign/close/SLA, audited
    - _(Req 29.1 / D6)_
  - [~] 35.2 KB/FAQ manager + segment notifications (`Admin\Support\Kb`, `Admin\Support\Notify`) delivering only to the targeted audience
    - _(Req 29.1, 29.2 / D6)_
  - [ ]* 35.3 **HTTP tests:** every one of the 28 Admin Panel routes requires the platform guard + IP allowlist + throttle, is audited, reads cross-tenant only via `actingAsPlatform()`, and keeps secrets redacted
    - **Validates: Requirements 24–29 / D1–D6 (Property 1, 17)**

---

## Phase 19 — Scalability at Scale

- [ ] 36. Partitioning, sharding, Redis upgrade, queue tuning & caching
  - [~] 36.1 Implement time-RANGE monthly partitioning on `messages`, `messages_inbound`, `event_log`, `flow_analytics_events`, `llm_usage` with pre-created partitions and `DROP PARTITION` retention
    - _(Req 30.5 / NFR1)_
  - [~] 36.2 Implement the tenant-sharding path keyed by `tenant_tiers.shard_key` via `TierResolver::connection` (consistent-hash router)
    - _(Req 30.5 / NFR1)_
  - [~] 36.3 Implement the documented Redis drop-in upgrade for queue/cache/locks/rate-buckets/broadcast with MySQL auto-fallback when Redis is absent
    - _(Req 30.3 / NFR1)_
  - [~] 36.4 Implement per-lane queue workers (`transactional`/`ai-reply`/`welcome`/`campaign`/`extraction`) with prefetch=1, backpressure shedding, and autoscale signals (`queue_depth`/`job_age_seconds`/`worker_busy_ratio`)
    - _(Req 30.1 / NFR1)_
  - [~] 36.5 Implement per-tenant in-flight AI concurrency caps and skip-rate-capped-tenant dispatch (noisy-neighbor bound)
    - _(Req 30.6 / NFR1)_
  - [~] 36.6 Implement versioned caching layers (config/flags, tenant settings, hot flows, prompt templates, semantic cache, retrieval) with version-bump invalidation and a read-replica connection (`useReadPdo`) for analytics
    - _(Req 30.4 / NFR1; Req 34.2 / NFR5)_
  - [ ]* 36.7 **Load test:** N tenants concurrent — assert p95 targets (transactional enqueue→bridge <2s, AI cache-hit <150ms, AI LLM <3s), each lane drains steady-state backlog within 60s, and per-tenant limits/quotas hold independently
    - _(Req 30.7 / NFR1)_

---

## Phase 20 — Data Architecture & Analytics

- [ ] 37. Event sourcing, CDC/ETL, star schema & conversation search
  - [~] 37.1 Create `event_log` (append-only; revoke UPDATE/DELETE; `uniq(stream_id, version)`) and `projection_checkpoints`; record conversation/messaging/order/session lifecycle events with monotonic per-stream versioning
    - _(Req 16.8 / B7; Req 34.1 / NFR5)_
  - [~] 37.2 Implement projectors rebuilding read models (`conversations`, `orders`) from `event_log` with checkpoint tracking
    - _(Req 34.1 / NFR5)_
  - [~] 37.3 Implement CDC/ETL from `event_log` (or binlog) into a warehouse or MySQL analytics replica; incremental `metrics_rollup`; batch historical reports off live tables
    - _(Req 34.2 / NFR5)_
  - [~] 37.4 Implement the star-schema reporting model (facts `fact_messages`/`fact_conversations`/`fact_orders`/`fact_llm_usage`; dims `dim_tenant`/`dim_date`/`dim_chatbot`/`dim_channel`/`dim_agent`) computing funnels/retention/revenue/deflection/handoff/CSAT/sentiment from replica/warehouse
    - _(Req 34.4 / NFR5)_
  - [ ]* 37.5 **PBT — Property 14 (Event-log append-only & monotonic versioning):** random event streams; assert strictly increasing gap-free versions, no update/delete, and projection replay equals live state
    - **Validates: Requirements 16.8 / B7, 34.1 / NFR5**

---

## Phase 21 — Observability & Operations

- [ ] 38. Tracing, metrics, SLOs, alerts & deployment safety
  - [~] 38.1 Create `traces`, `metrics_rollup`, `alerts` migrations/models; implement `Tracer` (`startSpan`/`currentTraceId`) and `Metrics` (`increment`/`observe`/`gauge`)
    - _(Req 27.4 / D4)_
  - [~] 38.2 Propagate `trace_id` panel → job → bridge → webhook and attach it to every structured log line and span (phone-redacted, bodies hashed only)
    - _(Req 27.4 / D4; Req 32.2 / NFR3)_
  - [~] 38.3 Implement the `wacb_*` metrics taxonomy exported at `/metrics` and define SLO/SLI targets + error budgets per subsystem
    - _(Req 27.1, 27.4 / D4)_
  - [~] 38.4 Implement deduplicated alerting (`alerts.fingerprint`) for breaker OPEN / queue-age / replica-lag / payment-failure / session-disconnect-storm, each linked to a runbook
    - _(Req 27.2 / D4)_
  - [~] 38.5 Implement blue-green/rolling FPM pools with graceful worker draining, expand-contract migrations, and feature-flag canary rollout with pool-swap rollback
    - _(Req 35.1, 35.2, 35.3 / NFR6)_
  - [~] 38.6 Implement disaster-recovery tooling: nightly full + continuous binlog PITR, per-tenant encrypted auth-state backup, documented restore order, meeting RPO ≤5m / RTO ≤30m
    - _(Req 31.6 / NFR2)_

---

## Phase 22 — Hardening, Completeness & Release

- [ ] 39. Definition of Done, full property suite & release
  - [~] 39.1 Implement graceful-degradation matrix code paths (LLM/vector/bridge/gateway/Redis/KMS/STT/Cloud-API each degrading to a real MySQL/Baileys default, not a stub)
    - _(Req 31.1 / NFR2; Req 36.2 / NFR7)_
  - [~] 39.2 Configure Supervisor (worker per lane incl. `ai-reply`), single cron `schedule:run`, and document the Redis upgrade path
    - _(Req 30.1, 30.3 / NFR1)_
  - [~] 39.3 Ensure every feature is tenant-scoped, plan-gated & quota-metered, capability-aware, typed-exception error-handled, observable (`trace_id` + `wacb_*`), and covered by tests
    - _(Req 36.3 / NFR7)_
  - [ ]* 39.4 **CI completeness test — Property 28 (no stub in production paths):** scan production bindings and `app/` for `Fake*`/`Stub*`/`NotImplementedException`/`TODO`; assert none reachable from a non-test path and every §4.1/§4.2 feature, every `GroupService`/`ChannelService` method, every `ChannelMode`, and the `BaseUrl`/`UrlBuilder` helper maps to a task
    - **Validates: Requirements 36.1, 36.3 / NFR7 (C1–C6, D1–D6, A5, A6, A8, A9)**
  - [ ]* 39.5 **Full PBT suite:** run and pass Correctness Properties 1–28; static analysis (Pint + PHPStan) + `composer audit` green
    - _(Req 36.3 / NFR7)_
  - [~] 39.6 Checkpoint — Ensure all tests pass, ask the user if questions arise.

---

## Notes

- Tasks marked with `*` are optional (test) sub-tasks and can be skipped for a faster MVP; core implementation sub-tasks are never optional.
- Each sub-task references its requirement clause `_(Req N.x / BlockID)_` for 1:1 traceability, and property/test sub-tasks name the Correctness Property they validate.
- Checkpoints and top-level parent tasks are not included in the Task Dependency Graph — only leaf sub-tasks are scheduled into waves.
- Property-based tests validate universal invariants; unit/feature/HTTP tests validate specific examples, edge cases, and per-route behaviour.
- All optional/scale-up dependencies degrade to a real, tested MySQL/Baileys default (never a stub), per the Definition of Done (Req 36 / NFR7, Property 28).

---

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["0.1", "0.2", "0.3", "0.4", "0.5"] },
    { "id": 1, "tasks": ["0.6", "1.1", "1.2", "1.3", "2.1", "3.1", "4.1", "4.3"] },
    { "id": 2, "tasks": ["1.4", "1.5", "2.2", "2.3", "3.2", "3.3", "3.4", "3.5", "3.6", "4.2", "4.4", "4.5", "4.6", "5.1"] },
    { "id": 3, "tasks": ["2.4", "2.5", "2.6", "3.7", "3.8", "3.9", "4.7", "4.8", "5.2", "6.1"] },
    { "id": 4, "tasks": ["5.3", "5.4", "5.5", "6.2", "6.3", "6.4"] },
    { "id": 5, "tasks": ["5.6", "5.7", "6.5", "6.6", "7.1", "7.2", "7.3", "7.4", "7.5", "7.6"] },
    { "id": 6, "tasks": ["7.7", "8.1", "8.2", "8.3", "8.4", "8.5", "8.6"] },
    { "id": 7, "tasks": ["8.7", "8.8", "9.1", "9.2", "10.1"] },
    { "id": 8, "tasks": ["9.3", "9.4", "9.5", "9.6", "9.7", "10.2"] },
    { "id": 9, "tasks": ["9.8", "9.9", "9.10", "10.3", "10.4", "10.5", "10.6", "10.7"] },
    { "id": 10, "tasks": ["10.8", "10.9", "11.1", "12.1", "13.1"] },
    { "id": 11, "tasks": ["11.2", "11.3", "11.4", "12.2", "12.3", "12.4", "13.2", "13.3"] },
    { "id": 12, "tasks": ["11.5", "13.4", "13.5", "15.1", "16.4", "19.1"] },
    { "id": 13, "tasks": ["13.6", "13.7", "13.8", "14.1", "14.2", "14.3", "15.2", "15.3", "16.1", "16.3"] },
    { "id": 14, "tasks": ["16.2", "16.5", "16.7", "17.1", "19.2"] },
    { "id": 15, "tasks": ["16.6", "16.8", "16.9", "17.2", "17.3", "18.1"] },
    { "id": 16, "tasks": ["17.4", "17.5", "18.2", "18.3", "18.4", "19.3", "19.4"] },
    { "id": 17, "tasks": ["17.6", "18.5", "18.6", "19.5", "19.6", "19.7", "19.8"] },
    { "id": 18, "tasks": ["18.7", "20.1", "20.2", "20.3", "21.1", "23.1"] },
    { "id": 19, "tasks": ["20.4", "20.5", "21.2", "21.3", "21.5", "21.6", "23.2", "23.3"] },
    { "id": 20, "tasks": ["21.4", "22.1", "22.2", "22.4", "22.5", "22.7", "23.4", "23.5", "23.6"] },
    { "id": 21, "tasks": ["22.3", "22.6", "22.8", "23.7"] },
    { "id": 22, "tasks": ["24.1", "24.2", "24.3", "24.4", "24.5", "24.6", "25.1", "25.2", "25.3", "25.4"] },
    { "id": 23, "tasks": ["26.1", "26.2", "26.3", "26.4", "26.5", "26.6", "27.1", "27.2", "27.3", "27.4", "27.5"] },
    { "id": 24, "tasks": ["28.1", "28.2", "28.3", "28.4", "29.1", "29.2", "29.3", "29.4"] },
    { "id": 25, "tasks": ["30.1", "30.2", "30.3", "30.4", "30.5", "30.6", "30.7"] },
    { "id": 26, "tasks": ["31.1", "31.2", "31.3", "31.4", "31.5", "31.6", "31.7", "32.1", "32.2", "32.3", "32.4", "32.5", "32.6"] },
    { "id": 27, "tasks": ["33.1", "33.2", "33.3", "33.4", "34.1", "34.2", "34.3", "34.4", "35.1", "35.2", "35.3"] },
    { "id": 28, "tasks": ["36.1", "36.2", "36.3", "36.4", "36.5", "36.6", "37.1", "38.1"] },
    { "id": 29, "tasks": ["36.7", "37.2", "37.3", "37.4", "37.5", "38.2", "38.3", "38.4"] },
    { "id": 30, "tasks": ["38.5", "38.6", "39.1", "39.2", "39.3"] },
    { "id": 31, "tasks": ["39.4", "39.5"] }
  ]
}
```

---

## Task → Requirement / Property Coverage Summary

### Requirements → Tasks (all 36 / A1–NFR7)

| Requirement (Block) | Covering tasks |
|---|---|
| 1 Tenant isolation, tiers & lifecycle (A1) | 0.1–0.6, 1.1–1.5 |
| 2 Multi-tenant session & connection (A2) | 6.1, 9.1, 9.2 |
| 3 Multi-tenant messaging engine (A3) | 9.3–9.5, 9.9, 9.10, 2.3 |
| 4 Anti-ban & safety (A4) | 9.6, 8.7 |
| 5 Group management (A5) | 21.1–21.6, 22.1–22.8 |
| 6 Channel management (A6) | 23.1–23.7, 7.7, 8.8 |
| 7 Error reporting & monitoring (A7) | 4.4, 29.2 |
| 8 Channel Mode (A8) | 6.1–6.6, 7.1–7.7, 8.1–8.8 |
| 9 Base URL (A9) | 5.1–5.7 |
| 10 Inbound & single-reply (B1) | 8.3, 11.1–11.3, 11.5, 6.5 |
| 11 Resolution pipeline (B2) | 11.3, 11.4, 2.6, 17.4 |
| 12 Keyword/intent/FAQ/rich (B3) | 12.1–12.4 |
| 13 LLM smart replies (B4) | 13.1–13.8, 14.1–14.3, 15.1–15.3, 16.1–16.9, 3.2 |
| 14 Flow builder (B5) | 17.1–17.6 |
| 15 Hours/lead/order/pay/saga/drip (B6) | 11.4, 18.1–18.7, 3.5 |
| 16 Integrations/agents/tools/A-B/media/search (B7) | 16.7, 19.1–19.8, 37.1, 37.5 |
| 17 Human handoff (B8) | 11.1, 20.1–20.5 |
| 18 Account & security (C1) | 24.1–24.6, 29.4 |
| 19 WhatsApp connection self-service (C2) | 25.1–25.4, 6.6 |
| 20 Messaging within quota (C3) | 26.1–26.6, 29.4 |
| 21 Contacts & groups self-service (C4) | 27.1–27.5, 9.8, 29.4 |
| 22 Chatbot self-service (C5) | 28.1–28.4, 2.6, 29.4 |
| 23 Reports & support (C6) | 29.1–29.4, 10.5 |
| 24 User & access management (D1) | 30.1–30.7, 4.3, 4.7, 35.3 |
| 25 Plans, billing & cost attribution (D2) | 10.1–10.9, 31.1–31.7 |
| 26 Platform control (D3) | 32.1–32.6 |
| 27 Monitoring, health & observability (D4) | 33.1–33.4, 38.1–38.4 |
| 28 Content & compliance (D5) | 34.1–34.4, 9.7 |
| 29 Support (D6) | 35.1–35.3 |
| 30 Performance & scalability (NFR1) | 1.4, 1.5, 36.1–36.7, 39.2 |
| 31 Reliability & fault tolerance (NFR2) | 3.1–3.9, 10.7, 38.6, 39.1 |
| 32 Security & compliance (NFR3) | 4.1–4.8, 5.7, 6.4, 6.6, 13.5, 13.8, 30.1, 31.3, 32.6, 38.2 |
| 33 Maintainability (NFR4) | 6.2, 10.2, 13.2, 16.4, 19.6 |
| 34 Data architecture & analytics (NFR5) | 19.7, 36.6, 37.1–37.5, 4.3 |
| 35 Deployment & operations safety (NFR6) | 38.5 |
| 36 Definition of Done / completeness (NFR7) | 7.5, 39.1, 39.3, 39.4, 39.5 |

### Correctness Properties → Tasks (all 1–28)

| Property | Title | Covering task(s) |
|---|---|---|
| 1 | Tenant isolation | 0.6, 29.4, 35.3 |
| 2 | Single reply per inbound | 11.5 |
| 3 | Opt-out is absolute | 9.8, 29.4 |
| 4 | Quota never negative / never double-counted | 2.5 |
| 5 | Wallet non-negativity | 10.8 |
| 6 | Flow termination | 17.6 |
| 7 | Plan gating | 2.6, 29.4 |
| 8 | Gateway idempotency | 10.9 |
| 9 | Status monotonicity | 9.9 |
| 10 | Handoff silence | 20.5 |
| 11 | RAG citation grounding | 13.7 |
| 12 | Semantic-cache soundness | 16.8 |
| 13 | Circuit-breaker safety | 3.7 |
| 14 | Event-log append-only & monotonic versioning | 37.5 |
| 15 | PII never egresses raw to the LLM | 4.8 |
| 16 | Outbox exactly-once effect | 3.8 |
| 17 | Audit-log hash-chain integrity | 4.7, 35.3 |
| 18 | Saga atomicity (all-or-compensated) | 3.9, 18.7 |
| 19 | Fair scheduling / noisy-neighbor bound | 1.5 |
| 20 | Tenant-scoped retrieval isolation | 13.8 |
| 21 | Channel-mode capability gating | 7.7 |
| 22 | Exactly-one-driver routing | 6.5 |
| 23 | Per-session mode isolation & credential scoping | 6.6 |
| 24 | Anti-ban applies iff web-protocol mode | 8.7 |
| 25 | Group admin-op before bridge; welcome exactly once | 22.8 |
| 26 | Channel/group op unsupported by mode fails typed | 8.8, 23.7 |
| 27 | Canonical-host URL generation (no host-header injection) | 5.7 |
| 28 | Every listed feature production-complete (no stub) | 39.4 |
