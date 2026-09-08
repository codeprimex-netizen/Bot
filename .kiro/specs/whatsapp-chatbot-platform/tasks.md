# Implementation Plan — WhatsApp Chatbot Platform (Multi-Tenant SaaS)

This plan builds the multi-tenant SaaS platform on top of the existing `whatsapp-auto-messenger` single-tenant engine. It assumes the engine (sessions, messaging, anti-ban, groups, channels, extraction, errors) either exists or is built in parallel from its own spec. Tasks are ordered so each block is testable before the next depends on it. Every task references the requirements it satisfies.

> Convention: PBT = property-based test (Pest). `Fake*` doubles (`FakeBridgeClient`, `FakeLlmProvider`, `FakeGateway`) are bound in the container for feature tests.

---

## Phase 0 — Tenancy Foundation

- [ ] 0.1 Create `tenants`, `tenant_users`, `tenant_usage` migrations and Eloquent models with backed enums (`TenantStatus`, `QuotaKind`). _(Req A1)_
- [ ] 0.2 Implement `TenantContext` (current/set/actingAsPlatform/forget) with per-request resolution by panel session, subdomain, and API key. _(Req A1.1, A1.5)_
- [ ] 0.3 Implement `BelongsToTenant` trait: global `TenantScope` + auto-fill `tenant_id` on `creating`. Add the trait + `tenant_id` column + `idx(tenant_id, ...)` to all reused engine tables via migrations. _(Req A1.1, A1.2)_
- [ ] 0.4 Add `CrossTenantAccessException` and a defense-in-depth check on find-by-id paths. _(Req A1.3)_
- [ ] 0.5 Namespace WhatsApp auth-state, export, and media storage under `storage/tenants/{tenantId}/`. _(Req A1.4)_
- [ ] 0.6 **PBT:** seed 2 tenants; assert every read path returns disjoint rows (Correctness Property 1). _(Req A1)_

**Done when:** a record created in tenant A is invisible to tenant B on every read path, and the isolation PBT passes.

---

## Phase 1 — Plans, Quota & Feature Gating

- [ ] 1.1 Create `plans` migration/model (feature flags JSON, limits JSON per `QuotaKind`). _(Req D2.1)_
- [ ] 1.2 Implement `PlanGate::allows/authorize` and `FeatureNotInPlanException`. _(Req B2.3, C5.2, Req A/D feature gates)_
- [ ] 1.3 Implement `QuotaGuard::verdict/consume/remaining` with atomic `Cache::lock` updates on `tenant_usage`; period bucketing (monthly/daily). _(Req A3.4, A3.5)_
- [ ] 1.4 Add `QuotaExceededException` (defer semantics) and period-reset scheduled command. _(Req A3.4, NFR2.1)_
- [ ] 1.5 **PBT:** quota never negative, never double-counted under random retry interleavings (Correctness Property 4). _(Req A3.5)_

**Done when:** feature access and quotas are enforced from one place; over-quota sends defer instead of dropping.

---

## Phase 2 — Multi-Tenant Engine Integration

- [ ] 2.1 Extend the send pipeline with `tenantSendGate` (Algorithm 3): plan gate → monthly/daily quota → existing anti-ban gate; consume quota once after bridge confirm, keyed by idempotency key. _(Req A3, A4.3)_
- [ ] 2.2 Enforce per-tenant `SESSIONS` quota in `SessionManager::create`; map every session to one tenant. _(Req A2.1, A2.4)_
- [ ] 2.3 Verify anti-ban caps are the stricter of plan vs config; ensure no bypass parameter exists. _(Req A4.2, A4.3)_
- [ ] 2.4 Confirm opt-out enforcement runs per tenant inside `RecipientResolver` with no bypass. _(Req A6, C4.2, D5.3, Correctness Property 3)_
- [ ] 2.5 Scope groups/channels/extraction/export/errors services by tenant (global scope) and per-tenant file output + signed URLs. _(Req A5, A6, A7)_
- [ ] 2.6 **Feature test:** two tenants run campaigns concurrently; assert independent rate limits/quotas, no cross-tenant starvation, zero duplicate `wa_message_id`. _(Req A3, NFR1.2)_

