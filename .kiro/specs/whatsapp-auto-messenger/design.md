# Design Document — WhatsApp Auto Messenger Bot (PHP + MySQL)

## Overview

Yeh design ek **Laravel 11 monolith on PHP 8.3 + MySQL 8** describe karta hai jo `https://bot.getxtrra.in` pe deploy hota hai. Core idea wahi hai: WhatsApp protocol layer (volatile, unreliable) ko business logic se decouple karna ek **persistent MySQL-backed job queue** ke through. Har outbound action ek durable `jobs` row banta hai, jo rate limiter aur anti-ban gate se guzarne ke baad hi bridge tak pahunchta hai.

**Design principles:**
1. **PHP owns everything** — data, decisions, scheduling, UI. Bridge sirf ek dumb wire hai (Req 35.1)
2. **Queue-first** — koi bhi outbound send synchronous nahi; sab `jobs` table se (Req 6.1)
3. **MySQL-only default** — queue, cache, locks, sessions sab MySQL pe; Redis optional performance upgrade
4. **Session isolation** — ek session ka failure doosre ko affect nahi karta (Req 1.7)
5. **Everything persisted** — messages, statuses, errors, schedules sab MySQL mein
6. **Fail loud, degrade gracefully** — unsupported operation clear error deta hai, silent no-op nahi

---

## The PHP Constraint and How This Design Handles It

Requirements document mein detail hai; yahan sirf design consequence:

```
┌──────────────────────────────────────────────────────────────┐
│  PHP / Laravel / MySQL         ← 100% of the application     │
│                                                              │
│  • Dashboard (Blade + Livewire)   • Campaigns & scheduling    │
│  • Auth, RBAC, audit              • Anti-ban engine           │
│  • All business rules             • Groups / channels logic    │
│  • Queue + workers                • Error reporting & alerts   │
│  • Contacts, templates, export    • ALL data in MySQL          │
└───────────────────────┬──────────────────────────────────────┘
                        │  localhost HTTP (token) ↓
                        │  webhook POST (HMAC)    ↑
┌───────────────────────▼──────────────────────────────────────┐
│  WA Bridge (Node + Baileys)    ← ~600 lines, zero logic      │
│  socket lifecycle · auth state files · send · emit events     │
│  NO database · NO decisions · NO scheduling · NO business rules│
└──────────────────────────────────────────────────────────────┘
```

Bridge ko **replaceable adapter** samjho: kal koi maintained PHP protocol library aa jaye, ya aap official Cloud API pe shift karo, to sirf `BridgeClient` implementation badalti hai — baaki 100% codebase untouched. Isliye `BridgeClient` ek PHP **interface** hai, concrete class nahi (Req 35.1).

**Pure-PHP alternative (agar bridge acceptable na ho):** `CloudApiBridgeClient` implement karo same interface pe. Cost: groups, channels, extraction, tagging, QR multi-session — sab unavailable ho jate hain, aur messaging 24-hour window + pre-approved templates tak limit ho jati hai. Interface same rehta hai, `capabilities` set chhota ho jata hai, aur dashboard un features ko hide kar deta hai. Yeh design us switch ko support karta hai without rewrite.

---

## Architecture

```
                        https://bot.getxtrra.in
                                  │
                      ┌───────────▼───────────┐
                      │  nginx (TLS, HTTP→S)  │
                      └───────────┬───────────┘
                                  │ FastCGI
                      ┌───────────▼───────────┐
                      │      PHP-FPM 8.3      │
                      │                       │
                      │  ┌─────────────────┐  │
                      │  │  WEB (Blade +   │  │
                      │  │  Livewire 3)    │  │
                      │  ├─────────────────┤  │
                      │  │  API (/api/v1)  │  │
                      │  │  Sanctum + RBAC │  │
                      │  └────────┬────────┘  │
                      └───────────┼───────────┘
                                  │
              ┌───────────────────▼────────────────────┐
              │        SERVICE LAYER (app/Services)     │
              │  Messaging · Campaign · Template · Media│
              │  Group · Channel · Welcome · Tagging    │
              │  Extraction · Export · Contact · Errors │
              │  AntiBan · SessionManager · PoolSelector│
              └────┬──────────────┬──────────────┬──────┘
                   │ dispatch     │ Eloquent     │ events
        ┌──────────▼─────┐ ┌──────▼───────┐ ┌────▼─────────────┐
        │ QUEUE (MySQL)  │ │  MySQL 8     │ │ Events +          │
        │ jobs           │ │  22 tables   │ │ Livewire/Reverb   │
        │ failed_jobs    │ │              │ │ broadcast         │
        │ job_batches    │ │              │ │                   │
        └──────────┬─────┘ └──────────────┘ └───────────────────┘
                   │
        ┌──────────▼──────────────────────────────────┐
        │  SUPERVISOR: php artisan queue:work         │
        │  workers per queue: transactional/welcome/  │
        │  campaign/extraction                        │
        └──────────┬──────────────────────────────────┘
                   │
        ┌──────────▼──────────────────────────────────┐
        │  ANTI-BAN GATE (inside SendMessageJob)      │
        │  RateLimiter → QuotaGuard → QuietHours →    │
        │  DelayRandomizer → TypingSimulator          │
        └──────────┬──────────────────────────────────┘
                   │
        ┌──────────▼──────────────────────────────────┐
        │  BridgeClient (PHP interface)               │
        │  HttpBridgeClient  |  FakeBridgeClient(test)│
        └──────────┬──────────────────────────────────┘
                   │ 127.0.0.1:3111 (token auth)
        ┌──────────▼──────────────────────────────────┐
        │  WA Bridge (Node + Baileys) — Supervisor    │
        │  sessions/<id>/ auth files, 0700            │
        └──────────┬──────────────────────────────────┘
                   │ webhook POST /api/bridge/events (HMAC)
                   └──────────────► back into PHP
```

**Cron (single entry):**
```cron
* * * * * cd /var/www/bot && php artisan schedule:run >> /dev/null 2>&1
```
Laravel Scheduler isse in-app schedules, analytics snapshots, retention purges, quota resets, aur health checks — sab drive karta hai (Req 31.4).

### Why this shape

