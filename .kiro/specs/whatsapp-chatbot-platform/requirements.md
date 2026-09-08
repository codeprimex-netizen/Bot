# Requirements — WhatsApp Chatbot Platform (Multi-Tenant SaaS)

## Introduction

This document defines the requirements for a **multi-tenant SaaS WhatsApp chatbot platform** built on the proven Laravel 11 / PHP 8.3 / MySQL 8 stack and the thin Node + Baileys "WA Bridge" architecture established by the `whatsapp-auto-messenger` spec. These requirements are **derived from the design document** (`design.md`) in this same spec, following the Design-First workflow.

The platform preserves all core messaging, group, channel, extraction, and anti-ban capabilities of the single-tenant engine, and adds three net-new subsystems: **multi-tenancy + billing**, a **conversational AI/chatbot engine**, a **User Panel** (tenant self-service), and an **Admin Panel** (platform super-admin).

**Requirements are grouped into four blocks:**
- **Block A — Core Engine (multi-tenant)** — the ~50 inherited core features, now tenant-scoped.
- **Block B — Conversational AI** — the ~15 net-new chatbot/AI features.
- **Block C — User Panel** — the 28 tenant self-service features.
- **Block D — Admin Panel** — the 28 platform super-admin features.

> ⚠️ **Compliance:** Opt-out enforcement and anti-ban rate limits are hard-enforced **per tenant** with no bypass parameter. Bulk/unsolicited messaging violates WhatsApp ToS; this platform is intended for opt-in audiences, owned groups/channels, and consented contacts.

### Glossary

- **Tenant** — an isolated customer account with its own numbers, contacts, campaigns, chatbots, plan, quota, and wallet.
- **Platform super-admin** — the owner of the SaaS platform, operating above all tenants via the Admin Panel.
- **Plan** — a subscription tier defining feature flags and quota limits.
- **Quota** — a metered, period-bucketed limit (messages, sessions, contacts, AI credits, concurrent campaigns).
- **Flow** — a no-code conversational graph (nodes + edges) authored in the visual builder.
- **Conversation** — a stateful thread between a tenant's WhatsApp number and one contact.

---

## Block A — Core Engine (Multi-Tenant)

### Requirement A1 — Tenant isolation

**User Story:** As a platform operator, I want every tenant's data strictly isolated, so that no tenant can ever read or affect another tenant's data.

#### Acceptance Criteria
1. WHEN any domain record is created THEN the system SHALL stamp it with the acting tenant's `tenant_id`.
2. WHEN any query runs in a tenant context THEN the system SHALL return only rows belonging to that tenant via a global scope.
3. IF a request attempts to access a record belonging to another tenant THEN the system SHALL deny it with a `CrossTenantAccessException` (403).
4. WHERE files are stored (WhatsApp auth state, exports, media) THE system SHALL namespace them under a per-tenant path prefix.
5. WHEN a platform super-admin acts in platform mode THEN the system SHALL bypass the tenant scope only for that audited context.

### Requirement A2 — Multi-tenant session & connection

**User Story:** As a tenant, I want to connect and manage my own WhatsApp numbers, so that my messaging runs on numbers I own.

#### Acceptance Criteria
1. WHEN a tenant connects a number THEN the system SHALL create a session mapped to exactly one `tenant_id`.
2. WHEN a tenant lists sessions THEN the system SHALL show only that tenant's sessions and their live status.
3. WHEN a session drops THEN the system SHALL auto-reconnect using the existing reconnect policy without affecting other tenants' sessions.
4. IF a tenant exceeds its plan's `SESSIONS` quota THEN the system SHALL prevent creating additional sessions and report the limit.
5. WHERE the Bridge is called THE system SHALL only reference session IDs owned by the requesting tenant.

### Requirement A3 — Multi-tenant messaging engine

**User Story:** As a tenant, I want single, dual, bulk, scheduled, and media messaging, so that I can run campaigns within my plan.

