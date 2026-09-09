# Requirements Document

_WhatsApp Chatbot Platform (Multi-Tenant SaaS)_

## Introduction

This document defines the requirements for a **multi-tenant SaaS WhatsApp chatbot platform** built on the proven Laravel 12 / PHP 8.3 / MySQL 8 stack and the thin Node + Baileys "WA Bridge" architecture established by the `whatsapp-auto-messenger` spec. These requirements are the **complete, authoritative derivation** of the upgraded design document (`design.md`) in this same spec, following the Design-First workflow. Every capability in the design — including the net-new **Channel Mode** and **Base URL** subsystems, the deepened **AI/LLM engine**, the fully itemized **Group** and **Channel** management, the full **User Panel (28 features)** and **Admin Panel (28 features)**, and all advanced non-functional areas — has a corresponding, EARS-compliant requirement here with 1:1 traceability.

The platform preserves all core messaging, group, channel, extraction, and anti-ban capabilities of the single-tenant engine, and adds net-new subsystems: **multi-tenancy + billing**, a **production-grade conversational AI/chatbot engine**, a **pluggable per-session messaging backend (Channel Mode)**, a **configurable Base URL / deployment domain**, a **User Panel** (tenant self-service), and an **Admin Panel** (platform super-admin), together with cross-cutting scalability, reliability, security, observability, data-architecture, and tenancy-tier guarantees.

**Requirements are grouped into blocks:**
- **Block A — Core Engine (multi-tenant)** — A1–A9: the inherited core features (now tenant-scoped), plus net-new Channel Mode (A8) and Base URL (A9).
- **Block B — Conversational AI** — B1–B8: the chatbot/AI features, with a deepened B4 (RAG/memory/guardrails/routing/resilience) and B7 (agents/tools/A-B/rich/STT/drip).
- **Block C — User Panel** — C1–C6: all 28 tenant self-service features.
- **Block D — Admin Panel** — D1–D6: all 28 platform super-admin features.
- **Non-Functional Requirements** — NFR1–NFR7: scalability, reliability, security, maintainability, observability, data architecture, and Definition of Done.

> ⚠️ **Compliance (non-bypassable).** Opt-out enforcement and anti-ban rate limits are hard-enforced **per tenant** with **no bypass parameter**. On web-protocol modes (`BAILEYS`, `ON_PREMISE`) the anti-ban warm-up/rate gate is mandatory; on official modes (`CLOUD_API`, `BSP_GATEWAY`) the provider's template / 24-hour-window / rate rules are enforced instead. Bulk/unsolicited messaging violates WhatsApp ToS; this platform is intended for opt-in audiences, owned groups/channels, and consented contacts.

## Glossary

- **Tenant** — an isolated customer account with its own numbers, contacts, campaigns, chatbots, plan, quota, and wallet.
- **Platform_Super_Admin** — the owner of the SaaS platform, operating above all tenants via the Admin Panel in an audited `actingAsPlatform()` context.
- **Plan** — a subscription tier defining feature flags and quota limits.
- **Quota** — a metered, period-bucketed limit (`MESSAGES_MONTHLY`, `MESSAGES_DAILY`, `SESSIONS`, `CONTACTS`, `AI_CREDITS`, `CAMPAIGNS_CONCURRENT`).
- **Flow** — a no-code conversational graph (nodes + edges) authored in the visual builder and interpreted at runtime.
- **Conversation** — a stateful thread between a tenant's WhatsApp number and one contact, in mode `BOT`, `HANDOFF_REQUESTED`, or `AGENT`.
- **Channel_Mode** — the per-session messaging backend: `BAILEYS` (default), `CLOUD_API`, `ON_PREMISE`, or `BSP_GATEWAY`.
- **Channel_Driver** — the interface (generalizing `BridgeClient`) that every messaging backend implements; a `ChannelRouter` maps `session.channel_mode → ChannelDriver`.
- **Channel_Capability** — a discrete operation class (e.g. `GROUPS`, `TEMPLATE`, `EXTRACTION`, `CHANNELS`) a driver may or may not `supports()`.
- **Base_URL** — the configurable canonical deployment origin from which all absolute links, webhook callbacks, and signed URLs are built.
- **Correctness_Property** — a universally quantified invariant (Properties 1–28 in the design) that the test suite must hold.

---

## Requirements

_The requirements below are organized into four blocks (A–D) plus a Non-Functional group. The original block IDs (A1–A9, B1–B8, C1–C6, D1–D6, NFR1–NFR7) are retained as a bold tag on each requirement so the design document and traceability tables continue to reference them._

**Block A — Core Engine (Multi-Tenant)**

### Requirement 1: Tenant isolation, tiers & lifecycle (A1)

**User Story:** As a platform operator, I want every tenant's data strictly isolated, tier-aware, and lifecycle-managed, so that no tenant can ever read or affect another tenant's data and tenants can scale or be offboarded cleanly.

#### Acceptance Criteria
1. WHEN any domain record is created THEN THE system SHALL stamp it with the acting tenant's `tenant_id`.
2. WHEN any query runs in a tenant context THEN THE system SHALL return only rows belonging to that tenant via a global scope.
3. IF a request attempts to access a record belonging to another tenant THEN THE system SHALL deny it with a `CrossTenantAccessException` (403).
4. WHERE files are stored (WhatsApp auth state, exports, media) THE system SHALL namespace them under a per-tenant path prefix `storage/tenants/{tenantId}/`.
5. WHEN a Platform_Super_Admin acts in platform mode THEN THE system SHALL bypass the tenant scope only for that audited `actingAsPlatform()` context.
6. THE system SHALL support tenant tiers `SHARED`, `DEDICATED_WORKER`, and `DEDICATED_DB` resolved by a `TierResolver`, so isolation level is a configuration flip rather than a code change.
7. WHILE multiple tenants have pending work THE system SHALL dispatch queued work by weighted fair scheduling using each tenant's `lane_weight`, so no single tenant can starve others beyond a bounded share.
8. WHEN a tenant is provisioned THEN THE system SHALL create its tenant record, wallet, default chatbot, per-tenant data-encryption key, storage prefix, and seed plan atomically.

### Requirement 2: Multi-tenant session & connection (A2)

**User Story:** As a tenant, I want to connect and manage my own WhatsApp numbers, so that my messaging runs on numbers I own.

#### Acceptance Criteria
1. WHEN a tenant connects a number THEN THE system SHALL create a session mapped to exactly one `tenant_id`.
2. WHEN a tenant lists sessions THEN THE system SHALL show only that tenant's sessions and their live status.
3. IF a session drops THEN THE system SHALL auto-reconnect using the existing reconnect policy without affecting other tenants' sessions.
4. IF a tenant exceeds its plan's `SESSIONS` quota THEN THE system SHALL prevent creating additional sessions and report the limit.
5. WHERE the Bridge or a channel driver is called THE system SHALL only reference session IDs owned by the requesting tenant.
6. WHEN a session is created THEN THE system SHALL record its `channel_mode` (defaulting to `BAILEYS`) so every session declares exactly one messaging backend.

