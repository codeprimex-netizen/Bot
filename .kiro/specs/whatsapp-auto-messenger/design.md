# Design Document — WhatsApp Auto Messenger Bot

## Overview

Yeh design ek **layered, event-driven Node.js monolith** describe karta hai jo horizontally scale ho sakta hai. Core idea: WhatsApp socket layer (volatile, unreliable) ko business logic se decouple karna ek **persistent job queue** ke through. Har outbound action ek durable job banta hai, jo rate limiter aur anti-ban engine se guzarne ke baad hi socket tak pahunchta hai. Isse restart-safety, retry, rate control aur observability — chaaron cheezein ek hi mechanism se milti hain.

**Design principles:**
1. **Queue-first** — koi bhi outbound send direct socket call nahi karta; sab queue se jata hai (Req 6)
2. **Session isolation** — ek session ka failure doosre ko affect nahi karta (Req 1.7)
3. **Adapter boundary** — Baileys API ke saare calls ek adapter ke peeche, taaki library breaking changes contained rahein
4. **Everything persisted** — messages, statuses, errors, schedules sab DB mein, memory sirf cache hai
5. **Fail loud, degrade gracefully** — unsupported operations clear error dete hain, silent no-op nahi (Req 21.6)

---

## Architecture

### High-Level Layers

```
┌──────────────────────────────────────────────────────────────────┐
│  PRESENTATION                                                    │
│  React Dashboard (Vite + Tailwind)  ·  WebSocket client          │
└────────────────────────────┬─────────────────────────────────────┘
                             │ HTTPS / WSS
┌────────────────────────────▼─────────────────────────────────────┐
│  API LAYER                                                       │
│  Express router (/api/v1) · zod validators · JWT+RBAC middleware  │
│  rate-limit middleware · audit middleware · OpenAPI/Swagger       │
└────────────────────────────┬─────────────────────────────────────┘
                             │ service calls (in-process)
┌────────────────────────────▼─────────────────────────────────────┐
│  SERVICE LAYER  (business logic, framework-agnostic)             │
│  Messaging · Campaign · Template · Media · Group · Channel        │
│  Welcome · Tagging · Extraction · Export · Contact · Schedule      │
└──────┬──────────────────────┬───────────────────────┬────────────┘
       │ enqueue              │ read/write            │ emit
┌──────▼───────────┐  ┌───────▼──────────┐  ┌─────────▼───────────┐
│  QUEUE LAYER     │  │  DATA LAYER      │  │  EVENT BUS          │
│  BullMQ producers│  │  Prisma repos    │  │  internal emitter + │
│  workers · DLQ   │  │  Redis cache     │  │  WS broadcaster     │
│  RateLimiter     │  │                  │  │                     │
└──────┬───────────┘  └──────────────────┘  └─────────────────────┘
       │ dispatch (rate-gated)
┌──────▼───────────────────────────────────────────────────────────┐
│  ANTI-BAN GATE                                                   │
│  delay randomizer · typing simulator · quota guard · spintax      │
└──────┬───────────────────────────────────────────────────────────┘
       │
┌──────▼───────────────────────────────────────────────────────────┐
│  WHATSAPP ADAPTER LAYER                                          │
│  SessionManager → SessionInstance[] → BaileysAdapter → socket    │
│  ConnectionStateMachine · ReconnectPolicy · EventNormalizer      │
└──────────────────────────────────────────────────────────────────┘
```

### Why this shape

| Decision | Rationale | Alternative rejected |
|----------|-----------|----------------------|
| Persistent queue between service and socket | Restart-safety, retry, rate limiting, backpressure — ek jagah (Req 6.6) | Direct send with in-memory retry — restart pe data loss |
| Adapter around Baileys | Baileys frequently breaking changes karta hai; adapter blast radius limit karta hai | Direct library calls scattered across services |
| Monolith + worker mode from same codebase | Simple deploy, but Phase 29 mein worker nodes alag scale ho sakte hain | Microservices — premature complexity |
| Prisma with SQLite→Postgres parity | Dev friction zero, prod pe proper concurrency | Mongo — relational reporting queries painful |
| Redis for queue + cache + rate limiter | Ek hi dependency teen kaam karti hai, multi-node coordination free | In-process queue — single node only |

### Runtime Modes

Ek hi codebase, `APP_MODE` env se mode:
- `all` — API + workers + scheduler (default, single-box deployment)
- `api` — sirf HTTP/WS server
- `worker` — sirf queue consumers + session sockets
- `scheduler` — sirf cron dispatcher (single instance, leader-locked)

---

## Components and Interfaces

### 1. SessionManager (Req 1, 2, 31)

Sessions ka lifecycle owner. In-memory registry `Map<sessionId, SessionInstance>` maintain karta hai; source of truth DB hai.

```ts
interface SessionManager {
  create(input: { name: string; loginMethod: 'qr' | 'pairing'; phone?: string }): Promise<Session>
  start(sessionId: string): Promise<void>
  stop(sessionId: string, opts?: { logout?: boolean }): Promise<void>
  delete(sessionId: string): Promise<void>
  get(sessionId: string): SessionInstance | undefined
  getHealthy(): SessionInstance[]           // pool selection ke liye
  restoreAll(): Promise<void>               // boot pe
  list(): Promise<SessionSummary[]>
}

interface SessionInstance {
  id: string
  state: ConnectionState
  socket: BaileysAdapter
  meta: { phone?: string; pushName?: string; deviceId?: string; connectedAt?: Date }
  stats: { reconnects: number; sentToday: number; lastEventAt: Date }
  quota: QuotaTracker
}
```

**Connection state machine (Req 1, 2):**

```
        create()
           │
           ▼
     INITIALIZING ──────► QR_PENDING ──5 failed attempts──► QR_TIMEOUT
           │                   │
           │              scan success
           ▼                   ▼
       CONNECTING ────────► CONNECTED ──────► CLOSING ──► CLOSED
           ▲                   │                            
           │            unexpected close                    
           │                   ▼                            
           └──── RECONNECTING ─┴─ reason=loggedOut ──► LOGGED_OUT (terminal)
                     │         └─ reason=replaced  ──► REPLACED   (terminal)
                     │
              backoff exhausted ──► FAILED
```