| Decision | Rationale | Alternative rejected |
|---|---|---|
| MySQL queue driver (not Redis) | User constraint "PHP + MySQL"; `jobs` table durable, transactional, zero extra service (Req 6.6) | Redis-required — extra dependency; still supported if configured |
| Laravel over plain PHP | Queue, scheduler, migrations, auth, validation, Eloquent, Blade — 60% of the spec's plumbing is framework-provided | Plain PHP — thousands of lines of reinvented infra |
| Livewire over React SPA | Server-rendered PHP components, reactive without a separate JS build/app; polling gives live updates without a WebSocket server (Req 28.4) | React SPA — second codebase, second language |
| Livewire polling default, Reverb optional | Zero extra process for live updates; Reverb (first-party PHP WS server) opt-in for sub-second feel | Mandatory WS server — extra deploy complexity |
| Thin Node bridge, PHP interface | Protocol reality; interface keeps it swappable (Req 35.1) | Cloud API only — kills groups/channels/extraction/tagging |
| Supervisor for workers | Standard PHP deployment; `queue:work` restart-safe | PM2 — Node tool; only used for bridge if preferred |

---

## Components and Interfaces

All namespaces under `App\`.

### 1. BridgeClient (Req 35)

The single boundary to the protocol. Everything else in the app talks to this interface.

```php
namespace App\Contracts;

interface BridgeClient
{
    // ---- session lifecycle ----
    public function provisionSession(string $sessionId, string $loginMethod, ?string $phone = null): SessionInitDto;
    public function startSession(string $sessionId): void;
    public function stopSession(string $sessionId, bool $logout = false): void;
    public function sessionState(string $sessionId): SessionStateDto;
    public function qr(string $sessionId): ?string;              // base64 PNG
    public function pairingCode(string $sessionId, string $phone): string;

    // ---- messaging ----
    public function sendText(string $sessionId, string $jid, string $text, array $opts = []): SentMessageDto;
    public function sendMedia(string $sessionId, string $jid, MediaPayload $media, array $opts = []): SentMessageDto;
    public function sendPresence(string $sessionId, string $jid, string $presence): void;
    public function checkNumbers(string $sessionId, array $numbers): array;   // onWhatsApp

    // ---- groups ----
    public function groupCreate(string $sessionId, string $subject, array $participants): GroupDto;
    public function groupMetadata(string $sessionId, string $jid): GroupDto;
    public function groupParticipants(string $sessionId, string $jid, array $jids, string $action): array;
    public function groupSettings(string $sessionId, string $jid, array $patch): void;
    public function groupInviteCode(string $sessionId, string $jid): string;
    public function groupRevokeInvite(string $sessionId, string $jid): string;
    public function groupLeave(string $sessionId, string $jid): void;
    public function joinRequests(string $sessionId, string $jid): array;
    public function decideJoinRequests(string $sessionId, string $jid, array $jids, string $action): array;

    // ---- channels ----
    public function channelCreate(string $sessionId, string $name, ?string $description): ChannelDto;
    public function channelUpdate(string $sessionId, string $jid, array $patch): void;
    public function channelDelete(string $sessionId, string $jid): void;
    public function channelMetadata(string $sessionId, string $jid): ChannelDto;

    public function capabilities(): array;      // Req 21.7, 35.9
    public function isReachable(): bool;         // Req 2.9
}
```

**Implementations:**
- `HttpBridgeClient` — Guzzle → `http://127.0.0.1:3111`, bearer token, per-call timeout, `BridgeUnreachableException` on connection failure
- `FakeBridgeClient` — in-memory test double; inject success/failure/timeout, emit fake status events (Req 36.2)
- *(future)* `CloudApiBridgeClient` — reduced capability set

**Capability gating:** every group/channel service method calls `Bridge::supports('groupCreate')` first. Missing → `UnsupportedOperationException` (Req 21.6). Capability list cached in `settings` on bridge handshake.

---

### 2. SessionManager (Req 1, 2, 32)

```php
namespace App\Services\Wa;

class SessionManager
{
    public function create(string $name, string $loginMethod, ?string $phone = null): Session;
    public function start(string $id): void;
    public function stop(string $id, bool $logout = false): void;
    public function delete(string $id): void;
    public function restoreAll(): void;                 // scheduled + post-deploy
    public function healthy(): Collection;              // pool selection
    public function markState(string $id, SessionStatus $s, ?string $reason = null): void;
}
```

**Connection state machine (Req 1, 2)** — state lives in `sessions.status` (MySQL, not memory, so any PHP process sees truth):

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
           └──── RECONNECTING ─┼─ reason=loggedOut ──────► LOGGED_OUT (terminal)
                     │         ├─ reason=replaced  ──────► REPLACED   (terminal)
                     │         └─ risk threshold   ──────► THROTTLED
                     │
              backoff exhausted ──► FAILED
```

Transitions enforced by a `SessionStatus` enum with an `allowedNext()` map — illegal transition throws (catches ordering bugs from out-of-order webhooks).

**ReconnectPolicy (Req 2.1–2.5):**

```php
final class ReconnectPolicy
{
    private const RETRYABLE = ['restartRequired','connectionLost','connectionClosed','timedOut'];
    private const TERMINAL  = ['loggedOut','connectionReplaced','badSession','multideviceMismatch'];

    public function shouldRetry(string $reason): bool
    {
        return in_array($reason, self::RETRYABLE, true);
    }