**Done when:** all inherited core features work per tenant with quota + anti-ban + opt-out enforced.

---

## Phase 3 — Billing

- [ ] 3.1 Create `subscriptions`, `wallets`, `wallet_txns`, `invoices`, `coupons`, `payment_events` migrations/models with enums. _(Req D2.1)_
- [ ] 3.2 Implement `PaymentGateway` interface + `RazorpayGateway`, `StripeGateway`, `UpiLinkGateway`, `FakeGateway`. _(Req B6.4, D2.1, NFR4.2)_
- [ ] 3.3 Implement `SubscriptionService` (subscribe/changePlan+prorate/cancel/onGatewayEvent) idempotent on `gateway_event_id`. _(Req D2.2)_
- [ ] 3.4 Implement `WalletService` (balance/topUp/debit) with non-negative guarantee under `Cache::lock`; `InsufficientFundsException`. _(Req D2.3, Correctness Property 5)_
- [ ] 3.5 Implement `InvoiceService` (sequential number, PDF via dompdf) + revenue reports. _(Req C6.1, D2.1)_
- [ ] 3.6 Implement coupon redemption math and limits. _(Req D2.1)_
- [ ] 3.7 Gateway webhook controller with signature verification; idempotent processing. _(Req D2.2, NFR2.2)_
- [ ] 3.8 **PBT:** gateway idempotency (event applied once for N deliveries) + wallet non-negativity. _(Correctness Properties 5, 8)_

**Done when:** a fake gateway drives a full subscribe → renew → dunning lifecycle; wallet debits never go negative; duplicate webhooks are no-ops.

---

## Phase 4 — Conversational AI: Inbound & Resolution Pipeline

- [ ] 4.1 Create `chatbots`, `conversations`, `conversation_states`, `messages_inbound`, `handoff_events` migrations/models with enums (`ConversationMode`). _(Req B1, B8)_
- [ ] 4.2 Extend inbound-message webhook: HMAC verify → resolve tenant/session/conversation → dispatch `HandleInboundMessageJob` (idempotent on `wa_message_id`) on the `ai-reply` lane. _(Req B1.1, B1.4, NFR1.1)_
- [ ] 4.3 Implement `ConversationEngine::handle` + `ResolverStage` contract and the ordered pipeline (Algorithm 1) with single-reply guarantee and suspended-tenant halt. _(Req B1.2, B1.3, B2.1, B2.2)_
- [ ] 4.4 Implement `OptOutStage` (STOP → record + confirm + halt), `LiveAgentStage`, `BusinessHoursStage`, `FallbackStage`, each plan-gated/skippable. _(Req B2.4, B6.1, B8.2)_
- [ ] 4.5 **PBT:** single-reply-per-inbound and opt-out-absolute over random stage configs (Correctness Properties 2, 3). _(Req B1.2, B2.4)_

**Done when:** an inbound message produces at most one reply, opt-out always wins, suspended tenants get no auto-reply.

---

## Phase 5 — Keyword, Intent/FAQ & LLM Stages

- [ ] 5.1 Create `keyword_triggers`, `intents`, `knowledge_base` migrations/models. _(Req B3)_
- [ ] 5.2 Implement `KeywordTriggerStage` (EXACT/CONTAINS/REGEX, priority-ordered, highest-priority wins). _(Req B3.1, B3.3)_
- [ ] 5.3 Implement `IntentFaqStage` (rule-based intent match → FAQ answer). _(Req B3.2)_
- [ ] 5.4 Implement `LlmProvider` interface + `OpenAiProvider`, `GeminiProvider`, `FakeLlmProvider` (reply/detectIntent/detectLanguage/sentiment). _(Req B4, B7.2, B7.3, NFR4.2)_
- [ ] 5.5 Implement `LlmReplyStage`: gated on AI-enabled + `AI_CREDITS`; low-confidence/handoff-intent → escalate; provider error → skip gracefully; consume AI credits on success. _(Req B4.1–B4.4)_
- [ ] 5.6 Multi-language reply (detect + reply in language) and per-message sentiment stored with rolling average. _(Req B7.2, B7.3)_
- [ ] 5.7 **Feature test:** with `FakeLlmProvider`, verify credit exhaustion skips LLM, low confidence escalates, provider error does not crash the job. _(Req B4.2–B4.4)_