### Requirement 3: Multi-tenant messaging engine (A3)

**User Story:** As a tenant, I want single, dual, bulk, scheduled, and media messaging, so that I can run campaigns within my plan.

#### Acceptance Criteria
1. WHEN a tenant sends any message THEN THE system SHALL enqueue it through the durable queue and the plan-gated, quota-metered, mode-aware send pipeline.
2. THE system SHALL support single mode, dual mode, auto bulk, bulk media, scheduled (once + recurring), and versioned templates, all scoped to the tenant.
3. WHEN a message is sent THEN THE system SHALL track delivery status (sent/delivered/read) and SHALL NOT downgrade status on out-of-order acknowledgements.
4. IF a tenant's `MESSAGES_MONTHLY` or `MESSAGES_DAILY` quota is exhausted THEN THE system SHALL defer or block the send (never drop it) and notify the tenant.
5. WHEN a send job retries THEN THE system SHALL consume message quota at most once per confirmed send, keyed by the message idempotency key.

### Requirement 4: Anti-ban & safety (per tenant) (A4)

**User Story:** As a platform operator, I want anti-ban protections enforced per tenant, so that tenants cannot get their numbers (or the platform) banned.

#### Acceptance Criteria
1. THE system SHALL apply gaussian delay, typing simulation, warm-up ramp, quiet hours, spintax, batch cool-down, and risk scoring per tenant session on web-protocol modes.
2. THE system SHALL NOT expose any runtime parameter that bypasses warm-up caps or rate limits on a web-protocol mode.
3. WHEN a tenant plan defines a rate limit stricter than the config cap THEN THE system SHALL apply the stricter of the two.
4. IF a session's failure rate crosses the risk threshold THEN THE system SHALL throttle the session and alert the tenant.

### Requirement 5: Group management (full lifecycle, per tenant) (A5)

**User Story:** As a tenant, I want full WhatsApp group control, so that I can manage every aspect of my owned groups without any operation being a stub.

#### Acceptance Criteria
1. THE system SHALL support group create, delete (leave + tombstone), and metadata update (name/description/icon), scoped to the tenant.
2. IF the bot is not a group admin for an admin-requiring operation (add/remove/promote/demote/settings/tag) THEN THE system SHALL reject the operation with a `NotGroupAdminException` before any bridge mutation is issued.
3. WHEN a member joins THEN THE system SHALL send the configured welcome exactly once per join, and a rejoin with the same `join_epoch` SHALL be a no-op (duplicate-guarded against rejoin spam).
4. THE system SHALL support invite-link operations: get the current link, revoke/rotate to a new code, and join via a link code.
5. WHEN a participant add/remove/promote/demote operation runs THEN THE system SHALL return a per-target normalized status of `ADDED`, `INVITE_SENT`, `ALREADY_MEMBER`, or `FAILED`, mapped deterministically from the WhatsApp response codes.
6. WHEN a group setting (announcement, locked, ephemeral, approval-mode) is changed THEN THE system SHALL require bot-admin and SHALL write an audit record of `{group, field, from, to, actor}`.
7. WHEN a join request is pending THEN THE system SHALL surface it in a tenant inbox and SHALL evaluate auto-approval rules in the fixed first-decisive order blocklist -> country-code -> regex -> manual.
8. WHEN member reconciliation runs THEN THE system SHALL pull the bridge ground-truth participant list, update the database, and cache the bot-admin status.
9. WHERE a bulk participant add is requested THE system SHALL split the target list into chunked, retry-safe queued jobs (default 50 per chunk), anti-ban paced.
10. THE system SHALL support welcome delivery as per-member or combined, with media welcome and a dynamic card that falls back to text when the session/mode cannot render the card, and SHALL rotate welcome template variants (sticky A/B when enabled).
11. THE system SHALL support member extraction via a streaming generator with temp-table de-duplication on normalized JID and active-number filtering, requiring the `Extraction` capability and bot-admin.
12. WHEN group data is exported THEN THE system SHALL stream to CSV/TXT/JSON/XLSX/vCard under the tenant storage prefix and serve it via a signed, expiring URL.
13. THE system SHALL support tag-all, selective, and custom tagging using hidden mentions, split into 200-mentions-per-chunk messages, applying a per-group cooldown, and admin-only guarded.
14. IF the bridge is unreachable or returns a transport error during a group operation THEN THE system SHALL fail the operation with a retryable error, leave group state unchanged, and release the job for retry rather than recording a partial result.
15. WHILE a mutating group operation is in progress for a given group THE system SHALL serialize concurrent mutating operations on that same group so overlapping admin actions cannot interleave into an inconsistent participant state.
16. WHEN the same participant-mutation request is retried with the same idempotency key THEN THE system SHALL apply it at most once and return the original per-target status without re-issuing the bridge mutation.

### Requirement 6: Channel (newsletter) management (full lifecycle, per tenant) (A6)

**User Story:** As a tenant, I want full WhatsApp Channel/newsletter control and audience extraction/export, so that I can grow and measure my channels.

#### Acceptance Criteria
1. THE system SHALL support channel create, delete, and metadata update (name/description/picture), scoped to the tenant.
2. THE system SHALL support channel subscriber listing (streamed), admin add/remove, and follow/mute operations.
3. THE system SHALL support channel posting of text, media, and poll content, plus scheduled and recurring posts and a content-calendar view over a date range.
4. WHEN channel analytics are requested THEN THE system SHALL report subscribers, reach, and engagement computed as deltas between stored periodic snapshots rather than trusting a single instantaneous read.
5. WHERE a channel operation is requested on an official-mode session (`CLOUD_API`/`ON_PREMISE`/`BSP_GATEWAY`) THE system SHALL reject it with a `ModeCapabilityException` **before** any driver call (channels are a Baileys-only capability), never crashing and never a silent no-op.
6. THE system SHALL support group member extraction, active-number filtering, and export to CSV/TXT/JSON/XLSX/vCard, streamed for large data, scoped to the tenant.
7. WHEN an export is produced THEN THE system SHALL store it under the tenant path prefix and serve it via a signed, expiring URL built from the canonical Base_URL.

### Requirement 7: Error reporting & monitoring (per tenant) (A7)

**User Story:** As a tenant, I want an error dashboard and retries, so that I can recover failed messages.

#### Acceptance Criteria
1. THE system SHALL provide a tenant-scoped error dashboard, failed-message retry, real-time notifications, and error export.
2. WHEN a failed message is manually retried THEN THE system SHALL update the original message row without creating a duplicate.
3. WHEN phone numbers appear in logs THEN THE system SHALL redact them, and THE system SHALL NOT log message bodies (only content hashes).