    public function delaySeconds(int $attempt): int
    {
        $base = min((int) (2 ** $attempt), 300);          // cap 5 min
        return (int) ($base * (0.5 + mt_rand() / mt_getrandmax()));   // ±50% jitter
    }
}
```

Terminal reasons → no retry, alert instead (Req 2.2, 25.3). A scheduled `wa:health-check` command (every minute) compares `sessions.last_seen_at` against the heartbeat timeout and forces reconnect on stale sessions (Req 2.6) — this replaces an in-process watchdog, which PHP-FPM can't host.

---

### 3. Send Pipeline (Req 3, 4, 5, 6, 7)

Everything outbound goes through `SendMessageJob`. This is the single most important flow in the system.

```
MessagingService::send() / CampaignService::start()
   │
   ├─► resolve recipients ─► normalize JIDs ─► opt-out filter (mandatory, Req 30.2)
   │                                        ├─► blocklist filter
   │                                        └─► de-duplicate (Req 4.4)
   ├─► render template per recipient (Req 9.3)
   ├─► create `messages` rows (status = PENDING)
   └─► dispatch SendMessageJob per recipient, queue = priority lane
                │
    ┌───────────▼──────────────────────────────────────────┐
    │  SendMessageJob::handle()                            │
    │                                                      │
    │  1. WithoutOverlapping(session:{id})  ← concurrency 1 │ Req 6.2
    │  2. RateLimiter::attempt(min/hour/day) → release()    │ Req 6.3
    │  3. QuotaGuard::verdict() → allow|defer|block         │ Req 7.4, 7.9
    │  4. QuietHours::active() → release until window end   │ Req 7.7
    │  5. BatchCooldown::check() → release                  │ Req 7.6
    │  6. Session connected? no → release (hold)            │ Req 2.8
    │  7. DelayRandomizer::gaussian() → usleep()            │ Req 7.1, 7.2
    │  8. TypingSimulator: composing → wait → paused        │ Req 7.3
    │  9. Bridge::sendText() / sendMedia()                  │
    │ 10. message.status = SENT, waMessageId stored         │ Req 5.2
    └───────────┬──────────────────────────────────────────┘
                │
        webhook: messages.update
                ▼
    DELIVERED ✓✓ → READ 🔵✓✓ → PLAYED       (Req 5.3–5.5)
                │
                │ on exception
    ┌───────────▼──────────────────────────────────────────┐
    │ ErrorReporter::capture() → classify()                │ Req 23
    │ retryable? → $this->release(backoff)                 │ Req 24.1
    │ non-retryable or attempts exhausted → failed_jobs    │ Req 24.2, 24.3
    └──────────────────────────────────────────────────────┘
```

**Job skeleton** (the shape matters — `release()` not `fail()` is what makes rate limiting free):

```php
class SendMessageJob implements ShouldQueue, ShouldBeUnique
{
    public int $tries = 5;
    public int $timeout = 120;

    public function __construct(public string $messageId) {}

    public function uniqueId(): string
    {
        return Message::find($this->messageId)->idempotency_key;   // Req 6.7
    }

    public function middleware(): array
    {
        return [new WithoutOverlapping("session:{$this->sessionId()}")];  // Req 6.2
    }

    public function handle(AntiBanEngine $antiBan, BridgeClient $bridge): void
    {
        $msg = Message::findOrFail($this->messageId);
        if ($msg->status !== MessageStatus::Pending) return;          // resume safety, Req 4.7

        $verdict = $antiBan->gate($msg);                               // steps 2–6
        if ($verdict->defer) { $this->release($verdict->seconds); return; }
        if ($verdict->block) { $msg->markSkipped($verdict->reason); return; }

        $antiBan->pace($msg);                                          // steps 7–8
        $sent = $bridge->sendText($msg->session_id, $msg->recipient_jid, $msg->body);
        $msg->markSent($sent->waMessageId);
    }

    public function failed(Throwable $e): void
    {
        app(ErrorReporter::class)->capture($e, ['messageId' => $this->messageId]);
        Message::find($this->messageId)?->markFailed($e);
    }
}
```

**Single vs dual mode (Req 3.1, 4.1)** — mode is a *worker allocation* concern, not a code-path fork:

| | Single mode | Dual mode |
|---|---|---|
| Queue | `transactional` | `campaign` |
| Supervisor `numprocs` | 1 | N (configurable) |
| Ack handling | job awaits ack up to `ACK_TIMEOUT`, then `PENDING_TIMEOUT` | fire-and-track, ack arrives via webhook |
| Use case | critical / verifiable | bulk throughput |

**Priority lanes (Req 6.4):** separate queues consumed with Laravel's priority ordering:
```
php artisan queue:work --queue=transactional,welcome,campaign,extraction
```

**Status tracking (Req 5.7, 5.8, 5.10):** `message_status_events` is append-only. Status updates guard against downgrade:
```php
public function applyStatus(MessageStatus $new): void
{
    if ($new->rank() <= $this->status->rank()) return;   // out-of-order ack ignored, Req 5.10
    $this->update(['status' => $new, ...$new->timestampColumn()]);
    $this->statusEvents()->create(['status' => $new, 'at' => now()]);
    $this->campaign?->bumpCounter($new);                 // Req 5.8
}
```

---

### 4. AntiBanEngine (Req 7)

Safety-critical. Enforces limits; does not circumvent them. Caps are not runtime-overridable (Req 7.10).

```php
namespace App\Services\AntiBan;