**Done when:** keyword/FAQ/LLM stages resolve in order; AI is safely gated, metered, and swappable.

---

## Phase 6 — Visual Flow Builder & Runtime

- [ ] 6.1 Create `flows` migration/model (graph JSON, versions, status) with `FlowNodeType` enum. _(Req B5.1)_
- [ ] 6.2 Implement `FlowValidator::validate` (Algorithm 4): one entry, reachability, default edges, termination; block publish on invalid. _(Req B5.2, Correctness Property 6)_
- [ ] 6.3 Implement `FlowRuntime` (start/resume/evaluateNode, Algorithm 2) for all node types; persist current node + variables in `conversation_states`. _(Req B5.1, B5.3, B5.4)_
- [ ] 6.4 Implement `ActiveFlowStage` (resume mid-flow at correct priority). _(Req B2.1)_
- [ ] 6.5 Implement `action` node executor as retry-safe queued work (webhook / CRM / Google Sheets). _(Req B7.1)_
- [ ] 6.6 Build the Livewire + Alpine drag-and-drop canvas producing the graph JSON with live validation feedback. _(Req B5.1, B5.2)_
- [ ] 6.7 **PBT:** flow termination over randomly generated valid graphs (Correctness Property 6). _(Req B5.4)_

**Done when:** a tenant builds, validates, publishes, and runs a flow; broken flows cannot be published; every traversal terminates.

---

## Phase 7 — Lead Capture, Orders/Booking & Payments in Chat

- [ ] 7.1 Create `leads`, `lead_forms`, `orders`, `catalog_items` migrations/models. _(Req B6.2, B6.3)_
- [ ] 7.2 Implement lead-capture form nodes writing to `leads`. _(Req B6.2)_
- [ ] 7.3 Implement order/booking bot (catalog browse → cart → order). _(Req B6.3)_
- [ ] 7.4 Wire order payment to `PaymentGateway::createPaymentLink`; include link in reply; reconcile on gateway webhook. _(Req B6.4, D2.2)_
- [ ] 7.5 **Feature test:** end-to-end order → payment link → fake gateway paid webhook → order marked PAID exactly once. _(Req B6.3, B6.4)_

**Done when:** a chat can capture a lead and complete a paid order idempotently.

---

## Phase 8 — Human Handoff / Live Agent

- [ ] 8.1 Implement `HandoffService` (request/assign/release/agentSend) with mode transitions + `handoff_events`. _(Req B8.1, B8.4)_
- [ ] 8.2 Enforce agent-only silence: bot enqueues zero replies while `mode = AGENT`. _(Req B8.2, Correctness Property 10)_
- [ ] 8.3 Route agent messages through the anti-ban/plan-gated send pipeline (no exemption). _(Req B8.3)_
- [ ] 8.4 Build the Live Inbox Livewire component (`wire:poll`), assignment, SLA/unassigned-timeout auto-escalation. _(Req B8.1)_
- [ ] 8.5 **Feature test:** escalate → agent takes over (bot silent) → agent replies (rate-limited) → release → bot resumes. _(Req B8)_

**Done when:** conversations move BOT ↔ AGENT cleanly with bot silence enforced and agent sends still throttled.

---

## Phase 9 — User Panel

- [ ] 9.1 Registration/login (email + phone OTP), profile, 2FA. _(Req C1)_
- [ ] 9.2 Subscription/plan view, wallet/credit balance, billing history & invoices. _(Req C1.2, C6.1)_
- [ ] 9.3 WhatsApp connection self-service (QR/pairing, status, reconnect/disconnect) with `SESSIONS` quota. _(Req C2)_
- [ ] 9.4 Messaging within quota: single, bulk campaign, schedule, media, templates, delivery status. _(Req C3)_
- [ ] 9.5 Contacts & groups self-service: manage, import CSV/vCard, own-group extraction, export; opt-out enforced. _(Req C4)_
- [ ] 9.6 Chatbot self-service surfaces (flows, keyword/FAQ, AI toggle, away/hours) with plan feature gates. _(Req C5)_
- [ ] 9.7 Reports (campaign analytics, error logs), notifications inbox, support ticket/help center. _(Req C6)_
- [ ] 9.8 **HTTP/Livewire tests:** every User Panel route is tenant-scoped and RBAC-enforced. _(Req C1–C6)_