### Requirement 8: Channel Mode / pluggable messaging backends (A8)

**User Story:** As a tenant, I want to choose per connected number how it talks to WhatsApp (unofficial Baileys, official Cloud API, legacy On-Premise, or a BSP/gateway partner), so that I can trade ban-risk against feature set without changing my workflow.

#### Acceptance Criteria
1. THE system SHALL support a per-tenant, per-session selectable `channel_mode` with exactly one value from the set {`BAILEYS`, `CLOUD_API`, `ON_PREMISE`, `BSP_GATEWAY`}, defaulting to `BAILEYS`, behind one `ChannelDriver` interface, and THE system SHALL reject any create or update request whose `channel_mode` is outside this set with a validation error identifying the invalid value.
2. WHEN a session is created or its `channel_mode` is changed THEN THE system SHALL obtain that mode's declared capability set by invoking the driver's `supports()` handshake exactly once and SHALL persist the resulting `Channel_Capability` set as the session's authoritative capability list used for all subsequent gating.
3. IF an operation requires a `Channel_Capability` not present in the session's persisted capability set THEN THE system SHALL reject the operation with a typed `ModeCapabilityException` before any driver call is made, SHALL perform no side effect, and SHALL surface an error indicating the unsupported capability.
4. WHEN an outbound message is dispatched THEN THE system SHALL route it to exactly the one driver whose `mode()` equals the session's `channel_mode`, and WHEN an inbound webhook is received for that session THEN THE system SHALL parse it via that same driver.
5. THE system SHALL store each mode's credentials and config per tenant, envelope-encrypted at rest, secret-redacted in all UI and log output, and SHALL hold decrypted credential values only within the lifetime of the request or job that uses them, discarding them before that request or job returns.
6. WHEN a tenant rotates or replaces the credentials for a session's mode THEN THE system SHALL validate the new credentials with the driver before activating them, SHALL apply new sends using the new credentials without dropping or duplicating already-acknowledged messages, and IF the new credentials fail validation THEN THE system SHALL retain the previous working credentials and raise a `ChannelCredentialException`.
7. WHEN a tenant changes the `channel_mode` of a session that has in-flight or queued messages THEN THE system SHALL stop admitting new sends to the old driver, drain or explicitly fail the queued sends on the old driver, re-run the `supports()` handshake for the new mode, re-evaluate the applicable anti-ban vs official-provider gate for the new mode, and audit the mode-switch event with old mode, new mode, and drained/failed counts.
8. WHERE a session's mode is a web-protocol mode (`BAILEYS`/`ON_PREMISE`) THE system SHALL apply the anti-ban warm-up/rate/quiet-hours gate with no configuration able to disable it, and WHERE the mode is an official mode (`CLOUD_API`/`BSP_GATEWAY`) THE system SHALL instead enforce the provider's template / 24-hour-window / rate rules.
9. WHERE a session's mode is `ON_PREMISE` THE system SHALL mark the mode deprecated in UI and expose a documented migration path to `CLOUD_API`.
10. WHERE a tenant configures multi-mode failover THE system SHALL advance to the next driver in the ordered failover chain when the primary driver's circuit breaker is OPEN or a send fails with a retryable error after exhausting its configured retry attempts, SHALL re-enable the anti-ban gate when falling back to a web-protocol mode, and SHALL audit each failover event with source mode, target mode, and trigger reason.
11. WHERE multi-mode failover is configured THE system SHALL attempt each distinct driver in the failover chain at most once per message dispatch, SHALL never route back to a mode already attempted for that dispatch, and IF every mode in the chain has been attempted and failed THEN THE system SHALL stop failing over, mark the message failed with an error indicating all modes exhausted, and audit the exhaustion.
12. WHEN a message is sent on an official mode outside the 24-hour session window without an approved template THEN THE system SHALL block the send with a `TemplateRequiredException`, perform no outbound call, and surface a prompt to the tenant to select an approved template.
13. IF per-mode credentials are missing or rejected by the driver THEN THE system SHALL raise a `ChannelCredentialException`, prevent selecting or sending on that mode, and keep the platform operating on the `BAILEYS` default session behavior.

### Requirement 9: Base URL / deployment domain configuration (A9)

**User Story:** As a platform operator, I want a single configurable canonical deployment domain, so that every absolute link, webhook callback, and signed URL is built safely from a trusted origin.

#### Acceptance Criteria
1. THE system SHALL build every absolute link, webhook callback, signed/expiring URL, and OAuth/OTP redirect from the configured canonical base, and SHALL derive the URL host solely from the resolved canonical base and never from the request `Host` or `X-Forwarded-Host` header.
2. WHEN a session is created or registered for any `channel_mode` THEN THE system SHALL register the webhook callback URL (Bridge/Cloud API/On-Premise/BSP/payment gateways) from the canonical base over HTTPS and SHALL store its `route_key` so inbound webhooks map back to exactly one `(tenant, session, driver)` tuple.
3. WHEN resolving the canonical base for an incoming request THEN THE system SHALL select a tenant by its subdomain (`tenant.{slug}.{apex}`) or, where present, by a verified per-tenant custom domain, applying the precedence per-tenant custom domain -> `platform_settings['base_url']` -> `config('app.url')`, using the first non-empty value in that order.
4. WHILE serving any request THE system SHALL accept only hosts on the allowlist (platform apex plus verified tenant subdomains and verified custom domains) and SHALL bind the resolved canonical host into every signed URL's signature.
5. IF a request arrives with a host that is not on the allowlist THEN THE system SHALL reject the request without executing the requested action and SHALL return a response indicating the host is not recognized.
6. IF a signed URL is presented with an expiry older than the current time, with a validity window exceeding 3600 seconds, or with a host component not matching the host bound into its signature THEN THE system SHALL reject the request without executing the requested action, return a response indicating the signed URL is invalid or expired, and retain any pending state unchanged.
7. WHERE a tenant configures a custom domain THE system SHALL mark the domain unverified until an ownership-verification challenge succeeds and a valid TLS certificate covering that domain is confirmed, SHALL exclude unverified domains from the accepted-host allowlist, and IF a requested subdomain or custom domain collides with the platform apex, a reserved name, or an already-assigned tenant host THEN THE system SHALL reject the assignment and return a response indicating the host is already in use or reserved.

---

**Block B — Conversational AI**

### Requirement 10: Inbound handling & single-reply guarantee (B1)

**User Story:** As a tenant, I want inbound messages processed by my chatbot, so that customers get automatic responses.

#### Acceptance Criteria
1. WHEN an inbound message webhook is received THEN THE system SHALL verify its signature via the session's channel driver, resolve the tenant and session, and enqueue processing on the `ai-reply` lane.
2. WHEN the conversation engine processes an inbound message THEN THE system SHALL enqueue at most one outbound reply for that message.
3. IF the tenant is suspended THEN THE system SHALL log the inbound message and SHALL NOT send any automatic reply.
4. WHEN a redelivered webhook with the same `wa_message_id` arrives THEN THE system SHALL process it idempotently with no duplicate reply.