class AntiBanEngine
{
    public function gate(Message $m): GateVerdict;      // rate + quota + quiet hours + cooldown
    public function pace(Message $m): void;             // delay + typing simulation
    public function delayMs(Session $s): int;
    public function spintax(string $text, string $seed): string;
    public function recordOutcome(string $sessionId, bool $ok): void;
    public function riskScore(string $sessionId): int;  // 0..100
}
```

**Gaussian delay (Req 7.2)** — uniform random leaves a detectable flat signature; Box-Muller truncated normal doesn't:

```php
public function delayMs(Session $s): int
{
    [$min, $max] = [$s->delay_min_ms, $s->delay_max_ms];
    $mean = ($min + $max) / 2;
    $sd   = ($max - $min) / 6;
    do {
        $u1 = mt_rand() / mt_getrandmax();
        $u2 = mt_rand() / mt_getrandmax();
        $v  = $mean + $sd * sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2);
    } while ($v < $min || $v > $max);
    return (int) $v;
}
```

**Typing simulation (Req 7.3):** `typingMs = clamp(strlen($text) * MS_PER_CHAR, 800, 6000)` → `composing` → sleep → `paused` → send.

**Warm-up ramp (Req 7.4, 7.9)** — from `sessions.warmup_start_at`:

| Session age (days) | Daily cap | New-contact cap |
|---|---|---|
| 0–1 | 20 | 5 |
| 2–3 | 50 | 15 |
| 4–7 | 150 | 40 |
| 8–14 | 400 | 80 |
| 15+ | configured max | configured max |

**Rate limiting on MySQL (Req 6.3):** Laravel `RateLimiter` with `database` cache store; counters keyed `wa:rate:{sessionId}:{min|hour|day}`. Atomicity via `Cache::lock()` (MySQL `cache_locks` table). Blocked → `$this->release($secondsUntilWindowReset)` — job stays in `jobs`, nothing lost.

**Quota reset:** scheduled `wa:reset-quotas` runs per session timezone at local midnight.

**Risk auto-throttle (Req 7.8):** rolling 100-outcome window per session in cache. `>15%` failures → halve effective rate + alert. `>30%` → `THROTTLED` (transactional only).

**Spintax (Req 7.5):** `{Hi|Hello|Hey} {{name}}` → variant chosen with `mt_srand(crc32($seed))` where seed = recipient id, so same recipient is stable but the corpus varies across recipients.

---

### 5. CampaignService (Req 4)

```php
class CampaignService
{
    public function create(CampaignData $d): Campaign;
    public function start(Campaign $c): void;
    public function pause(Campaign $c): void;     // Req 4.6
    public function resume(Campaign $c): void;    // Req 4.7
    public function cancel(Campaign $c): void;    // Req 4.8
    public function progress(Campaign $c): ProgressDto;
}
```

```
DRAFT ──start──► QUEUEING ──► RUNNING ──┬── all settled ──► COMPLETED
                                        ├── pause ──► PAUSED ──resume──► RUNNING
                                        ├── cancel ─► CANCELLED
                                        └── fatal ──► FAILED
```

**Resume safety (Req 4.7):** recipient state lives in `messages.status`. Resume re-dispatches only `PENDING`/`FAILED` rows. Combined with `ShouldBeUnique` on `idempotency_key = sha256(campaign_id . recipient_jid)`, double-send is structurally impossible even if dispatch runs twice.

**Pause (Req 4.6):** `campaigns.status = PAUSED`; job's first line re-checks campaign status and releases itself. In-flight jobs finish naturally.

Large dispatch uses `Bus::batch()` so `job_batches` gives progress + cancellation for free.

---

### 6. Group Services (Req 11–14)

```php
class GroupService {
    public function create(string $sessionId, string $subject, array $participants): Group;
    public function metadata(string $sessionId, string $jid, bool $fresh = false): GroupDto;
    public function updateParticipants(string $sessionId, string $jid, array $numbers, string $action): BulkResult;
    public function updateSettings(string $sessionId, string $jid, array $patch): void;
    public function syncMembers(string $sessionId, string $jid): SyncReport;
    public function inviteCode(string $sessionId, string $jid): string;
    public function revokeInvite(string $sessionId, string $jid): string;
    public function leave(string $sessionId, string $jid): void;
}

