# Design Document — WhatsApp Chatbot Platform (Multi-Tenant SaaS)

## Overview

This document designs a **multi-tenant SaaS platform** built on the proven **Laravel 11 / PHP 8.3 / MySQL 8** monolith and the thin **Node + Baileys "WA Bridge"** sidecar established by the existing `whatsapp-auto-messenger` spec. It keeps every core messaging, group, channel, extraction, and anti-ban capability of that system and adds three large net-new subsystems:

1. **Multi-tenancy + Billing** — many isolated tenants (customers), each with their own WhatsApp numbers, contacts, campaigns, chatbots, plan, quota, and wallet.
2. **Conversational AI Engine** — auto-reply, keyword triggers, LLM smart replies, visual flow builder, intent/FAQ, business-hours/away mode, lead capture, order/booking, payments, multi-language, sentiment, human handoff, and conversation analytics. This is the biggest new subsystem and is designed with both high-level and low-level detail below.
3. **User Panel + Admin Panel** — a customer-facing self-service dashboard (28 features) and a super-admin/platform-owner control plane (28 features).

The document is intentionally **both High-Level (architecture, sequence diagrams, interfaces, data models) and Low-Level (formal PHP interfaces, algorithmic pseudocode with pre/postconditions and loop invariants, correctness properties)**, because both artifact levels were requested.

**Design principles carried forward and extended:**

1. **PHP owns everything** — data, decisions, scheduling, UI. The Bridge stays a dumb, replaceable wire.
2. **Queue-first** — every outbound send and every AI reply flows through the durable MySQL `jobs` queue.
3. **MySQL-only default** — queue, cache, locks, sessions on MySQL; Redis is an optional performance upgrade (recommended once tenant count grows).
4. **Tenant isolation is non-negotiable** — every domain row carries a `tenant_id`; a global query scope makes cross-tenant reads structurally impossible.
5. **Compliance & anti-ban are hard-enforced per tenant** — opt-out filtering and rate limits have no bypass parameter, exactly as before, now scoped per tenant.
6. **Plan gates everything** — messaging volume, session count, and feature access are gated by the tenant's plan; enforcement lives in one place, not scattered.
7. **Fail loud, degrade gracefully** — unsupported operations and exhausted quotas produce clean, typed errors, never silent no-ops.

---

## Relationship to `whatsapp-auto-messenger`

This is a **new, separate spec**. It does not modify the existing one. Conceptually, `whatsapp-auto-messenger` becomes the **single-tenant engine core** that this platform wraps and multiplies:

| Existing (single-tenant) | This platform (multi-tenant SaaS) |
|---|---|
| One implicit owner, `admin`/`admin` | Many tenants + platform super-admin above them |
| Global sessions, contacts, campaigns | Every row scoped by `tenant_id` |
| Config-file rate/warm-up caps | Per-plan quotas + config caps, whichever is stricter |
| No billing | Plans, wallets/credits, invoices, coupons, gateway |
| Manual replies only | Full conversational AI / chatbot engine |
| Single dashboard | User Panel (tenant) + Admin Panel (platform) |

The 22 existing domain tables are reused with an added `tenant_id` column and a global scope; new tables are added for tenancy, billing, and the AI engine.

---

## Architecture

```mermaid
graph TD
    subgraph Edge
        NGINX[nginx TLS HTTP->S]
    end
    subgraph PHP_FPM[PHP-FPM 8.3 - Laravel 11]
        USERPANEL[User Panel - Livewire]
        ADMINPANEL[Admin Panel - Livewire]
        API[/api/v1 - Sanctum + RBAC + Tenant scope/]
        WEBHOOKS[Inbound + Gateway Webhooks - HMAC]
    end
    subgraph Services[Service Layer app/Services]
        TENANCY[Tenancy + PlanGate + QuotaGuard]
        BILLING[Billing + Wallet + Invoicing]
        MESSAGING[Messaging + Campaign + AntiBan]
        CHATBOT[Conversational AI Engine]
        GROUPS[Groups + Channels + Extract + Export]
        PLATFORM[Platform Ops + Health + Audit]
    end
    subgraph Data
        MYSQL[(MySQL 8 - tenant-scoped rows)]
        QUEUE[(jobs / job_batches / failed_jobs)]
        CACHE[(cache / cache_locks)]
    end
    subgraph Workers[Supervisor Workers]
        WQ[queue:work lanes: transactional, ai-reply, welcome, campaign, extraction]
        CRON[schedule:run - single cron]
    end
    subgraph External
        LLM[LLM Providers - GPT / Gemini]
        PAY[Payment Gateways - Razorpay/Stripe/UPI]
        BRIDGE[WA Bridge - Node + Baileys per tenant sessions]
        OBS[Prometheus / Alerting]
    end

    NGINX --> USERPANEL & ADMINPANEL & API & WEBHOOKS
    USERPANEL & ADMINPANEL & API --> Services
    WEBHOOKS --> CHATBOT
    WEBHOOKS --> BILLING
    Services --> MYSQL & QUEUE & CACHE
    WQ --> Services
    CRON --> Services
    MESSAGING --> BRIDGE
    CHATBOT --> LLM
    CHATBOT --> BRIDGE
    BILLING --> PAY
    PLATFORM --> OBS
    BRIDGE -. webhook HMAC .-> WEBHOOKS
    PAY -. webhook HMAC .-> WEBHOOKS
```

**What changed vs the single-tenant architecture:**

- A **Tenancy layer** sits in front of every service. Requests are resolved to a tenant (by subdomain, panel session, or API key), a global Eloquent scope is applied, and `PlanGate`/`QuotaGuard` are consulted before any billable action.
- A **Conversational AI Engine** consumes inbound-message webhooks and produces replies through the same anti-ban send pipeline.
- A **Billing subsystem** manages plans, wallets/credits, invoices, coupons, and payment-gateway webhooks.
- Two Livewire panels replace the single dashboard: **User Panel** (tenant self-service) and **Admin Panel** (platform super-admin).

### Multi-tenancy model

**Single database, shared schema, row-level isolation** (`tenant_id` on every domain table) is the chosen model.

| Model | Verdict | Rationale |
|---|---|---|
| Row-level `tenant_id` + global scope | **Chosen** | Simplest to operate on MySQL, cheapest per tenant, matches "PHP + MySQL only" constraint; isolation enforced in one global scope + tests |
| Database-per-tenant | Rejected for v1 | Migration/backup fan-out, connection sprawl; revisit only for enterprise isolation tiers |
| Schema-per-tenant | Rejected | MySQL schema = database; same cost as DB-per-tenant without the benefit |

Isolation is guaranteed by `BelongsToTenant` (a global scope + auto-fill of `tenant_id` on create). The WhatsApp **auth-state files** and **export files** are stored under a per-tenant path prefix (`storage/tenants/{tenantId}/...`) so a filesystem bug can't leak across tenants either.

### Bridge multi-tenancy

The Bridge stays logic-free. Sessions are already keyed by an opaque `sessionId` (ULID); the platform simply guarantees every `sessionId` maps to exactly one tenant (`sessions_wa.tenant_id`). The Bridge never learns about tenants — PHP resolves tenant → session set and only ever asks the Bridge about session IDs the tenant owns. Auth-state directories are namespaced per tenant on disk.

---

## Conversational AI Engine — High-Level Design

This is the largest net-new subsystem. It turns an inbound WhatsApp message into a reply by running it through a deterministic **resolution pipeline** whose stages are ordered from cheapest/most-specific to most-expensive/most-general.

```mermaid
sequenceDiagram
    participant WA as WhatsApp user
    participant BR as WA Bridge
    participant WH as Inbound Webhook (PHP)
    participant EN as ConversationEngine (ai-reply job)
    participant FLOW as FlowRuntime
    participant LLM as LLM Provider
    participant AG as Human Agent (Live Inbox)
    participant SP as Send Pipeline

    WA->>BR: inbound message
    BR->>WH: POST /webhooks/bridge (HMAC)
    WH->>WH: verify HMAC, resolve tenant + session
    WH->>EN: dispatch HandleInboundMessageJob (ai-reply lane)
    EN->>EN: opt-out / STOP keyword check
    EN->>EN: business-hours / away-mode check
    alt conversation is in a live-agent session
        EN->>AG: route to agent inbox, no bot reply
    else bot is handling
        EN->>FLOW: active flow step? resume flow
        alt no active flow
            EN->>EN: keyword trigger match
            EN->>EN: intent / FAQ match
            alt matched
                EN->>SP: enqueue templated reply
            else no rule matched and AI enabled
                EN->>LLM: smart reply (context + knowledge base)
                LLM-->>EN: draft reply + confidence
                alt low confidence or handoff intent
                    EN->>AG: escalate to human handoff
                else
                    EN->>SP: enqueue AI reply
                end
            end
        else active flow
            FLOW->>FLOW: evaluate node, capture input, branch
            FLOW->>SP: enqueue next node's message(s)
        end
    end
    SP->>BR: send (through anti-ban gate)
```

### Resolution pipeline order (deterministic, first-win)

1. **Compliance short-circuit** — opt-out keyword (`STOP`/`UNSUBSCRIBE`) always wins; records opt-out, sends confirmation, stops.
2. **Live-agent lock** — if a human agent has taken over this conversation, the bot stays silent (agent-only).
3. **Business-hours / away-mode** — outside configured hours, send the away message and (optionally) suppress further stages.
4. **Active flow resume** — if the contact is mid-flow, resume that flow's runtime (highest priority among bot logic).
5. **Keyword triggers** — exact / contains / regex matchers, tenant-ordered.
6. **Intent & FAQ** — classify intent (rule-based first, LLM-assisted optional) → matched FAQ answer.
7. **LLM smart reply** — only if enabled for the tenant and nothing above matched; uses conversation context + tenant knowledge base; low confidence or explicit "talk to human" intent → handoff.
8. **Default fallback** — configured fallback message, or silent.

Every stage is **feature-gated by plan** and **quota-metered** (LLM calls consume AI credits; outbound replies consume message quota). The order is fixed in code so behavior is predictable and testable.

### Visual Chatbot / Flow Builder (no-code)

- The builder is a **Livewire + Alpine** drag-and-drop canvas producing a **flow graph JSON** (nodes + edges) — no code generation, the graph is interpreted at runtime.
- **Node types:** `message`, `question` (capture input to a variable), `condition` (branch on variable/intent), `menu` (numbered options), `action` (call webhook / write to CRM / Google Sheet), `handoff` (to human), `end`.
- The graph is **validated** on save (reachability, no dangling edges, exactly one entry node, terminal nodes reachable) so a broken flow can never be published.
- Flow state per contact lives in `conversation_states` (current node id + captured variables), so runtime is stateless and horizontally scalable.

### Human handoff / Live Agent inbox

- A conversation can be in mode `BOT`, `HANDOFF_REQUESTED`, or `AGENT`.
- When escalated, it appears in the tenant's **Live Inbox** (Livewire, `wire:poll`), bot replies are suppressed, and agent messages go out through the same send pipeline (still rate-limited/anti-ban'd).
- Agent can **release** back to the bot; SLA timers and unassigned-timeout can auto-escalate or auto-reply.

---

## AI / LLM Engine — Deep Dive (Production-Grade)

> **Scope note (new requirements implied).** This section deepens Requirement **B4** (LLM smart replies) and **B7** (language/sentiment) and introduces behaviours that will require **new requirements** on regeneration: RAG grounding (B4.5–B4.8), conversation memory/token budgeting (B4.9), prompt guardrails & PII redaction (B4.10, sec), semantic caching (NFR-perf), and the LLM fallback/circuit-breaker chain (NFR-reliability). New tasks are flagged inline as **[NEW TASK]**.
>
> **MySQL-only default.** Every external dependency here (vector store, embeddings API, reranker, STT) is **optional/scale-up**. When absent the engine degrades to keyword + FAQ + lexical (MySQL `FULLTEXT`) retrieval and a single-provider LLM call, still functioning per Design Principle 3.

### 1.1 RAG architecture (knowledge grounding)

The LLM smart-reply stage (`LlmReplyStage`) is backed by a **Retrieval-Augmented Generation** pipeline so answers are grounded in the tenant's knowledge base (KB) rather than model priors. This directly reduces hallucination and enables **citation grounding**.

```mermaid
graph LR
    subgraph Ingest[Ingestion pipeline - queued, per tenant]
        SRC[KB source: doc / URL / FAQ / catalog] --> NORM[Normalize + clean]
        NORM --> CHUNK[Chunk: recursive, 512 tok target, 64 overlap]
        CHUNK --> EMB[Embed: text-embedding-3-small 1536d]
        EMB --> STORE[(Vector store: pgvector / Qdrant / MySQL fallback)]
        CHUNK --> FTS[(MySQL FULLTEXT mirror - always on)]
    end
    subgraph Query[Query-time retrieval]
        Q[Inbound question] --> QE[Embed query]
        QE --> ANN[ANN top-k=20 cosine]
        ANN --> RR[Rerank -> top-n=5 cross-encoder / LLM]
        Q --> BM[Lexical BM25 / FULLTEXT top-k=20]
        BM --> RRF[Reciprocal Rank Fusion]
        RR --> RRF
        RRF --> CTX[Assemble grounded context + source ids]
        CTX --> GEN[LLM generate w/ citation instruction]
        GEN --> CITE[Verify each claim maps to a retrieved chunk]
    end
```

**Ingestion pipeline (`KbIngestionJob`, [NEW TASK]):**

| Stage | Choice | Detail |
|---|---|---|
| Loaders | Text/MD/HTML/PDF/CSV, URL crawl | Strip boilerplate, keep headings as metadata |
| Chunking | **Recursive structural** chunker | Target **512 tokens**, **64-token overlap**, never split mid-sentence; keep `(source_id, heading_path, ordinal)` |
| Embedding | `text-embedding-3-small` (1536-d), batch 96 | Cheap, good recall; pluggable via `Embedder` interface |
| Storage | Vector store row `{tenant_id, kb_chunk_id, vector, tokens, checksum}` | `checksum` de-dupes re-ingest; re-embed only changed chunks |
| Mirror | MySQL `FULLTEXT` on chunk text | Always populated so lexical search works with **zero external deps** |

**Chunking strategy — trade-off table:**

| Strategy | Chosen | Rationale |
|---|---|---|
| Fixed 512-tok + overlap, structural boundaries | **Chosen** | Predictable token budget, cheap, preserves sentence/heading integrity |
| Sentence-window / small-to-big | Rejected v1 | Better precision but 2–3× storage and retrieval complexity; revisit for enterprise |
| Whole-document | Rejected | Blows the context window, poor retrieval granularity |