### Requirement 11: Resolution pipeline & priority order (B2)

**User Story:** As a tenant, I want predictable bot behavior, so that the most specific rule wins consistently.

#### Acceptance Criteria
1. THE system SHALL evaluate resolution stages in this fixed order: opt-out → live-agent → business-hours/away → active-flow → keyword → intent/FAQ → LLM smart reply → fallback.
2. WHEN a stage returns HANDLED or HALT THEN THE system SHALL stop and SHALL NOT evaluate later stages.
3. IF a stage's feature is not in the tenant's plan THEN THE system SHALL skip that stage.
4. WHEN an opt-out keyword (e.g., STOP) is detected THEN THE system SHALL record the opt-out, send confirmation, and halt with no further bot reply.

### Requirement 12: Auto-reply, keyword triggers, intent, FAQ & rich messages (B3)

**User Story:** As a tenant, I want keyword and FAQ auto-replies and rich interactive messages, so that common questions are answered instantly and richly.

#### Acceptance Criteria
1. THE system SHALL support auto-reply rules with keyword triggers matched by EXACT, CONTAINS, or REGEX, ordered by tenant-defined priority.
2. THE system SHALL support intent detection and an FAQ bot that returns a configured answer for a matched intent.
3. WHEN multiple keyword triggers match THEN THE system SHALL apply the highest-priority one only.
4. WHERE a session/mode cannot render a rich interactive message (reply buttons, list message, product/catalog) THE system SHALL degrade it to an equivalent numbered-text menu so the flow works everywhere.

### Requirement 13: LLM / AI smart replies (RAG, memory, guardrails, routing, resilience) (B4)

**User Story:** As a tenant, I want grounded, safe, cost-efficient AI-generated replies, so that customers get natural, accurate answers when no rule matches without leaking data or crashing on provider outages.

#### Acceptance Criteria
1. WHERE the tenant has AI enabled and no earlier pipeline stage matched, WHEN an inbound message reaches the LLM smart-reply stage, THE system SHALL request a smart reply from the configured primary LLM provider using the assembled conversation context (system prompt, rolling summary, up to the last 8 verbatim turns, and top-n=5 retrieved knowledge-base chunks) within the per-request token budget.
2. IF the LLM-returned confidence score is below 0.55, OR the classified intent is an explicit "talk to human" handoff intent, THEN THE system SHALL escalate to human handoff and SHALL NOT send an AI reply to the customer.
3. WHILE the LLM-returned confidence score is between 0.55 and 0.70 inclusive AND the answer is grounded in at least one retrieved chunk, THE system SHALL send the reply appended with a hedging disclaimer and an explicit "type AGENT for a human" instruction.
4. IF the tenant's `AI_CREDITS` quota is zero THEN THE system SHALL skip the LLM stage and fall through to the default fallback stage without calling any LLM or embedding provider.
5. IF the LLM provider returns an error or does not respond within the configured request timeout THEN THE system SHALL skip the LLM stage, record the failure against the provider circuit breaker, and SHALL NOT fail or crash the processing job.
6. WHEN the RAG path produces an answer THEN THE system SHALL require the model to answer only from the retrieved chunks, emit a `[[source:<kb_chunk_id>]]` citation marker for each cited chunk, and IF a factual claim's citation id is not present in the retrieved set THEN THE system SHALL drop the unsupported sentence or, if no grounded content remains, escalate to human handoff.
7. THE system SHALL maintain two-tier conversation memory (up to the last 8 verbatim turns plus a rolling summary and optional episodic recall) within the per-request token budget, and WHEN the verbatim-turn token count exceeds the working-memory window budget THE system SHALL summarize and evict the oldest turns into the rolling summary such that every evicted turn is represented in the summary and no turn is both dropped and unsummarized.
8. THE system SHALL classify inbound text against prompt-injection patterns, fence untrusted user content in a delimited data block, and validate model output against policy, and IF a jailbreak or prompt-injection attempt is detected THEN THE system SHALL suppress the reply and record an entry in the abuse-events store.
9. WHEN the engine emits structured output for flows or tools THEN THE system SHALL validate it against the applicable JSON Schema, and IF the output is invalid THEN THE system SHALL perform exactly one repair retry and, if still invalid, fall back to a rule-based parse.
10. THE system SHALL route requests to the cheap/fast model tier first and SHALL escalate to the stronger model tier only when confidence is below 0.55, RAG grounding is empty, or the structured-output repair retry fails.
11. WHERE a semantic-cache entry matches the normalized and embedded query with cosine similarity at or above 0.95 for the same tenant, chatbot, knowledge-base version, and prompt-template version, THE system SHALL return the cached reply and skip the LLM call.
12. WHEN the tenant's knowledge base is re-ingested or the prompt-template version is incremented THEN THE system SHALL invalidate all affected semantic-cache entries so no cache hit is served against a stale knowledge-base or template version.
13. IF the primary LLM provider's circuit breaker is OPEN or the primary call fails THEN THE system SHALL follow the fallback chain (secondary provider, then local heuristic FAQ/templated fallback) guarded by a per-provider and per-tenant circuit breaker that opens after at least 5 failures within a 30-second window or an error rate above 50% over the last 20 calls, remains OPEN for 30 seconds, and admits 3 probe calls in HALF_OPEN.
14. IF the embedding provider is unavailable or errors during query-time retrieval THEN THE system SHALL fall back to lexical MySQL FULLTEXT-only retrieval, emit a `rag.degraded` metric, and continue producing a grounded reply from the available chunks without failing the job.
15. IF the vector store is unavailable THEN THE system SHALL fall back to MySQL FULLTEXT-only retrieval for the request, emit a `rag.degraded` metric, and SHALL not block or crash the smart-reply stage.
16. IF the assembled context exceeds the per-request token budget after summarization and RAG trimming THEN THE system SHALL trim retrieved chunks by descending relevance score and trim the oldest verbatim turns until the context fits within the budget reserved for the completion, without dropping the system prompt or guardrails.

### Requirement 14: Visual chatbot / flow builder (B5)

**User Story:** As a tenant, I want a no-code drag-and-drop flow builder, so that I can design conversation flows without coding.

#### Acceptance Criteria
1. THE system SHALL let a tenant author a flow as a node/edge graph with node types message, question, condition, menu, action, handoff, and end.
2. WHEN a tenant attempts to publish a flow THEN THE system SHALL validate it (exactly one entry node, no unreachable or dangling nodes, branch nodes have a default edge, every path reaches a terminal node) and SHALL reject publishing if invalid.
3. WHILE a contact is mid-flow THE system SHALL persist the current node and captured variables in conversation state so runtime is stateless.
4. WHEN a flow traversal executes THEN THE system SHALL reach an end or handoff node in finite steps.

