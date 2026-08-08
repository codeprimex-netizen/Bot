# WhatsApp Auto Messenger Bot — Development Roadmap

**Total Phases:** 31 (Phase 0 → Phase 30)
**Total Features Covered:** 37
**Stack:** PHP 8.3 · Laravel 11 · MySQL 8 · Livewire 3 + Blade + Tailwind · Supervisor · nginx + PHP-FPM
**Deployment:** `https://bot.getxtrra.in`
**Default admin:** `admin` / `admin` (forced change on first login — see Phase 25)

> Full detail: [`.kiro/specs/whatsapp-auto-messenger/`](.kiro/specs/whatsapp-auto-messenger/) — requirements.md, design.md, tasks.md

> ⚠️ **Disclaimer:** Bulk/unsolicited messaging WhatsApp ke Terms of Service ke against hai aur number ban ho sakta hai. Yeh roadmap **opt-in audience, apne owned groups/channels aur consented contacts** ke liye design kiya gaya hai. Phase 9 (anti-ban) rate limits ko **enforce** karta hai, bypass nahi karta.

---

## ⚠️ Why there is one Node process

PHP-FPM ka request-response model WhatsApp ka persistent encrypted WebSocket hold nahi kar sakta, aur is protocol ka koi maintained pure-PHP implementation exist nahi karta. Meta ki official Cloud API is feature list ko cover nahi karti — usme group support nahi hai aur free-form messages 24-hour window mein limited hain.

**Isliye:** 100% application PHP + MySQL mein hai (dashboard, auth, campaigns, queue, scheduler, anti-ban, groups, channels, welcome, extraction, export, error reporting, saara data). Ek **thin Node "WA Bridge" sidecar** (~600 lines, Phase 1) sirf protocol socket hold karta hai — koi business logic, koi DB access, koi decision nahi. Woh `BridgeClient` PHP interface ke peeche hai, isliye replaceable hai.