**Vector store — decision table:**

| Option | Chosen / Rejected | Rationale |
|---|---|---|
| **Qdrant** (external, self-host/managed) | **Chosen (scale-up default)** | Purpose-built ANN (HNSW), payload filtering by `tenant_id`, cheap ops at scale, gRPC |
| **pgvector** (Postgres sidecar) | **Alt (medium scale)** | Single-node simplicity if a Postgres box already exists; HNSW index; good to ~1–5M vectors/tenant pool |
| **MySQL 8 brute-force cosine** (`VEC_` UDF or app-side) | **Fallback (zero-dep default)** | No new infra; O(N) scan acceptable for small KBs (<5k chunks/tenant); auto-selected when `RAG_DRIVER=mysql` |
| Pinecone / managed only | Rejected as default | Vendor lock-in, cost, egress; supported behind the interface but not the default |

Retrieval driver is chosen by `config('rag.driver')`; the `VectorStore` interface makes it swappable. **When the vector store is unavailable the query path falls back to FULLTEXT-only retrieval** (lower recall, still grounded) and emits a `rag.degraded` metric.

**Hybrid retrieval + reranking:** dense ANN (top-k=20) is fused with lexical BM25/FULLTEXT (top-k=20) via **Reciprocal Rank Fusion** (`score = Σ 1/(60+rank)`), then reranked to **top-n=5** by a cross-encoder (scale-up) or an LLM-as-reranker (default when no cross-encoder). Fusion beats either signal alone on short WhatsApp queries with typos/code-switching.

**Citation grounding:** the generation prompt requires the model to answer **only** from the provided chunks and to emit `[[source:<kb_chunk_id>]]` markers. A post-generation verifier checks every citation id was actually in the retrieved set; **unsupported claims trigger a grounding failure** → the reply is downgraded (drop the unsupported sentence) or escalated to handoff. This is captured by **Correctness Property 11 (RAG citation grounding)**.

```php
namespace App\Services\Chatbot\Rag;

interface VectorStore {
    public function upsert(int $tenantId, iterable $chunks): void;       // {id, vector, payload}
    public function search(int $tenantId, array $queryVector, int $k, array $filter = []): array; // ranked hits
    public function delete(int $tenantId, array $chunkIds): void;        // right-to-delete path
    public function driver(): string;                                    // 'qdrant'|'pgvector'|'mysql'
}
interface Embedder {
    public function embed(array $texts): array;   // batched; returns float[][]
    public function dimensions(): int;
    public function model(): string;
}
interface Retriever {
    /** Hybrid dense+lexical+rerank; returns grounded chunks with source ids and scores. */
    public function retrieve(int $tenantId, string $query, int $topN = 5): RetrievedContext;
}
```

### 1.2 Conversation memory & context-window management

A conversation may span hundreds of turns; the model context window (and cost) is finite. Memory is **two-tier**:

- **Short-term (working) memory** — the last `K` verbatim turns (default `K=8`, hard cap by token budget), pulled from `messages_inbound` + outbound log.
- **Long-term memory** — a **rolling summary** (`conversation_states.summary`) plus optional **episodic vectors** (per-conversation embeddings in the vector store) for semantic recall of older facts ("what's my order number?").

**Token budgeting** (per request, default 8k-context model):

| Bucket | Budget | Policy |
|---|---|---|
| System prompt + guardrails | ~800 tok | Templated per tenant, cached |
| RAG context (top-n chunks) | ~2500 tok | Trimmed to budget by score order |
| Rolling summary (long-term) | ~600 tok | Regenerated when it grows past cap |
| Recent turns (short-term) | ~2500 tok | Newest-first until budget hit |
| Reserved for completion | ~1600 tok | `max_tokens` on the call |

**Sliding-window + summarization compaction** (`MemoryCompactor`, [NEW TASK]): when short-term tokens exceed the window, the oldest turns are summarized into the rolling summary and dropped from the verbatim window (see Algorithm 5). Summarization itself uses the **cheap model tier** to control cost.

```php
interface ConversationMemory {
    public function assemble(Conversation $c, int $tokenBudget): AssembledContext; // system+summary+recent+rag
    public function compact(Conversation $c): void;                                 // slide + summarize
    public function remember(Conversation $c, string $fact): void;                  // episodic upsert
}
```

### 1.3 Prompt engineering, guardrails & PII redaction

- **Per-tenant system-prompt templating.** `PromptTemplate` renders `{tenant.persona}`, `{business.hours}`, `{catalog.brief}`, `{guardrails}` into a versioned system prompt (stored in `prompt_templates`, versioned so a bad prompt is rollback-able). Templates are cached (see caching layer).
- **Guardrails / jailbreak defense.** A layered defense: (1) instruction hierarchy — system prompt asserts non-overridable rules; (2) an **input classifier** flags prompt-injection patterns ("ignore previous instructions", tool-exfil attempts); (3) **delimiter fencing** wraps user content in a data block the model is told is untrusted; (4) **output validation** rejects replies that leak the system prompt or violate policy. Injection attempts are logged to `abuse_events` and can trip a per-conversation rate limit.
- **PII redaction before LLM egress.** A `PiiRedactor` masks phone numbers, emails, card-like sequences, and configurable tenant patterns **before** any text leaves to the LLM/embeddings API; a reversible token map is kept in-process for the single request so the reply can be re-hydrated for the customer but the provider never sees raw PII. This satisfies the "message bodies never logged / minimize LLM exposure" security posture and is covered by **Correctness Property 15**.
- **Structured output.** For flows/tools the engine uses **JSON mode / function calling**: the model returns a typed object (validated against a JSON Schema) instead of prose, e.g. `{intent, entities, next_action, tool_calls[]}`. Invalid JSON triggers one **repair retry** then falls back to a rule-based parse.

```php
interface PiiRedactor {
    public function redact(string $text): RedactionResult;   // {masked, tokenMap}
    public function rehydrate(string $text, TokenMap $map): string;
}
final class Guardrail {
    public function inspectInput(string $text): GuardVerdict;   // allow|flag|block + reasons
    public function inspectOutput(string $reply, string $systemPrompt): GuardVerdict;
}
```

### 1.4 Cost & latency optimization

- **Token accounting.** Every LLM/embedding call records `{tenant_id, conversation_id, model, prompt_tokens, completion_tokens, cost_micros}` in `llm_usage` (drives per-tenant cost attribution + `AI_CREDITS` metering). Consumption is idempotent per `request_id`.
- **Semantic reply caching.** Before calling the model, the normalized+embedded query is looked up in a **semantic cache** (`semantic_cache` table + vector). A hit within cosine ≥ `0.95` **and** same tenant/chatbot/flow-context returns the cached reply, skipping the LLM entirely. Cache entries are invalidated on KB change or template version bump. Correctness is bounded by **Correctness Property 12 (semantic-cache soundness)** — a cache hit must be for an equivalent context, never cross-tenant.
- **Model routing (cheap→strong escalation).** A `ModelRouter` sends the request to a **cheap/fast model** first (e.g. `gpt-4o-mini`/`gemini-flash`); if the response fails a confidence/coverage check (low logprob-derived confidence, empty RAG grounding, or JSON-repair failure), it **escalates to a stronger model**. Simple FAQ-style queries never pay for the expensive model.
- **Streaming.** Replies stream token-by-token internally to cut time-to-first-token; because WhatsApp sends discrete messages, streaming is used to start typing-indicator and to allow **early cancellation** if the user sends a new message (supersede).
- **Batching.** Embeddings are batched (96/req) in the ingestion lane; sentiment/language detection are batched per inbound burst.

**Cost/latency budget table:**

| Path | p50 latency target | p95 target | Cost control |
|---|---|---|---|
| Semantic cache hit | < 50 ms | < 150 ms | $0 (no LLM call) |
| Cheap model + RAG | < 1.2 s | < 3.0 s | mini model + capped `max_tokens` |
| Escalated strong model | < 3.0 s | < 8.0 s | only on low confidence |
| Embedding (ingest) | batch | — | off critical path (queued) |

### 1.5 LLM fallback chain, circuit breaker & hallucination mitigation

The provider call is wrapped in a **fallback chain** guarded by a **circuit breaker** (shared machinery with §Reliability):

```mermaid
graph TD
    A[LlmReplyStage] --> CB{Primary breaker CLOSED?}
    CB -- yes --> P[Primary provider - OpenAI]
    CB -- no/open --> S{Secondary breaker CLOSED?}
    P -- success --> OUT[reply + confidence]
    P -- error/timeout --> REC[record failure -> maybe OPEN] --> S
    S -- yes --> P2[Secondary provider - Gemini]
    S -- no/open --> H[Local heuristic: FAQ / templated fallback]
    P2 -- success --> OUT
    P2 -- error --> H
    OUT --> CONF{confidence >= threshold and grounded?}
    CONF -- yes --> SEND[enqueue reply]
    CONF -- no --> HAND[escalate to human handoff]
    H --> SEND
```

- **Circuit breaker** states `CLOSED → OPEN → HALF_OPEN`; opens after `≥5` failures within a `30s` window **or** error-rate `>50%` over the last `20` calls; stays open `30s`; `HALF_OPEN` admits `3` probes. Per-provider **and** per-tenant scoping prevents one tenant's abuse from opening the breaker for all (see §Reliability, Algorithm 7). Governed by **Correctness Property 13 (circuit-breaker safety)**.
- **Confidence calibration.** Confidence combines RAG coverage (was the answer grounded?), model self-report, and a cheap NLI-style entailment check for critical intents. Below `0.55` → handoff; `0.55–0.7` → reply with a hedging disclaimer + "type AGENT for a human".
- **Hallucination mitigation.** Grounding requirement (§1.1), citation verification, "answer only from context / say you don't know" instruction, and JSON-schema validation for structured answers. Ungrounded free-text answers to factual queries are suppressed.

### Algorithm 5 — Memory compaction (sliding window + summarization)

```php
function compactMemory(conversation): void
```
**Preconditions:** conversation has ≥1 turn; `tokenCount(recentTurns) > windowBudget`.
**Postconditions:** verbatim window holds only the newest turns within `windowBudget`; all evicted turns are represented in `summary`; no turn is both dropped and unsummarized (no information silently lost); `summary` token count ≤ `summaryCap`.
**Loop invariant:** at each step the set (still-verbatim ∪ already-summarized) equals all turns processed so far.

```pascal
ALGORITHM compactMemory(conv)
BEGIN
    turns   <- conv.recentTurns ordered oldest->newest
    budget  <- windowBudget
    keep    <- []                       // newest turns to keep verbatim
    FOR t IN reverse(turns) DO          // newest first
        IF tokens(keep) + tokens(t) <= budget THEN keep.prepend(t)
        ELSE evict.append(t) END IF
    END FOR
    IF evict NOT EMPTY THEN
        // INVARIANT: keep ∪ evict = turns (partition, no loss)
        delta   <- summarizeCheapModel(evict, conv.summary)
        conv.summary <- capTo(merge(conv.summary, delta), summaryCap)
    END IF
    conv.recentTurns <- keep
    persist(conv)
END
```

---

## Components and Interfaces

All namespaces under `App\`. New/changed components are shown; unchanged single-tenant engine services (SessionManager, AntiBanEngine, GroupService, etc.) are reused as-is with tenant scoping applied by the global scope.

### 1. Tenancy layer

```php
namespace App\Services\Tenancy;

interface TenantContext
{
    public function current(): ?Tenant;          // null in platform-admin/global context
    public function set(Tenant $tenant): void;
    public function actingAsPlatform(): bool;     // super-admin bypasses tenant scope for admin panel
    public function forget(): void;
}

/** Global Eloquent scope + creating() hook mixed into every tenant-owned model. */
trait BelongsToTenant
{
    // adds: static::addGlobalScope(new TenantScope);
    //       static::creating(fn ($m) => $m->tenant_id ??= app(TenantContext::class)->current()?->id);
}

final class PlanGate
{
    /** True if the tenant's plan includes a feature flag. */
    public function allows(Tenant $t, string $feature): bool;

    /** Throws FeatureNotInPlanException if not allowed. */
    public function authorize(Tenant $t, string $feature): void;
}

final class QuotaGuard
{
    public function verdict(Tenant $t, QuotaKind $kind, int $units = 1): QuotaVerdict; // allow|defer|block
    public function consume(Tenant $t, QuotaKind $kind, int $units = 1): void;          // atomic
    public function remaining(Tenant $t, QuotaKind $kind): int;
}
```

`QuotaKind` enum: `MESSAGES_MONTHLY`, `MESSAGES_DAILY`, `SESSIONS`, `CONTACTS`, `AI_CREDITS`, `CAMPAIGNS_CONCURRENT`. Quota state lives in `tenant_usage` (period-bucketed counters) updated atomically via `Cache::lock()`.

### 2. Billing subsystem

```php
namespace App\Services\Billing;

interface PaymentGateway
{
    public function createCheckout(Tenant $t, Plan $p, Money $amount, array $meta = []): CheckoutSession;
    public function createPaymentLink(Tenant $t, Money $amount, string $ref): PaymentLink; // for chatbot order/booking
    public function verifyWebhook(Request $r): GatewayEvent;   // HMAC / signature verify
}

final class SubscriptionService
{
    public function subscribe(Tenant $t, Plan $p, ?Coupon $c = null): Subscription;
    public function changePlan(Tenant $t, Plan $to): Subscription;   // prorate
    public function cancel(Tenant $t, bool $atPeriodEnd = true): void;
    public function onGatewayEvent(GatewayEvent $e): void;           // activate/renew/dunning
}

final class WalletService
{
    public function balance(Tenant $t): Money;
    public function topUp(Tenant $t, Money $amount, string $source): WalletTxn;   // idempotent by source ref
    public function debit(Tenant $t, Money $amount, string $reason): WalletTxn;   // never below zero
}

final class InvoiceService
{
    public function issue(Tenant $t, Subscription $s): Invoice;      // PDF, sequential number
    public function markPaid(Invoice $i, GatewayEvent $e): void;
}
```

Implementations: `RazorpayGateway`, `StripeGateway`, `UpiLinkGateway` (payment links / UPI intents), plus `FakeGateway` for tests. Gateways sit behind the `PaymentGateway` interface so a tenant/admin can switch provider without touching billing logic.

### 3. Conversational AI Engine

```php
namespace App\Services\Chatbot;