### Requirement 15: Business hours, lead capture, orders, payments, sagas & drip sequences (B6)

**User Story:** As a tenant, I want business-hours handling, lead forms, an order/booking bot with payments, atomic multi-step order flows, and re-engagement sequences, so that I can capture and convert customers reliably.

#### Acceptance Criteria
1. WHERE the current time is outside configured business hours THE system SHALL send the away message and optionally suppress further bot stages.
2. THE system SHALL support lead-capture forms whose submitted fields are stored as leads.
3. THE system SHALL support an order/booking bot with a catalog and cart that produces an order.
4. WHEN an order requires payment THEN THE system SHALL generate a payment link/UPI via the configured gateway (built from the canonical Base_URL) and include it in the reply.
5. WHEN a multi-step order flow (reserve → payment → fulfilment) is executed THEN THE system SHALL run it as a saga such that either all forward steps complete or every completed step is compensated in reverse order, leaving no partial side effect, with idempotent compensations.
6. THE system SHALL support scheduled drip and re-engagement sequences triggered by conversation state, and SHALL respect opt-out, quiet hours, and quota with no bypass.

### Requirement 16: Integrations, language, sentiment, analytics, agents, tools, A/B, media & search (B7)

**User Story:** As a tenant, I want CRM/Sheets/webhook integrations, multi-language, sentiment, analytics, multi-agent orchestration, function-calling tools, A/B testing, voice/media understanding, and conversation search, so that I can operate, extend, and measure my chatbot.

#### Acceptance Criteria
1. THE system SHALL support action nodes that call a webhook, write to a CRM, or write to Google Sheets, executed as retry-safe queued work.
2. THE system SHALL auto-detect message language (e.g., Hindi/English) and reply in the detected language where configured.
3. THE system SHALL compute sentiment per inbound message and store a rolling sentiment average per conversation.
4. THE system SHALL provide conversation analytics and reports (volume, resolution, handoff rate, sentiment, per-node flow funnels) scoped to the tenant.
5. THE system SHALL support multi-agent orchestration in which a router agent classifies the inbound and dispatches to a specialist skill, plus a per-tenant function-calling tool registry whose tools are validated against JSON Schemas and invoked idempotently.
6. THE system SHALL support A/B testing of flows and replies with sticky per-unit variant assignment and metric recording, defaulting every unit to the control variant when A/B is disabled.
7. THE system SHALL transcribe inbound voice notes via a speech-to-text provider into the normal pipeline as text, and WHERE no speech-to-text provider is bound THE system SHALL acknowledge the voice note and ask the user to type (degraded, still functional).
8. THE system SHALL record messaging/conversation/order/session lifecycle to an append-only, monotonically versioned event log with projections, and SHALL support conversation search via MySQL FULLTEXT (always on) and semantic vector search where available.

### Requirement 17: Human handoff / live agent (B8)

**User Story:** As a tenant, I want to hand conversations to a human agent, so that complex cases get personal attention.

#### Acceptance Criteria
1. WHEN a conversation is escalated THEN THE system SHALL set its mode to `HANDOFF_REQUESTED` and surface it in the tenant's live inbox.
2. WHILE a conversation is in `AGENT` mode THE system SHALL suppress all automatic bot replies for that conversation.
3. WHEN an agent sends a message THEN THE system SHALL send it through the same anti-ban and plan-gated send pipeline (agents are not exempt from limits).
4. WHEN an agent releases a conversation THEN THE system SHALL return it to `BOT` mode.

---

**Block C — User Panel (Tenant Self-Service)**

### Requirement 18: Account & security (C1)

**User Story:** As a tenant user, I want to register, log in, and manage my account securely, so that I can access my workspace.

#### Acceptance Criteria
1. THE system SHALL support registration via email and phone OTP that provisions the tenant, wallet, default chatbot, and data-encryption key on verification, with disposable-email/velocity anti-fraud checks.
2. THE system SHALL support login via email and phone OTP with throttle and lockout on repeated failure, binding the tenant context to the user's active tenant.
3. THE system SHALL let a user manage their profile, including avatar upload served via a signed tenant-prefixed URL.
4. THE system SHALL let a user view their subscription/plan (with upgrade/downgrade and dunning state) and wallet/credit balance with gateway top-up.
5. WHEN a user enables two-factor authentication (TOTP + recovery codes) THEN THE system SHALL require the second factor on subsequent logins and provide a recovery-code fallback.

### Requirement 19: WhatsApp connection (self-service) (C2)

**User Story:** As a tenant user, I want to connect and manage my own numbers and choose their channel mode, so that I control my sessions.

#### Acceptance Criteria
1. THE system SHALL let a user connect a number via QR/pairing (Baileys) or per-mode credential entry (Cloud API/BSP), view session status, and reconnect/disconnect their own numbers.
2. IF the user's plan `SESSIONS` quota is reached THEN THE system SHALL block adding more and show the limit with an upgrade call-to-action.
3. THE system SHALL let a user choose and view a session's `channel_mode` and enter per-mode credentials that are secret-redacted and envelope-encrypted, disabling a mode with a setup hint when its credentials are absent.

### Requirement 20: Messaging within quota (C3)

**User Story:** As a tenant user, I want to send and schedule messages within my plan, so that I can run campaigns without exceeding limits.

#### Acceptance Criteria
1. THE system SHALL let a user send single/dual messages, create bulk campaigns, schedule messages (once + recurring), upload/send media, use/create/version templates, and view a delivery-status board — all within plan limits and with opt-out enforced.
2. IF a campaign would exceed the plan quota THEN THE system SHALL prevent starting it and report remaining quota.
3. WHILE a campaign is running and its quota is exhausted mid-run THE system SHALL pause it as `QUOTA_PAUSED` and auto-resume at the next period reset or after top-up/upgrade, never dropping queued sends.
4. WHERE a session's `channel_mode` does not support a messaging capability (e.g. media or template) THE system SHALL surface it as disabled with an explanation rather than erroring at send time.

### Requirement 21: Contacts & groups (self-service) (C4)

**User Story:** As a tenant user, I want to manage my contacts and groups, so that I can organize my audience.

#### Acceptance Criteria
1. THE system SHALL let a user manage contacts, import (CSV/vCard) with de-duplication and validation, create/manage their own groups, extract numbers from their own groups, and export their own data via signed expiring URLs.
2. THE system SHALL enforce opt-out on all of the user's outbound paths with no bypass.
3. IF a contact import or group extraction would exceed the `CONTACTS` quota THEN THE system SHALL block it and report the limit, while still importing valid rows and reporting malformed ones.

### Requirement 22: Chatbot self-service (C5)

**User Story:** As a tenant user, I want to build my own bot, so that I can automate replies.