**ReconnectPolicy (Req 2.1–2.5):**

```ts
const RETRYABLE = new Set(['restartRequired', 'connectionLost', 'connectionClosed', 'timedOut'])
const TERMINAL  = new Set(['loggedOut', 'connectionReplaced', 'badSession', 'multideviceMismatch'])

function nextDelay(attempt: number): number {
  const base = Math.min(1000 * 2 ** attempt, 5 * 60_000)   // cap 5 min
  return base * (0.5 + Math.random())                       // ±50% jitter
}
```

Terminal reasons pe koi retry nahi — instead alert (Req 25.3). Heartbeat watchdog: agar `lastEventAt` se `HEARTBEAT_TIMEOUT` guzar gaya, socket ko stale maan ke forced reconnect (Req 2.6).

---

### 2. BaileysAdapter (Req 21.6, 21.7)

Baileys ke saath **single point of contact**. Do kaam: raw events ko normalized internal events mein convert karna, aur capability detection.

```ts
interface BaileysAdapter {
  // messaging
  sendMessage(jid: string, content: AnyMessageContent, opts?): Promise<SentMessage>
  sendPresence(jid: string, presence: 'composing' | 'paused' | 'available'): Promise<void>
  onWhatsApp(numbers: string[]): Promise<Array<{ jid: string; exists: boolean; isBusiness?: boolean }>>

  // groups
  groupCreate(subject: string, participants: string[]): Promise<GroupMetadata>
  groupParticipantsUpdate(jid: string, participants: string[], action: ParticipantAction): Promise<ParticipantResult[]>
  groupSettingUpdate(jid: string, setting: GroupSetting): Promise<void>
  groupRequestParticipantsUpdate(jid: string, participants: string[], action: 'approve' | 'reject'): Promise<Result[]>
  groupInviteCode(jid: string): Promise<string>
  groupRevokeInvite(jid: string): Promise<string>

  // channels (capability-gated)
  newsletterCreate(name: string, description?: string): Promise<NewsletterMetadata>
  newsletterUpdate(jid: string, patch: NewsletterPatch): Promise<void>
  newsletterMetadata(jid: string): Promise<NewsletterMetadata>

  capabilities: CapabilitySet     // boot pe detect
}
```

**Capability detection (Req 21.6, 21.7):** boot pe adapter check karta hai ki required methods library build mein exist karte hain ya nahi. Missing method → `UNSUPPORTED_OPERATION` throw, aur startup pe capability matrix log hota hai. Isse channel features Baileys version change pe crash nahi karte, gracefully degrade karte hain.

**Event normalization:** Baileys ke `connection.update`, `messages.upsert`, `messages.update`, `group-participants.update`, `groups.update` events → internal `EventBus` pe stable-shaped events, session id tagged. Services sirf internal events consume karte hain.

---

### 3. MessagingService + Queue Pipeline (Req 3, 4, 5, 6)

**Send pipeline — every outbound message isi path se:**

```
Service.send()
   │
   ├─► resolve recipients ──► normalize JIDs ──► filter opt-outs (Req 29.2)
   │                                          └─► de-duplicate (Req 4.4)
   ├─► render template per recipient (Req 9.3)
   ├─► create Message rows (status=PENDING)
   └─► enqueue jobs { idempotencyKey } (Req 6.7)
                │
        ┌───────▼────────┐
        │  send-queue    │  concurrency = 1 per session (Req 6.2)
        └───────┬────────┘
                │
        ┌───────▼─────────────────────────┐
        │ RateLimiter.acquire(sessionId)   │  token bucket: /min /hour /day
        │   └─ blocked? → delay job        │  (Req 6.3)
        ├─────────────────────────────────┤
        │ QuotaGuard: warm-up + daily cap  │  (Req 7.4, 31.4)
        ├─────────────────────────────────┤
        │ QuietHours check → defer         │  (Req 7.7)
        ├─────────────────────────────────┤
        │ DelayRandomizer.wait()           │  (Req 7.1, 7.2)
        ├─────────────────────────────────┤
        │ TypingSimulator: composing→paused│  (Req 7.3)
        └───────┬─────────────────────────┘
                │
         adapter.sendMessage()
                │
        ┌───────▼────────┐
        │ status=SENT ✓  │──► messages.update events ──► DELIVERED ✓✓ ──► READ 🔵✓✓
        └────────────────┘                                    (Req 5)
                │ throw
        ┌───────▼────────────────────────┐
        │ ErrorReporter.capture()        │ (Req 23)
        │ RetryPolicy: retryable? ───────┤ (Req 24)
        │   yes → backoff re-attempt      │
        │   no / max → DLQ                │
        └────────────────────────────────┘
```

**Single vs dual mode (Req 3.1, 4.1):**

| | Single mode | Dual mode |
|---|---|---|
| Worker concurrency | 1 | `N` (configurable) |
| Ack handling | next job se pehle server ack wait (`ACK_TIMEOUT` cap) | fire-and-track, ack async |
| Use case | critical / transactional | bulk campaigns |
| Throughput | low, verifiable | high |

Single mode mein worker `awaitAck(messageId, timeout)` karta hai — timeout pe status `PENDING_TIMEOUT`, sequence rukti nahi (Req 3.5).

**Status tracking (Req 5.7, 5.8):** `MessageStatusEvent` table append-only timeline rakhti hai. Har transition pe campaign counters atomically increment hote hain (Redis `HINCRBY`, periodically DB flush) aur WS event push hota hai. Read receipts disabled recipients ke liye `DELIVERED` terminal success (Req 5.9).

**Priority lanes (Req 6.4):** BullMQ priority — `transactional: 1`, `welcome: 5`, `campaign: 10`.

---

### 4. AntiBanEngine (Req 7)

Sabse safety-critical component. Ye limits **enforce** karta hai, bypass nahi.