#### Acceptance Criteria
1. WHEN a tenant sends any message THEN the system SHALL enqueue it through the durable queue and the anti-ban send pipeline.
2. THE system SHALL support single mode, dual mode, auto bulk, bulk media, scheduled (once + recurring), and versioned templates, all scoped to the tenant.
3. WHEN a message is sent THEN the system SHALL track delivery status (sent/delivered/read) without downgrading status on out-of-order acks.
4. IF a tenant's `MESSAGES_MONTHLY` or `MESSAGES_DAILY` quota is exhausted THEN the system SHALL defer or block the send (never drop it) and notify the tenant.
5. WHEN a send job retries THEN the system SHALL consume message quota at most once per confirmed send (keyed by idempotency key).

### Requirement A4 — Anti-ban & safety (per tenant)

**User Story:** As a platform operator, I want anti-ban protections enforced per tenant, so that tenants cannot get their numbers (or the platform) banned.

#### Acceptance Criteria
1. THE system SHALL apply gaussian delay, typing simulation, warm-up ramp, quiet hours, spintax, batch cool-down, and risk scoring per tenant session.
2. THE system SHALL NOT expose any runtime parameter that bypasses warm-up caps or rate limits.
3. WHEN a tenant plan defines a rate limit stricter than the config cap THEN the system SHALL apply the stricter of the two.
4. WHEN a session's failure rate crosses the risk threshold THEN the system SHALL throttle it and alert the tenant.

### Requirement A5 — Group management (per tenant)

**User Story:** As a tenant, I want full group control, so that I can manage my owned groups.

#### Acceptance Criteria
1. THE system SHALL support group create/delete, admin management, settings control, member approve/reject, auto welcome (with media and custom templates), and tag all/selective/custom tagging, scoped to the tenant.
2. IF the bot is not a group admin THEN the system SHALL reject admin-requiring operations before any Bridge call.
3. WHEN a member joins THEN the system SHALL send the configured welcome exactly once per join (duplicate-guarded against rejoin spam).

### Requirement A6 — Extraction, export & channels (per tenant)

**User Story:** As a tenant, I want to extract members, export data, and manage channels, so that I can work with my audience.

#### Acceptance Criteria
1. THE system SHALL support group member extraction, active-number filtering, and export to CSV/TXT/JSON/XLSX/vCard, streamed for large data, scoped to the tenant.
2. THE system SHALL support channel create/delete, member management, auto post, and analytics, feature-gated by capability and plan.
3. WHEN an export is produced THEN the system SHALL store it under the tenant's path prefix and serve it via a signed, expiring URL.

### Requirement A7 — Error reporting & monitoring (per tenant)

**User Story:** As a tenant, I want an error dashboard and retries, so that I can recover failed messages.

#### Acceptance Criteria
1. THE system SHALL provide a tenant-scoped error dashboard, failed-message retry, real-time notifications, and error export.
2. WHEN a failed message is manually retried THEN the system SHALL update the original message row without creating a duplicate.
3. WHEN phone numbers appear in logs THEN the system SHALL redact them and never log message bodies (only content hashes).

---

## Block B — Conversational AI

### Requirement B1 — Inbound handling & single-reply guarantee

**User Story:** As a tenant, I want inbound messages processed by my chatbot, so that customers get automatic responses.

#### Acceptance Criteria
1. WHEN an inbound message webhook is received THEN the system SHALL verify its HMAC signature, resolve the tenant and session, and enqueue processing on the `ai-reply` lane.
2. WHEN the conversation engine processes an inbound message THEN the system SHALL enqueue at most one outbound reply for that message.
3. IF the tenant is suspended THEN the system SHALL log the inbound message but SHALL NOT send any automatic reply.
4. WHEN a redelivered webhook with the same `wa_message_id` arrives THEN the system SHALL process it idempotently (no duplicate reply).

### Requirement B2 — Resolution pipeline & priority order

**User Story:** As a tenant, I want predictable bot behavior, so that the most specific rule wins consistently.

#### Acceptance Criteria
1. THE system SHALL evaluate resolution stages in this fixed order: opt-out → live-agent → business-hours/away → active-flow → keyword → intent/FAQ → LLM smart reply → fallback.
2. WHEN a stage returns HANDLED or HALT THEN the system SHALL stop and SHALL NOT evaluate later stages.
3. IF a stage's feature is not in the tenant's plan THEN the system SHALL skip that stage.
4. WHEN an opt-out keyword (e.g., STOP) is detected THEN the system SHALL record the opt-out, send confirmation, and halt with no further bot reply.