#### Acceptance Criteria
1. THE system SHALL let a user build no-code flows (publish blocked when the graph is invalid, with the failing node highlighted), manage keyword triggers and FAQ, toggle AI auto-reply, and configure away/business-hours mode — within plan feature gates.
2. IF a chatbot feature is not in the user's plan THEN THE system SHALL hide or disable it.
3. IF the tenant's `AI_CREDITS` are zero THEN THE system SHALL skip the LLM stage and fall through to FAQ/fallback.

### Requirement 23: Reports & support (C6)

**User Story:** As a tenant user, I want reports, notifications, and support, so that I can operate and get help.

#### Acceptance Criteria
1. THE system SHALL provide own campaign analytics and conversation reports (from pre-aggregated rollups), own error logs (phone-redacted), a notifications inbox, a support ticket/help center, and billing history & invoices served via signed URLs.
2. WHEN a user opens a support ticket THEN THE system SHALL route it to platform support and track its status.

---

**Block D — Admin Panel (Platform Super-Admin)**

### Requirement 24: User & access management (D1)

**User Story:** As a Platform_Super_Admin, I want to manage all users and access, so that I can operate the platform securely.

#### Acceptance Criteria
1. THE system SHALL let the Platform_Super_Admin CRUD/suspend all users and tenants, assign roles/permissions (owner/admin/operator/viewer/agent), and impersonate/login-as a user.
2. THE system SHALL log every admin action, impersonation, and login attempt to an append-only, hash-chained audit trail whose integrity can be verified.
3. THE system SHALL enforce login security for the admin panel: IP allowlist, login throttle, and lockout.
4. WHILE impersonating a user THE system SHALL show a banner, time-box the session, and keep certain destructive operations blocked.
5. WHEN an audit row is written THEN THE system SHALL chain it to the previous row's hash so any mutation of a past row is detectable by a verifier.

### Requirement 25: Plans, billing & cost attribution (D2)

**User Story:** As a Platform_Super_Admin, I want to manage plans, billing, and cost attribution, so that I can monetize the platform profitably.

#### Acceptance Criteria
1. THE system SHALL let the Platform_Super_Admin create/manage plans and pricing, set feature limits per plan, integrate payment gateways, manage wallet/credit top-ups, manage coupons/discounts, and view invoices & revenue reports.
2. WHEN a payment gateway webhook is received THEN THE system SHALL verify its signature and process it exactly once (idempotent on the gateway event id).
3. THE system SHALL keep wallet balances non-negative on every debit.
4. THE system SHALL attribute per-tenant cost (LLM usage, message counts, storage) rolled up for margin analysis and optional billing against `AI_CREDITS`/wallet.

### Requirement 26: Platform control (D3)

**User Story:** As a Platform_Super_Admin, I want global controls, so that I can manage platform-wide behavior.

#### Acceptance Criteria
1. THE system SHALL provide a global session monitor, global campaign & queue monitor, global anti-ban/rate-limit config, broadcast announcements to all users, feature flags, and system settings (SMTP, API keys, LLM/gateway keys, defaults, base URL).
2. WHEN the Platform_Super_Admin changes a global anti-ban/rate-limit setting THEN THE system SHALL apply it as a floor that tenant plans cannot loosen.
3. WHEN a feature flag is toggled THEN THE system SHALL apply it globally or as a per-tenant override.
4. THE system SHALL store platform secrets (gateway/LLM/SMTP keys) secret-redacted and SHALL NOT expose them to tenants.

### Requirement 27: Monitoring, health & observability (D4)

**User Story:** As a Platform_Super_Admin, I want system health, analytics, and observability, so that I can keep the platform reliable.

#### Acceptance Criteria
1. THE system SHALL provide a system health dashboard (server/bridge/queue), a global error center & alerts, per-user and platform-wide usage analytics, and logs/metrics via a Prometheus `/metrics` endpoint.
2. IF a session disconnects or a queue backlog crosses threshold THEN THE system SHALL raise a deduplicated alert linked to a runbook.
3. WHERE cross-tenant analytics are computed THE system SHALL use pre-aggregated snapshots or read replicas rather than scanning live tables.
4. THE system SHALL propagate a `trace_id` from panel → job → bridge → webhook, attach it to every structured log line and span, and define SLO/SLI targets with error budgets per subsystem.

### Requirement 28: Content & compliance (D5)

**User Story:** As a Platform_Super_Admin, I want content and compliance controls, so that the platform meets ToS and data obligations.

#### Acceptance Criteria
1. THE system SHALL provide a global templates library, opt-out/blocklist management, data retention & deletion policies, and compliance/ToS enforcement with a per-session risk kill-switch.
2. WHEN a tenant is cancelled or invokes right-to-delete THEN THE system SHALL schedule deletion of that tenant's data per the retention policy across OLTP, vector store, object storage, and analytics, then run a verification pass and write a signed deletion certificate to the audit log.
3. THE system SHALL keep opt-out enforcement non-bypassable across all tenants.

### Requirement 29: Support (D6)

**User Story:** As a Platform_Super_Admin, I want support tooling, so that I can help tenants.

#### Acceptance Criteria
1. THE system SHALL provide support ticket management, a knowledge-base/FAQ manager, and the ability to send notifications to users or segments.
2. WHEN the Platform_Super_Admin sends a segment notification THEN THE system SHALL deliver it only to the targeted audience.

---

**Non-Functional Requirements**

### Requirement 30: Performance & scalability (NFR1)

**User Story:** As a platform operator, I want the platform to scale predictably and fairly, so that growth does not degrade service or let one tenant starve others.

#### Acceptance Criteria
1. THE system SHALL run AI replies on a dedicated `ai-reply` queue lane so a slow LLM provider does not block transactional or campaign lanes.
2. THE system SHALL schedule campaign dispatch by weighted-fair (deficit round-robin) scheduling across tenants so one tenant cannot starve others beyond a bounded share.
3. THE system SHALL support a documented drop-in upgrade to Redis for queue/cache/locks/rate-limits/broadcast when tenant concurrency warrants it, degrading to MySQL when Redis is absent.
4. THE system SHALL provide semantic reply caching with version-aware invalidation (on knowledge-base or template version change) that never serves a hit across tenants or stale versions.
5. THE system SHALL partition high-volume tables (messages, messages_inbound, event_log, flow_analytics_events, llm_usage) by time RANGE for cheap retention and recency queries, with a documented tenant-sharding path keyed by `shard_key` for horizontal write scaling.
6. THE system SHALL cap per-tenant in-flight AI calls and skip rate-capped tenants in the dispatch loop so no single tenant consumes an entire lane (noisy-neighbor bound).
7. THE system SHALL meet documented load/latency targets: transactional enqueue→bridge p95 < 2s, AI cache-hit reply < 150ms, AI LLM reply p95 < 3s, and each lane draining its steady-state backlog within 60s at target concurrency.

### Requirement 31: Reliability & fault tolerance (NFR2)