```ts
interface AntiBanEngine {
  computeDelay(ctx: SendContext): number
  simulateTyping(session: SessionInstance, jid: string, text: string): Promise<void>
  checkQuota(sessionId: string): QuotaVerdict          // allow | defer | block
  isQuietHours(now: Date, tz: string): boolean
  applySpintax(text: string, seed: string): string
  recordOutcome(sessionId: string, ok: boolean): void  // failure-rate tracking
  riskScore(sessionId: string): number                 // 0..100
}
```

**Delay distribution (Req 7.2):** plain uniform random detectable pattern banata hai. Instead truncated-gaussian:

```ts
function gaussianDelay(min: number, max: number): number {
  const mean = (min + max) / 2, sd = (max - min) / 6
  let v: number
  do { 
    const u1 = Math.random(), u2 = Math.random()
    v = mean + sd * Math.sqrt(-2 * Math.log(u1)) * Math.cos(2 * Math.PI * u2)
  } while (v < min || v > max)
  return v
}
```

**Typing simulation (Req 7.3):** `typingMs = clamp(text.length * MS_PER_CHAR, 800, 6000)` — presence `composing` → wait → `paused` → send.

**Warm-up ramp (Req 7.4):** session ki age se daily cap:

| Session age (days) | Daily cap | New-contact cap |
|---|---|---|
| 0–1 | 20 | 5 |
| 2–3 | 50 | 15 |
| 4–7 | 150 | 40 |
| 8–14 | 400 | 80 |
| 15+ | configured max | configured max |

**Batch cool-down (Req 7.6):** `BATCH_SIZE` messages ke baad `COOLDOWN_MS` pause.

**Risk-based auto-throttle (Req 7.8):** rolling 100-message window mein failure rate; `>15%` → rate limit 50% cut + alert; `>30%` → session ko `THROTTLED` mark, sirf transactional allow.

**Spintax (Req 7.5):** `{Hi|Hello|Hey} {{name}}` → per-recipient deterministic-random variant (seed = recipientId) taaki identical payload signature na bane.

---

### 5. CampaignService (Req 4, 10)

```ts
interface CampaignService {
  create(input: CampaignInput): Promise<Campaign>
  start(id: string): Promise<void>
  pause(id: string): Promise<void>      // stop dispatch, let in-flight finish (Req 4.6)
  resume(id: string): Promise<void>     // skip already-sent (Req 4.7)
  cancel(id: string): Promise<void>     // drain pending, keep stats (Req 4.8)
  progress(id: string): Promise<CampaignProgress>
}
```

**Campaign state machine:**

```
DRAFT ──start──► QUEUEING ──► RUNNING ──┬── all jobs done ──► COMPLETED
                                        ├── pause ──► PAUSED ──resume──► RUNNING
                                        ├── cancel ─► CANCELLED
                                        └── fatal ──► FAILED
```

Resume safety (Req 4.7): recipient rows ka status DB mein hai; resume sirf `PENDING`/`FAILED` rows re-enqueue karta hai, `SENT+` ko skip. Idempotency key = `hash(campaignId + recipientJid)` — double-send impossible even if enqueue duplicate ho (Req 6.7).

---

### 6. Group Services (Req 11, 12, 13, 14)

```ts
interface GroupService {
  create(sessionId: string, subject: string, participants: string[]): Promise<Group>
  leave(sessionId: string, jid: string): Promise<void>
  metadata(sessionId: string, jid: string, opts?: { fresh?: boolean }): Promise<GroupMetadata>
  inviteCode(sessionId: string, jid: string): Promise<string>
  revokeInvite(sessionId: string, jid: string): Promise<string>
  updateParticipants(sessionId: string, jid: string, numbers: string[], action: ParticipantAction): Promise<BulkResult>
  updateSettings(sessionId: string, jid: string, patch: GroupSettingsPatch): Promise<void>
  syncMembers(sessionId: string, jid: string): Promise<SyncReport>
}

interface JoinRequestService {
  listPending(sessionId: string, jid: string): Promise<JoinRequest[]>
  decide(sessionId: string, jid: string, numbers: string[], action: 'approve' | 'reject'): Promise<BulkResult>
  evaluateRules(request: JoinRequest): RuleVerdict   // approve | reject | manual
}
```

**Admin guard (Req 12.5):** har admin-requiring operation se pehle cached metadata se check ki bot `admin`/`superadmin` hai. Nahi → `NOT_GROUP_ADMIN` immediately, socket call waste nahi.

**Bulk participant result (Req 12.1, 12.6):** WhatsApp per-participant status code deta hai; adapter unko map karta hai:

| WA code | Result |
|---|---|
| 200 | `ADDED` |
| 403 | `INVITE_SENT` (privacy settings) |
| 408 | `RECENTLY_LEFT` |
| 409 | `ALREADY_MEMBER` |
| 401/other | `FAILED` |

Bulk adds chunked (`CHUNK_SIZE` default 5) with inter-chunk delay (Req 12.7).

**Auto-approve rules (Req 14.5–14.8):** ordered rule list, first match wins:
```ts
type JoinRule =
  | { type: 'blocklist'; numbers: string[]; action: 'reject' }
  | { type: 'countryCode'; codes: string[]; action: 'approve' | 'reject' }
  | { type: 'regex'; pattern: string; action: 'approve' | 'reject' }
// no match → manual pending
```
Har decision audit-logged with matched rule (Req 14.7).

---

### 7. WelcomeService (Req 15, 16)

`group-participants.update` event consumer.

```ts
interface WelcomeService {
  handleParticipantEvent(e: ParticipantEvent): Promise<void>
  getConfig(groupJid: string): Promise<WelcomeConfig>
  pickTemplate(config: WelcomeConfig): WelcomeTemplate    // sequential | random | weighted
}

interface WelcomeConfig {
  enabled: boolean
  goodbyeEnabled: boolean
  target: 'GROUP' | 'DM'
  batching: 'COMBINED' | 'PER_MEMBER'
  templates: WelcomeTemplate[]
  rotation: 'SEQUENTIAL' | 'RANDOM' | 'WEIGHTED'
  cooldownMinutes: number
  dynamicCard: boolean
}
```