final class ConversationEngine
{
    /** Entry point from HandleInboundMessageJob. Returns the action taken. */
    public function handle(InboundMessage $in): EngineOutcome;
}

interface ResolverStage
{
    /** Returns a StageResult: handled(reply)|passthrough|halt. Ordered by priority(). */
    public function resolve(ConversationContext $ctx): StageResult;
    public function priority(): int;
    public function requiresFeature(): ?string;   // plan gate key, or null
}

// Concrete stages, highest priority first:
//   OptOutStage, LiveAgentStage, BusinessHoursStage, ActiveFlowStage,
//   KeywordTriggerStage, IntentFaqStage, LlmReplyStage, FallbackStage

final class FlowRuntime
{
    public function start(ConversationContext $ctx, Flow $flow): FlowStep;
    public function resume(ConversationContext $ctx): FlowStep;   // uses conversation_states
    public function evaluateNode(FlowNode $node, ConversationContext $ctx): NodeResult;
}

interface LlmProvider
{
    public function reply(LlmRequest $req): LlmResponse;   // {text, confidence, intent, tokensUsed}
    public function detectIntent(string $text, array $intents): IntentMatch;
    public function detectLanguage(string $text): string;  // ISO code, e.g. 'hi' | 'en'
    public function sentiment(string $text): Sentiment;    // -1..1 + label
}

final class HandoffService
{
    public function request(Conversation $c, string $reason): void;    // BOT -> HANDOFF_REQUESTED
    public function assign(Conversation $c, User $agent): void;         // -> AGENT
    public function release(Conversation $c): void;                    // -> BOT
    public function agentSend(Conversation $c, User $agent, OutboundContent $m): void;
}
```

Implementations: `OpenAiProvider`, `GeminiProvider` behind `LlmProvider`; `FakeLlmProvider` for tests (deterministic canned responses + injectable confidence). Language detection and sentiment can be provider-backed or a lightweight local heuristic when AI credits are exhausted.

### 4. Panels

- **User Panel** (`app/Livewire/Panel/*`) — tenant-scoped; every component runs inside the resolved tenant context.
- **Admin Panel** (`app/Livewire/Admin/*`) — platform super-admin; runs in `actingAsPlatform()` mode so the tenant global scope is bypassed and cross-tenant data is visible. Guarded by a distinct `platform-admin` guard, IP allowlist, and full audit logging (reusing the existing default-admin guard rails).

### 5. Reliability primitives (new)

```php
namespace App\Services\Reliability;

interface CircuitBreaker {
    /** Runs $op unless the breaker for (scope,name) is OPEN; see Algorithm 7. */
    public function call(string $scope, string $name, callable $op): mixed;
    public function state(string $scope, string $name): CircuitState;
    public function trip(string $scope, string $name): void;   // manual/kill-switch
}

interface Outbox {
    /** MUST be called inside the same DB transaction as the state change. */
    public function enqueue(string $aggregateType, string $aggregateId, string $eventType, array $payload, string $dedupKey): void;
    public function relay(int $batch = 200): int;   // Algorithm 6; returns delivered count
}

interface IdempotencyStore {
    /** Returns cached response if $key already processed in $scope, else runs $op once. */
    public function once(string $scope, string $key, callable $op): mixed;
}

interface SagaOrchestrator {
    public function run(Saga $saga): SagaOutcome;   // Algorithm 8, with compensation
}

/** Backoff policy per error class (see Reliability section matrix). */
final class RetryPolicy {
    public function delay(int $attempt, ErrorClass $class): int; // ms, exponential + full jitter
    public function shouldRetry(int $attempt, ErrorClass $class): bool;
}
```

### 6. AI orchestration (new)

```php
namespace App\Services\Chatbot\Orchestration;

interface AgentRouter {
    /** Router agent classifies + dispatches to a specialist/skill; returns the chosen agent. */
    public function route(ConversationContext $ctx): AgentDecision;   // {agent, confidence, reason}
}
interface Skill {                       // a specialist agent / capability
    public function name(): string;
    public function canHandle(ConversationContext $ctx): float;       // 0..1 score
    public function handle(ConversationContext $ctx): SkillResult;    // reply | toolCalls | handoff
}
interface ToolRegistry {                // function-calling registry (per tenant/chatbot)
    public function schemas(int $chatbotId): array;                   // JSON Schemas for the model
    public function invoke(int $chatbotId, string $tool, array $args): ToolResult; // validated, idempotent
}
interface ModelRouter {                 // cheap -> strong escalation
    public function pick(LlmRequest $req, ?ConfidenceSignal $prev = null): ModelChoice;
}
interface AbTester {
    public function assign(string $test, string $unitId): string;     // sticky variant
    public function record(string $test, string $unitId, string $metric, float $value): void;
}
interface SpeechToText {                 // inbound voice-note transcription (optional)
    public function transcribe(MediaRef $audio, ?string $langHint = null): Transcript; // {text, confidence, lang}
}
interface TextToSpeech {                 // optional outbound voice (degrades to text)
    public function synthesize(string $text, string $lang): MediaRef;
}
```

**Optional-dependency fallbacks:** no `SpeechToText` bound → voice notes are acknowledged with "please type your message" (degraded); no `ToolRegistry`/router configured → single-agent LLM path; no `AbTester` → everyone gets the control variant.

### 7. Observability & tenancy tiers (new)

```php
namespace App\Services\Observability;

interface Tracer {
    public function startSpan(string $op, array $attrs = []): Span;   // propagates trace_id/correlation_id
    public function currentTraceId(): ?string;
}
interface Metrics {
    public function increment(string $metric, array $tags = [], int $by = 1): void;
    public function observe(string $metric, float $value, array $tags = []): void; // histograms
    public function gauge(string $metric, float $value, array $tags = []): void;
}
namespace App\Services\Tenancy;
interface TierResolver {
    public function tierOf(Tenant $t): TenantTier;                    // SHARED | DEDICATED_WORKER | DEDICATED_DB
    public function laneWeight(Tenant $t): int;                       // fair-scheduling weight
    public function connection(Tenant $t): string;                    // dedicated DB conn or 'default'
}
interface TenantLifecycle {
    public function provision(array $spec): Tenant;                   // create tenant, wallet, defaults, keys
    public function suspend(Tenant $t, string $reason): void;         // block outbound; keep inbound log
    public function export(Tenant $t): ExportArchive;                 // GDPR/DPDP portability
    public function offboard(Tenant $t): void;                        // schedule hard-delete + verification
}
interface FieldCipher {                  // envelope-encrypted sensitive fields
    public function encrypt(int $tenantId, string $plaintext): string;   // per-tenant DEK, KMS-wrapped
    public function decrypt(int $tenantId, string $ciphertext): string;
    public function rotate(int $tenantId): void;                          // re-wrap DEK, mark old RETIRING
}
```

---

## Data Models

New and changed tables (the 22 existing engine tables are reused, each gaining `tenant_id` + `idx(tenant_id, ...)`). New tables shown below.

```
-- ---------- Tenancy & access ----------
tenants                 id(ulid), name, slug(uniq), subdomain(uniq null),
                        status(ACTIVE|SUSPENDED|TRIAL|CANCELLED), plan_id->plans,
                        trial_ends_at, timezone, locale, created_at
                        idx(status)

tenant_users            id, tenant_id->tenants, user_id->users, role(owner|admin|operator|viewer|agent),
                        invited_at, joined_at   uniq(tenant_id, user_id)
                        -- a User can belong to multiple tenants; platform super-admins have tenant_id NULL

tenant_usage            id, tenant_id->, kind(enum QuotaKind), period_key(e.g. 2025-06 / 2025-06-14),
                        used(int), limit(int), updated_at   uniq(tenant_id, kind, period_key)

-- ---------- Billing ----------
plans                   id, name, slug(uniq), price_cents, currency, interval(MONTH|YEAR),
                        features(json feature flags), limits(json QuotaKind->int),
                        active, sort   idx(active)

subscriptions           id, tenant_id->(uniq active partial), plan_id->plans, status(TRIALING|ACTIVE|PAST_DUE|CANCELLED),
                        gateway, gateway_ref, current_period_start, current_period_end,
                        cancel_at_period_end, coupon_id->coupons
                        idx(status, current_period_end)

wallets                 id, tenant_id->(uniq), balance_cents, currency, updated_at
wallet_txns             id, tenant_id->, wallet_id->, type(TOPUP|DEBIT|REFUND|ADJUST),
                        amount_cents, balance_after, reason, source_ref(uniq null), created_at
                        idx(tenant_id, created_at)

invoices                id, tenant_id->, number(uniq), subscription_id->, amount_cents, currency,
                        status(DRAFT|OPEN|PAID|VOID), issued_at, paid_at, pdf_ref, line_items(json)
                        idx(tenant_id, status)

coupons                 id, code(uniq), type(PERCENT|FIXED), value, max_redemptions, redeemed,
                        expires_at, active   idx(active)

payment_events          id, tenant_id->null, gateway, gateway_event_id(uniq), type, payload(json),
                        processed_at, created_at   -- idempotent gateway webhook log

-- ---------- Conversational AI ----------
chatbots                id, tenant_id->, name, enabled, ai_enabled, default_flow_id->flows null,
                        away_message, fallback_message, settings(json), created_at
                        idx(tenant_id, enabled)

flows                   id, tenant_id->, chatbot_id->chatbots, name, version, status(DRAFT|PUBLISHED|ARCHIVED),
                        graph(json nodes+edges), entry_node_id, is_current
                        uniq(chatbot_id, name, version)

keyword_triggers        id, tenant_id->, chatbot_id->, match_type(EXACT|CONTAINS|REGEX), pattern,
                        priority, response_type(TEXT|TEMPLATE|FLOW), response_ref, enabled
                        idx(tenant_id, chatbot_id, priority)

intents                 id, tenant_id->, chatbot_id->, name, samples(json), answer, faq_group, enabled
knowledge_base          id, tenant_id->, chatbot_id->, title, content, embedding_ref null, tags(json)

conversations           id, tenant_id->, session_id->sessions_wa, contact_id->contacts, chatbot_id->,
                        mode(BOT|HANDOFF_REQUESTED|AGENT), assigned_agent_id->users null,
                        language, last_inbound_at, last_outbound_at, sentiment_avg, status(OPEN|CLOSED)
                        uniq(tenant_id, session_id, contact_id), idx(tenant_id, mode)

conversation_states     id, conversation_id->(uniq), flow_id->flows, current_node_id,
                        variables(json), expires_at, updated_at

messages_inbound        id(ulid), tenant_id->, conversation_id->, wa_message_id(uniq), body,
                        media_ref, intent, sentiment, language, handled_by(enum), created_at
                        idx(conversation_id, created_at)

leads                   id, tenant_id->, conversation_id->, contact_id->, form_id->lead_forms null,
                        fields(json), status, created_at
lead_forms              id, tenant_id->, chatbot_id->, name, fields(json schema)

orders                  id, tenant_id->, conversation_id->, contact_id->, items(json), total_cents,
                        currency, status(CART|PLACED|PAID|CANCELLED), payment_link, created_at
catalog_items           id, tenant_id->, name, description, price_cents, currency, sku, media_ref, active

handoff_events          id, tenant_id->, conversation_id->, from_mode, to_mode, agent_id->users null,
                        reason, created_at   idx(conversation_id, created_at)

-- ---------- Platform ops ----------
feature_flags           id, key(uniq), description, enabled_globally, tenant_overrides(json)
announcements           id, title, body, audience(json segment), publish_at, expires_at, created_by
platform_settings       key(pk), value(json), secret(bool), updated_at   -- SMTP, gateway keys, LLM keys, defaults
support_tickets         id, tenant_id->, user_id->, subject, status(OPEN|PENDING|CLOSED), priority,
                        assigned_admin_id->users null, created_at   idx(status)
support_messages        id, ticket_id->, author_id->users, body, attachments(json), created_at
kb_articles             id, category, title, body_md, published, sort   -- platform knowledge base / help center

-- ---------- RAG / AI engine (scale-up; mysql-fallback columns noted) ----------
kb_chunks               id, tenant_id->, knowledge_base_id->knowledge_base, ordinal, heading_path,
                        content(text, FULLTEXT idx), token_count, checksum(uniq per kb),
                        embedding_model, created_at   idx(tenant_id, knowledge_base_id)
                        -- FULLTEXT(content) always populated; vectors live in vector store or embeddings

embeddings              id, tenant_id->, owner_type(kb_chunk|conversation|semantic_cache), owner_id,
                        model, dims, vector(json/blob -- mysql fallback), created_at
                        idx(tenant_id, owner_type, owner_id)
                        -- external vector store is source of truth when RAG_DRIVER != mysql; this mirrors for fallback

prompt_templates        id, tenant_id->, chatbot_id->, version, body, variables(json),
                        is_current, created_by, created_at   uniq(chatbot_id, version)

semantic_cache          id, tenant_id->, chatbot_id->, context_hash, query_norm, query_embedding_ref,
                        reply, model, hits, kb_version, template_version, expires_at, created_at
                        idx(tenant_id, chatbot_id, context_hash)

llm_usage               id, tenant_id->, conversation_id->null, request_id(uniq), provider, model,
                        prompt_tokens, completion_tokens, cost_micros, latency_ms, cache_hit(bool),
                        escalated(bool), created_at   idx(tenant_id, created_at)

-- ---------- Reliability / idempotency ----------
outbox                  id, tenant_id->null, aggregate_type, aggregate_id, event_type, payload(json),
                        dedup_key(uniq), status(PENDING|SENT|FAILED), attempts, next_attempt_at,
                        created_at, sent_at   idx(status, next_attempt_at)
                        -- transactional outbox: written in same tx as the state change; relay publishes

idempotency_keys        id, tenant_id->null, scope, key, response_hash, locked_at, created_at
                        uniq(scope, key)   -- generic dedup for webhooks/side-effects

circuit_breakers        id, scope(provider|tenant|gateway|bridge), name, state(CLOSED|OPEN|HALF_OPEN),
                        failure_count, window_started_at, opened_at, half_open_probes, updated_at
                        uniq(scope, name)   -- persisted breaker state (also cached)

sagas                   id, tenant_id->, type(ORDER_FULFILLMENT|...), state(json),
                        status(RUNNING|COMPLETED|COMPENSATING|FAILED), current_step, created_at, updated_at
                        idx(status)
saga_steps              id, saga_id->, name, status(PENDING|DONE|COMPENSATED|FAILED), compensation_ref,
                        payload(json), created_at