### Requirement B3 — Auto-reply, keyword triggers, intent & FAQ

**User Story:** As a tenant, I want keyword and FAQ auto-replies, so that common questions are answered instantly.

#### Acceptance Criteria
1. THE system SHALL support auto-reply rules with keyword triggers matched by EXACT, CONTAINS, or REGEX, ordered by tenant-defined priority.
2. THE system SHALL support intent detection and an FAQ bot that returns a configured answer for a matched intent.
3. WHEN multiple keyword triggers match THEN the system SHALL apply the highest-priority one only.

### Requirement B4 — LLM / AI smart replies

**User Story:** As a tenant, I want AI-generated replies, so that customers get natural answers when no rule matches.

#### Acceptance Criteria
1. WHERE the tenant has AI enabled and no earlier stage matched THE system SHALL request a smart reply from the configured LLM provider using conversation context and the tenant knowledge base.
2. WHEN the LLM returns low confidence or a "talk to human" intent THEN the system SHALL escalate to human handoff instead of replying.
3. IF the tenant's `AI_CREDITS` quota is zero THEN the system SHALL skip the LLM stage and fall through to fallback.
4. IF the LLM provider errors or times out THEN the system SHALL skip the LLM stage gracefully and SHALL NOT crash the job.

### Requirement B5 — Visual chatbot / flow builder

**User Story:** As a tenant, I want a no-code drag-and-drop flow builder, so that I can design conversation flows without coding.

#### Acceptance Criteria
1. THE system SHALL let a tenant author a flow as a node/edge graph with node types: message, question, condition, menu, action, handoff, end.
2. WHEN a tenant attempts to publish a flow THEN the system SHALL validate it (exactly one entry node, no unreachable/dangling nodes, branch nodes have a default edge, every path reaches a terminal node) and SHALL reject publishing if invalid.
3. WHEN a contact is mid-flow THEN the system SHALL persist the current node and captured variables in conversation state so runtime is stateless.
4. WHEN a flow traversal executes THEN the system SHALL reach an end/handoff node in finite steps.

### Requirement B6 — Business hours, lead capture, orders & payments

**User Story:** As a tenant, I want business-hours handling, lead forms, and an order/booking bot with payments, so that I can capture and convert customers.

#### Acceptance Criteria
1. WHERE the current time is outside configured business hours THE system SHALL send the away message and optionally suppress further bot stages.
2. THE system SHALL support lead capture forms whose submitted fields are stored as leads.
3. THE system SHALL support an order/booking bot with a catalog and cart that produces an order.
4. WHEN an order requires payment THEN the system SHALL generate a payment link/UPI via the configured gateway and include it in the reply.

### Requirement B7 — Integrations, language, sentiment & analytics

**User Story:** As a tenant, I want CRM/Sheets/webhook integrations, multi-language, sentiment, and conversation analytics, so that I can operate and measure my chatbot.

#### Acceptance Criteria
1. THE system SHALL support action nodes that call a webhook, write to a CRM, or write to Google Sheets, executed as retry-safe queued work.
2. THE system SHALL auto-detect message language (e.g., Hindi/English) and reply in the detected language where configured.
3. THE system SHALL compute sentiment per inbound message and store a rolling sentiment average per conversation.
4. THE system SHALL provide conversation analytics and reports (volume, resolution, handoff rate, sentiment) scoped to the tenant.

### Requirement B8 — Human handoff / live agent

**User Story:** As a tenant, I want to hand conversations to a human agent, so that complex cases get personal attention.

#### Acceptance Criteria
1. WHEN a conversation is escalated THEN the system SHALL set its mode to HANDOFF_REQUESTED and surface it in the tenant's live inbox.
2. WHILE a conversation is in AGENT mode THE system SHALL suppress all automatic bot replies for that conversation.
3. WHEN an agent sends a message THEN the system SHALL send it through the same anti-ban and plan-gated send pipeline (agents are not exempt from limits).
4. WHEN an agent releases a conversation THEN the system SHALL return it to BOT mode.

---

## Block C — User Panel (Tenant Self-Service)

### Requirement C1 — Account & security