class JoinRequestService {
    public function listPending(string $sessionId, string $jid): Collection;
    public function decide(string $sessionId, string $jid, array $numbers, string $action): BulkResult;
    public function evaluateRules(JoinRequest $r): RuleVerdict;   // approve | reject | manual
}
```

**Admin guard (Req 12.5):** cached `groups.is_bot_admin` checked before any admin-requiring op → `NotGroupAdminException` without a wasted bridge round-trip.

**Bulk participant result mapping (Req 12.1, 12.2):**

| WhatsApp code | Result |
|---|---|
| 200 | `ADDED` |
| 403 | `INVITE_SENT` (privacy settings) |
| 408 | `RECENTLY_LEFT` |
| 409 | `ALREADY_MEMBER` |
| 401 / other | `FAILED` |

Bulk ops chunked (default 5) with inter-chunk delay, executed in `BulkParticipantJob` — never in an HTTP request (Req 12.7).

**Auto-approve rules (Req 14.5–14.8):** ordered JSON rule list on the group, first match wins, no match → stays pending. Every decision writes `audit_logs` with the matched rule (Req 14.7).

```php
// rules JSON
[
  ['type' => 'blocklist',   'numbers' => [...],          'action' => 'reject'],
  ['type' => 'countryCode', 'codes'   => ['91','971'],    'action' => 'approve'],
  ['type' => 'regex',       'pattern' => '^91[6-9]\\d{9}$','action' => 'approve'],
]
```

---

### 7. WelcomeService (Req 15, 16)

Driven by the `group-participants.update` webhook.

```php
class WelcomeService
{
    public function handleParticipantEvent(ParticipantEventDto $e): void;
    public function pickTemplate(WelcomeConfig $c): array;   // sequential | random | weighted
}
```

**Duplicate guard (Req 15.6):** `Cache::add("welcome:{$groupJid}:{$memberJid}", true, $cooldownMinutes * 60)` — atomic add returns false if key exists → skip. Handles rejoin spam.

**Multi-add batching (Req 15.7):** `COMBINED` → one message with all mentions; `PER_MEMBER` → individual queued sends on the `welcome` lane.

**Dynamic welcome card (Req 16.3, 16.4):** Intervention Image (GD) composes member name + avatar + count. Wrapped in try/catch — failure falls back to text-only so the welcome never silently disappears; the failure is logged.

---

### 8. TaggingService (Req 20)

```php
class TaggingService
{
    public function tag(string $sessionId, string $jid, TagSelector $sel, TagPayload $p): TagResult;
}
```

**Hidden mentions (Req 20.2):** WhatsApp notifies from `contextInfo.mentionedJid`; the body doesn't need visible `@number` text. Hidden mode sends only the array. Visible mode appends the list.

**Chunking (Req 20.5):** participants > `MENTION_CHUNK_SIZE` (default 200) → multiple queued messages, each with its own mention subset and inter-chunk delay.

**Cooldown (Req 20.6):** `Cache::add("tagall:{$groupJid}", ...)` with TTL; active → reject with remaining seconds.

---

### 9. ExtractionService (Req 17, 18)

PHP memory is the constraint here, so extraction is generator-based and chunk-inserted.

```php
class ExtractionService
{
    /** @return \Generator<ExtractedMember> */
    public function extract(ExtractRequest $r): \Generator;
}
```

- Runs inside `ExtractGroupJob` on the `extraction` queue — never in an HTTP request (Req 17.7)
- `yield` per member; export writer consumes lazily; DB writes via `chunk(500)->insertOrIgnore()`
- Cross-group de-dup via a per-run temp table (not an in-memory array — a 50k-member multi-group run would blow `memory_limit`)
- Self-number always excluded (Req 17.3)

**Active check (Req 18):** `checkNumbers()` batched (20/call) with inter-batch delay; results cached (`wa:onwa:{number}`, TTL 7 days). Rate-limit error → job releases with backoff and resumes (Req 18.6). Records `ACTIVE`/`INACTIVE` plus `is_business`, `has_photo`.

---

### 10. ExportService (Req 19)

```php
interface Exporter {
    public function format(): string;                       // csv|txt|json|xlsx|vcard
    public function write(iterable $rows, ExportOptions $o, string $path): void;
}
```

- CSV: `fputcsv` on a streamed handle, UTF-8 BOM prepended (Req 19.2), configurable delimiter, column selection/order (Req 19.3)
- Large exports: `Model::query()->lazyById(1000)` → constant memory (Req 19.4)
- XLSX: `maatwebsite/excel` in queued export mode
- vCard: hand-built valid `BEGIN:VCARD … END:VCARD` (Req 19.7)
- Download: file stored **outside web root** under `storage/app/exports`; served via `Route::signedRoute` with expiry (Req 19.5). Filename is server-generated `{ulid}.{ext}`; user input never touches the path (Req 19.8, 36.5)

---

### 11. ChannelService (Req 21, 22)

Every method capability-gated (Req 21.6). Feature flag `FEATURE_CHANNELS`.

**Analytics (Req 22.5, 22.6):** scheduled `channels:snapshot` (hourly) writes `channel_analytics_snapshots` rows `{post_id, views, reactions, follower_count, captured_at}`. Deltas are computed as differences **between snapshots** — treating a cumulative view counter as a delta is the classic bug here, so the snapshot table is the source and delta is always derived.

---

### 12. ErrorReporter + Retry (Req 23, 24, 25)

```php
class ErrorReporter
{
    public function capture(Throwable $e, array $ctx = []): ErrorLog;
    public function classify(Throwable $e): Classification;   // code, category, severity, retryable
}
```

**Retry matrix (Req 24.1, 24.2, 24.7):**

| Category | Retryable | Max attempts | Backoff |
|---|---|---|---|
| `NETWORK` | ✅ | 5 | exponential, 2s base + jitter |
| `BRIDGE` | ✅ | 5 | exponential, 5s base |
| `RATE_LIMIT` | ✅ | 3 | fixed cool-down |
| `MEDIA` | ✅ | 3 | exponential, 5s base |
| `AUTH` | ❌ | 0 | session alert instead |
| `NOT_ON_WHATSAPP` | ❌ | 0 | — |
| `PERMISSION` | ❌ | 0 | — |
| `VALIDATION` | ❌ | 0 | — |
| `UNKNOWN` | ✅ | 2 | exponential, 10s base |

Exhausted → `failed_jobs` (Req 24.3). Manual bulk retry re-dispatches with a fresh attempt counter and **updates the original `messages` row** rather than creating a duplicate (Req 24.6).

**PII redaction (Req 23.3, 33.4):** a Monolog processor masks phone-shaped strings to `91XXXX**7890`. Message bodies are never logged — only `content_hash`.

**Alert pipeline (Req 25):**

```
ErrorLog created
   ├─► dashboard live surface (Livewire poll / Reverb)         Req 25.1
   └─► AlertRuleEngine
         ├─ severity ≥ threshold
         ├─ error-rate spike (rolling window)
         ├─ session disconnect
         └─ queue backlog > limit
              │
        Deduper: Cache::add("alert:{fingerprint}", ttl)         Req 25.6
              │
        Notifier chain (Laravel Notifications):
          WhatsApp admin DM → Telegram → Mail → Webhook
              └─ delivery failure → log + next channel          Req 25.7
```

Fingerprint = `sha256(code . sessionId . entityId)`.

---

### 13. SchedulerService (Req 10)

Single cron entry drives `schedule:run`; a `DispatchDueSchedules` command reads `schedules` from MySQL each minute.

- Timezone-aware via Carbon + `dragonmantank/cron-expression` (Req 10.1, 10.2, 10.6)
- State in MySQL, never process memory (Req 10.4)
- **Missed-run recovery (Req 10.5):** compare `last_run_at` vs now → `SKIP` / `RUN_ONCE` / `CATCH_UP`
- **Multi-server safety (Req 10.9):** `->onOneServer()` + `Cache::lock('scheduler:leader')`
- `runNow` dispatches immediately without touching `next_run_at` (Req 10.8)

---

### 14. ContactService (Req 29, 30)

```php
class ContactService
{
    public function previewImport(UploadedFile $f): ImportPreview;         // detected columns
    public function commitImport(string $token, ColumnMapping $m): void;   // queued job
    public function syncFromWhatsApp(string $sessionId): SyncReport;
    public function resolveSegment(ContactList $l): Builder;               // dynamic
    public function optOut(string $number, string $source): void;
}
```

**Import (Req 29.2, 29.3, 29.9):** upload → parse header → return columns → user maps → `ImportContactsJob` validates, normalizes, dedupes, chunk-upserts. Report: `{total, imported, updated, skipped, invalid[{row, reason}]}`.

**Dynamic segments (Req 29.7):** rule tree JSON compiled to an Eloquent `Builder` at query time — no materialized membership table, so membership is always correct after contact edits.

**Opt-out (Req 30):** inbound message webhook matches configured keywords → `opt_outs` row + confirmation reply. The filter is applied inside `RecipientResolver` with **no bypass parameter** (Req 30.7) — it's structurally impossible to send a campaign to an opted-out number.

---

### 15. Auth and Default Admin (Req 26, 27)

```php
// database/seeders/AdminUserSeeder.php
User::updateOrCreate(
    ['username' => 'admin'],
    [
        'name'                 => 'Administrator',
        'email'                => 'admin@bot.getxtrra.in',
        'password'             => Hash::make('admin'),      // bcrypt, Req 27.5
        'role'                 => 'owner',
        'must_change_password' => true,                     // Req 27.2
    ]
);
```

**Guard rails around the weak default** (this is the part that makes `admin`/`admin` survivable on a public domain):

| Control | Mechanism |
|---|---|
| Forced change on first login | `ForcePasswordChange` middleware redirects every route except the change-password form (Req 27.2) |
| Persistent warning | Blade banner while `must_change_password` is true (Req 27.3) |
| Risky ops locked | `RequireStrongPassword` middleware on bulk-send + destructive routes → blocked until changed (Req 27.4) |
| Brute-force defence | `RateLimiter::for('login')` — per-IP + per-username, lockout after N attempts (Req 27.6) |
| IP allowlist | `AdminIpAllowlist` middleware, CIDR list in settings (Req 27.7) |
| Idle logout | session lifetime + last-activity check (Req 27.8) |
| Login audit | every attempt logged with IP + user agent (Req 27.9) |

**RBAC (Req 26.5, 26.6):** Gate abilities per role.

| | viewer | operator | admin | owner |
|---|---|---|---|---|
| Read dashboards/reports | ✅ | ✅ | ✅ | ✅ |
| Send / run campaigns | ❌ | ✅ | ✅ | ✅ |
| Manage groups/channels | ❌ | ✅ | ✅ | ✅ |
| Sessions, settings | ❌ | ❌ | ✅ | ✅ |
| Users, API keys, deletion | ❌ | ❌ | ❌ | ✅ |

API auth: Laravel Sanctum tokens; API keys stored as SHA-256, plaintext shown once (Req 26.3, 26.4).

---

## Data Models (MySQL 8, Laravel migrations)

22 domain tables + Laravel's `jobs`, `job_batches`, `failed_jobs`, `cache`, `cache_locks`, `sessions`, `personal_access_tokens`.

```
users                     id, username(uniq), name, email, password, role,
                          must_change_password, active, last_login_at, timestamps