-- ---------- Multi-tenancy tiers & encryption ----------
tenant_tiers            id, tenant_id->(uniq), tier(SHARED|DEDICATED_WORKER|DEDICATED_DB),
                        lane_weight(int), data_region, shard_key, dedicated_conn(json null), updated_at
encryption_keys         id, tenant_id->null, purpose(FIELD|EXPORT|BACKUP), kms_key_id, wrapped_dek(blob),
                        version, status(ACTIVE|RETIRING|RETIRED), created_at, rotated_at
                        idx(tenant_id, purpose, status)   -- envelope encryption: KMS-wrapped DEKs per tenant

-- ---------- Observability ----------
traces                  id, trace_id(idx), span_id, parent_span_id, tenant_id->null, service, operation,
                        started_at, duration_ms, status, attributes(json)   idx(trace_id)
                        -- sampled; export to OTLP collector when configured, MySQL ring-buffer fallback
metrics_rollup          id, tenant_id->null, metric, period_key, dims(json), value, created_at
                        uniq(tenant_id, metric, period_key, dims)   -- pre-aggregated for dashboards
alerts                  id, scope, name, severity, state(FIRING|RESOLVED), fingerprint(uniq active),
                        first_seen_at, last_seen_at, payload(json)   -- deduplicated alerting

-- ---------- Advanced chatbot capabilities ----------
agents_registry         id, tenant_id->, chatbot_id->, name, kind(ROUTER|SPECIALIST), skill_tags(json),
                        system_prompt_ref, tools(json), enabled   idx(tenant_id, chatbot_id)
tools_registry          id, tenant_id->, chatbot_id->, name, json_schema(json), handler(kind+ref),
                        auth(json), enabled   uniq(tenant_id, chatbot_id, name)
ab_tests                id, tenant_id->, chatbot_id->, name, unit(CONVERSATION|CONTACT),
                        status(DRAFT|RUNNING|STOPPED), variants(json weights), metric, started_at, stopped_at
                        idx(tenant_id, status)
ab_assignments          id, tenant_id->, ab_test_id->, unit_id, variant, assigned_at
                        uniq(ab_test_id, unit_id)   -- sticky assignment
flow_analytics_events   id(ulid), tenant_id->, conversation_id->, flow_id->, node_id,
                        event(ENTER|COMPLETE|DROP|ERROR), created_at
                        idx(tenant_id, flow_id, node_id, created_at)
campaign_sequences      id, tenant_id->, chatbot_id->, name, trigger(json state-condition),
                        steps(json drip), enabled   idx(tenant_id, enabled)
sequence_enrollments    id, tenant_id->, sequence_id->, contact_id->, step_index, next_run_at,
                        status(ACTIVE|DONE|CANCELLED)   uniq(sequence_id, contact_id), idx(next_run_at)
media_transcripts       id, tenant_id->, messages_inbound_id->(uniq), kind(STT|OCR), provider, text,
                        confidence, lang, created_at   -- voice-note transcription / media understanding

-- ---------- Event sourcing & analytics ----------
event_log               id(ulid, monotonic), tenant_id->, stream_id, stream_type(CONVERSATION|ORDER|SESSION|...),
                        version(int), event_type, payload(json), metadata(json trace_id,actor),
                        occurred_at   uniq(stream_id, version), idx(tenant_id, stream_type, occurred_at)
                        -- APPEND-ONLY: no UPDATE/DELETE grants for app role; projections rebuild from here
projection_checkpoints  id, projection, last_event_id, updated_at   uniq(projection)
```

**Append-only enforcement:** the application DB role has `INSERT/SELECT` on `event_log` but **no `UPDATE`/`DELETE`** grant; the same is applied to `audit_logs`, which are **hash-chained** — each row stores `prev_hash` + `row_hash = H(prev_hash || canonical(payload))` so tampering is detectable. See Correctness Properties 14 and 17.

**Enums (PHP 8.3 backed enums):**

```php
enum TenantStatus: string { case Active='ACTIVE'; case Suspended='SUSPENDED'; case Trial='TRIAL'; case Cancelled='CANCELLED'; }
enum ConversationMode: string { case Bot='BOT'; case HandoffRequested='HANDOFF_REQUESTED'; case Agent='AGENT'; }
enum QuotaKind: string { case MessagesMonthly='MESSAGES_MONTHLY'; case MessagesDaily='MESSAGES_DAILY'; case Sessions='SESSIONS'; case Contacts='CONTACTS'; case AiCredits='AI_CREDITS'; case CampaignsConcurrent='CAMPAIGNS_CONCURRENT'; }
enum FlowNodeType: string { case Message='message'; case Question='question'; case Condition='condition'; case Menu='menu'; case Action='action'; case Handoff='handoff'; case End='end'; }
enum SubscriptionStatus: string { case Trialing='TRIALING'; case Active='ACTIVE'; case PastDue='PAST_DUE'; case Cancelled='CANCELLED'; }
enum CircuitState: string { case Closed='CLOSED'; case Open='OPEN'; case HalfOpen='HALF_OPEN'; }
enum OutboxStatus: string { case Pending='PENDING'; case Sent='SENT'; case Failed='FAILED'; }
enum SagaStatus: string { case Running='RUNNING'; case Completed='COMPLETED'; case Compensating='COMPENSATING'; case Failed='FAILED'; }
enum TenantTier: string { case Shared='SHARED'; case DedicatedWorker='DEDICATED_WORKER'; case DedicatedDb='DEDICATED_DB'; }
enum RagDriver: string { case Mysql='mysql'; case Pgvector='pgvector'; case Qdrant='qdrant'; }
enum AgentKind: string { case Router='ROUTER'; case Specialist='SPECIALIST'; }
enum FlowAnalyticsEvent: string { case Enter='ENTER'; case Complete='COMPLETE'; case Drop='DROP'; case Error='ERROR'; }
```

Reused engine enums (`SessionStatus`, `MessageStatus`, `WaStatus`) are unchanged.

---

## Algorithmic Pseudocode (Low-Level Design)

### Algorithm 1 — Conversation resolution pipeline

```php
function resolveInbound(inbound): EngineOutcome
```

**Preconditions:**
- `inbound` is a webhook event that passed HMAC verification.
- `inbound.tenant_id` resolved and tenant is `ACTIVE` or `TRIAL` (not `SUSPENDED`).
- `inbound.conversation` exists or is created for (tenant, session, contact).

**Postconditions:**
- At most one reply action is enqueued per inbound message (no double-reply).
- If opted-out keyword detected, an `opt_outs` row exists and no marketing reply is sent.
- Conversation `mode` and `conversation_states` reflect the outcome.
- Any LLM call consumed AI credits atomically; if credits were 0, LLM stage is skipped.

**Loop invariant (over ordered stages):** every stage examined so far returned `PASSTHROUGH`; the first stage returning `HANDLED` or `HALT` terminates the loop and no later stage runs.

```pascal
ALGORITHM resolveInbound(inbound)
BEGIN
    ctx <- buildContext(inbound)          // tenant, conversation, contact, history
    ASSERT ctx.tenant.status IN {ACTIVE, TRIAL}

    stages <- orderedStagesByPriority()   // OptOut, LiveAgent, BusinessHours,
                                          // ActiveFlow, Keyword, IntentFaq, Llm, Fallback

    FOR each stage IN stages DO
        // INVARIANT: all previously examined stages returned PASSTHROUGH
        IF stage.requiresFeature() != NULL
           AND NOT planGate.allows(ctx.tenant, stage.requiresFeature()) THEN
            CONTINUE                       // feature not in plan -> skip stage
        END IF

        result <- stage.resolve(ctx)

        IF result.kind = HALT THEN
            RETURN outcome(HALT, stage)    // e.g. opt-out, agent-only: no bot reply
        ELSE IF result.kind = HANDLED THEN
            enqueueReply(ctx, result.reply) // through anti-ban send pipeline
            persistState(ctx, result)
            RETURN outcome(HANDLED, stage)
        END IF
        // PASSTHROUGH -> try next stage
    END FOR

    RETURN outcome(NO_MATCH)               // Fallback stage normally prevents reaching here
END
```

### Algorithm 2 — Flow node evaluation (FlowRuntime)

```php
function evaluateNode(node, ctx): NodeResult
```

**Preconditions:**
- `ctx.state.flow_id` references a `PUBLISHED` flow whose graph was validated on save.
- `node` is reachable from the flow's entry node (guaranteed by save-time validation).

**Postconditions:**
- Exactly one successor node is chosen (or the flow ends), so runtime never dead-ends on a validated graph.
- Captured input is stored in `conversation_states.variables` before branching on it.
- On `handoff` node, conversation transitions to `HANDOFF_REQUESTED`.

**Loop invariant (menu/condition edge scan):** all edges checked so far did not match; the first matching edge determines the successor; the graph guarantees at least one default/else edge for `condition`/`menu` nodes.

```pascal
ALGORITHM evaluateNode(node, ctx)
BEGIN
    CASE node.type OF
        MESSAGE:
            RETURN NodeResult(reply := render(node.text, ctx.variables),
                              next := node.singleSuccessor)

        QUESTION:
            IF ctx.awaitingAnswerFor = node.id THEN
                ctx.variables[node.var] <- ctx.lastInboundText   // capture BEFORE branching
                RETURN NodeResult(reply := NONE, next := node.singleSuccessor)
            ELSE
                ctx.awaitingAnswerFor <- node.id
                RETURN NodeResult(reply := render(node.prompt, ctx.variables), next := node.id) // stay
            END IF

        MENU:
            choice <- parseChoice(ctx.lastInboundText, node.options)
            FOR each option IN node.options DO
                // INVARIANT: no earlier option matched the user's choice
                IF option.matches(choice) THEN
                    RETURN NodeResult(reply := NONE, next := option.targetNode)
                END IF
            END FOR
            RETURN NodeResult(reply := render(node.invalidPrompt, ctx.variables), next := node.id)

        CONDITION:
            FOR each edge IN node.edges DO
                IF evalPredicate(edge.predicate, ctx) THEN
                    RETURN NodeResult(reply := NONE, next := edge.targetNode)
                END IF
            END FOR
            RETURN NodeResult(reply := NONE, next := node.elseEdge.targetNode) // guaranteed to exist

        ACTION:
            runAction(node.action, ctx)      // webhook / CRM / Google Sheet (queued, retry-safe)
            RETURN NodeResult(reply := NONE, next := node.singleSuccessor)

        HANDOFF:
            handoffService.request(ctx.conversation, reason := node.reason)
            RETURN NodeResult(reply := render(node.message, ctx.variables), next := NONE, terminal := true)

        END:
            clearState(ctx)
            RETURN NodeResult(reply := render(node.message, ctx.variables), next := NONE, terminal := true)
    END CASE
END
```

### Algorithm 3 — Plan-gated, quota-metered send (extends the engine's SendMessageJob)

```php
function tenantSendGate(tenant, message): GateVerdict
```

**Preconditions:**
- `tenant` is resolved; `message.tenant_id = tenant.id` (enforced by global scope).
- Existing anti-ban gate (rate limit, quiet hours, warm-up) still applies unchanged.

**Postconditions:**
- Message is sent only if BOTH the tenant plan quota AND the anti-ban caps allow it.
- On quota exhaustion the job is `release()`d or blocked (never dropped); usage counter reflects exactly the messages actually sent (no double count on retry).

```pascal
ALGORITHM tenantSendGate(tenant, message)
BEGIN
    IF NOT planGate.allows(tenant, feature := featureFor(message)) THEN
        RETURN block(reason := FEATURE_NOT_IN_PLAN)
    END IF

    v <- quotaGuard.verdict(tenant, MESSAGES_MONTHLY, 1)
    IF v = BLOCK THEN RETURN block(QUOTA_EXCEEDED) END IF
    IF v = DEFER THEN RETURN defer(secondsUntilPeriodReset(tenant)) END IF

    // hand off to existing anti-ban gate (rate + quiet hours + warm-up + cooldown)
    antiBanVerdict <- antiBanEngine.gate(message)
    IF antiBanVerdict.defer THEN RETURN defer(antiBanVerdict.seconds) END IF
    IF antiBanVerdict.block THEN RETURN block(antiBanVerdict.reason) END IF

    RETURN allow()
    // NOTE: quotaGuard.consume() is called exactly once, AFTER the bridge confirms send,
    //       keyed by message.idempotency_key so a job retry never double-consumes.
END
```

### Algorithm 4 — Flow graph validation (save-time)

```php
function validateFlowGraph(graph): ValidationResult
```

**Preconditions:** `graph` has `nodes[]` and `edges[]`.

**Postconditions:** A flow can be published only if: exactly one entry node; every non-terminal node has a defined successor for every branch; every node is reachable from entry; every path can reach a terminal (`end`/`handoff`) node (no infinite non-terminating cycle without an exit).

```pascal
ALGORITHM validateFlowGraph(graph)
BEGIN
    entries <- nodes WHERE isEntry
    IF count(entries) != 1 THEN RETURN invalid("exactly one entry node required") END IF

    reachable <- BFS(from := entries[0], via := edges)
    FOR each node IN graph.nodes DO
        // INVARIANT: nodes checked so far were all reachable
        IF node NOT IN reachable THEN RETURN invalid("unreachable node: " + node.id) END IF
    END FOR

    FOR each node IN graph.nodes DO
        IF node.type IN {CONDITION, MENU} AND NOT hasDefaultEdge(node) THEN
            RETURN invalid("branch node missing default/else edge: " + node.id)
        END IF
        IF node.type NOT IN {END, HANDOFF} AND successorCount(node) = 0 THEN
            RETURN invalid("dangling node: " + node.id)
        END IF
    END FOR

    IF NOT everyPathReachesTerminal(graph, entries[0]) THEN
        RETURN invalid("a path never reaches an end/handoff node")
    END IF

    RETURN valid()