**Duplicate guard (Req 15.6):** Redis key `welcome:{groupJid}:{memberJid}` with TTL = cooldown. Key exist → skip. Rejoin-spam se bachata hai.

**Multi-add batching (Req 15.7):** ek event mein N members → `COMBINED` mode single message with all mentions, `PER_MEMBER` mode individual messages (queue ke through, delay applied).

**Dynamic welcome card (Req 16.3, 16.4):** `canvas` se image — member name + avatar + count. Generation failure pe text-only fallback, error log hota hai but welcome miss nahi hota.

Welcome messages `welcome` priority lane use karte hain (campaign se aage, transactional se peeche).

---

### 8. TaggingService (Req 20)

```ts
interface TaggingService {
  tagAll(input: TagInput): Promise<TagResult>
  tagSelective(input: TagInput & { selector: TagSelector }): Promise<TagResult>
}

type TagSelector =
  | { kind: 'ADMINS' } | { kind: 'NON_ADMINS' }
  | { kind: 'CUSTOM'; jids: string[] } | { kind: 'REGEX'; pattern: string }
```

**Hidden mentions (Req 20.2):** WhatsApp mentions `contextInfo.mentionedJid` array se aate hain — message body mein `@number` text hona zaroori nahi. Visible mode mein body mein `@number` list bhi append hoti hai; hidden mode mein sirf array. Dono cases mein notification jata hai.

**Chunking (Req 20.5):** `participants.length > MENTION_CHUNK_SIZE` (default 200) → multiple messages, har ek apne mention subset ke saath, inter-chunk delay. Isse mention limit aur spam-detection dono handle hote hain.

**Cooldown (Req 20.6):** Redis key `tagall:{groupJid}` with TTL. Active → reject with remaining seconds.

---

### 9. ExtractionService (Req 17, 18)

```ts
interface ExtractionService {
  extract(input: {
    sessionId: string
    groupJids: string[]
    filters: ExtractFilters
    activeCheck: boolean
  }): AsyncIterable<ExtractedMember>
}

interface ExtractFilters {
  countryCodes?: string[]
  adminsOnly?: boolean
  excludeExisting?: boolean
  excludeSelf: true          // always
}
```

Async iterable return karta hai — bade groups pe memory safe (Req 17.7), aur export writer directly stream consume karta hai.

**Active check (Req 18):** `onWhatsApp()` batched (default 20 numbers/call) with inter-batch delay. Result Redis mein cache, TTL default 7 days (Req 18.5). Rate-limit error pe pause + backoff + resume (Req 18.6). Classification: `ACTIVE` / `INACTIVE` plus `isBusiness`, `hasProfilePhoto` flags.

**Cross-group de-dup (Req 17.2):** in-memory `Set<jid>` per extraction run; multi-group extract mein same number ek hi baar.

---

### 10. ExportService (Req 19)

Pluggable, streaming writers.

```ts
interface Exporter {
  format: 'csv' | 'txt' | 'json' | 'xlsx' | 'vcard'
  stream(rows: AsyncIterable<Record<string, unknown>>, opts: ExportOptions): Readable
}
```

- CSV: UTF-8 BOM prepended (Req 19.2), configurable delimiter, column selection + ordering (Req 19.3)
- Streaming pipeline: `ExtractionService` iterable → `Exporter.stream()` → file write, backpressure respected (Req 19.4)
- Download: file `exports/` mein, endpoint signed HMAC token + expiry deta hai (Req 19.5). Path traversal guard: filename server-side generate hota hai, user input se nahi (Req 34.5)
- vCard: proper `BEGIN:VCARD`/`VERSION`/`FN`/`TEL`/`END:VCARD` structure (Req 19.7)

---

### 11. ChannelService (Req 21, 22)

```ts
interface ChannelService {
  create(sessionId: string, input: { name: string; description?: string }): Promise<Channel>
  update(sessionId: string, jid: string, patch: ChannelPatch): Promise<void>
  delete(sessionId: string, jid: string): Promise<void>
  follow(sessionId: string, jid: string): Promise<void>
  post(input: ChannelPostInput): Promise<ChannelPost>
  collectAnalytics(jid: string): Promise<AnalyticsSnapshot>
}
```

Saare methods `adapter.capabilities` check karte hain pehle — unsupported → `UNSUPPORTED_OPERATION` (Req 21.6). Channel features feature-flagged (`FEATURE_CHANNELS`), default on but capability-gated.

**Analytics (Req 22.5, 22.6):** periodic snapshot job (default hourly) — `ChannelAnalyticsSnapshot` rows: `{ postId, views, reactions: Record<emoji, count>, followerCount, capturedAt }`. Delta over time snapshots ke difference se compute hota hai — cumulative counter ko delta samajhne ki galti avoid.

---

### 12. ErrorReporter + RetryPolicy (Req 23, 24, 25)

```ts
interface ErrorReporter {
  capture(err: unknown, ctx: ErrorContext): Promise<ErrorLog>
  classify(err: unknown): { code: string; category: ErrorCategory; severity: Severity; retryable: boolean }
}

type ErrorCategory =
  | 'AUTH' | 'RATE_LIMIT' | 'NOT_ON_WHATSAPP' | 'MEDIA'
  | 'NETWORK' | 'PERMISSION' | 'VALIDATION' | 'UNKNOWN'
```

**Retry matrix (Req 24.1, 24.2, 24.7):**

| Category | Retryable | Max attempts | Backoff |
|---|---|---|---|
| `NETWORK` | ✅ | 5 | exponential 2s base + jitter |
| `RATE_LIMIT` | ✅ | 3 | fixed cool-down (`RATE_LIMIT_COOLDOWN_MS`) |
| `MEDIA` | ✅ | 3 | exponential 5s base |
| `AUTH` | ❌ | 0 | — (session-level alert instead) |
| `NOT_ON_WHATSAPP` | ❌ | 0 | — |
| `PERMISSION` | ❌ | 0 | — |
| `VALIDATION` | ❌ | 0 | — |
| `UNKNOWN` | ✅ | 2 | exponential 10s base |