api_keys                  id, user_id→users, name, key_hash(uniq), rate_limit,
                          last_used_at, revoked_at

sessions_wa               id(ulid), name(uniq), phone, push_name, device_id,
                          status(enum), auth_ref, warmup_start_at, daily_quota,
                          weight, delay_min_ms, delay_max_ms, reconnects,
                          sent_today, last_seen_at, connected_at, deleted_at
                          idx(status)

contacts                  id, jid(uniq), number(uniq), name, push_name, is_business,
                          has_photo, wa_status(enum), checked_at, custom_fields(json),
                          tags(json), notes, blocked
                          idx(wa_status), idx(blocked)

opt_outs                  id, number(uniq), source, reason, created_at

contact_lists             id, name(uniq), type(STATIC|DYNAMIC), rule_tree(json)
contact_list_items        id, list_id→, contact_id→   uniq(list_id, contact_id)

groups                    id, jid(uniq), session_id→, subject, description, owner,
                          is_bot_admin, participant_count, settings(json),
                          join_rules(json), status, synced_at
                          idx(session_id)

group_members             id, group_id→, jid, number, push_name, role, joined_at,
                          left_at   uniq(group_id, jid), idx(number)

join_requests             id, group_id→, jid, number, status, decided_by,
                          matched_rule, decided_at   uniq(group_id, jid)

welcome_configs           id, group_id→(uniq), enabled, goodbye_enabled, target,
                          batching, rotation, cooldown_minutes, dynamic_card,
                          rotation_cursor, templates(json)

channels                  id, jid(uniq), session_id→, name, description,
                          invite_link, status

channel_posts             id, channel_id→, wa_message_id, type, content(json),
                          status, publish_at, published_at

channel_analytics_snapshots  id, post_id→, views, reactions(json),
                             follower_count, captured_at   idx(post_id, captured_at)

templates                 id, name, category, version, body, media_ref,
                          variables(json), strict, is_current
                          uniq(name, version)

campaigns                 id, name, mode(SINGLE|DUAL), template_id→, session_ids(json),
                          strategy, status, total, sent, delivered, read_count, failed,
                          schedule_id→, batch_id, started_at, completed_at
                          idx(status)

messages                  id(ulid), campaign_id→, session_id→, recipient_jid,
                          recipient_number, type, content_hash, body, media_ref,
                          wa_message_id, status(enum), failure_code, failure_reason,
                          attempts, idempotency_key(uniq), sent_at, delivered_at,
                          read_at, created_at
                          idx(campaign_id, status), idx(recipient_number), idx(wa_message_id)

message_status_events     id, message_id→, status, at   idx(message_id, at)

schedules                 id, name, kind(ONCE|CRON), run_at, cron, timezone,
                          target_type, target_id, recovery_policy, enabled,
                          last_run_at, next_run_at   idx(enabled, next_run_at)

error_logs                id, code, category, severity, session_id→, entity_type,
                          entity_id, message, stack, context(json), retry_count,
                          resolved, created_at
                          idx(category, created_at), idx(session_id, created_at)

audit_logs                id, actor_id→, actor_type, action, target_type, target_id,
                          before(json), after(json), ip, user_agent, created_at
                          idx(target_type, target_id)

settings                  key(pk), value(json), secret(bool), updated_at
```

**Enums (PHP 8.3 backed enums, stored as strings):**

```php
enum SessionStatus: string {
    case Initializing='INITIALIZING'; case QrPending='QR_PENDING';
    case QrTimeout='QR_TIMEOUT';      case Connecting='CONNECTING';
    case Connected='CONNECTED';       case Reconnecting='RECONNECTING';
    case Throttled='THROTTLED';       case Closing='CLOSING';
    case Closed='CLOSED';             case LoggedOut='LOGGED_OUT';
    case Replaced='REPLACED';         case Failed='FAILED';
}

enum MessageStatus: string {
    case Pending='PENDING'; case Sent='SENT'; case Delivered='DELIVERED';
    case Read='READ'; case Played='PLAYED'; case Failed='FAILED';
    case PendingTimeout='PENDING_TIMEOUT'; case Skipped='SKIPPED';

    public function rank(): int { /* PENDING 0 … PLAYED 4 */ }   // Req 5.10
}