END
```

### Algorithm 6 — Transactional outbox relay (exactly-once side effects)

```php
function relayOutbox(): void
```
**Preconditions:** every side-effecting state change (webhook fire, gateway callback ack, downstream notify) wrote its intent into `outbox` **inside the same DB transaction** as the state change, with a unique `dedup_key`.
**Postconditions:** each `outbox` row is delivered **at least once** and processed **at most once** at the consumer (consumer dedups on `dedup_key`), giving effective **exactly-once**; a row is marked `SENT` only after the receiver acks; failures retry with backoff and never lose the row. Covered by **Correctness Property 16 (outbox exactly-once)**.
**Loop invariant:** at each iteration, every row already marked `SENT` was acknowledged by the receiver at least once; unsent rows remain `PENDING`/`FAILED` and are eligible for retry.

```pascal
ALGORITHM relayOutbox()
BEGIN
    batch <- outbox WHERE status IN {PENDING, FAILED}
                    AND next_attempt_at <= now()
                    ORDER BY id LIMIT N   FOR UPDATE SKIP LOCKED
    FOR each row IN batch DO
        // INVARIANT: rows marked SENT before now were acked >= once
        TRY
            deliver(row.event_type, row.payload, headers := {dedup_key: row.dedup_key})
            row.status <- SENT; row.sent_at <- now()
        CATCH transient e
            row.attempts <- row.attempts + 1
            row.status <- FAILED
            row.next_attempt_at <- now() + backoffWithJitter(row.attempts)
        END TRY
        persist(row)
    END FOR
END
```

### Algorithm 7 — Circuit breaker (per-provider / per-tenant guarded call)

```php
function guardedCall(scope, name, operation): Result
```
**Preconditions:** `operation` is an external call (LLM provider, payment gateway, bridge) that may fail/timeout; breaker state for `(scope,name)` is persisted + cached.
**Postconditions:** while `OPEN`, `operation` is **not** invoked and the caller gets a fast typed failure (which triggers fallback); a bounded number of probes run in `HALF_OPEN`; success in `HALF_OPEN` closes the breaker, failure re-opens it. The breaker never invokes `operation` while `OPEN`. Covered by **Correctness Property 13**.
**Loop invariant (over the failure window):** `failure_count` counts only failures within the current rolling window; window rotation resets it without losing an in-window failure.

```pascal
ALGORITHM guardedCall(scope, name, operation)
BEGIN
    b <- breaker(scope, name)
    IF b.state = OPEN THEN
        IF now() - b.opened_at >= openDuration THEN b.state <- HALF_OPEN; b.half_open_probes <- 0
        ELSE RETURN fastFail(CIRCUIT_OPEN) END IF     // operation NOT invoked
    END IF

    IF b.state = HALF_OPEN AND b.half_open_probes >= probeLimit THEN
        RETURN fastFail(CIRCUIT_OPEN)
    END IF

    TRY
        r <- operation()                              // the only place operation() runs
        IF b.state = HALF_OPEN THEN b.state <- CLOSED END IF
        b.failure_count <- 0
        RETURN r
    CATCH e
        rotateWindowIfExpired(b)                       // INVARIANT: count is within-window only
        b.failure_count <- b.failure_count + 1
        IF b.state = HALF_OPEN
           OR b.failure_count >= failureThreshold
           OR errorRate(b) > 0.5 THEN
            b.state <- OPEN; b.opened_at <- now()
        END IF
        THROW e
    FINALLY
        persist(b)
    END TRY
END
```

### Algorithm 8 — Saga orchestration with compensation (order → payment → fulfilment)

```php
function runSaga(saga): SagaOutcome
```
**Preconditions:** `saga.steps` is an ordered list; each step has a forward action and an idempotent compensation; forward actions are idempotent (keyed by `saga_id, step`).
**Postconditions:** either **all** forward steps complete (`COMPLETED`), or a failing step triggers compensation of all previously-completed steps **in reverse order** leaving no partial side effect (`COMPENSATING → FAILED`); compensations are idempotent so retrying the saga is safe. Covered by **Correctness Property 18 (saga atomicity)**.
**Loop invariant:** at any point, `done[]` holds exactly the steps whose forward action succeeded and whose compensation has not yet run.

```pascal
ALGORITHM runSaga(saga)
BEGIN
    done <- []
    FOR step IN saga.steps DO
        TRY
            executeIdempotent(step.forward, key := saga.id + ':' + step.name)
            done.push(step); markDone(step)
        CATCH e
            saga.status <- COMPENSATING
            FOR s IN reverse(done) DO       // INVARIANT: done = succeeded, not-yet-compensated
                compensateIdempotent(s.compensation, key := saga.id + ':' + s.name)
                markCompensated(s)
            END FOR
            saga.status <- FAILED
            RETURN failed(step, e)
        END TRY
    END FOR
    saga.status <- COMPLETED
    RETURN completed()
END
```

---

## Key Functions with Formal Specifications

### `QuotaGuard::consume(tenant, kind, units)`
- **Preconditions:** `units > 0`; called at most once per unit of billable work (keyed by idempotency key at the call site).
- **Postconditions:** `tenant_usage.used` increased by exactly `units` for the current period; the increment is atomic under `Cache::lock("quota:{tenant}:{kind}")`; never exceeds `limit` unless overage is explicitly allowed by plan.
- **Loop invariant:** N/A (single atomic update).

### `WalletService::debit(tenant, amount, reason)`
- **Preconditions:** `amount > 0`.
- **Postconditions:** returns a `wallet_txns` row with `balance_after >= 0`; if balance is insufficient, throws `InsufficientFundsException` and no row is written; operation is serialized per tenant wallet (`Cache::lock`).
- **Loop invariant:** N/A.

### `HandoffService::agentSend(conversation, agent, content)`
- **Preconditions:** `conversation.mode = AGENT`; `agent` is assigned to this conversation and is a member of the tenant.
- **Postconditions:** message is enqueued through the same anti-ban/plan-gated send pipeline (agents are not exempt from rate limits); `handoff_events` unchanged (send is not a mode transition); `conversation.last_outbound_at` updated.
- **Loop invariant:** N/A.

### `SubscriptionService::onGatewayEvent(event)`
- **Preconditions:** `event` came from `PaymentGateway::verifyWebhook` (signature valid); `event.gateway_event_id` recorded.
- **Postconditions:** processed exactly once (idempotent on `payment_events.gateway_event_id` unique index); subscription/tenant status transitions follow the allowed state map; a duplicate delivery is a no-op.
- **Loop invariant:** N/A.

---

## Example Usage

```php
// --- Inbound message -> conversation engine (queued on the ai-reply lane) ---
class HandleInboundMessageJob implements ShouldQueue, ShouldBeUnique
{
    public function uniqueId(): string { return $this->waMessageId; } // dedupe redelivered webhooks

    public function handle(TenantContext $tenants, ConversationEngine $engine): void
    {
        $tenants->set($this->tenant);                 // establish tenant scope for this job
        $engine->handle(InboundMessage::fromWebhook($this->payload));
    }
}

// --- Publishing a chatbot flow: validation blocks broken graphs ---
$result = app(FlowValidator::class)->validate($graph);
if (! $result->valid) {
    throw new InvalidFlowException($result->reason);  // cannot publish
}
$flow->update(['status' => 'PUBLISHED', 'is_current' => true]);

// --- Order/booking bot generates a payment link ---
$link = app(PaymentGateway::class)->createPaymentLink(
    tenant: $tenant, amount: $cart->total(), ref: "order:{$order->id}"
);
$reply = "Your total is {$cart->total()}. Pay here: {$link->url}";