Max attempts exhaust → DLQ (Req 24.3). Retry success pe **original** Message row update hoti hai, naya row nahi (Req 24.6).

**PII redaction (Req 23.3, 32.4):** logger serializer phone numbers ko `91XXXX**7890` pattern mein mask karta hai. Message bodies logs mein nahi jaate, sirf content hash.

**Alert pipeline (Req 25):**

```
ErrorLog created
   │
   ├─► EventBus → WS broadcast (Req 25.1)
   └─► AlertRuleEngine
         ├─ severity >= threshold
         ├─ error-rate spike (rolling window)
         ├─ session disconnect
         └─ queue backlog > limit
              │
         Deduper (Redis: alert:{fingerprint}, TTL) ── suppress repeats (Req 25.6)
              │
         Notifier chain: WhatsApp DM → Telegram → Email → Webhook
              └─ delivery failure → log + try next (Req 25.7)
```

Alert fingerprint = `hash(code + sessionId + entityId)`.

---

### 13. SchedulerService (Req 10, 22.2)

```ts
interface SchedulerService {
  create(input: ScheduleInput): Promise<Schedule>
  nextRuns(id: string, count: number): Promise<Date[]>
  runNow(id: string): Promise<void>
  reload(): Promise<void>          // boot pe
}
```

- Timezone-aware: `luxon` se IANA tz, cron parse `cron-parser` se tz option ke saath (Req 10.1, 10.6)
- Persisted `Schedule` rows; boot pe reload (Req 10.4)
- **Missed-run recovery (Req 10.5):** `lastRunAt` vs now compare — policy `SKIP` (ignore missed), `RUN_ONCE` (ek baar catch up), `CATCH_UP` (saare missed runs execute)
- **Leader lock:** multi-node deploy mein Redis lock (`scheduler:leader`, TTL-renewed) — ek hi instance dispatch karta hai, duplicate fire nahi (Req 31.6)
- `runNow` schedule ka `nextRunAt` disturb nahi karta (Req 10.8)

---

### 14. ContactService (Req 28, 29)

```ts
interface ContactService {
  importFile(input: { file: Buffer; format: 'csv' | 'xlsx' | 'vcard'; mapping: ColumnMapping }): Promise<ImportReport>
  syncFromWhatsApp(sessionId: string): Promise<SyncReport>
  resolveSegment(segmentId: string): AsyncIterable<Contact>
  blocklistAdd(number: string, reason?: string): Promise<void>
  optOut(number: string, source: 'KEYWORD' | 'MANUAL'): Promise<void>
}
```

**Import flow (Req 28.2, 28.3):** upload → parse headers → return detected columns → user mapping → validate + normalize + dedupe → commit. `ImportReport`: `{ total, imported, updated, skipped, invalid: Array<{row, reason}> }`.

**Dynamic segments (Req 28.7):** rule tree stored as JSON, compiled to Prisma `where` clause at query time — membership always fresh, materialization nahi.

**Opt-out (Req 29):** inbound message handler configured keywords (`STOP`, `UNSUBSCRIBE`, `BAND KARO`) match karta hai → `OptOut` row + confirmation reply. **Recipient resolution mein opt-out filter mandatory hai** — service layer mein hard-coded, bypassable option nahi (Req 29.2, 29.3).

---

## Data Models

Prisma schema (key models). SQLite dev / Postgres prod — same schema.