**User Story:** As a tenant user, I want to register, log in, and manage my account securely, so that I can access my workspace.

#### Acceptance Criteria
1. THE system SHALL support registration/login via email and phone OTP, profile management, and 2FA.
2. THE system SHALL let a user view their subscription/plan and wallet/credit balance.
3. WHEN a user enables 2FA THEN the system SHALL require the second factor on subsequent logins.

### Requirement C2 — WhatsApp connection (self-service)

**User Story:** As a tenant user, I want to connect and manage my own numbers, so that I control my sessions.

#### Acceptance Criteria
1. THE system SHALL let a user connect a number via QR/pairing, view session status, and reconnect/disconnect their own numbers.
2. IF the user's plan `SESSIONS` quota is reached THEN the system SHALL block adding more and show the limit.

### Requirement C3 — Messaging within quota

**User Story:** As a tenant user, I want to send and schedule messages within my plan, so that I can run campaigns without exceeding limits.

#### Acceptance Criteria
1. THE system SHALL let a user send single messages, create bulk campaigns, schedule messages, upload/send media, use/create templates, and view delivery status — all within plan limits.
2. WHEN a campaign would exceed the plan quota THEN the system SHALL prevent starting it and report remaining quota.

### Requirement C4 — Contacts & groups (self-service)

**User Story:** As a tenant user, I want to manage my contacts and groups, so that I can organize my audience.

#### Acceptance Criteria
1. THE system SHALL let a user manage contacts, import (CSV/vCard), create/manage groups, extract numbers from their own groups, and export their own data.
2. THE system SHALL enforce opt-out on all of the user's outbound paths with no bypass.

### Requirement C5 — Chatbot self-service

**User Story:** As a tenant user, I want to build my own bot, so that I can automate replies.

#### Acceptance Criteria
1. THE system SHALL let a user build auto-reply flows, keyword triggers and FAQ, enable AI auto-reply, and configure away/business-hours mode — within plan feature gates.
2. IF a chatbot feature is not in the user's plan THEN the system SHALL hide or disable it.

### Requirement C6 — Reports & support

**User Story:** As a tenant user, I want reports, notifications, and support, so that I can operate and get help.

#### Acceptance Criteria
1. THE system SHALL provide own campaign analytics, own error logs, a notifications inbox, a support ticket/help center, and billing history & invoices.
2. WHEN a user opens a support ticket THEN the system SHALL route it to platform support and track its status.

---

## Block D — Admin Panel (Platform Super-Admin)

### Requirement D1 — User & access management

**User Story:** As a platform super-admin, I want to manage all users and access, so that I can operate the platform securely.

#### Acceptance Criteria
1. THE system SHALL let the super-admin CRUD/suspend all users and tenants, assign roles/permissions (owner/admin/operator/viewer/agent), and impersonate/login-as a user.
2. THE system SHALL log every admin action, impersonation, and login attempt to an audit trail.
3. THE system SHALL enforce login security: IP allowlist, login throttle, and lockout for the admin panel.
4. WHILE impersonating a user THE system SHALL show a banner and keep certain destructive operations blocked.

### Requirement D2 — Plans & billing

**User Story:** As a platform super-admin, I want to manage plans and billing, so that I can monetize the platform.

#### Acceptance Criteria
1. THE system SHALL let the super-admin create/manage plans and pricing, set feature limits per plan, integrate payment gateways, manage wallet/credit top-ups, manage coupons/discounts, and view invoices & revenue reports.
2. WHEN a payment gateway webhook is received THEN the system SHALL verify its signature and process it exactly once (idempotent on gateway event id).
3. THE system SHALL keep wallet balances non-negative on every debit.

### Requirement D3 — Platform control

**User Story:** As a platform super-admin, I want global controls, so that I can manage platform-wide behavior.

#### Acceptance Criteria
1. THE system SHALL provide a global session monitor, global campaign & queue monitor, global anti-ban/rate-limit config, broadcast announcements to all users, feature flags, and system settings (SMTP, API keys, defaults).
2. WHEN the super-admin changes a global anti-ban/rate-limit setting THEN the system SHALL apply it as a floor that tenant plans cannot loosen.
3. WHEN a feature flag is toggled THEN the system SHALL apply it globally or per tenant override.