// --- Admin panel: platform-wide view bypasses tenant scope ---
app(TenantContext::class)->actingAsPlatform();        // super-admin only, audited
$allActiveSessions = SessionWa::query()->where('status', 'CONNECTED')->count(); // across all tenants
```

---

## Correctness Properties

These are the invariants the test suite (property-based where noted) must hold. Universal quantification is over all valid inputs.

### Property 1: Tenant isolation

∀ tenant t, ∀ query q issued in t's context → q returns only rows where `tenant_id = t.id`. No API/panel path returns another tenant's row. *(Property test: seed 2 tenants, assert every read endpoint is disjoint.)*

**Validates: Requirements A1**

### Property 2: Single reply per inbound

∀ inbound message m → `resolveInbound(m)` enqueues at most one outbound reply. *(Property test over random stage configurations.)*

**Validates: Requirements B1.2**

### Property 3: Opt-out is absolute

∀ contact c with an `opt_outs` row → no marketing/campaign/bot reply is ever enqueued to c (opt-out short-circuits and has no bypass parameter).

**Validates: Requirements B2.4, D5.3**

### Property 4: Quota never negative, never double-counted

∀ tenant t, ∀ retry of a send job → `tenant_usage.used` increases by exactly the number of messages the bridge confirmed, and never exceeds `limit` (except explicit plan overage).

**Validates: Requirements A3.5**

### Property 5: Wallet non-negativity

∀ debit sequence → wallet balance ≥ 0 at all times.

**Validates: Requirements D2.3**

### Property 6: Flow termination

∀ published flow f, ∀ contact traversal → traversal reaches an `end`/`handoff` node in finite steps (guaranteed by save-time validation Algorithm 4).

**Validates: Requirements B5.4**

### Property 7: Plan gating

∀ tenant t, ∀ feature not in t.plan → the feature's API/panel/stage is inaccessible and its stage is skipped.

**Validates: Requirements B2.3**

### Property 8: Gateway idempotency

∀ payment webhook delivered N≥1 times → the subscription/wallet effect is applied exactly once.

**Validates: Requirements D2.2**

### Property 9: Status monotonicity (reused)

∀ message → status never downgrades (out-of-order acks ignored via `rank()`).

**Validates: Requirements A3.3**

### Property 10: Handoff silence

∀ conversation in `AGENT` mode → the bot enqueues zero automatic replies for that conversation.

**Validates: Requirements B8.2**

### Property 11: RAG citation grounding

∀ AI reply r produced by the RAG path, ∀ citation marker `[[source:c]]` in r → chunk `c` was in the retrieved set for that request, and every factual sentence in r maps to at least one retrieved chunk; ungrounded factual claims are suppressed or escalated. *(Property test: random KB + query, assert citations ⊆ retrieved and no citation is cross-tenant.)*

**Validates: Requirements B4.1** *(deepened; regeneration adds a dedicated RAG-grounding criterion, e.g. B4.5)*

### Property 12: Semantic-cache soundness

∀ semantic-cache hit returned for query q in context ctx → the cached entry was created for the **same tenant, chatbot, KB version, and template version**, and its stored query is within the similarity threshold of q; a cache entry is never served across tenants or after a KB/template version bump. *(Property test over random tenants/queries; assert no cross-tenant or stale-version hit.)*

**Validates: Requirements B4.1, NFR1.1** *(regeneration adds an explicit caching criterion, e.g. NFR1.4)*

### Property 13: Circuit-breaker safety

∀ breaker in state `OPEN` → the guarded operation is never invoked; ∀ transition sequence → `CLOSED→OPEN` occurs only after the failure threshold/error-rate within the window, and `HALF_OPEN` admits at most `probeLimit` probes. *(Property test over random failure/success sequences; assert operation-not-called-while-open.)*

**Validates: Requirements B4.4, NFR2** *(regeneration adds an explicit resilience criterion)*

### Property 14: Event-log append-only & monotonic versioning

∀ stream s, ∀ appended events → versions are strictly increasing with no gaps for a given `stream_id`, no event is ever updated or deleted, and rebuilding a projection from `event_log` reproduces the current read-model state. *(Property test: random event streams, assert monotonic versions + projection replay equals live state.)*

**Validates: Requirements A3.3, B7.4** *(regeneration adds an explicit event-sourcing criterion)*

### Property 15: PII never egresses raw to the LLM

∀ text t sent to an LLM/embedding provider → t contains no unmasked phone number, email, or card-like sequence (all replaced by reversible tokens), and rehydration restores the customer-facing reply exactly. *(Property test: generate messages seeded with PII, assert redacted payload matches no PII regex and `rehydrate(redact(x)) == x`.)*

**Validates: Requirements NFR3.2, NFR3.3** *(regeneration adds an explicit PII-redaction criterion)*

### Property 16: Outbox exactly-once effect

∀ side effect written to `outbox` and delivered N≥1 times → the downstream effect is applied exactly once (consumer dedups on `dedup_key`), and no `outbox` row is lost even across relay crashes. *(Property test over random crash/retry interleavings.)*

**Validates: Requirements D2.2, NFR2.2**

### Property 17: Audit-log hash-chain integrity

∀ audit row i>0 → `row_hash_i = H(row_hash_{i-1} || canonical(payload_i))`; any mutation of a past row breaks the chain and is detectable by a verifier. *(Property test: build a chain, mutate a random row, assert verification fails.)*

**Validates: Requirements D1.2** *(regeneration adds an explicit audit-integrity criterion)*

### Property 18: Saga atomicity (all-or-compensated)

∀ saga execution → either all forward steps complete, or every completed step is compensated in reverse order, leaving no partial side effect; compensations are idempotent so re-running the saga is safe. *(Property test: inject failure at each step index, assert no orphaned side effect.)*

**Validates: Requirements B6.3, B6.4** *(regeneration adds an explicit transactional-flow criterion)*

### Property 19: Fair scheduling / noisy-neighbor bound

∀ set of tenants with pending work → over any dispatch window, the share of dispatched units per tenant is proportional to its `lane_weight` within a bounded error; no single tenant can starve others beyond that bound. *(Property test: random per-tenant backlogs, assert weighted-fair dispatch.)*

**Validates: Requirements NFR1.2**

### Property 20: Tenant-scoped retrieval isolation

∀ retrieval (vector or FULLTEXT) issued in tenant t's context → every returned chunk has `tenant_id = t.id`; no embedding or KB chunk of another tenant can be retrieved or cited. *(Property test: 2-tenant KB seed, assert retrieval disjointness.)*

**Validates: Requirements A1.2, B4.1**

---

## Error Handling

New exceptions extend the engine's `AppException` hierarchy:

```
AppException
├── TenantException
│   ├── TenantSuspendedException        → 403, non-retryable
│   └── CrossTenantAccessException      → 403, non-retryable  (should be impossible; defense in depth)
├── PlanException
│   ├── FeatureNotInPlanException       → 402/403, non-retryable
│   └── QuotaExceededException          → 429, retryable-as-defer until period reset
├── BillingException
│   ├── InsufficientFundsException      → 402, non-retryable
│   └── GatewayException                → 502, retryable
├── ChatbotException
│   ├── InvalidFlowException            → 422, non-retryable (publish blocked)
│   ├── LlmProviderException            → 502, retryable (falls back to no-AI stages)
│   ├── GroundingFailedException        → internal; downgrade/drop unsupported claim or handoff
│   └── ToolInvocationException         → internal; JSON-repair retry then rule-based fallback
├── ResilienceException
│   ├── CircuitOpenException            → fast-fail; triggers fallback chain (never crashes job)
│   └── SagaCompensatedException        → 409/internal; all steps compensated, safe to retry saga
├── SecurityException
│   ├── PromptInjectionBlockedException → logged to abuse_events; reply suppressed
│   └── KeyUnavailableException         → 503, retryable (KMS/DEK fetch failed; no plaintext leak)
```

**Retry / backoff matrix (per error class, `RetryPolicy`):** exponential base `250ms`, **full jitter** `delay = rand(0, base·2^attempt)`, capped at `30s`.

| Error class | Retryable | Max attempts | Backoff | Notes |
|---|---|---|---|---|
| Transient network / 5xx (LLM, gateway, bridge) | Yes | 5 | exp + full jitter | trips circuit breaker on repeated failure |
| Rate-limited (429 from provider) | Yes (defer) | 8 | honor `Retry-After` else exp | separate from tenant quota defer |
| Timeout | Yes | 3 | exp + jitter | shorter cap; may escalate model tier |
| Validation / 4xx (bad request, schema) | No | 0 | — | one JSON-repair retry for structured output only |
| Auth / signature invalid (webhook) | No | 0 | — | reject + log; not enqueued |
| Quota exceeded | Defer | until reset | period-reset scheduler | never dropped |
| Circuit open | No (fast-fail) | 0 | — | fallback chain handles it |

| Scenario | Condition | Response | Recovery |
|---|---|---|---|
| Quota exhausted mid-campaign | `MESSAGES_MONTHLY` hit | Job `release()`d; campaign marked `QUOTA_PAUSED`; tenant notified | Auto-resumes at next period reset or after top-up/upgrade |
| LLM primary down/timeout | `LlmProviderException` / breaker OPEN | Fallback chain: secondary provider → local heuristic; log; alert if error-rate spikes | Auto-recovers when breaker half-opens and probes succeed |
| RAG grounding failure | `GroundingFailedException` | Drop unsupported sentence or escalate to handoff | Next inbound retries; KB may need enrichment |
| Vector store unavailable | ANN call fails / breaker OPEN | Fall back to FULLTEXT-only retrieval; emit `rag.degraded` | Auto-recovers; ingestion backlog relays later |
| Payment webhook replay | Duplicate `gateway_event_id` | No-op (idempotent) | — |
| Suspended tenant sends | `TenantStatus::Suspended` | All outbound blocked; inbound still logged, no bot reply | Admin reactivates tenant |
| Broken flow published attempt | `validateFlowGraph` fails | `InvalidFlowException`, publish rejected, editor shows the failing node | Fix flow, re-validate |
| Saga step fails (order→pay→fulfil) | any forward step error | Compensate completed steps in reverse; mark `FAILED`; notify | Retry saga (idempotent) or manual review |
| Prompt-injection detected | guardrail `block` | Suppress reply, log to `abuse_events`, rate-limit conversation | Human review; tenant policy tuning |
| KMS/DEK fetch fails | `KeyUnavailableException` | 503 retryable; **no plaintext fallback** | Retry after KMS recovers |
| STT provider down | transcribe fails | Ack voice note, ask user to type (degraded) | Retry when provider back |
| Relay/outbox crash mid-send | worker dies | Row stays `PENDING`; redelivered on restart; consumer dedups | Exactly-once effect preserved |

**Graceful degradation modes (what still works when a dependency is down):**

| Dependency down | Still works | Degraded / off |
|---|---|---|
| LLM (both providers) | opt-out, live-agent, business-hours, active-flow, keyword, FAQ, fallback, handoff, all messaging | AI smart replies (fall to FAQ/fallback), sentiment/lang (local heuristic) |
| Vector store | LLM replies via FULLTEXT-grounded RAG, all rules | high-recall semantic retrieval, episodic memory recall |
| Bridge (a session) | queue accepts & holds sends, panels, billing, flow authoring | actual send for that session (auto-resumes on reconnect) |
| Payment gateway | messaging, chatbot, panels | new checkouts/top-ups (retry); existing subs unaffected until renewal |
| Redis (if adopted) | everything (auto-fallback to MySQL queue/cache/locks) | peak throughput headroom |
| KMS | reads of already-decrypted-in-request data | new encrypt/decrypt of sensitive fields (fail-closed) |

---

## Testing Strategy

**Unit (Pest):** resolution-pipeline ordering, flow node evaluation for each node type, flow graph validation (reachability/termination), quota arithmetic, wallet non-negativity, gateway idempotency, language/sentiment heuristics, coupon math, proration.

**Property-based (Pest + a generator helper, mirroring the engine's approach):** properties **1–20** above. Notably tenant isolation (random 2-tenant seed, assert read disjointness), single-reply, quota-no-double-count under random retry interleavings, flow termination over randomly generated *valid* graphs, **RAG citation ⊆ retrieved & tenant-scoped (11, 20), semantic-cache soundness (12), circuit-breaker never-invokes-while-open (13), event-log monotonicity + projection replay (14), PII redaction round-trip (15), outbox exactly-once under crash interleavings (16), audit hash-chain tamper detection (17), saga all-or-compensated with per-step fault injection (18), weighted-fair scheduling (19)**.

**Feature:** `FakeBridgeClient` + `FakeLlmProvider` + `FakeGateway` + `FakeVectorStore` + `FakeEmbedder` + `FakeStt` + `FakeKms` bound in the container. Full inbound→reply flow, handoff lifecycle, flow builder publish, subscription lifecycle via fake gateway webhooks, wallet top-up/debit, plan upgrade/downgrade proration, RAG ingest→retrieve→cite, model-router escalation, A/B sticky assignment, saga order-fulfilment with injected failures, tenant offboarding + hard-delete verification.

**HTTP / Panel:** every User Panel and Admin Panel route — tenant scope enforced, RBAC enforced, platform-admin routes require the platform guard + IP allowlist. Livewire component tests for the flow builder canvas and live inbox.

**Load:** N tenants each running campaigns concurrently — assert per-tenant rate limits and quotas hold independently, no cross-tenant queue starvation (fair lane scheduling), zero duplicate `wa_message_id`.

---

## Performance & Scalability Considerations

- **MySQL queue ceiling:** with many tenants the single `jobs` table becomes the throughput bottleneck. Mitigation: per-lane workers + `idx(tenant_id, ...)` on hot tables; **Redis is the documented drop-in upgrade** (`QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`) once tenant concurrency warrants it.
- **LLM latency/cost:** AI replies run on a dedicated `ai-reply` lane so a slow provider doesn't block transactional/campaign lanes; responses cached by (chatbot, normalized-question) where safe; AI credits cap spend per tenant.
- **Fair scheduling:** campaign dispatch is round-robined across tenants so one large tenant can't starve others.
- **Knowledge base retrieval:** optional embedding search (`embedding_ref`) for FAQ/RAG; degrades to keyword search when embeddings are disabled.
- **Read scaling:** platform-admin cross-tenant analytics run against read replicas / pre-aggregated snapshot tables rather than scanning live domain tables.

### Partitioning & sharding strategy (high-volume tables)

The hot tables (`messages`, `messages_inbound`, `conversations`, `event_log`, `flow_analytics_events`, `llm_usage`) grow fastest. Strategy:

| Table | Partitioning | Rationale / retention |
|---|---|---|
| `messages`, `messages_inbound` | **RANGE by month** on `created_at` (MySQL native partitioning) | Cheap `DROP PARTITION` for retention; queries are recency-biased; index `(tenant_id, created_at)` local to partition |
| `event_log` | RANGE by month on `occurred_at`, `stream_id` clustered | Append-only; old partitions archived to cold storage, never mutated |
| `conversations` | Hot/cold split: `OPEN` live, `CLOSED` archived after N days | Live table stays small; closed rows moved by a nightly job |
| `flow_analytics_events`, `llm_usage` | RANGE by month; roll up into `metrics_rollup` then drop | Raw events short-lived; aggregates kept long-term |

**Tenant sharding option (scale-up).** Row-level `tenant_id` remains the default. When a single MySQL primary saturates, tenants are distributed across **shards keyed by `tenant_tiers.shard_key`** (consistent-hash of `tenant_id`). A lightweight **shard router** (`TierResolver::connection`) picks the connection per tenant; cross-shard platform analytics read from the warehouse/replica, never fan-out live queries. **Chosen / Rejected:**

| Option | Chosen / Rejected | Rationale |
|---|---|---|
| Time-based RANGE partitioning first | **Chosen (v1)** | Solves retention + recency queries with zero app changes; native to MySQL 8 |
| Tenant sharding by hash of `tenant_id` | **Chosen (scale-up)** | Horizontal write scaling; dedicated shard for enterprise tenants; router is thin |
| Split by table into microservices | Rejected | Breaks the monolith simplicity, distributed transactions; not warranted |

### Redis upgrade path (detailed)

MySQL is the default for queue/cache/locks/sessions. Redis is the documented drop-in once concurrency warrants it; it unlocks capabilities MySQL can't cheaply provide:

| Use | MySQL default | Redis upgrade |
|---|---|---|
| Queue | `jobs` table (poll) | `QUEUE_CONNECTION=redis`, BRPOPLPUSH, lower latency, per-lane lists |
| Cache | `cache` table | `CACHE_STORE=redis`, sub-ms reads for config/flags/hot flows |
| Locks | `cache_locks` | Redis `SET NX PX` locks (quota, wallet, breaker) — faster, TTL-safe |
| Rate limits | DB counters | **Token-bucket** via `INCR`/Lua sliding window per (tenant, session) |
| Live inbox / broadcast | `wire:poll` | Redis pub/sub + Laravel broadcasting (websockets) for real-time |
| Semantic cache index | table scan | Redis hash + optional RediSearch vector index |

**Connection pooling:** PHP-FPM is share-nothing per request; use **persistent Redis connections** + a small pool per worker; for MySQL, tune `pm.max_children` against `max_connections`, add a **read-replica connection** (`DB_READ_HOST`) for analytics/dashboards (`useReadPdo`), and route breaker/quota writes to the primary.

### Queue throughput tuning

| Lane | Concurrency (workers) | Prefetch | Backpressure signal | Autoscale trigger |
|---|---|---|---|---|
| `transactional` | high (e.g. 8) | 1 | queue depth > 500 | depth or age p95 > 5s |
| `ai-reply` | medium (e.g. 4) | 1 | provider latency / breaker OPEN | depth > 200 or latency p95 > 4s |
| `welcome` | low (2) | 1 | — | depth > 1000 |
| `campaign` | medium (4), **fair per tenant** | 1 | per-tenant rate cap | depth > 2000 |
| `extraction`/`ingest` | low (2) | batch | CPU | backlog age > 15m |

- **Prefetch = 1** on send lanes so a slow bridge doesn't hog buffered jobs; larger batches only on ingest/embedding.
- **Backpressure:** when a lane's depth or oldest-job age crosses threshold, the dispatcher **sheds/defers** low-priority work (campaign, welcome) and pages if `transactional` is affected.
- **Worker autoscaling signals** (exported to `/metrics`): `queue_depth{lane}`, `job_age_seconds{lane}`, `worker_busy_ratio`. Supervisor `numprocs` is the floor; a scaler (k8s HPA / systemd + script) scales on these.

### Caching layers & invalidation

| Layer | Contents | TTL | Invalidation |
|---|---|---|---|
| Config / feature flags | plan features, global flags, tenant overrides | 5 min | event `flag.changed` / plan update → targeted `Cache::forget` + version bump |
| Tenant settings | timezone, business hours, chatbot settings | 10 min | write-through on save |
| Hot flows | published flow graphs (interpreted) | until publish | `flow.published` version bump (never serve stale graph) |
| Prompt templates | rendered system prompts | until version bump | `template.version` change |
| Semantic cache | LLM replies keyed by context hash | 24 h | KB/template version bump; explicit purge |
| Retrieval | embeddings for repeated queries | 1 h | KB re-ingest |

All caches are **versioned** (`{key}:v{n}`) so invalidation is a cheap version bump rather than a scan.

### Concrete load targets & capacity planning

| Metric | Target |
|---|---|
| Sustained inbound processing | ≥ **50 msgs/sec/worker** on `ai-reply` for FAQ/cache path; ≥ 8/sec when hitting the LLM |
| Outbound send (per session) | bounded by anti-ban warm-up (e.g. 1 msg / 6–20s); **throughput scales with session count**, not workers |
| p95 latency budgets | transactional enqueue→bridge < **2s**; AI cache-hit reply < **150ms**; AI LLM reply < **3s** (see §AI 1.4) |
| Queue drain | any lane drains its steady-state backlog within **60s** at target concurrency |
| Capacity model | `workers = ceil(peak_msgs_per_sec / per_worker_throughput)`; `db_conns ≈ workers + fpm_children + headroom`; provision Redis when `jobs` insert rate > ~2k/s |

---

## Scalability & Performance at Scale (Deep Dive)

> Extends NFR1. Regeneration will add explicit criteria for partitioning/retention (NFR1.5), fair-scheduling bounds (NFR1.6), and load/capacity targets (NFR1.7).

The subsections above (partitioning, Redis, queue tuning, caching, load targets) are the concrete plan. Two additional scale mechanics:

- **Read replicas for analytics.** Dashboards and cross-tenant reports use `DB_READ_HOST` (replica) with `useReadPdo`; writes always hit the primary. Replica lag is monitored; reports tolerate eventual consistency (they read `metrics_rollup`, not live rows).
- **Backpressure & shedding as a first-class control loop.** The dispatcher continuously compares per-lane depth/age against thresholds and applies weighted-fair dispatch (Property 19), deferring non-urgent lanes before urgent ones — this is what prevents a viral campaign from delaying transactional replies.

---

## Advanced Multi-Tenancy & Isolation (Deep Dive)

> Extends A1/A2. Regeneration adds criteria for tenant tiers (A1.6), noisy-neighbor bounds (A1.7 / NFR1.6), per-tenant encryption (NFR3.5), and tenant lifecycle/offboarding (A1.8, D5.4).

### Tenant tiers

| Tier | Isolation | For | Migration path |
|---|---|---|---|
| **SHARED** | row-level `tenant_id`, shared workers/DB | default, SMB | grows in place |
| **DEDICATED_WORKER** | shared DB, **own Supervisor worker group + lanes** | high-volume tenants | flip `tenant_tiers.tier`, spin dedicated workers; no data move |
| **DEDICATED_DB** | own MySQL (shard/connection), own workers | enterprise, data-residency | online copy → dual-write → cutover via `TierResolver::connection` |

Hybrid model: a tenant can be `DEDICATED_WORKER` while still on the shared DB; escalate to `DEDICATED_DB` only when isolation/compliance demands it. The router (`TierResolver`) makes tier a **config flip**, not a code change.

### Noisy-neighbor prevention

- **Per-tenant lane weighting & fair scheduling.** Campaign/AI dispatch is **weighted round-robin** by `tenant_tiers.lane_weight` (deficit round-robin), bounding any tenant's share per window (Property 19).
- **Quota-aware dispatch.** A tenant at its rate cap is skipped in the dispatch loop (not spun), freeing workers for others.
- **Per-tenant circuit breaking.** Breakers are scoped `(scope=tenant, name=...)` so one tenant hammering a failing integration opens **only its** breaker.
- **Per-tenant concurrency caps.** Max in-flight AI calls per tenant prevents one tenant from consuming the whole `ai-reply` lane.

### Per-tenant encryption & data residency

- **Envelope encryption.** Sensitive fields (lead PII, order details, KB where marked, WA auth-state) are encrypted with a **per-tenant Data Encryption Key (DEK)**; the DEK is wrapped by a **KMS master key** and stored in `encryption_keys.wrapped_dek`. `FieldCipher` fetches+unwraps the DEK (cached in-process, never persisted in plaintext). Rotation re-wraps DEKs and marks old versions `RETIRING`.
- **Data residency.** `tenant_tiers.data_region` pins a tenant's DB shard, object storage bucket, and (where offered) LLM region endpoint to a geography; `DEDICATED_DB` tenants can be provisioned in-region.

### Tenant lifecycle

```mermaid
stateDiagram-v2
    [*] --> TRIAL: provision
    TRIAL --> ACTIVE: subscribe
    ACTIVE --> SUSPENDED: non-payment / abuse
    SUSPENDED --> ACTIVE: reactivate
    ACTIVE --> CANCELLED: cancel / offboard
    SUSPENDED --> CANCELLED: cancel
    CANCELLED --> [*]: hard-delete after retention window (verified)