Detail aur pure-PHP alternative ka trade-off: [requirements.md](.kiro/specs/whatsapp-auto-messenger/requirements.md#️-critical-architectural-constraint-please-read)

---

## 1. Architecture Overview

```
                   https://bot.getxtrra.in
                             │
                  ┌──────────▼──────────┐
                  │ nginx (TLS, HTTP→S) │
                  └──────────┬──────────┘
                             │ FastCGI
                  ┌──────────▼──────────────────────────┐
                  │            PHP-FPM 8.3               │
                  │  Blade + Livewire 3   │  /api/v1     │
                  │  (dashboard)          │  Sanctum+RBAC│
                  └──────────┬──────────────────────────┘
                             │
              ┌──────────────▼───────────────────────────┐
              │  SERVICE LAYER (app/Services)            │
              │  Messaging · Campaign · AntiBan · Group  │
              │  Channel · Welcome · Tagging · Extract   │
              │  Export · Contact · Errors · SessionMgr  │
              └────┬─────────────┬─────────────┬─────────┘
                   │             │             │
        ┌──────────▼──┐  ┌───────▼──────┐  ┌───▼────────────┐
        │ QUEUE       │  │  MySQL 8     │  │ Livewire poll  │
        │ jobs        │  │  22 tables   │  │ (or Reverb WS) │
        │ failed_jobs │  │              │  │                │
        └──────────┬──┘  └──────────────┘  └────────────────┘
                   │
        ┌──────────▼─────────────────────────────────┐
        │ SUPERVISOR: php artisan queue:work         │
        │ lanes: transactional/welcome/campaign/…    │
        └──────────┬─────────────────────────────────┘
                   │
        ┌──────────▼─────────────────────────────────┐
        │ ANTI-BAN GATE (inside SendMessageJob)      │
        │ RateLimiter → Quota → QuietHours →         │
        │ GaussianDelay → TypingSimulator            │
        └──────────┬─────────────────────────────────┘
                   │
        ┌──────────▼─────────────────────────────────┐
        │ BridgeClient  (PHP interface)              │
        └──────────┬─────────────────────────────────┘
                   │ 127.0.0.1:3111 (token)
        ┌──────────▼─────────────────────────────────┐
        │ WA Bridge (Node + Baileys) — Supervisor    │
        │ socket + auth files ONLY, zero logic       │
        └──────────┬─────────────────────────────────┘
                   │ webhook POST (HMAC) → back to PHP
                   └────────────────────────────────►
```

**Single cron entry drives everything scheduled:**
```cron
* * * * * cd /var/www/bot && php artisan schedule:run >> /dev/null 2>&1
```

### Target Folder Structure

```
bot/
├── app/
│   ├── Contracts/            # BridgeClient interface
│   ├── Enums/                # SessionStatus, MessageStatus, WaStatus
│   ├── Exceptions/           # AppException hierarchy
│   ├── Http/
│   │   ├── Controllers/Api/V1/
│   │   ├── Middleware/       # ForcePasswordChange, AdminIpAllowlist, …
│   │   └── Requests/
│   ├── Jobs/                 # SendMessageJob, ExtractGroupJob, …
│   ├── Livewire/             # dashboard components
│   ├── Models/
│   ├── Services/
│   │   ├── Wa/               # SessionManager, HttpBridgeClient, FakeBridgeClient
│   │   ├── Messaging/        ├── AntiBan/       ├── Groups/
│   │   ├── Channels/         ├── Contacts/      ├── Export/
│   │   └── Errors/
│   ├── Support/              # JidNormalizer, Spintax, Redaction
│   └── Console/Commands/     # wa:health-check, channels:snapshot, …
├── bridge/                   # ← the ONLY non-PHP part (Node + Baileys)
├── config/wa.php
├── database/migrations|seeders/
├── lang/{en,hi}/
├── resources/views/
├── routes/{web,api,console}.php
├── storage/wa-sessions/      # auth state, 0700, outside web root
├── storage/app/exports/
├── deploy/                   # nginx.conf, supervisor.conf, deploy.sh
└── tests/{Unit,Feature}/
```

---

## 2. Phase Roadmap

Legend — **Effort:** S (≤1 din) · M (2–3 din) · L (4–6 din) · XL (1–2 hafte)

---

### 🏗️ BLOCK A — Foundation (Phase 0–4)

#### Phase 0 — Laravel Bootstrap
**Effort:** S | **Features:** infrastructure
- Laravel 11 + PHP 8.3, Pest/Pint/PHPStan, package install
- MySQL-only drivers: `QUEUE_CONNECTION=database`, `CACHE_STORE=database`, `SESSION_DRIVER=database`
- `config/wa.php` — delays, rate limits, warm-up ramp, quiet hours, media caps, bridge url/token
- Monolog JSON logging with **phone-number redaction**
- `AppException` hierarchy + uniform error handler

**Done when:** `php artisan serve` boots, config validation fails loudly on missing keys, Pint + PHPStan clean.

---

#### Phase 1 — WA Bridge + BridgeClient
**Effort:** M | **Features:** foundation for everything
- `BridgeClient` PHP **interface** (session / messaging / group / channel / capability)
- Node bridge: Express + Baileys, binds `127.0.0.1` only, token auth, zero DB, zero logic
- Bridge → PHP webhook with **HMAC-SHA256** signature verification
- `HttpBridgeClient` (Guzzle) + `FakeBridgeClient` (tests)
- Capability handshake → unsupported ops give clean errors, not crashes
- Auth state in `storage/wa-sessions/`, `0700`, outside web root

**Done when:** PHP se bridge pe ek test message jata hai, event webhook wapas aata hai aur signature verify hoti hai.

---

#### Phase 2 — MySQL Schema
**Effort:** M | **Features:** foundation
- 22 domain tables + Laravel's `jobs` / `failed_jobs` / `job_batches` / `cache` / `cache_locks` / `sessions`
- Backed enums: `SessionStatus` (`allowedNext()`), `MessageStatus` (`rank()`), `WaStatus`
- Seeders: **admin/admin owner user with `must_change_password`**, default settings, sample template
- Runtime settings service (DB-backed, cached, secret-redacted)

**Done when:** `php artisan migrate --seed` clean chalta hai, `admin` login karta hai aur forced-change flag set hai.

---

#### Phase 3 — Session Manager 🔹 *Feature #30, #35*
**Effort:** L
- `SessionManager` create / start / stop / delete / restoreAll / healthy
- State machine enforced via enum `allowedNext()` — out-of-order webhooks safely rejected
- QR (base64 PNG, 60s refresh, 5 attempts → `QR_TIMEOUT`) + pairing code
- Session isolation, max-concurrent limit, per-session quota tracker

**Done when:** 3 numbers simultaneously connected; ek session fail hone pe baaki unaffected; restore verified.

---

#### Phase 4 — Auto Reconnect & Health 🔹 *Feature #31*
**Effort:** M
- `ReconnectPolicy`: 1s base, ×2, 300s cap, ±50% jitter
- Terminal reasons (`loggedOut`, `connectionReplaced`) → no retry, alert instead
- `wa:health-check` scheduled command replaces in-process watchdog (PHP-FPM can't host one)
- `/api/v1/health` per-session + bridge reachability; disconnected sessions ke jobs `release()` hote hain, fail nahi

**Done when:** Network drop pe khud reconnect; `loggedOut` pe retry loop nahi banta; bridge down pe jobs hold hote hain.

---

### 💬 BLOCK B — Messaging Engine (Phase 5–12)

#### Phase 5 — Send Core (Single Mode) 🔹 *Feature #9*
**Effort:** M
- JID normalizer, number validation with 7-day cache
- `MessagingService::sendText` → `messages` row + `wa_message_id` mapping
- Single mode: `transactional` queue, 1 worker, `WithoutOverlapping`, ack wait with `PENDING_TIMEOUT` fallback

**Done when:** Ek contact ko message jata hai; ack timeout sequence ko block nahi karta.

---

#### Phase 6 — Delivery / Check Marks 🔹 *Feature #11*
**Effort:** M
- Webhook ack → `SENT ✓` → `DELIVERED ✓✓` → `READ 🔵✓✓` → `PLAYED`
- `applyStatus()` **rank guard** — out-of-order ack se status downgrade nahi hota
- Append-only `message_status_events` timeline + campaign counters
- Livewire poll (default) / Reverb broadcast (optional)

**Done when:** Ticks live badalte hain aur DB mein full transition timeline hai.

---

#### Phase 7 — Queue & Rate Limiting 🔹 *Feature #15*
**Effort:** L
- `SendMessageJob` with `ShouldBeUnique` (idempotency key) + `WithoutOverlapping(session)`
- Named lanes: `transactional` > `welcome` > `campaign` > `extraction`
- MySQL-backed token buckets (min/hour/day) via `Cache::lock()`; limit hit → `release()`, never drop
- Pause/resume, stats, backpressure on `jobs` depth

**Done when:** 500 jobs pe configured rate cross nahi hota; worker restart pe kuch lose nahi hota.

---

#### Phase 8 — Dual Mode & Bulk 🔹 *Feature #10, #13*
**Effort:** L
- `RecipientResolver`: normalize → **opt-out filter (no bypass param)** → blocklist → de-dup
- `Bus::batch()` dispatch; mode = worker allocation (SINGLE 1 worker / DUAL N workers)
- Lifecycle: pause (self-release), resume (only `PENDING`/`FAILED`), cancel (partial stats kept)
- `idempotency_key = sha256(campaign_id . recipient_jid)` → double-send structurally impossible

**Done when:** 200-recipient campaign dono modes mein chalta hai; pause→resume mein zero double-send.

---

#### Phase 9 — Anti-Ban Engine 🔹 *Feature #16, #34*
**Effort:** L
- **Box-Muller truncated-gaussian** delay (uniform random leaves a flat detectable signature)
- Typing simulation: `composing` → length-proportional wait → `paused`
- **Warm-up ramp** (day 0–1: 20 msgs / 5 new contacts → day 15+: full) — **not runtime-overridable**
- Spintax `{Hi|Hello|Hey}` with per-recipient stable seed
- Batch cool-down, timezone-aware quiet hours
- Risk scoring: >15% failure → rate halved + alert; >30% → `THROTTLED`

**Done when:** Do identical campaigns ka timing/payload pattern statistically different hai; quiet hours respect hote hain.

---

#### Phase 10 — Bulk Media 🔹 *Feature #14*
**Effort:** M
- All media types; `finfo` MIME sniffing (extension pe trust nahi), size caps
- Intervention Image compression, ffmpeg video thumbnail
- Media reference reuse — 10MB video 100 recipients ko bina re-upload
- ULID server-generated filenames outside web root

**Done when:** Ek media N recipients ko ek hi upload se jata hai; path traversal reject hota hai.

---

#### Phase 11 — Templates 🔹 *Feature #36*
**Effort:** M
- Versioned CRUD, `{{name}}` / `{{group}}` / `{{date}}` / `{{custom.*}}`
- Fallbacks + strict mode, preview, test-send to self

**Done when:** Ek template 3 contacts ko personalized jata hai; strict mode missing variable block karta hai.

---

#### Phase 12 — Scheduler 🔹 *Feature #12*
**Effort:** M
- `ONCE` + `CRON`, IANA timezone, DST-safe
- Missed-run recovery: `SKIP` / `RUN_ONCE` / `CATCH_UP`
- `onOneServer()` + cache lock for multi-server safety; `runNow` without disturbing `next_run_at`

**Done when:** Server restart ke baad bhi scheduled campaign apne local time pe fire hota hai.

---

### 👥 BLOCK C — Groups (Phase 13–20)

| Phase | Scope | Features | Effort |
|---|---|---|---|
| **13 — Group Core** | create, metadata cache, invite get/revoke/join, leave, paginated list | #5 | M |
| **14 — Admin & Members** | add/remove/promote/demote, WA status-code → `ADDED`/`INVITE_SENT`/`ALREADY_MEMBER`/`FAILED` mapping, chunked `BulkParticipantJob`, member reconciliation, **admin guard before any bridge call** | #6, #2 | M |
| **15 — Settings & Join Requests** | announcement/locked/ephemeral/approval mode, audit log on every change, join-request inbox, ordered auto-approve rules (blocklist → country code → regex → manual) | #7, #8 | M |
| **16 — Welcome Engine** | participant event handler, mentions, DM target, `Cache::add()` duplicate guard for rejoin spam, combined vs per-member batching | #21 | M |
| **17 — Welcome Templates** | sequential/random/weighted rotation, media welcome, GD dynamic card **with text-only fallback so welcome never silently disappears**, A/B stats | #22, #23 | S |
| **18 — Number Extraction** | generator-based `ExtractGroupJob`, **temp-table de-dup** (in-memory set would blow `memory_limit` on 50k members), filters, active classification with batched checks + cache | #24, #26 | M |
| **19 — Export Engine** | CSV (UTF-8 BOM) / TXT / JSON / XLSX / vCard, `lazyById` streaming, signed expiring URLs, ULID filenames | #20, #25 | S |
| **20 — Auto Tagging** | hidden mentions via `contextInfo.mentionedJid`, selective selectors, tag+media, 200-per-chunk splitting, per-group cooldown, admin-only guard | #27, #28, #29 | M |

---

### 📢 BLOCK D — Channels (Phase 21–22)

| Phase | Scope | Features | Effort |
|---|---|---|---|
| **21 — Channel Management** | capability-gated CRUD, follow/mute, subscriber info, `FEATURE_CHANNELS` flag, unsupported → clean error not crash | #1, #2 | L |
| **22 — Auto Post & Analytics** | text/media/poll posts, scheduled + recurring, content calendar, hourly snapshots with **delta derived between snapshots** (treating a cumulative counter as delta is the classic bug), engagement ranking + export | #3, #4 | M |

---

### 🚨 BLOCK E — Reliability (Phase 23–24)

#### Phase 23 — Error Reporting & Retry 🔹 *Feature #17, #18*
**Effort:** L
- `ErrorReporter` + classification: `AUTH`, `RATE_LIMIT`, `NOT_ON_WHATSAPP`, `MEDIA`, `NETWORK`, `BRIDGE`, `PERMISSION`, `VALIDATION`, `UNKNOWN`
- Retry matrix per category (NETWORK 5 / BRIDGE 5 / RATE_LIMIT 3 cool-down / NOT_ON_WHATSAPP 0)
- Exhausted → `failed_jobs`; manual bulk retry **updates original message row**, no duplicate
- Error dashboard queries, trend, top failing recipients, retention purge
- Job + webhook error boundaries; `--max-time` + Supervisor for PHP memory drift

**Done when:** Forced failure → retries → `failed_jobs` → manual retry → success, without duplicate rows.

---

#### Phase 24 — Alerts & Observability 🔹 *Feature #19*
**Effort:** M
- Alert rules: severity threshold, error-rate spike, session disconnect, queue backlog
- Notifier chain WhatsApp DM → Telegram → Mail → Webhook, with fingerprint dedup (alert storm suppression)
- `/api/v1/metrics` Prometheus, correlation ids through jobs + bridge calls

**Done when:** Session disconnect pe 5 second ke andar alert; repeated same error ek hi alert bhejta hai.

---

### 🖥️ BLOCK F — Control Plane (Phase 25–27)

#### Phase 25 — API, Auth & Admin Security 🔹 *Feature #33*
**Effort:** L
- `/api/v1` + Form Request validation + uniform errors + forced HTTPS
- Sanctum tokens, API keys (SHA-256 stored, plaintext once), RBAC gates (`owner`/`admin`/`operator`/`viewer`)
- **Default-admin guard rails** — this is what makes `admin`/`admin` survivable on a public domain:
  - `ForcePasswordChange` middleware
  - `RequireStrongPassword` → **bulk-send aur destructive ops blocked until password changed**
  - Persistent warning banner
  - Login throttle per-IP + per-username with lockout
  - Optional IP allowlist, idle-session timeout, every login attempt audit-logged
- OpenAPI spec + protected docs UI

**Done when:** Default password pe bulk-send blocked hai; change ke baad allowed; lockout aur allowlist kaam karte hain.

---

#### Phase 26 — Web Dashboard 🔹 *Feature #33*
**Effort:** XL
- Blade + Livewire 3 + Alpine + Tailwind (no separate SPA, no second language)
- Screens: Login · Forced password change · Overview · Sessions (QR modal, `wire:poll.3s` auto-refresh) · Contacts + import wizard · Groups (members, settings, join requests, welcome, tag-all) · Channels (composer, calendar, analytics) · Campaign builder (5-step + compliance gate) · Campaign detail (`wire:poll.2s` tick funnel) · Templates · Schedules · Queue monitor · Error center · Extractor · Settings
- Live updates via Livewire polling by default — **zero extra processes**; Reverb opt-in for sub-second
- Charts, dark mode, mobile-responsive, `lang/en` + `lang/hi`

**Done when:** Naya user QR scan se login karke pura campaign bina CLI chala sakta hai.

---

#### Phase 27 — Contacts & Compliance 🔹 *Feature #37*
**Effort:** M
- Contact CRUD, queued CSV/XLSX import with column-mapping wizard, vCard 3.0/4.0
- Phonebook sync, dynamic segments compiled at query time (always-correct membership)
- Blocklist + **opt-out enforced inside `RecipientResolver` with no bypass parameter**
- Data-deletion + retention purge jobs

**Done when:** 1000-contact import queued job se hota hai; opted-out number kisi bhi resolution path se nahi nikalta.

---

### 🚀 BLOCK G — Production (Phase 28–30)

#### Phase 28 — Deployment (bot.getxtrra.in)
**Effort:** M | **Features:** #32 (Supervisor replaces PM2)
- nginx server block for `bot.getxtrra.in`, root `public/`, TLS + HTTP→HTTPS, security headers
- Supervisor programs: worker per lane (`transactional` 1, `campaign` N, `welcome`, `extraction`) + `bot-bridge`
- Single cron entry for `schedule:run`
- Deploy script: `down` → pull → composer/npm → migrate → cache → `queue:restart` → `up`
- CI: Pint → PHPStan → Pest → `composer audit`
- MySQL + auth-state backup/restore, log rotation, `.env`-only secrets

**Done when:** `deploy.sh` se release hota hai aur `https://bot.getxtrra.in` live serve karta hai.

---

#### Phase 29 — Multi-Device Orchestration 🔹 *Feature #35*
**Effort:** L
- Pool strategies: round-robin, least-used, weighted, health-aware
- Sticky routing (same recipient → same session)
- Failover on unhealthy/logged-out, quota exclusion, warm-up reduced share
- Multi-server coordination on shared MySQL queue

**Done when:** 5-number pool pe 1000-msg campaign evenly distribute hota hai; ek number gir jaane pe baaki without duplicates complete karte hain.

---

#### Phase 30 — Hardening, Testing & v1.0
**Effort:** L
- Pest coverage gate on `app/Services`, `app/Jobs`, `app/Support`; `FakeBridgeClient` feature suite
- Load/soak: 10k messages → assert rate limits held, peak memory in bounds, zero duplicate `wa_message_id`, `jobs` empty
- Security pass: path traversal, uploads, CSRF, XSS, no raw string SQL, default-admin guard rails end-to-end
- Docs: server setup (nginx + PHP-FPM + Supervisor + cron + bridge), API reference, troubleshooting, **first-login password change instruction**
- `v1.0.0` tag + CHANGELOG

**Done when:** CI green, load test pass, docs complete, all 36 requirements traced.

---

## 3. Feature → Phase Mapping

| # | Feature | Phase |
|---|---------|-------|
| 1 | Channel Create/Delete | 21 |
| 2 | Channel Member Management | 14, 21 |
| 3 | Channel Auto Post | 22 |
| 4 | Channel Analytics | 22 |
| 5 | Group Create/Delete | 13 |
| 6 | Group Admin Management | 14 |
| 7 | Group Settings Control | 15 |
| 8 | Group Member Approve/Reject | 15 |
| 9 | Single Mode Messaging | 5 |
| 10 | Dual Mode Messaging | 8 |
| 11 | Check Mark Status | 6 |
| 12 | Scheduled Messaging | 12 |
| 13 | Auto Bulk Message | 8 |
| 14 | Bulk Media Send | 10 |
| 15 | Message Queue System | 7 |
| 16 | Custom Delay Between Messages | 9 |
| 17 | Full Error Report Dashboard | 23, 26 |
| 18 | Failed Message Retry | 23 |
| 19 | Real-time Error Notifications | 24 |
| 20 | Error Export (CSV/JSON) | 19, 23 |
| 21 | Auto Welcome Message | 16 |
| 22 | Custom Welcome Templates | 17 |
| 23 | Welcome with Media | 17 |
| 24 | Group Member Number Extract | 18 |
| 25 | Export to CSV/TXT | 19 |
| 26 | Filter Active Numbers | 18 |
| 27 | Tag All Members | 20 |
| 28 | Selective Tagging | 20 |
| 29 | Tag with Custom Message | 20 |
| 30 | Session Management | 3 |
| 31 | Auto Reconnect | 4 |
| 32 | Process Management | 28 (Supervisor; PM2 optional for bridge) |
| 33 | API Dashboard | 25, 26 |
| 34 | Anti-Ban Protection | 9 |
| 35 | Multi-Device Support | 3, 29 |
| 36 | Message Templates | 11 |
| 37 | Contact Management (vCard) | 27 |

---

## 4. Milestones

| Milestone | Phases | Kya usable ho jayega |
|-----------|--------|----------------------|
| **M1 — Connected** | 0–4 | Bridge live, multi-session connect + auto reconnect, MySQL schema, admin login |
| **M2 — Messaging MVP** | 5–12 | Single/dual/bulk send, ticks, queue, anti-ban, media, templates, scheduling |
| **M3 — Group Suite** | 13–20 | Full group control, welcome, extraction, export, tagall |
| **M4 — Channels** | 21–22 | Channel manage + auto post + analytics |
| **M5 — Reliable** | 23–24 | Error center, retries, live alerts, metrics |
| **M6 — Product** | 25–27 | API + secured admin + Livewire dashboard + contact CRM |
| **M7 — v1.0** | 28–30 | Live on bot.getxtrra.in, number pool scaling, tested & documented |

**Suggested build order:** M1 → M2 → Phase 23 → M3 → M6 → M4 → M7.
Reason: error reporting ko messaging ke turant baad laane se aage ke saare phases debug karna aasan ho jata hai.

---

## 5. Cross-Cutting Concerns

- **Logging:** structured JSON, session-scoped, phone numbers masked, message bodies never logged (only `content_hash`)
- **Config:** every tunable in `config/wa.php` or the `settings` table — nothing hardcoded
- **Idempotency:** every send is retry-safe via `ShouldBeUnique` + unique `idempotency_key`
- **Backpressure:** `jobs` depth threshold throttles new dispatch
- **Audit trail:** who sent what, who changed which group setting, every login attempt
- **Compliance:** opt-out honored with no bypass, rate caps hard-enforced, disclaimer in UI

---

## 6. Key Risks

| Risk | Impact | Mitigation |
|------|--------|------------|
| Number ban (bulk messaging) | High | Phase 9 anti-ban, warm-up caps (non-overridable), quota, number pool (Phase 29) |
| `admin`/`admin` on a public domain | High | Phase 25 guard rails: forced change, bulk-ops lock, login throttle, IP allowlist, audit |
| Bridge is a single point of failure | High | Supervisor auto-restart, `isReachable()` probe, jobs `release()` instead of fail, CRITICAL alert |
| Baileys breaking changes | Medium | Contained inside `bridge/`; `BridgeClient` interface absorbs the churn |
| Channel/newsletter API instability | Medium | `FEATURE_CHANNELS` flag + capability gating, graceful degradation |
| MySQL queue throughput ceiling | Medium | Named lanes + indexes; Redis is a drop-in upgrade if needed |
| PHP `memory_limit` on large extractions | Medium | Generators + temp-table de-dup + `lazyById` streaming (Phases 18–19) |
| Session auth corruption | Medium | Auth-state backup/restore script (Phase 28) |
| ToS / legal exposure | High | Opt-in only workflow, disclaimer, non-bypassable opt-out |

---

## 7. Rough Timeline (1 developer, full-time)

| Block | Phases | Estimate |
|-------|--------|----------|
| A — Foundation | 0–4 | ~2.5 weeks |
| B — Messaging | 5–12 | ~4 weeks |
| C — Groups | 13–20 | ~3 weeks |
| D — Channels | 21–22 | ~1.5 weeks |
| E — Reliability | 23–24 | ~1.5 weeks |
| F — Control Plane | 25–27 | ~3.5 weeks |
| G — Production | 28–30 | ~3 weeks |
| **Total** | **31 phases** | **~19–20 weeks** |

Livewire dashboard React SPA se tez banta hai (ek hi language, no separate frontend app), but Phase 1 bridge extra time leta hai — net roughly same as the Node estimate. Parallel team (2 devs) mein ~11–13 weeks.