### Requirement D4 — Monitoring & health

**User Story:** As a platform super-admin, I want system health and analytics, so that I can keep the platform reliable.

#### Acceptance Criteria
1. THE system SHALL provide a system health dashboard (server/bridge/queue), a global error center & alerts, per-user and platform-wide usage analytics, and logs/metrics via a Prometheus endpoint.
2. WHEN a session disconnects or a queue backlog crosses threshold THEN the system SHALL raise a deduplicated alert.
3. WHERE cross-tenant analytics are computed THE system SHALL use pre-aggregated snapshots or read replicas rather than scanning live tables.

### Requirement D5 — Content & compliance

**User Story:** As a platform super-admin, I want content and compliance controls, so that the platform meets ToS and data obligations.

#### Acceptance Criteria
1. THE system SHALL provide a global templates library, opt-out/blocklist management, data retention & deletion policies, and compliance/ToS enforcement.
2. WHEN a tenant is cancelled or invokes right-to-delete THEN the system SHALL schedule deletion of that tenant's data per the retention policy.
3. THE system SHALL keep opt-out enforcement non-bypassable across all tenants.

### Requirement D6 — Support

**User Story:** As a platform super-admin, I want support tooling, so that I can help tenants.

#### Acceptance Criteria
1. THE system SHALL provide support ticket management, a knowledge base/FAQ manager, and the ability to send notifications to users or segments.
2. WHEN the super-admin sends a segment notification THEN the system SHALL deliver it only to the targeted audience.

---

## Non-Functional Requirements

### NFR1 — Performance & scalability
1. THE system SHALL run AI replies on a dedicated queue lane so a slow LLM provider does not block transactional or campaign lanes.
2. THE system SHALL schedule campaign dispatch fairly across tenants so one tenant cannot starve others.
3. THE system SHALL support a documented drop-in upgrade to Redis for queue/cache when tenant concurrency warrants it.

### NFR2 — Reliability
1. THE system SHALL never drop an outbound job on quota/rate limits; it SHALL defer (release) or block explicitly.
2. THE system SHALL process every payment and bridge webhook idempotently.

### NFR3 — Security & compliance
1. THE system SHALL guarantee tenant data isolation on every read and write path (verified by tests).
2. THE system SHALL redact phone numbers in logs and never log message bodies.
3. THE system SHALL store gateway/LLM secrets in secret-redacted settings and never expose platform keys to tenants.
4. THE system SHALL minimize PCI scope by using hosted checkout / payment links and storing no card data.

### NFR4 — Maintainability
1. THE system SHALL keep the WA Bridge logic-free and behind the `BridgeClient` interface.
2. THE system SHALL keep LLM providers and payment gateways behind interfaces so implementations are swappable.
3. THE system SHALL keep the single-tenant engine reusable, adding multi-tenancy via a global scope rather than forking code.

---

## Requirements → Design Traceability

| Requirement | Design section |
|---|---|
| A1 tenant isolation | Multi-tenancy model; `BelongsToTenant`; Correctness Property 1 |
| A2 sessions | Bridge multi-tenancy; reused SessionManager |
| A3 messaging | Algorithm 3 (tenant send gate); reused SendMessageJob |
| A4 anti-ban | Algorithm 3; reused AntiBanEngine |
| A5 groups / A6 extract-export-channels / A7 errors | Reused engine services (scoped) |
| B1 inbound / B2 pipeline | Conversational AI HLD; Algorithm 1; Properties 2,3 |
| B3 keyword/FAQ | ResolverStage (KeywordTrigger, IntentFaq) |
| B4 LLM | LlmProvider interface; LlmReplyStage; error handling |
| B5 flow builder | FlowRuntime; Algorithm 2 & 4; Property 6 |
| B6 hours/lead/order/pay | BusinessHoursStage; Billing PaymentGateway; data models |
| B7 integrations/lang/sentiment | Action node; LlmProvider detectLanguage/sentiment |
| B8 handoff | HandoffService; Property 10 |
| C1–C6 user panel | Panels (User Panel) |
| D1–D6 admin panel | Panels (Admin Panel); Billing; Platform ops; Security |
| NFR1–NFR4 | Performance, Security, Testing, Key Decisions |