```

- **Provisioning** (`TenantLifecycle::provision`): create tenant, wallet, default chatbot, per-tenant DEK, storage prefix, seed plan.
- **Suspension:** block all outbound (Property-guarded), keep inbound logging; panels read-only.
- **Offboarding/export:** `export()` produces a portable archive (contacts, conversations, invoices) via signed URL; `offboard()` schedules deletion.
- **Hard-delete with verification:** after the retention window, a purge job deletes tenant rows across all tables **and vector store + object storage**, then a **verification pass** asserts zero residual rows/objects for that `tenant_id` and writes a signed deletion certificate to the audit log (right-to-delete evidence).

---

## Reliability & Fault Tolerance (Deep Dive)

> Extends NFR2. Regeneration adds criteria for circuit breakers (NFR2.3), transactional outbox (NFR2.4), saga compensation (NFR2.5 / B6), and DR targets (NFR2.6).

### Circuit breakers

Three breaker families, each `(scope, name)` scoped and persisted in `circuit_breakers` (Algorithm 7):

| Breaker | Trip threshold | Open duration | Half-open probes | Fallback |
|---|---|---|---|---|
| LLM provider (per provider, per tenant) | ≥5 fails / 30s or >50% err over 20 | 30s | 3 | secondary provider → local heuristic |
| Payment gateway (per gateway) | ≥5 fails / 60s | 60s | 3 | queue + retry; surface "try again" |
| Bridge (per session) | ≥3 fails / 30s | 15s | 2 | hold sends in queue; reconnect flow |

### Idempotency everywhere (outbox pattern)

- **Transactional outbox + relay** (Algorithm 6): any webhook fire / downstream notify / side effect is written to `outbox` **in the same transaction** as the state change, then a relay worker delivers it with a `dedup_key` header; consumers dedup. This turns dual-writes into a single atomic write, giving **effective exactly-once** (Property 16).
- **Dedup keys everywhere:** inbound webhooks dedup on `wa_message_id` (`ShouldBeUnique`), gateway on `gateway_event_id`, sends on `idempotency_key`, generic side effects via `IdempotencyStore::once(scope, key)`.

### Saga pattern for multi-step flows

Order → payment → fulfilment is a **saga** (Algorithm 8) with per-step compensations (reverse reservation, void payment link, cancel order). Sagas are persisted (`sagas`, `saga_steps`) so a crash resumes mid-flight; compensations are idempotent (Property 18).

```mermaid
graph LR
    R[Reserve items] --> P[Create payment link]
    P --> W[Await paid webhook]
    W --> F[Fulfil / confirm order]
    F --> D[Done]
    R -.comp.-> RC[Release reservation]
    P -.comp.-> PC[Void payment link]
    W -.comp.-> WC[Cancel order + refund]
```

### Disaster recovery

| Target | Value |
|---|---|
| **RPO** (max data loss) | ≤ 5 min (binlog shipping / PITR); ≤ 15 min for object storage |
| **RTO** (max downtime) | ≤ 30 min (restore + reattach workers) |
| MySQL backup | nightly full + continuous binlog; **PITR** tested by restore drills |
| WA auth-state | per-tenant auth dirs backed up encrypted; bridge **session recovery** re-pairs from restored state or prompts re-scan |
| Multi-AZ | primary + standby in a second AZ; replica promotable; object storage cross-AZ replicated |
| Bridge session recovery | on bridge restart, sessions reconnect from persisted auth state; unrecoverable sessions flagged for tenant re-auth |
| DR runbook | documented restore order: DB → object storage → bridge auth → workers → smoke test; quarterly game-day |

---

## Security & Compliance Hardening (Deep Dive)

> Extends NFR3 / D5. Regeneration adds criteria for threat-model coverage (NFR3.5), key rotation (NFR3.6), data-subject requests (D5.5), and abuse/anti-fraud (D5.6).

### STRIDE threat model (main trust boundaries)

| Boundary | Threat (STRIDE) | Mitigation |
|---|---|---|
| Bridge → webhook | **S**poofing inbound events | HMAC signature verify + per-session shared secret; reject on mismatch |
| Gateway → webhook | **T**ampering / replay of payment events | Signature verify + idempotent on `gateway_event_id` + timestamp window |
| Panels (User/Admin) | **E**levation of privilege / cross-tenant | RBAC + global `TenantScope` + `CrossTenantAccessException`; platform guard + IP allowlist for admin |
| Public API | **S**poofing / **D**oS | Sanctum tokens, per-token rate limits, tenant scope, WAF/nginx limits |
| LLM egress | **I**nformation disclosure (PII) | `PiiRedactor` before egress (Property 15); tenant content only when AI enabled; no body logging |
| Vector store | **I**nfo disclosure cross-tenant | payload filter `tenant_id` on every query (Property 20); per-tenant namespaces |
| Audit log | **R**epudiation / tampering | append-only grants + hash-chain (Property 17) |
| Queue/workers | **D**oS via noisy tenant | fair scheduling + per-tenant concurrency caps + breakers |

### Encryption, secrets & key management

- **In transit:** TLS everywhere (nginx termination, internal service mTLS optional); bridge↔PHP over TLS or private network.
- **At rest:** MySQL TDE / disk encryption; **field-level envelope encryption** for sensitive columns (`FieldCipher`, per-tenant DEK).
- **Secrets management:** app secrets from **Vault / cloud KMS-backed secret store**; `.env` is the boundary of last resort in dev. Gateway/LLM keys live in `platform_settings` (secret, redacted) or the secret store; **never exposed to tenants**.
- **Key rotation:** KMS master key rotated on schedule; per-tenant DEKs rotated via `FieldCipher::rotate` (re-wrap, mark old `RETIRING`, lazy re-encrypt on write); webhook/HMAC secrets support **dual-secret rotation** (accept old+new during overlap).

### Compliance (GDPR / DPDP)

- **Data-subject requests:** access/portability via `TenantLifecycle::export`; **right-to-delete** pipeline purges the subject's rows across OLTP, vector store, object storage, and analytics warehouse, with a verification pass + signed deletion certificate.
- **Data retention:** per-tenant retention policy drives partition drops (`messages*`) and archival; configurable per data class.
- **PCI minimization:** hosted checkout / payment links only — **no card data touches the platform** (SAQ-A scope).
- **Audit-log integrity:** append-only + hash-chain (Property 17); admin actions, impersonation, and logins recorded.

### Abuse / anti-fraud

| Vector | Defense |
|---|---|
| Signup abuse (free-trial farming) | email+phone OTP, device/IP heuristics, velocity limits, trial quota caps |
| LLM prompt injection | guardrail input classifier + delimiter fencing + output validation (§AI 1.3); log to `abuse_events` |
| Rate-limit evasion | limits keyed by (tenant, session, contact); anomaly detection on burst patterns; no bypass parameter |
| WhatsApp ToS / ban risk | opt-out non-bypassable, warm-up caps hard-enforced, per-session risk scoring, tenant education, kill-switch for offending sessions |
| Payment fraud | gateway-side fraud tools + wallet non-negativity + chargeback handling via gateway events |

---

## Observability & Operations (Deep Dive)

> Extends D4 / NFR1. Regeneration adds criteria for tracing (D4.4), SLOs/error budgets (D4.5), cost attribution (D2.4), and deployment safety (NFR-ops).

### Distributed tracing & structured logging

- **Correlation/trace IDs** flow **panel → job → bridge → webhook**: a `trace_id` is minted at ingress (panel request or inbound webhook), stored on the job payload, propagated to the bridge via header and echoed on its webhook, and attached to every log line and `traces` row. `Tracer::startSpan` wraps engine stages and external calls.
- **Structured logging schema (JSON):** `{ts, level, trace_id, span_id, tenant_id, session_id?, conversation_id?, event, latency_ms, outcome, err_class?}`. **Phone numbers redacted, message bodies never logged** (only content hashes) — carried forward from the engine.
- **Metrics taxonomy:** `wacb_<subsystem>_<metric>` — e.g. `wacb_ai_reply_latency_ms` (histogram, tags `model,cache_hit,escalated`), `wacb_queue_depth{lane}`, `wacb_send_total{status}`, `wacb_breaker_state{scope,name}`, `wacb_llm_cost_micros{tenant}`. Exposed at `/metrics` (Prometheus).

### SLOs / SLIs & error budgets

| Subsystem | SLI | SLO | Error budget |
|---|---|---|---|
| Inbound→reply | % replies enqueued < 3s | 99% / 30d | 1% |
| Send pipeline | % sends delivered to bridge < 2s (excl. anti-ban wait) | 99.5% | 0.5% |
| Webhook intake | % 2xx & processed | 99.9% | 0.1% |
| Billing webhooks | % processed exactly-once < 10s | 99.95% | 0.05% |
| Panel availability | % 2xx < 1s | 99.9% | 0.1% |

Burning >2× budget triggers a freeze on risky deploys. **Alerting rules** (dedup via `alerts.fingerprint`): breaker OPEN, queue age p95 breach, replica lag, payment webhook failures, session disconnect storms. Each alert links a **runbook** (symptom → checks → mitigation → escalation). On-call playbook summaries live in `kb_articles`.

### Per-tenant dashboards & cost attribution

- Per-tenant dashboards (from `metrics_rollup`): message volume, delivery/read rates, bot deflection rate, handoff rate, AI reply latency, sentiment trend.
- **Cost attribution:** `llm_usage.cost_micros` + message counts + storage bytes rolled up per tenant → shown in admin (margin analysis) and optionally billed against `AI_CREDITS`/wallet.

### Deployment strategy

```mermaid
graph TD
    subgraph AZ1[AZ-1]
        LB[nginx LB] --> B1[Blue FPM pool]
        LB --> G1[Green FPM pool]
        B1 --> DBP[(MySQL primary)]
        W1[Workers blue/green] --> DBP
    end
    subgraph AZ2[AZ-2]
        DBS[(MySQL standby/replica)]
        BR2[Bridge standby]
    end
    DBP -. binlog/PITR .-> DBS
    W1 --> BRP[Bridge primary]
    BRP -. auth-state backup .-> BR2
```

- **Blue-green / rolling** FPM pools behind nginx; workers drained gracefully (finish in-flight jobs, stop consuming) — **zero-downtime queue drain**.
- **Migration safety = expand-contract:** add columns/tables (expand) → deploy code that writes both → backfill → switch reads → drop old (contract), so schema and code are always compatible during rollout. Partitioned tables get partitions pre-created.
- **Feature-flag rollout:** new subsystems ship behind `feature_flags` (global or per-tenant), enabling canary tenants first.
- **Rollback:** code rollback is a pool swap; DB is forward-compatible by design (expand-contract), so rollback never requires a destructive down-migration.

---

## Advanced Chatbot Capabilities (Deep Dive)

> Extends B3–B8. Regeneration adds criteria for multi-agent routing (B4.6), tool registry (B7.5), A/B testing (B7.6), rich messages (B3.4), STT/media (B7.7), and drip sequences (B6.5).

### Multi-agent orchestration

A **router agent** classifies the inbound and dispatches to a **specialist agent/skill** (sales, support, billing, booking). Each skill scores `canHandle` and can emit a reply, request tool calls, or hand off. Skills are registered in `agents_registry`; the router uses structured output (JSON function-calling) to pick. Falls back to single-agent LLM when no router is configured.

```mermaid
graph TD
    IN[Inbound] --> RT[Router agent classify]
    RT -->|sales| S1[Sales skill]
    RT -->|support| S2[Support skill + RAG]
    RT -->|billing| S3[Billing skill + tools]
    RT -->|unknown| FB[Fallback / handoff]
    S1 & S2 & S3 --> TL[ToolRegistry function calls]
    TL --> OUT[Reply / action]