**User Story:** As a platform operator, I want the platform to degrade gracefully and never lose work, so that outages and failures do not cause data loss or crashes.

#### Acceptance Criteria
1. THE system SHALL never drop an outbound job on quota/rate limits; it SHALL defer (release) or block explicitly.
2. THE system SHALL process every payment and bridge/provider webhook idempotently.
3. THE system SHALL guard external calls (LLM, payment gateway, bridge) with a per-scope circuit breaker that never invokes the guarded operation while OPEN and admits at most the configured probes while HALF_OPEN.
4. THE system SHALL write side-effecting intents to a transactional outbox in the same database transaction as the state change and relay them with a dedup key so the downstream effect is applied exactly once even across relay crashes.
5. WHEN a multi-step flow step fails THEN THE system SHALL compensate all completed steps in reverse order via idempotent compensations, leaving no partial side effect.
6. THE system SHALL meet disaster-recovery targets of RPO ≤ 5 minutes and RTO ≤ 30 minutes, with tested point-in-time restore and per-tenant encrypted auth-state backup.

### Requirement 32: Security & compliance (NFR3)

**User Story:** As a platform operator, I want strong, verifiable security and compliance, so that tenant data is protected and legal obligations are met.

#### Acceptance Criteria
1. THE system SHALL guarantee tenant data isolation on every read and write path, verified by tests, and SHALL apply a `tenant_id` filter on every vector and FULLTEXT retrieval so no other tenant's chunk can be retrieved or cited.
2. THE system SHALL redact phone numbers in logs, SHALL NOT log message bodies (only content hashes), and SHALL redact all PII (phone/email/card-like sequences and configurable tenant patterns) before any text egresses to an LLM/embedding provider, restoring it only for the customer-facing reply.
3. THE system SHALL store gateway/LLM/SMTP secrets secret-redacted and SHALL NOT expose platform keys to tenants.
4. THE system SHALL minimize PCI scope by using hosted checkout / payment links and storing no card data.
5. THE system SHALL encrypt data in transit (TLS) and at rest, applying per-tenant envelope encryption (KMS-wrapped DEK via `FieldCipher`) to sensitive fields and failing closed (no plaintext fallback) when the key store is unavailable.
6. THE system SHALL rotate the KMS master key and per-tenant DEKs on schedule and support dual-secret rotation for webhook/HMAC secrets, mitigating the documented STRIDE threats at each trust boundary.
7. THE system SHALL apply anti-fraud defenses (signup velocity/OTP/device-IP heuristics, prompt-injection guardrails logged to abuse events, rate-limit-evasion keyed by tenant/session/contact, and a kill-switch for offending sessions).

### Requirement 33: Maintainability (NFR4)

**User Story:** As a platform engineer, I want swappable, interface-bound components, so that implementations change without rewrites.

#### Acceptance Criteria
1. THE system SHALL keep the WA Bridge logic-free behind the `BridgeClient`/`ChannelDriver` contract, so adding a messaging backend is a new class + enum case, not a rewrite.
2. THE system SHALL keep LLM providers, payment gateways, vector store, embedder, STT/TTS, KMS, and tracer behind interfaces so implementations are swappable and their absence is a config flip.
3. THE system SHALL keep the single-tenant engine reusable, adding multi-tenancy via a global scope rather than forking code.

### Requirement 34: Data architecture & analytics (NFR5)

**User Story:** As a platform operator, I want an auditable event log and an analytics pipeline, so that state is replayable and reporting is fast and cheap.

#### Acceptance Criteria
1. THE system SHALL keep `event_log` and `audit_logs` append-only (no UPDATE/DELETE grant to the app role), with `event_log` monotonically versioned per stream and read models rebuildable as projections from the event log.
2. THE system SHALL feed the event log (or binlog CDC) through ETL into a warehouse or analytics replica and compute real-time metrics from `metrics_rollup`, running heavy historical reports in batch off live tables.
3. THE system SHALL provide conversation search via MySQL FULLTEXT (always on) and semantic vector search where a vector store is available, degrading to FULLTEXT when vectors are off.
4. THE system SHALL expose a star-schema reporting model (fact + dimension tables) computing business metrics (funnels, retention, revenue, bot deflection rate, handoff rate, CSAT, sentiment) per tenant and platform-wide from replica/warehouse, never live-table scans.

### Requirement 35: Deployment & operations safety (NFR6)

**User Story:** As a platform operator, I want zero-downtime, always-rollback-safe deploys, so that shipping changes never risks an outage.

#### Acceptance Criteria
1. THE system SHALL deploy via blue-green/rolling FPM pools with graceful worker draining that finishes in-flight jobs before stopping.
2. THE system SHALL use expand-contract migrations (add → dual-write → backfill → switch reads → drop) so schema and code are always compatible during rollout and rollback needs no destructive down-migration.
3. THE system SHALL ship new subsystems behind feature flags (global or per-tenant) enabling canary tenants first.

### Requirement 36: Definition of Done / completeness (NFR7)

**User Story:** As a platform operator, I want every listed feature production-complete, so that the platform ships in a 100%-working condition with no stubs.

#### Acceptance Criteria
1. THE system SHALL resolve every feature listed in the design (28 User Panel, 28 Admin Panel, every `GroupService`/`ChannelService` method, every `ChannelMode`, and the Base_URL helper) to a concrete production implementation, with no `Fake*`/`Stub*` binding, `NotImplementedException`, or `TODO` reachable from `app/`.
2. WHERE an optional/scale-up dependency is absent THE system SHALL take a real, tested graceful-degradation path to a MySQL/Baileys default rather than a stub.
3. THE system SHALL ensure every such feature is tenant-scoped, plan-gated & quota-metered, capability-aware, error-handled with a typed exception, observable (`trace_id` + `wacb_*` metrics), and covered by tests including a property-based test where a universal property applies.

---

## Requirements → Design Traceability

Every requirement maps to at least one design section. Design section names refer to headings/subsections of `design.md`.