enum WaStatus: string { case Unknown='UNKNOWN'; case Active='ACTIVE'; case Inactive='INACTIVE'; }
```

`sessions_wa` is named to avoid colliding with Laravel's own `sessions` table (database session driver).

---

## API Design (Req 26)

Base: `https://bot.getxtrra.in/api/v1` · Auth: `Authorization: Bearer <sanctum-token>`

```
POST   /auth/login                       → { token, must_change_password }
POST   /auth/logout
POST   /auth/change-password

GET    /sessions
POST   /sessions                          { name, login_method, phone? }
GET    /sessions/{id}/qr                  → { qr: "data:image/png;base64,…" }
POST   /sessions/{id}/start | /stop | /logout
DELETE /sessions/{id}
GET    /health                            → per-session + bridge reachability
GET    /metrics                           → Prometheus text

POST   /messages/send                     { session_id, to, content, mode }
GET    /messages/{id}                     → status + timeline

POST   /campaigns                         { name, mode, recipients, template_id, session_ids, delay, schedule }
POST   /campaigns/{id}/start|pause|resume|cancel
GET    /campaigns/{id}/progress

GET|POST|PUT|DELETE /templates
POST   /templates/{id}/preview

GET|POST|PUT|DELETE /schedules
POST   /schedules/{id}/run-now
GET    /schedules/{id}/next-runs

GET    /groups
POST   /groups                            { session_id, subject, participants }
GET    /groups/{jid}
PATCH  /groups/{jid}/settings
POST   /groups/{jid}/participants         { numbers, action }
GET    /groups/{jid}/invite  |  POST /groups/{jid}/invite/revoke
GET    /groups/{jid}/join-requests
POST   /groups/{jid}/join-requests/decide
GET|PUT /groups/{jid}/welcome
POST   /groups/{jid}/tag                  { selector, message, hidden }

POST   /extract                           { group_jids, filters, active_check } → { job_id }
GET    /extract/{jobId}
POST   /exports                           { source, format, columns } → { download_url }

GET|POST|PATCH|DELETE /channels
POST   /channels/{jid}/posts
GET    /channels/{jid}/analytics

GET|POST /contacts
POST   /contacts/import                   (multipart) → column preview
POST   /contacts/import/commit
POST   /contacts/sync                     { session_id }
GET|POST /contacts/lists
POST   /optouts

GET    /errors                            ?session&from&to&category&severity
POST   /errors/retry                      { message_ids[] }
GET    /errors/failed-jobs
GET    /errors/export

GET    /queue/stats
POST   /queue/pause | /resume
GET|PUT /settings

# internal, not public API
POST   /api/bridge/events                 ← bridge webhook, HMAC-verified (Req 35.4, 35.5)
```

Uniform error body:
```json
{ "error": { "code": "NOT_GROUP_ADMIN", "category": "PERMISSION",
             "message": "Bot is not an admin in this group",
             "details": {}, "correlation_id": "01JC…" } }
```

---

## Dashboard Design (Req 28)

Blade layouts + Livewire 3 components + Alpine + Tailwind. No SPA, no separate frontend build pipeline beyond Vite for assets.

| Route | Livewire component | Contents |
|---|---|---|
| `/login` | `Auth\LoginForm` | throttled login |
| `/password/change` | `Auth\ChangePassword` | forced on first admin login |
| `/` | `Dashboard\Overview` | active sessions, today's sent/delivered/read, error rate, queue depth |
| `/sessions` | `Sessions\Index` | status cards, QR modal (`wire:poll.3s` auto-refresh), start/stop/logout |
| `/contacts` | `Contacts\Index` + `ImportWizard` | table, filters, 4-step import, export |
| `/groups` | `Groups\Index`, `Groups\Show` | members, settings panel, join-request inbox, welcome editor, tag-all |
| `/channels` | `Channels\Index`, `PostComposer`, `Calendar` | posts, schedule, analytics charts |
| `/campaigns/create` | `Campaigns\Builder` | 5-step wizard + compliance gate |
| `/campaigns/{id}` | `Campaigns\Show` | `wire:poll.2s` progress, tick funnel, per-recipient table |
| `/templates` | `Templates\Index` | CRUD, live preview, version history |
| `/schedules` | `Schedules\Index` | next-run preview, toggle, run-now |
| `/queue` | `Queue\Monitor` | pending/reserved/failed counts, pause/resume, throughput |
| `/errors` | `Errors\Center` | filters, trend chart, top failing recipients, failed-jobs bulk retry |
| `/extract` | `Extract\Wizard` | group multi-select, filters, active-check, results, export |
| `/settings` | `Settings\Index` | delays, rate limits, quiet hours, warm-up, alerts, users, API keys, IP allowlist |

**Live updates (Req 28.4, 28.10):** default is Livewire polling — `wire:poll.2s` on campaign progress, `wire:poll.5s` on overview/queue, `wire:poll.3s` on the QR modal. Zero extra processes. If `BROADCAST_CONNECTION=reverb`, the same components subscribe to broadcast events instead and polling is disabled — sub-second updates, one extra Supervisor program.

**i18n (Req 28.8):** `lang/en/*.php` + `lang/hi/*.php`, locale in user prefs.
**Compliance gate (Req 28.9):** non-dismissible banner on the bulk step + one-time acknowledgement stored per user.

---

## Error Handling Strategy

**Exception hierarchy** (`app/Exceptions`), all extending `AppException`:

```
AppException (code, category, severity, retryable, httpStatus, context)
├── ValidationException          → 422, non-retryable
├── AuthException                → 401/403
├── NotFoundException            → 404
├── RateLimitException           → 429, retryable w/ cool-down
├── BridgeUnreachableException   → 503, retryable      (Req 2.9, 35.8)
├── WhatsAppException
│   ├── SessionNotConnectedException
│   ├── NotOnWhatsAppException        → non-retryable
│   ├── NotGroupAdminException        → non-retryable
│   └── UnsupportedOperationException → non-retryable  (Req 21.6)
└── MediaException               → retryable
```