```prisma
model Session {
  id            String    @id @default(cuid())
  name          String    @unique
  phone         String?
  pushName      String?
  deviceId      String?
  status        SessionStatus @default(INITIALIZING)
  authPath      String
  warmupStartAt DateTime?
  dailyQuota    Int?
  weight        Int       @default(1)      // pool weighting (Req 31.1)
  reconnects    Int       @default(0)
  lastSeenAt    DateTime?
  connectedAt   DateTime?
  deletedAt     DateTime?
  createdAt     DateTime  @default(now())

  messages      Message[]
  errors        ErrorLog[]
  @@index([status])
}

model Contact {
  id           String   @id @default(cuid())
  jid          String   @unique
  number       String   @unique
  name         String?
  pushName     String?
  isBusiness   Boolean  @default(false)
  hasPhoto     Boolean  @default(false)
  waStatus     WaStatus @default(UNKNOWN)   // ACTIVE | INACTIVE | UNKNOWN
  checkedAt    DateTime?
  customFields Json?
  tags         String[]
  notes        String?
  blocked      Boolean  @default(false)
  createdAt    DateTime @default(now())

  listItems    ContactListItem[]
  @@index([waStatus])
  @@index([blocked])
}

model OptOut {
  id        String   @id @default(cuid())
  number    String   @unique
  source    String                        // KEYWORD | MANUAL | IMPORT
  reason    String?
  createdAt DateTime @default(now())
}

model ContactList {
  id        String  @id @default(cuid())
  name      String  @unique
  type      String                        // STATIC | DYNAMIC
  ruleTree  Json?                         // DYNAMIC ke liye (Req 28.7)
  items     ContactListItem[]
}

model ContactListItem {
  id        String  @id @default(cuid())
  listId    String
  contactId String
  list      ContactList @relation(fields: [listId], references: [id], onDelete: Cascade)
  contact   Contact     @relation(fields: [contactId], references: [id], onDelete: Cascade)
  @@unique([listId, contactId])
}

model Group {
  id           String   @id @default(cuid())
  jid          String   @unique
  sessionId    String
  subject      String
  description  String?
  owner        String?
  isBotAdmin   Boolean  @default(false)
  participantCount Int  @default(0)
  settings     Json?                       // announcement, locked, ephemeral, approvalMode
  status       String   @default("ACTIVE")  // ACTIVE | LEFT | DELETED
  syncedAt     DateTime?

  members      GroupMember[]
  welcomeConfig WelcomeConfig?
  joinRequests JoinRequest[]
  @@index([sessionId])
}

model GroupMember {
  id        String   @id @default(cuid())
  groupId   String
  jid       String
  number    String
  pushName  String?
  role      String   @default("member")    // member | admin | superadmin
  joinedAt  DateTime?
  leftAt    DateTime?
  group     Group    @relation(fields: [groupId], references: [id], onDelete: Cascade)
  @@unique([groupId, jid])
  @@index([number])
}

model JoinRequest {
  id          String   @id @default(cuid())
  groupId     String
  jid         String
  number      String
  status      String   @default("PENDING")  // PENDING | APPROVED | REJECTED
  decidedBy   String?                        // userId | "AUTO:<ruleId>"
  matchedRule String?
  decidedAt   DateTime?
  createdAt   DateTime @default(now())
  group       Group    @relation(fields: [groupId], references: [id], onDelete: Cascade)
  @@unique([groupId, jid])
}

model WelcomeConfig {
  id              String  @id @default(cuid())
  groupId         String  @unique
  enabled         Boolean @default(false)
  goodbyeEnabled  Boolean @default(false)
  target          String  @default("GROUP")      // GROUP | DM
  batching        String  @default("COMBINED")
  rotation        String  @default("SEQUENTIAL")
  cooldownMinutes Int     @default(60)
  dynamicCard     Boolean @default(false)
  rotationCursor  Int     @default(0)
  templates       Json                            // WelcomeTemplate[]
  group           Group   @relation(fields: [groupId], references: [id], onDelete: Cascade)
}

model Channel {
  id          String  @id @default(cuid())
  jid         String  @unique
  sessionId   String
  name        String
  description String?
  inviteLink  String?
  status      String  @default("ACTIVE")
  posts       ChannelPost[]
}

model ChannelPost {
  id          String   @id @default(cuid())
  channelId   String
  waMessageId String?
  type        String                          // TEXT | MEDIA | POLL
  content     Json
  status      String   @default("DRAFT")      // DRAFT | SCHEDULED | PUBLISHED | FAILED
  publishAt   DateTime?
  publishedAt DateTime?
  channel     Channel  @relation(fields: [channelId], references: [id], onDelete: Cascade)
  snapshots   ChannelAnalyticsSnapshot[]
}

model ChannelAnalyticsSnapshot {
  id            String   @id @default(cuid())
  postId        String
  views         Int      @default(0)
  reactions     Json?                          // { "👍": 12, "❤️": 3 }
  followerCount Int      @default(0)
  capturedAt    DateTime @default(now())
  post          ChannelPost @relation(fields: [postId], references: [id], onDelete: Cascade)
  @@index([postId, capturedAt])
}

model Template {
  id        String   @id @default(cuid())
  name      String
  category  String?
  version   Int      @default(1)
  body      String
  mediaRef  String?
  variables Json?                              // { name: { fallback: "there" } }
  strict    Boolean  @default(false)
  isCurrent Boolean  @default(true)
  createdAt DateTime @default(now())
  @@unique([name, version])
}

model Campaign {
  id          String   @id @default(cuid())
  name        String
  mode        String                            // SINGLE | DUAL
  templateId  String?
  sessionIds  String[]                          // pool (Req 31.1)
  status      String   @default("DRAFT")
  strategy    String   @default("ROUND_ROBIN")
  total       Int      @default(0)
  sent        Int      @default(0)
  delivered   Int      @default(0)
  read        Int      @default(0)
  failed      Int      @default(0)
  scheduleId  String?
  startedAt   DateTime?
  completedAt DateTime?
  messages    Message[]
  @@index([status])
}

model Message {
  id             String   @id @default(cuid())
  campaignId     String?
  sessionId      String
  recipientJid   String
  recipientNumber String
  type           String   @default("TEXT")
  contentHash    String
  body           String?
  mediaRef       String?
  waMessageId    String?
  status         MessageStatus @default(PENDING)
  failureCode    String?
  failureReason  String?
  attempts       Int      @default(0)
  idempotencyKey String   @unique              // (Req 6.7)
  sentAt         DateTime?
  deliveredAt    DateTime?
  readAt         DateTime?
  createdAt      DateTime @default(now())

  session        Session  @relation(fields: [sessionId], references: [id])
  campaign       Campaign? @relation(fields: [campaignId], references: [id], onDelete: SetNull)
  statusEvents   MessageStatusEvent[]
  @@index([campaignId, status])
  @@index([recipientNumber])
  @@index([waMessageId])
}

model MessageStatusEvent {
  id        String   @id @default(cuid())
  messageId String
  status    MessageStatus
  at        DateTime @default(now())
  message   Message  @relation(fields: [messageId], references: [id], onDelete: Cascade)
  @@index([messageId, at])
}

model Schedule {
  id             String   @id @default(cuid())
  name           String
  kind           String                        // ONCE | CRON
  runAt          DateTime?
  cron           String?
  timezone       String   @default("Asia/Kolkata")
  targetType     String                        // CAMPAIGN | CHANNEL_POST
  targetId       String
  recoveryPolicy String   @default("SKIP")     // SKIP | RUN_ONCE | CATCH_UP
  enabled        Boolean  @default(true)
  lastRunAt      DateTime?
  nextRunAt      DateTime?
  @@index([enabled, nextRunAt])
}

model ErrorLog {
  id           String   @id @default(cuid())
  code         String
  category     String
  severity     String                          // INFO | WARN | ERROR | CRITICAL
  sessionId    String?
  entityType   String?                         // MESSAGE | GROUP | CHANNEL | SESSION
  entityId     String?
  message      String
  stack        String?
  context      Json?
  retryCount   Int      @default(0)
  resolved     Boolean  @default(false)
  createdAt    DateTime @default(now())
  session      Session? @relation(fields: [sessionId], references: [id], onDelete: SetNull)
  @@index([category, createdAt])
  @@index([sessionId, createdAt])
}

model AuditLog {
  id         String   @id @default(cuid())
  actorId    String?
  actorType  String   @default("USER")         // USER | SYSTEM | AUTO_RULE
  action     String
  targetType String?
  targetId   String?
  before     Json?
  after      Json?
  createdAt  DateTime @default(now())
  @@index([targetType, targetId])
}

model User {
  id           String   @id @default(cuid())
  email        String   @unique
  passwordHash String
  role         String   @default("viewer")     // owner | admin | operator | viewer
  active       Boolean  @default(true)
  createdAt    DateTime @default(now())
  apiKeys      ApiKey[]
}

model ApiKey {
  id        String   @id @default(cuid())
  userId    String
  name      String
  keyHash   String   @unique
  rateLimit Int      @default(600)             // req/hour
  lastUsedAt DateTime?
  revokedAt DateTime?
  user      User     @relation(fields: [userId], references: [id], onDelete: Cascade)
}

model Setting {
  key       String   @id
  value     Json
  secret    Boolean  @default(false)           // API pe redact (Req 33.5)
  updatedAt DateTime @updatedAt
}

enum SessionStatus {
  INITIALIZING QR_PENDING QR_TIMEOUT CONNECTING CONNECTED
  RECONNECTING THROTTLED CLOSING CLOSED LOGGED_OUT REPLACED FAILED
}

enum MessageStatus {
  PENDING SENT DELIVERED READ PLAYED FAILED PENDING_TIMEOUT SKIPPED
}

enum WaStatus { UNKNOWN ACTIVE INACTIVE }
```