| Requirement | Design section(s) |
|---|---|
| A1 tenant isolation, tiers & lifecycle | Multi-tenancy model; `BelongsToTenant`/`TenantContext`; Advanced Multi-Tenancy & Isolation (tiers, noisy-neighbor, encryption, lifecycle); Observability & tenancy tiers (`TierResolver`/`TenantLifecycle`) |
| A2 sessions | Bridge multi-tenancy; reused SessionManager; Channel Mode §2.5 (`channel_mode` column) |
| A3 messaging | Algorithm 3 (tenant send gate); Algorithm 9 (mode-aware send); reused SendMessageJob; Property 4, 9 |
| A4 anti-ban | Algorithm 3/9; reused AntiBanEngine; Channel Mode §2.8 (anti-ban mandatory on web-protocol) |
| A5 group management (full) | WhatsApp Groups — Full Management Design (G.1–G.8); `GroupService`/`GroupAdminGuard`; Properties 25, 26 |
| A6 channel management (full) | WhatsApp Channels — Full Management Design (CH.1–CH.5); reused extraction/export services; Properties 21, 26 |
| A7 error reporting & monitoring | Reused engine services; Error Handling; structured logging |
| A8 Channel Mode | Channel Mode — Pluggable Messaging Backends (§2.1–2.8); `ChannelDriver`/`ChannelRouter`/`ChannelCredentialStore`; Algorithm 9; Properties 21, 22, 23, 24, 26 |
| A9 Base URL | Base URL / APP_URL Configuration (U.1–U.5); `BaseUrl`/`UrlBuilder`; Property 27 |
| B1 inbound handling | Conversational AI HLD; Algorithm 1; Channel Mode §2.5 (inbound routing); Properties 2, 22 |
| B2 resolution pipeline | Resolution pipeline order; Algorithm 1; Properties 2, 3, 7 |
| B3 keyword/FAQ/rich | ResolverStage (KeywordTrigger, IntentFaq); Advanced Chatbot — Rich interactive messages & degradation |
| B4 LLM smart replies (deep) | AI / LLM Engine — Deep Dive (§1.1 RAG, §1.2 memory + Algorithm 5, §1.3 guardrails/PII, §1.4 caching/routing, §1.5 fallback/breaker); Properties 11, 12, 13, 15, 20 |
| B5 flow builder | FlowRuntime; Algorithm 2 & 4; Property 6 |
| B6 hours/lead/order/pay/saga/drip | BusinessHoursStage; Billing PaymentGateway; Algorithm 8 (saga); Advanced Chatbot — drip sequences; Property 18 |
| B7 integrations/agents/tools/A-B/media/search | Action node; LlmProvider detectLanguage/sentiment; AI orchestration (`AgentRouter`/`ToolRegistry`/`AbTester`/`SpeechToText`); Data Architecture (event log, search, reporting); Property 14 |
| B8 handoff | HandoffService; Property 10 |
| C1 account & security | Panels §4.1 Group A (features 1–6) |
| C2 WhatsApp connection | Panels §4.1 Group B (features 7–10); Channel Mode §2.7 |
| C3 messaging within quota | Panels §4.1 Group C (features 11–16); Algorithm 3/9; Property 9 |
| C4 contacts & groups | Panels §4.1 Group D (features 17–21); Groups Full Mgmt |
| C5 chatbot self-service | Panels §4.1 Group E (features 22–25) |
| C6 reports & support | Panels §4.1 Group F (features 26–28) |
| D1 user & access management | Panels §4.2 Group A (features 1–6); Security; Property 17 |
| D2 plans, billing & cost attribution | Panels §4.2 Group B (features 7–12); Billing subsystem; Observability (cost attribution); Property 5, 8 |
| D3 platform control | Panels §4.2 Group C (features 13–18); Platform ops |
| D4 monitoring, health & observability | Panels §4.2 Group D (features 19–22); Observability & Operations Deep Dive (tracing, SLO/SLI, alerting); Property (dedup alerts) |
| D5 content & compliance | Panels §4.2 Group E (features 23–26); Security & Compliance Hardening (GDPR/DPDP DSR, retention); Property 3 |
| D6 support | Panels §4.2 Group F (features 27–28) |
| NFR1 performance & scalability | Performance & Scalability Considerations; Scalability at Scale Deep Dive (partitioning/sharding, Redis, queue tuning, caching, load targets); Properties 12, 19 |
| NFR2 reliability & fault tolerance | Reliability & Fault Tolerance Deep Dive (circuit breakers, outbox, saga, DR); Algorithms 6, 7, 8; Properties 13, 16, 18 |
| NFR3 security & compliance | Security & Compliance Hardening Deep Dive (STRIDE, encryption/KMS/rotation, GDPR/DPDP, PCI, anti-fraud); Properties 15, 20, 23, 27 |
| NFR4 maintainability | Key Design Decisions; Dependencies (interface-bound components); Channel Mode §2.4 |
| NFR5 data architecture & analytics | Data Architecture & Analytics Deep Dive (event sourcing, CDC/ETL, conversation search, star schema); Property 14 |
| NFR6 deployment & operations safety | Observability & Operations — Deployment strategy (blue-green, expand-contract, feature flags) |
| NFR7 Definition of Done / completeness | Definition of Done / Completeness Guarantees (DoD.1–DoD.3); Property 28 |

### Correctness Properties → Requirements Validated

The design defines 28 correctness properties (property-based tests where noted). Each maps to the requirement(s) it validates:

| Property | Title | Validates requirement(s) |
|---|---|---|
| 1 | Tenant isolation | A1.1, A1.2, A1.3 |
| 2 | Single reply per inbound | B1.2 |
| 3 | Opt-out is absolute | B2.4, C4.2, D5.3 |
| 4 | Quota never negative / never double-counted | A3.5 |
| 5 | Wallet non-negativity | D2.3 |
| 6 | Flow termination | B5.4 |
| 7 | Plan gating | B2.3, C5.2 |
| 8 | Gateway idempotency | D2.2 |
| 9 | Status monotonicity | A3.3 |
| 10 | Handoff silence | B8.2 |
| 11 | RAG citation grounding | B4.5 |
| 12 | Semantic-cache soundness | B4.10, NFR1.4 |
| 13 | Circuit-breaker safety | B4.11, NFR2.3 |
| 14 | Event-log append-only & monotonic versioning | B7.8, NFR5.1 |
| 15 | PII never egresses raw to the LLM | B4.7, NFR3.2 |
| 16 | Outbox exactly-once effect | D2.2, NFR2.4 |
| 17 | Audit-log hash-chain integrity | D1.2, D1.5 |
| 18 | Saga atomicity (all-or-compensated) | B6.5, NFR2.5 |
| 19 | Fair scheduling / noisy-neighbor bound | A1.7, NFR1.2, NFR1.6 |
| 20 | Tenant-scoped retrieval isolation | A1.2, B4.1, NFR3.1 |
| 21 | Channel-mode capability gating (unsupported op never dispatched) | A8.2, A6.5 |
| 22 | Exactly-one-driver routing | A8.3, A2.6, B1.1 |
| 23 | Per-session mode isolation & credential scoping | A8.4, C2.3, NFR3.3 |
| 24 | Anti-ban applies iff web-protocol mode | A8.5, A4.2 |
| 25 | Group admin-op rejected before bridge call; welcome exactly once per join | A5.2, A5.3 |
| 26 | Channel/group op unsupported by session mode fails typed, never crashes | A8.2, A6.5, A5.1 |
| 27 | Canonical-host URL generation (no host-header injection) | A9.1, A9.4, NFR3.2 |
| 28 | Every listed feature is production-complete (no stub in prod paths) | C1–C6, D1–D6, A5, A6, A8, A9, NFR7 |