```

- **Tool/function-calling registry** (`tools_registry`): per-tenant tools with JSON Schemas exposed to the model; `ToolRegistry::invoke` validates args, runs the handler **idempotently**, returns typed results. Tools are the only way the model triggers side effects (create order, check status, book slot).

### A/B testing of flows & replies

`AbTester` gives **sticky** variant assignment per unit (conversation/contact, stored in `ab_assignments`) and records the metric (deflection, conversion, CSAT). Weighted variants; results surfaced with basic significance. Everyone gets control when A/B is disabled.

### Flow analytics & funnels

`flow_analytics_events` (ENTER/COMPLETE/DROP/ERROR per node) power **funnels** (drop-off per step, completion rate) rolled into `metrics_rollup`.

### Rich interactive WhatsApp messages & graceful degradation

Buttons, list messages, and product/catalog messages are modeled as typed `OutboundContent` variants. The `BridgeClient` reports capability; **if the bridge/session doesn't support a rich type, it degrades** to a numbered-text menu (the `menu` node already parses numeric choices), so flows work everywhere.

| Rich type | Native | Degraded fallback |
|---|---|---|
| Reply buttons | interactive buttons | "Reply 1/2/3" numbered text |
| List message | sectioned list | numbered text menu |
| Product/catalog | product cards | text w/ name, price, link |

### Voice notes (STT) & media understanding

Inbound voice notes are transcribed via `SpeechToText` (optional) into `media_transcripts`, then fed to the normal pipeline as text; images can be OCR'd/described (media understanding). Optional **TTS** for outbound voice degrades to text when disabled. No STT bound → ask the user to type (degraded, still functional).

### Scheduled / drip sequences & re-engagement

`campaign_sequences` + `sequence_enrollments` drive **drip** conversations and **re-engagement** triggered by conversation state (e.g. cart abandoned → follow-up at +1h/+24h). Steps respect opt-out, quiet hours, and quota (no bypass). A scheduler advances enrollments at `next_run_at`.

---

## Data Architecture & Analytics (Deep Dive)

> Extends B7 / D4. Regeneration adds criteria for event sourcing (B7.8), analytics pipeline (D4.6), conversation search (B7.9), and reporting model (D4.7).

### Event sourcing (scoped)

**Scope decision** — event sourcing is applied to **conversations, messaging lifecycle, orders, and sessions** (high-value audit + replay), **not** to CRUD-heavy config tables (plans, settings) which stay state-oriented.

| Scope option | Chosen / Rejected | Rationale |
|---|---|---|
| Event-source conversation/order/session streams only | **Chosen** | Full auditability + projection rebuild where it matters; avoids event-sourcing tax on config CRUD |
| Event-source everything | Rejected | Overhead and complexity on low-value tables |
| No event log (mutable rows only) | Rejected | Loses audit/replay; harder analytics; weaker compliance evidence |

`event_log` is **append-only, monotonically versioned per stream** (Property 14). Read models (`conversations`, `orders`) are **projections** rebuilt from events; `projection_checkpoints` track progress so projections can be rebuilt or corrected without touching source events.

```mermaid
graph LR
    CMD[Command: send / reply / order] --> AGG[Aggregate applies + validates]
    AGG --> EL[(event_log append-only)]
    EL --> PJ[Projectors]
    PJ --> RM[(Read models: conversations, orders)]
    EL --> CDC[CDC / stream]
    CDC --> ETL[ETL transform]
    ETL --> WH[(Warehouse / analytics replica)]
    WH --> BI[Dashboards / reports]
    EL --> SEM[Embeddings for conversation search]
```

### Analytics pipeline (OLTP → warehouse)

- **CDC/ETL:** `event_log` (and binlog CDC as an alternative) feeds an **ETL** into a **warehouse** (or a dedicated **MySQL analytics replica** as the zero-extra-infra default). Real-time metrics come from `metrics_rollup` (updated incrementally); heavy historical reports run **batch** on the warehouse/replica.
- **Star schema (reporting model):** fact tables `fact_messages`, `fact_conversations`, `fact_orders`, `fact_llm_usage`; dimensions `dim_tenant`, `dim_date`, `dim_chatbot`, `dim_channel`, `dim_agent`. Real-time vs batch split keeps dashboards fast and history cheap.

### Conversation search

- **Lexical:** MySQL `FULLTEXT` over message text (always on).
- **Semantic:** per-message/embedding search via the same vector store (scale-up) for "find conversations about refunds"; degrades to FULLTEXT when vectors are off. Reuses the RAG embeddings store (`embeddings`, `owner_type=conversation`).

### Reporting model (business metrics)

Funnels, cohort/retention, revenue, **bot deflection rate** (resolved-without-human / total), **handoff rate**, **CSAT** and **sentiment trends** — all computed from facts + `metrics_rollup`, per tenant and platform-wide (platform-wide via replica/warehouse, never live-table scans, per D4.3).

---

## Security & Compliance Considerations

| Concern | Mitigation |
|---|---|
| Cross-tenant data leak | Global `TenantScope` + auto-fill `tenant_id`; `CrossTenantAccessException` as defense-in-depth; property test on every read path |
| Tenant file leakage | Per-tenant storage prefix `storage/tenants/{id}/`; ULID filenames; signed expiring URLs |
| Platform-admin power | Distinct `platform-admin` guard, IP allowlist, forced-strong-password, full audit of impersonation/login-as (reuses existing default-admin guard rails) |
| Impersonation ("login-as") | Explicit, audited, time-boxed; banner shown; certain destructive ops still blocked while impersonating |
| Payment security | Gateway keys in `platform_settings` (secret, redacted); webhook signature verified; PCI scope minimized (hosted checkout / links, no card storage) |
| LLM data exposure | Tenant content sent to LLM only when tenant enabled AI; provider keys are platform-level and never exposed to tenants; message bodies still never logged (only hashes) |
| Compliance (opt-out, anti-ban) | Enforced per tenant with no bypass parameter (unchanged guarantee); rate/warm-up caps hard-enforced |
| Data retention & deletion | Per-tenant retention policy + right-to-delete purge jobs; tenant cancellation triggers scheduled data deletion |
| Threat model / encryption / abuse | See **§Security & Compliance Hardening (Deep Dive)**: STRIDE table, envelope encryption + key rotation, GDPR/DPDP DSR pipeline, prompt-injection & anti-fraud defenses |

---

## Dependencies

- **Reused:** Laravel 11, PHP 8.3, MySQL 8, Livewire 3, Alpine, Tailwind, Supervisor, nginx + PHP-FPM, Node + Baileys bridge, Sanctum, `maatwebsite/excel`, Intervention Image, `dragonmantank/cron-expression`.
- **New (core):** an LLM SDK/HTTP client for OpenAI + Gemini (behind `LlmProvider`), payment gateway SDKs (Razorpay/Stripe) behind `PaymentGateway`, a PDF generator for invoices (e.g. `barryvdh/laravel-dompdf`).
- **New (optional / scale-up — each MUST degrade gracefully when absent):**

| Dependency | Purpose | Fallback when absent (MySQL-only default still works) |
|---|---|---|
| Redis | queue/cache/locks/rate-limit/broadcast | MySQL `jobs`/`cache`/`cache_locks`, `wire:poll` |
| Vector store (Qdrant / pgvector) | semantic RAG + conversation search | MySQL `FULLTEXT` lexical retrieval (`RAG_DRIVER=mysql`) |
| Embeddings API | KB/query embeddings | RAG disabled → keyword/FAQ only |
| Reranker (cross-encoder) | retrieval precision | LLM-as-reranker or RRF-only ordering |
| KMS / Vault | envelope encryption + secrets | Laravel app-key encryption + `.env`/`platform_settings` (dev/small) |
| STT / TTS provider | voice-note transcription / voice out | ask user to type; text-only replies |
| Warehouse / analytics replica | historical reporting | MySQL read replica or `metrics_rollup` on primary |
| OTLP collector (Prometheus/tracing) | metrics + distributed traces | `/metrics` scrape + MySQL `traces` ring-buffer |
| Second LLM provider | fallback chain | local heuristic (FAQ/templated) after primary breaker opens |

Every optional dependency sits behind an interface (`VectorStore`, `Embedder`, `FieldCipher`, `SpeechToText`, `Tracer`, `LlmProvider`, ...) so it is swappable and its absence is a config flip, never a rewrite.

---

## Key Design Decisions & Trade-offs

| # | Decision | Trade-off accepted |
|---|---|---|
| 1 | Row-level multi-tenancy (`tenant_id` + global scope) | Weaker physical isolation than DB-per-tenant; in exchange, simple ops on MySQL and cheap per-tenant cost |
| 2 | Conversation engine as ordered, feature-gated stages | Fixed priority order is less flexible than a rules DSL; in exchange, predictable, testable, and safe-by-default behavior |
| 3 | Flow graph interpreted at runtime, not code-generated | Slight interpretation overhead; in exchange, no-code safety and no code execution from user input |
| 4 | Flow validated at save time (reachability + termination) | Rejects some author intent; in exchange, no broken flow can ever run |
| 5 | LLM on a dedicated queue lane behind an interface | Extra lane to supervise; in exchange, provider slowness/outage is contained and providers are swappable |
| 6 | Quota consumed after bridge confirms, keyed by idempotency | Slightly more bookkeeping; in exchange, retries never double-count and usage billing is exact |
| 7 | Payment via hosted checkout / links, no card storage | Less UI control; in exchange, minimal PCI scope |
| 8 | Reuse single-tenant engine unchanged, add scope on top | Some engine tables need `tenant_id` backfill; in exchange, all 50 core features are inherited, not rewritten |
| 9 | MySQL default, Redis documented upgrade | Lower ceiling out of the box; in exchange, deployment stays PHP + MySQL only until scale demands more |
| 10 | Hybrid RAG (dense ANN + lexical fusion + rerank), vector store behind interface, MySQL FULLTEXT fallback | Extra retrieval components; in exchange, grounded answers, high recall, and zero-dep default |
| 11 | Two-tier conversation memory (recent verbatim + rolling summary/episodic) with token budgeting | Summarization cost + slight recall loss on eviction; in exchange, bounded context cost and long conversations that don't blow the window |
| 12 | Semantic reply cache + cheap→strong model routing | Cache-invalidation & routing logic; in exchange, large cost/latency wins on FAQ traffic |
| 13 | LLM fallback chain + circuit breaker (per provider & per tenant) | Breaker state to manage; in exchange, provider outages never crash the pipeline and a tenant can't open everyone's breaker |
| 14 | Transactional outbox + relay for side effects | Extra table + relay worker; in exchange, effective exactly-once and no dual-write races |
| 15 | Saga with compensations for order→pay→fulfil | Compensation logic per step; in exchange, no partial/orphaned side effects across a multi-step flow |
| 16 | Tenant tiers (SHARED → DEDICATED_WORKER → DEDICATED_DB) via router | Router + provisioning complexity; in exchange, isolation/residency without forking code — tier is a config flip |
| 17 | Envelope encryption (per-tenant KMS-wrapped DEK) for sensitive fields | Key management overhead; in exchange, per-tenant crypto isolation + rotation + residency |
| 18 | Scoped event sourcing (conversation/order/session only) + projections | Event-log storage + projector code; in exchange, audit/replay/analytics where it matters, without event-sourcing tax on config CRUD |
| 19 | Time-based RANGE partitioning now, tenant sharding later | Partition management; in exchange, cheap retention + a clear horizontal-scale path |
| 20 | Expand-contract migrations + blue-green + feature flags | Multi-step migrations; in exchange, zero-downtime deploys and always-safe rollback |

---

## New Requirements & New Tasks Implied by This Upgrade

This design upgrade deepens existing behaviour and introduces net-new subsystems. Because the Design-First workflow regenerates `requirements.md` and `tasks.md` from this document, the following **new/extended requirements** and **new tasks** are expected on regeneration. Existing requirement IDs are referenced where the new behaviour deepens them; suggested new IDs are noted for genuinely new capabilities.

**New / extended requirements (suggested IDs):**

| Area | Suggested requirement | Deepens / relates to |
|---|---|---|
| RAG grounding | B4.5 — grounded answers with verifiable citations; ungrounded factual claims suppressed/escalated | B4.1 (Props 11, 20) |
| Conversation memory | B4.6 — two-tier memory + token budgeting + compaction | B4.1 (Alg 5) |
| Prompt guardrails / PII | NFR3.5 — PII redaction before LLM egress; jailbreak/injection defense | NFR3.2/3.3 (Prop 15) |
| Semantic cache | NFR1.4 — semantic reply caching with version-aware invalidation | NFR1.1 (Prop 12) |
| LLM resilience | NFR2.3 — fallback chain + circuit breaker; no crash on provider outage | B4.4, NFR2 (Prop 13) |
| Outbox / idempotency | NFR2.4 — transactional outbox exactly-once side effects | D2.2, NFR2.2 (Prop 16) |
| Saga | B6.5 — multi-step order flow is atomic-or-compensated | B6.3/6.4 (Prop 18) |
| Tenant tiers | A1.6 — tenant tiers + noisy-neighbor fair scheduling | A1, NFR1.2 (Prop 19) |
| Per-tenant encryption | NFR3.6 — envelope encryption + key rotation + residency | NFR3 |
| Tenant lifecycle | D5.5 — export + verified hard-delete (right-to-delete) | D5.2 |
| Event sourcing | B7.5 — append-only event log + projections | A3.3, B7.4 (Prop 14) |
| Audit integrity | D1.5 — hash-chained append-only audit log | D1.2 (Prop 17) |
| Observability | D4.5 — distributed tracing, SLOs/error budgets, dedup alerts | D4 |
| Cost attribution | D2.4 — per-tenant LLM/message/storage cost attribution | D2 |
| Multi-agent / tools | B4.7, B7.6 — router+specialist agents, function-calling tool registry | B4, B7.1 |
| A/B testing & funnels | B7.7 — sticky A/B variants + flow funnels | B7.4 |
| Rich messages | B3.4 — buttons/list/product messages with graceful degradation | B3 |
| STT/media | B7.8 — voice-note transcription / media understanding | B7 |
| Drip sequences | B6.6 — scheduled/re-engagement sequences (opt-out/quota respected) | B6 |
| Deployment safety | NFR-ops — expand-contract migrations, blue-green, zero-downtime drain | — |

**New tasks implied (to be added on `tasks.md` regeneration):** RAG ingestion pipeline + `VectorStore`/`Embedder`/`Retriever`; memory compactor; `PiiRedactor`/`Guardrail`; semantic cache + `ModelRouter`; `CircuitBreaker`/`Outbox`/`IdempotencyStore`/`SagaOrchestrator` + relay worker; `TierResolver`/`TenantLifecycle`/`FieldCipher` + KMS; `Tracer`/`Metrics` + `/metrics` + rollups + alerting; `AgentRouter`/`Skill`/`ToolRegistry`/`AbTester`; rich-message `OutboundContent` + degradation; `SpeechToText`/`TextToSpeech`; drip `campaign_sequences` engine; `event_log` + projectors + checkpoints + CDC/ETL + star-schema reporting. Each carries property-based tests for Properties 11–20 and the new `Fake*` doubles listed in Testing Strategy.

---

## Sources

- [Baileys — WebSocket-based WhatsApp Web API (TypeScript)](https://github.com/whiskeysockets/Baileys)
- [Laravel Multi-Tenancy patterns (single DB, row-level scoping)](https://laravel.com/docs/11.x/eloquent#global-scopes)

*Content was rephrased for compliance with licensing restrictions.*