---

## API Design (Req 26)

```
POST   /api/v1/auth/login              → { accessToken, refreshToken }
POST   /api/v1/auth/refresh

GET    /api/v1/sessions
POST   /api/v1/sessions                 { name, loginMethod, phone? }
GET    /api/v1/sessions/:id/qr          → { qr: "data:image/png;base64,…" }
POST   /api/v1/sessions/:id/start | /stop | /logout
DELETE /api/v1/sessions/:id
GET    /api/v1/health                   → per-session health (Req 2.7)
GET    /api/v1/metrics                  → Prometheus (Req 32.1)

POST   /api/v1/messages/send            { sessionId, to, content, mode }
GET    /api/v1/messages/:id             → status + timeline (Req 5.7)

POST   /api/v1/campaigns                { name, mode, recipients, templateId, sessionIds, delay, schedule }
POST   /api/v1/campaigns/:id/start | /pause | /resume | /cancel
GET    /api/v1/campaigns/:id/progress   → live counters (Req 4.5)

GET/POST/PUT/DELETE /api/v1/templates
POST   /api/v1/templates/:id/preview    { sampleData } → rendered (Req 9.6)

GET/POST/PUT/DELETE /api/v1/schedules
POST   /api/v1/schedules/:id/run-now
GET    /api/v1/schedules/:id/next-runs

GET    /api/v1/groups
POST   /api/v1/groups                   { sessionId, subject, participants }
GET    /api/v1/groups/:jid
PATCH  /api/v1/groups/:jid/settings
POST   /api/v1/groups/:jid/participants  { numbers, action }
GET    /api/v1/groups/:jid/invite | POST .../invite/revoke
GET    /api/v1/groups/:jid/join-requests
POST   /api/v1/groups/:jid/join-requests/decide
GET/PUT /api/v1/groups/:jid/welcome
POST   /api/v1/groups/:jid/tag           { selector, message, hidden }

POST   /api/v1/extract                  { groupJids, filters, activeCheck } → jobId
GET    /api/v1/extract/:jobId
POST   /api/v1/exports                  { source, format, columns } → { downloadUrl }

GET/POST/PATCH/DELETE /api/v1/channels
POST   /api/v1/channels/:jid/posts
GET    /api/v1/channels/:jid/analytics

GET/POST /api/v1/contacts
POST   /api/v1/contacts/import           multipart → mapping preview
POST   /api/v1/contacts/import/commit
POST   /api/v1/contacts/sync             { sessionId }
GET/POST /api/v1/contacts/lists
POST   /api/v1/optouts

GET    /api/v1/errors                    ?session&from&to&category&severity
POST   /api/v1/errors/retry              { messageIds[] }
GET    /api/v1/errors/dlq
GET    /api/v1/errors/export

GET    /api/v1/queue/stats
POST   /api/v1/queue/pause | /resume | /drain
GET/PUT /api/v1/settings

WS     /ws                               events: message.status, campaign.progress,
                                                  error.new, session.status, queue.stats
```

Error response shape (uniform):
```json
{ "error": { "code": "NOT_GROUP_ADMIN", "category": "PERMISSION",
             "message": "Bot is not an admin in this group",
             "details": {}, "correlationId": "req_01H…" } }
```

---

## Dashboard Design (Req 27)

| Screen | Contents |
|---|---|
| Login | email/password, token stored in memory + httpOnly refresh cookie |
| Overview | active sessions, today's sent/delivered/read, error rate, queue depth, recent activity |
| Sessions | session cards with status badge, QR modal for new login, start/stop/logout, health stats |
| Contacts | table with search/filter/tags, import wizard (upload → map columns → preview → commit), export |
| Groups | group list, member table, settings panel, join-request inbox, welcome config editor, tag-all action |
| Channels | channel list, post composer, content calendar, analytics charts |
| Campaign Builder | 5-step wizard: recipients → template → mode & session pool → delays & schedule → review + disclaimer |
| Campaign Detail | live progress bar, tick funnel (✓/✓✓/🔵✓✓/✗), per-recipient table, pause/resume/cancel |
| Templates | CRUD, variable helper, live preview, version history |
| Schedules | list with next-run preview, enable toggle, run-now |
| Queue Monitor | waiting/active/failed/delayed counts, pause/resume, throughput chart |
| Error Center | filterable log table, trend chart, top failing recipients, DLQ with bulk retry, export |
| Extractor | group multi-select, filter builder, active-check toggle, results preview, export |
| Settings | delays, rate limits, quiet hours, warm-up, alert channels, opt-out keywords, users & API keys |

**Live updates (Req 27.4, 27.5):** single WS connection, topic-subscribed. Reconnect with exponential backoff; on reconnect, refetch current state (WS is delta transport, REST is truth).