**Done when:** a tenant user can self-serve the full workflow within plan limits.

---

## Phase 10 — Admin Panel (Platform Super-Admin)

- [ ] 10.1 Distinct `platform-admin` guard + `actingAsPlatform()` mode; reuse forced-strong-password, IP allowlist, login throttle/lockout, idle timeout, login audit. _(Req D1.3, NFR3)_
- [ ] 10.2 User/tenant management (CRUD/suspend), RBAC role assignment, impersonate/login-as (banner + destructive-op block + audit). _(Req D1.1, D1.2, D1.4)_
- [ ] 10.3 Plans & pricing management, feature limits per plan, payment gateway config, wallet/credit top-ups, coupons, invoices & revenue reports. _(Req D2.1)_
- [ ] 10.4 Platform control: global session/campaign/queue monitors, global anti-ban/rate-limit floor config, broadcast announcements, `feature_flags`, `platform_settings` (SMTP/API/LLM/gateway keys, secret-redacted). _(Req D3)_
- [ ] 10.5 Monitoring & health: health dashboard (server/bridge/queue), global error center & dedup alerts, per-user + platform usage analytics, Prometheus `/metrics`. _(Req D4)_
- [ ] 10.6 Cross-tenant analytics via pre-aggregated snapshots / read replica. _(Req D4.3, NFR1)_
- [ ] 10.7 Content & compliance: global templates library, opt-out/blocklist management, data retention & deletion (cancellation/right-to-delete purge jobs), ToS enforcement. _(Req D5)_
- [ ] 10.8 Support: ticket management, KB/FAQ manager, segment notifications. _(Req D6)_
- [ ] 10.9 **HTTP tests:** platform-admin routes require the platform guard + IP allowlist; global anti-ban floor cannot be loosened by a tenant plan. _(Req D1.3, D3.2)_

**Done when:** the super-admin can operate the whole platform, with all actions audited and global floors enforced.

---

## Phase 11 — Hardening, Load & Release

- [ ] 11.1 Complete PBT suite for Correctness Properties 1–10; ensure all pass. _(All)_
- [ ] 11.2 Load test: N tenants running campaigns concurrently — per-tenant rate limits/quotas hold, fair lane scheduling (no starvation), zero duplicate `wa_message_id`, `jobs` drains. _(NFR1)_
- [ ] 11.3 Security pass: tenant-isolation on every path, per-tenant file access, impersonation audit, gateway/LLM secret redaction, PCI-minimal payments, webhook signature verification. _(NFR3)_
- [ ] 11.4 Supervisor config: worker per lane incl. `ai-reply`; single cron `schedule:run`; document Redis upgrade path. _(NFR1.1, NFR1.3)_
- [ ] 11.5 Docs: tenant onboarding, plan/quota model, chatbot builder guide, gateway/LLM setup, admin runbook; trace all requirements. _(All)_
- [ ] 11.6 Static analysis (Pint + PHPStan) + `composer audit` green; tag release.

**Done when:** CI green, load test passes, all requirements traced, docs complete.

---

## Task → Requirement Coverage Summary

| Phase | Focus | Requirements |
|---|---|---|
| 0 | Tenancy foundation | A1 |
| 1 | Plans, quota, gating | A3.4–5, D2, feature gates |
| 2 | Engine integration | A2–A7 |
| 3 | Billing | D2, B6.4 |
| 4 | Inbound & pipeline | B1, B2 |
| 5 | Keyword/FAQ/LLM | B3, B4, B7.2–3 |
| 6 | Flow builder | B5, B7.1 |
| 7 | Lead/order/payments | B6 |
| 8 | Human handoff | B8 |
| 9 | User Panel | C1–C6 |
| 10 | Admin Panel | D1–D6 |
| 11 | Hardening & release | NFR1–4, all |