**Boundaries:**
1. `bootstrap/app.php` exception handler → `AppException` renders uniform JSON/Blade with correlation id; unknown → 500 + full capture
2. Job `failed()` hooks → classify → retry via `release()` or land in `failed_jobs`; a worker never dies from a job error
3. Bridge webhook controller → each event handled in its own try/catch so one bad event doesn't drop the whole batch
4. `queue:work --max-time` + Supervisor restart handles PHP's long-run memory drift (Req 31.2)

**Graceful deploy (Req 31.3):**
```bash
php artisan down --render=errors::503
git pull && composer install --no-dev -o && npm ci && npm run build
php artisan migrate --force
php artisan config:cache route:cache view:cache
php artisan queue:restart          # workers finish current job, then respawn
php artisan up
```

---

## Security Considerations (Req 27, 31.9, 36.5)

| Concern | Mitigation |
|---|---|
| Weak default `admin`/`admin` | forced change + risky-op lock + login throttle + IP allowlist + audit (Req 27.2–27.9) |
| Passwords | bcrypt via `Hash::make` |
| API keys | SHA-256 stored, plaintext shown once |
| Sessions | secure + httpOnly + `SameSite=Lax` cookies, HTTPS-only |
| CSRF | Laravel middleware on all web forms |
| Bridge exposure | binds `127.0.0.1` only, shared-secret token, nginx never proxies it (Req 35.2) |
| Webhook spoofing | HMAC-SHA256 signature verified before processing (Req 35.5) |
| Auth state files | `storage/wa-sessions/`, outside web root, `0700` (Req 35.10) |
| Path traversal | export/media filenames server-generated ULIDs; user input never in paths |
| Upload validation | `finfo` MIME sniff + size cap + extension allowlist |
| SQL injection | Eloquent / parameterized only; raw string SQL banned (Req 36.6) |
| XSS | Blade `{{ }}` escaping; `{!! !!}` requires review |
| PII in logs | Monolog processor masks phone numbers; bodies never logged |
| Secrets | `.env` only, `.env` gitignored, never logged |
| Dependency risk | `composer audit` + `npm audit` in CI |

---

## Testing Strategy (Req 36)

**Unit (Pest)** — pure logic first: gaussian delay bounds, spintax, JID normalization, template rendering, error classification, retry matrix, join-rule evaluation, warm-up quota table, cron next-run, `MessageStatus::rank()` ordering, CSV/vCard writers.

**Feature** — `FakeBridgeClient` bound in the container (Req 36.2). Covers the full send pipeline, campaign lifecycle (start/pause/resume with zero double-send), welcome flow, retry → `failed_jobs` → manual retry, extraction + export, opt-out enforcement. `Queue::fake()` for dispatch assertions, real queue for integration paths.

**HTTP** — every API route: auth required, RBAC enforced, validation errors, response shape.

**Load (Req 36.4)** — 10k messages dispatched, workers run to drain. Assert: rate limits respected, `memory_get_peak_usage` within threshold, zero duplicate `wa_message_id`, `jobs` table empty at end.

**Static** — Pint (style) + PHPStan level 6 (Req 31.7).

---

## Key Design Decisions & Trade-offs

| # | Decision | Trade-off accepted |
|---|---|---|
| 1 | Thin Node bridge instead of pure PHP | One non-PHP process to deploy; in exchange all 37 features remain possible |
| 2 | `BridgeClient` as an interface | Slight indirection; in exchange Cloud API or a future PHP library is a drop-in swap |
| 3 | MySQL queue driver, not Redis | Lower throughput ceiling than Redis; in exchange deployment is genuinely PHP + MySQL only |
| 4 | Livewire polling instead of mandatory WebSockets | 2s latency instead of instant; in exchange zero extra processes. Reverb available when instant is needed |
| 5 | Queue-mandatory sends | Even a single message needs a worker running; in exchange uniform retry/rate/observability |
| 6 | Gaussian delay over uniform | Marginal extra compute; in exchange a less detectable timing signature |
| 7 | Warm-up caps not overridable | Frustrating on a new number; in exchange materially lower ban risk |
| 8 | `admin`/`admin` seeded, but bulk ops locked until changed | Slightly more friction on day one; in exchange a public domain isn't left wide open |
| 9 | Extraction via temp table, not in-memory set | Extra table churn; in exchange large multi-group runs don't hit `memory_limit` |
| 10 | Opt-out filter has no bypass flag | Lost flexibility; in exchange a compliance guarantee that can't be misconfigured |
| 11 | Append-only status timeline | More rows per message; in exchange full delivery forensics |

---

## Traceability: Component → Requirements

| Component | Requirements |
|---|---|
| `BridgeClient` + WA Bridge sidecar | 35, 21.6, 21.7 |
| `SessionManager` + state machine | 1, 2, 32 |
| `ReconnectPolicy` + `wa:health-check` | 2 |
| `SendMessageJob` pipeline | 3, 4, 5, 6 |
| `AntiBanEngine` | 7 |
| `MediaService` | 8 |
| `TemplateService` | 9 |
| `SchedulerService` + cron | 10, 22 |
| `GroupService` | 11, 12, 13 |
| `JoinRequestService` | 14 |
| `WelcomeService` | 15, 16 |
| `ExtractionService` | 17, 18 |
| `ExportService` | 19 |
| `TaggingService` | 20 |
| `ChannelService` + snapshots | 21, 22 |
| `ErrorReporter` + retry + alerts | 23, 24, 25 |
| API routes + Sanctum + Gates | 26 |
| `AdminUserSeeder` + security middleware | 27 |
| Blade + Livewire dashboard | 28 |
| `ContactService` + opt-out | 29, 30 |
| Supervisor + nginx + deploy scripts | 31 |
| `PoolSelector` + failover | 32 |
| Monolog + metrics + correlation id | 33 |
| `config/*` + `settings` table | 34 |
| Pest suites + hardening | 36 |

---

## Sources

- [Baileys — WebSocket-based WhatsApp Web API (TypeScript)](https://github.com/whiskeysockets/Baileys)
- [Laravel WhatsApp: Two Backends Behind One Facade](https://laravel-news.com/laravel-whatsapp-two-backends-behind-one-facade)
- [WhatsApp Groups API: 2026 Business Guide](https://www.imbee.io/resource/whatsapp-groups-api-business-guide-2026)

*Content was rephrased for compliance with licensing restrictions.*