**i18n (Req 27.8):** `react-i18next`, `en` + `hi` bundles. **Compliance disclaimer (Req 27.9):** campaign builder ke bulk step pe non-dismissible banner + first-time acknowledgement checkbox.

---

## Error Handling Strategy

**Error class hierarchy:**
```
AppError (code, category, severity, retryable, httpStatus, context)
├── ValidationError      → 400, non-retryable
├── AuthError            → 401/403, non-retryable
├── NotFoundError        → 404
├── RateLimitError       → 429, retryable with cool-down
├── WhatsAppError        → mapped from Baileys/DisconnectReason
│   ├── SessionNotConnectedError
│   ├── NotOnWhatsAppError        → non-retryable
│   ├── NotGroupAdminError        → non-retryable
│   └── UnsupportedOperationError → non-retryable (Req 21.6)
└── MediaError           → retryable
```

**Boundaries:**
1. **Express error middleware** — AppError → structured JSON + correlation id; unknown → 500 + full capture
2. **Queue worker wrapper** — har job try/catch mein; classify → retry / DLQ; kabhi worker crash nahi
3. **Socket event handlers** — har handler isolated try/catch; ek handler ka throw event loop ya session ko na girae
4. **Process level** — `unhandledRejection` / `uncaughtException` → capture + CRITICAL alert + graceful shutdown (process manager restart karega, Req 30.2)

**Graceful shutdown:** SIGTERM pe — new enqueues stop → in-flight jobs complete (grace timeout) → sockets flush + close → DB/Redis disconnect → exit 0. Isse zero-downtime reload possible (Req 30.4).

---

## Security Considerations (Req 34.5, 30.9)

| Concern | Mitigation |
|---|---|
| Secrets | env-only, zod-validated at boot, never logged, `Setting.secret` API pe redacted |
| Passwords | argon2id hash |
| API keys | stored as SHA-256 hash, plaintext sirf creation response mein |
| Tokens | short-lived access (15 min) + rotating refresh, httpOnly cookie |
| Path traversal | media/export filenames server-generated; user input sirf whitelist-validated |
| File upload | MIME content-sniffing (extension pe trust nahi), size cap, extension allowlist |
| SQL injection | Prisma parameterized queries only, raw SQL banned |
| PII in logs | logger serializer phone numbers mask karta hai; message bodies log nahi hoti |
| Rate abuse | per-API-key limiter + global limiter |
| Dependency risk | `npm audit` CI gate, Dependabot |
| Session auth files | `sessions/` gitignored, `chmod 700`, backup encrypted |

---

## Testing Strategy (Req 34)

**Unit (Vitest)** — pure logic pehle: delay distribution, spintax, JID normalization, template rendering, error classification, retry matrix, rule evaluation, warm-up quota, cron next-run, CSV/vCard writers.

**Integration** — `MockBaileysAdapter` (in-memory fake): send success/failure/timeout inject kar sakta hai, status events emit kar sakta hai, group ops simulate karta hai. Isse pura send pipeline, campaign lifecycle, welcome flow, retry→DLQ→manual-retry path bina network test hota hai (Req 34.2).

**Contract** — Express routes supertest se, OpenAPI schema ke against response validation.

**Load (Req 34.4)** — 10k jobs enqueue → soak run → assert: rate limits respected, memory stable (heap snapshot start vs end within threshold), zero duplicate sends, queue drains clean.

**Coverage (Req 34.3)** — core (`services/`, `queue/`, `utils/`) pe minimum 70% lines, CI gate.

**Manual QA checklist** — real number pe: QR login, reconnect (network pull), welcome trigger, tag-all in 200+ member group, quota enforcement.

---

## Key Design Decisions & Trade-offs

| # | Decision | Trade-off accepted |
|---|---|---|
| 1 | Queue-mandatory sends (no direct path) | Simple 1-message send pe bhi Redis dependency; badle mein uniform retry/rate/observability |
| 2 | Single mode ack-wait with timeout cap | Slow throughput; badle mein per-message verifiability |
| 3 | Gaussian delay over uniform | Thoda extra compute; badle mein less detectable timing signature |
| 4 | Warm-up caps hard-enforced (override nahi) | Naye number pe operator frustration; badle mein ban risk substantially kam |
| 5 | Prisma + SQLite dev / Postgres prod | Kuch Postgres-specific features avoid; badle mein zero-setup local dev |
| 6 | Adapter layer around Baileys | Extra indirection; badle mein library upgrade blast radius contained |
| 7 | Capability detection for channels | Feature availability runtime-variable; badle mein version change pe crash nahi |
| 8 | Dynamic segments compiled at query time | Har resolve pe query cost; badle mein membership always correct |
| 9 | Opt-out filter non-bypassable in service layer | Flexibility loss; badle mein compliance guarantee |
| 10 | Append-only status timeline | Extra rows per message; badle mein full delivery forensics |

---

## Traceability: Design Component → Requirements

| Component | Requirements |
|---|---|
| SessionManager + state machine | 1, 2, 31 |
| BaileysAdapter + capability detection | 21, 32 |
| MessagingService + send pipeline | 3, 4, 5 |
| Queue layer + RateLimiter | 6, 24 |
| AntiBanEngine | 7 |
| MediaService | 8 |
| TemplateService | 9 |
| SchedulerService | 10, 22 |
| GroupService | 11, 12, 13 |
| JoinRequestService | 14 |
| WelcomeService | 15, 16 |
| ExtractionService | 17, 18 |
| ExportService | 19 |
| TaggingService | 20 |
| ChannelService + analytics | 21, 22 |
| ErrorReporter + RetryPolicy + Alerts | 23, 24, 25 |
| API layer + auth/RBAC | 26 |
| React dashboard | 27 |
| ContactService + OptOut | 28, 29 |
| Deployment (PM2/Docker/CI) | 30 |
| Pool selector + failover | 31 |
| Logger + metrics + correlation | 32 |
| Config loader + runtime settings | 33 |
| Test suites + security hardening | 34 |
