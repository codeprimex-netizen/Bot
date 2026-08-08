# WhatsApp Auto Messenger Bot — Development Roadmap

**Total Phases:** 31 (Phase 0 → Phase 30)
**Total Features Covered:** 37
**Stack:** Node.js 20 LTS · Baileys (WhatsApp Multi-Device) · Express · SQLite/Postgres (Prisma) · BullMQ + Redis · React + Vite (Dashboard) · PM2 / Docker

> ⚠️ **Disclaimer:** Bulk/unsolicited messaging WhatsApp ke Terms of Service ke against hai aur number ban ho sakta hai. Yeh roadmap **opt-in audience, apne owned groups/channels aur consented contacts** ke liye design kiya gaya hai. Anti-ban phase (Phase 9) rate-limits ko enforce karta hai, bypass nahi karta.

---

## 1. Architecture Overview

```
┌──────────────────────────────────────────────────────────────┐
│                    Web Dashboard (React)                     │
└───────────────────────────┬──────────────────────────────────┘
                            │ REST + WebSocket
┌───────────────────────────▼──────────────────────────────────┐
│                    API Layer (Express)                       │
│  auth · sessions · messages · groups · channels · reports     │
└───────────────────────────┬──────────────────────────────────┘
                            │
        ┌───────────────────┼───────────────────┐
        │                   │                   │
┌───────▼──────┐   ┌────────▼────────┐   ┌──────▼───────────┐
│ Session Mgr  │   │  Job Queue      │   │  Event Bus       │
│ (Baileys x N)│   │  (BullMQ/Redis) │   │  (group/channel  │
│ multi-device │   │  send · retry   │   │   /message evts) │
└───────┬──────┘   └────────┬────────┘   └──────┬───────────┘
        │                   │                   │
┌───────▼───────────────────▼───────────────────▼───────────────┐
│         Core Services: messaging · media · templates ·        │
│         scheduler · tagging · extraction · error-reporter     │
└───────────────────────────┬───────────────────────────────────┘
                            │
┌───────────────────────────▼───────────────────────────────────┐
│              Persistence (Prisma → SQLite/Postgres)           │
│   sessions · contacts · groups · messages · jobs · errors     │
└───────────────────────────────────────────────────────────────┘
```

### Target Folder Structure

```
bot/
├── src/
│   ├── core/            # session manager, socket lifecycle, reconnect
│   ├── services/        # messaging, media, groups, channels, tagging...
│   ├── queue/           # BullMQ producers/workers, rate limiter
│   ├── scheduler/       # cron jobs, scheduled campaigns
│   ├── handlers/        # baileys event handlers (welcome, join req...)
│   ├── api/             # express routes + controllers + validators
│   ├── db/              # prisma schema, repositories, migrations
│   ├── utils/           # logger, jid helpers, csv/vcard, delays
│   └── index.js
├── dashboard/           # React + Vite frontend
├── sessions/            # auth state (gitignored)
├── exports/             # csv/txt/json/vcard output (gitignored)
├── tests/
├── ecosystem.config.js  # PM2
├── docker-compose.yml
└── .env.example
```

---

## 2. Phase Roadmap

Legend — **Effort:** S (≤1 din) · M (2–3 din) · L (4–6 din) · XL (1–2 hafte)

---

### 🏗️ BLOCK A — Foundation (Phase 0–4)

#### Phase 0 — Project Bootstrap
**Effort:** S | **Features:** infrastructure
- Repo init, Node 20 + ESM setup, `package.json` scripts
- ESLint + Prettier + Husky pre-commit
- `.env.example`, config loader with schema validation (zod)
- Pino logger with rotating file transport + log levels
- Base error classes (`AppError`, `WhatsAppError`, `ValidationError`)
- `docker-compose.yml` for Redis + Postgres (local dev)

**Done when:** `npm run dev` boots, logs structured JSON, lint passes clean.

---

#### Phase 1 — WhatsApp Connection Core
**Effort:** M | **Features:** foundation for #30, #31
- Baileys socket wrapper (`makeWASocket`) with multi-file auth state
- QR code generation (terminal + base64 for API) and pairing-code login
- Connection state machine: `connecting → open → closing → closed`
- Graceful logout, credential persistence

**Done when:** QR scan karke ek number connect hota hai aur restart pe session restore hota hai.

---

#### Phase 2 — Database Layer
**Effort:** M | **Features:** foundation for all
- Prisma schema: `Session`, `Contact`, `ContactList`, `Group`, `GroupMember`, `Channel`, `Message`, `Campaign`, `Template`, `ErrorLog`, `JobRun`, `Setting`
- Migration workflow + seed script
- Repository pattern layer (no raw Prisma calls in services)
- SQLite for dev, Postgres for prod — same schema

**Done when:** `npx prisma migrate dev` clean chalta hai, seed data insert hota hai.

---

#### Phase 3 — Multi-Session Manager 🔹 *Feature #30, #35*
**Effort:** L | **Features:** Session Management, Multi-Device Support
- `SessionManager`: create / start / stop / delete named sessions
- Per-session isolated auth folder + in-memory socket registry
- Session metadata in DB (phone, status, last-seen, device id)
- Concurrent session limit + memory guard
- Session-scoped logger (`[session:sales-1]`)

**Done when:** 3 numbers simultaneously connected, ek session crash hone pe baaki unaffected.

---

#### Phase 4 — Auto Reconnect & Health Monitor 🔹 *Feature #31*
**Effort:** M | **Features:** Auto Reconnect
- Exponential backoff reconnect (1s → 2s → 4s … cap 5 min, jitter)
- Disconnect-reason handling: `loggedOut` (stop) vs `restartRequired` (retry) vs network
- Heartbeat / presence ping + stale-socket detection
- `/health` endpoint per session, uptime & reconnect-count metrics

**Done when:** Network cable pull karne pe bot khud reconnect ho jata hai, `loggedOut` pe retry loop nahi banta.

---

### 💬 BLOCK B — Messaging Engine (Phase 5–12)

#### Phase 5 — Message Sending Core 🔹 *Feature #9*
**Effort:** M | **Features:** Single Mode Messaging
- `MessagingService.sendText(sessionId, jid, text, opts)`
- JID normalization + `onWhatsApp()` number validation
- Single-mode sequential sender (ek ke baad ek, confirm-then-next)
- Every send persisted in `Message` table with local id ↔ WA message id mapping

**Done when:** Ek contact ko message jata hai, DB mein WA message id ke saath record banta hai.

---

#### Phase 6 — Delivery / Check Mark Tracking 🔹 *Feature #11*
**Effort:** M | **Features:** Check Mark Status
- `messages.update` listener → status mapping:
  `PENDING(🕐) → SENT(✓) → DELIVERED(✓✓) → READ(🔵✓✓) → PLAYED → FAILED(✗)`
- Status history timeline per message (timestamps for each transition)
- Per-campaign aggregate counters (sent/delivered/read/failed %)
- WebSocket push so dashboard live update ho

**Done when:** Dashboard pe ticks real-time badalte dikhte hain aur DB mein transition timestamps hain.

---

#### Phase 7 — Message Queue & Rate Limiting 🔹 *Feature #15*
**Effort:** L | **Features:** Message Queue System
- BullMQ `send-queue` with per-session concurrency = 1
- Token-bucket rate limiter: configurable msgs/min, msgs/hour, msgs/day
- Priority lanes: `transactional > welcome > campaign`
- Pause / resume / drain / purge queue controls
- Idempotency key so duplicate send na ho

**Done when:** 500 jobs enqueue karke bhi configured rate cross nahi hota; restart pe queue survive karta hai.

---

#### Phase 8 — Dual Mode & Bulk Messaging 🔹 *Feature #10, #13*
**Effort:** L | **Features:** Dual Mode, Auto Bulk Message
- `Campaign` entity: recipient list + template + mode + schedule
- **Single mode:** strictly sequential, per-message ack wait
- **Dual mode:** parallel workers (configurable N) for faster fan-out
- Recipient sources: manual list, CSV upload, contact list, group members
- Live progress (`x/y sent`), mid-flight pause/resume/cancel

**Done when:** 200 recipients ka campaign dono modes mein chalta hai, pause/resume state safe hai.

---

#### Phase 9 — Anti-Ban Engine 🔹 *Feature #16, #34*
**Effort:** L | **Features:** Custom Delay, Anti-Ban Protection
- Randomized delay between messages (min/max range, gaussian jitter)
- Human simulation: `presence: composing` → typing duration ∝ message length → `paused`
- Warm-up ramp for new numbers (day 1: 20 msgs → day 7: full)
- Message spintax / variation engine (`{Hi|Hello|Hey}`) to avoid identical payloads
- Batch breaks (e.g. 50 msgs ke baad 10 min cool-down), quiet hours window
- Ban-risk scoring + auto-throttle jab delivery failure spike ho

**Done when:** Two identical campaigns ka payload/timing pattern statistically different hai; quiet hours respect hote hain.

---

#### Phase 10 — Media Messaging 🔹 *Feature #14*
**Effort:** M | **Features:** Bulk Media Send
- Image / video / audio / document / sticker send with caption
- Local upload + remote URL fetch, MIME sniffing, size limits
- Sharp-based image compression, ffmpeg thumbnail for video
- Media cache & re-upload avoidance (reuse uploaded media key across recipients)
- View-once and PTT (voice note) support

**Done when:** Ek 10MB video 100 recipients ko bheja ja sakta hai without re-upload per recipient.

---

#### Phase 11 — Templates & Personalization 🔹 *Feature #36*
**Effort:** M | **Features:** Message Templates
- Template CRUD with categories + versioning
- Variable interpolation: `{{name}}`, `{{group}}`, `{{custom.city}}`, `{{date}}`
- Fallback values, missing-variable strict mode
- Rich composition: text + media + buttons/list (where supported)
- Preview renderer + test-send to self

**Done when:** Ek template 3 different contacts ko personalized text ke saath deliver hota hai.

---

#### Phase 12 — Scheduler 🔹 *Feature #12*
**Effort:** M | **Features:** Scheduled Messaging
- One-time schedule (ISO datetime + timezone) and recurring (cron expression)
- Timezone-aware execution, DST safe
- Persistent schedule store — restart pe missed jobs recovery policy (skip / run-once / catch-up)
- Schedule CRUD + next-5-runs preview + manual "run now"

**Done when:** Bot restart karne ke baad bhi scheduled campaign apne time pe fire hota hai.

---

### 👥 BLOCK C — Groups (Phase 13–20)

#### Phase 13 — Group Core 🔹 *Feature #5*
**Effort:** M | **Features:** Group Create/Delete
- Group create with initial participants, subject, description
- Group leave / delete (owner), group metadata fetch + local cache
- Invite link get / revoke, join-by-link
- Group list sync with pagination

**Done when:** API se group ban jata hai, invite link generate + revoke hota hai, cache DB mein sync hai.

---

#### Phase 14 — Group Admin & Members 🔹 *Feature #6, #2*
**Effort:** M | **Features:** Group Admin Management, Channel Member Management
- Participant add / remove / promote / demote (bulk supported)
- "Cannot add — invite sent" case handling
- Admin-only action guard (pehle check karo bot admin hai ya nahi)
- Bulk import participants from CSV with per-row result report

**Done when:** 50 numbers bulk add hote hain aur per-number success/failure report milti hai.

---

#### Phase 15 — Group Settings & Join Requests 🔹 *Feature #7, #8*
**Effort:** M | **Features:** Group Settings Control, Member Approve/Reject
- Settings: `announcement` (only admins message), `locked` (only admins edit info), `ephemeral` (disappearing msgs), `approvalMode`
- Subject / description / icon update
- Pending join-request list, approve / reject (single + bulk)
- **Auto-approve rules:** country-code allowlist, blocklist, regex, manual-only

**Done when:** Join request aane pe rule ke hisaab se auto approve/reject hota hai aur audit log banta hai.

---

#### Phase 16 — Welcome Message Engine 🔹 *Feature #21*
**Effort:** M | **Features:** Auto Welcome Message
- `group-participants.update` handler: `add` / `remove` / `promote` / `demote`
- Per-group welcome + goodbye config (enable/disable)
- Variables: `{{member}}`, `{{group}}`, `{{memberCount}}`, `{{rules}}`
- Send target: group message with mention, ya member ko DM
- Duplicate/flood guard (same member re-join spam pe cooldown)

**Done when:** Naya member add hone pe 1 hi welcome message jata hai, mention properly render hota hai.

---

#### Phase 17 — Welcome Templates & Media 🔹 *Feature #22, #23*
**Effort:** S | **Features:** Custom Welcome Templates, Welcome with Media
- Multiple templates per group with rotation (sequential / random / weighted)
- Image / video / GIF welcome card attach
- Optional dynamic welcome image generation (canvas: member name + avatar + count)
- A/B slots for testing different welcome copy

**Done when:** Ek group mein 3 templates rotate hote hain, media ke saath deliver hote hain.

---

#### Phase 18 — Group Number Extraction 🔹 *Feature #24, #26*
**Effort:** M | **Features:** Member Number Extract, Filter Active Numbers
- Full participant dump: number, pushName, admin role, joined-at (jahan available)
- Multi-group extract + de-duplication across groups
- **Active filter:** `onWhatsApp()` registration check, business-account flag, has-profile-photo, recent-activity heuristic
- Filters: country code, admins-only, exclude-existing-contacts

**Done when:** 3 groups se combined unique numbers nikalte hain with active/inactive classification.

---

#### Phase 19 — Export Engine 🔹 *Feature #20, #25*
**Effort:** S | **Features:** Export to CSV/TXT, Error Export
- Pluggable exporters: CSV, TXT, JSON, XLSX, vCard
- Column selection + custom delimiter + UTF-8 BOM (Excel-safe)
- Streaming writer for large datasets (no memory blowup)
- Download endpoint with signed, expiring URLs

**Done when:** 10k rows ka CSV stream hoke download hota hai, Excel mein correctly khulta hai.

---

#### Phase 20 — Auto Tagging 🔹 *Feature #27, #28, #29*
**Effort:** M | **Features:** Tag All, Selective Tagging, Tag with Message
- `@everyone` / tagall — hidden mentions (mentions array without visible @text) ya visible list
- Selective tagging: admins only, non-admins, custom list, regex filter
- Tag + custom message / media caption
- Chunked tagging for large groups (mention limit safe) with delay between chunks
- Anti-spam: per-group cooldown + admin-only permission

**Done when:** 300-member group mein tagall chunked hoke jata hai, sabko notification milta hai.

---

### 📢 BLOCK D — Channels (Phase 21–22)

#### Phase 21 — Channel (Newsletter) Management 🔹 *Feature #1*
**Effort:** L | **Features:** Channel Create/Delete
- Newsletter create / delete / mute / unmute / follow / unfollow
- Channel metadata: name, description, picture, invite link
- Admin management + subscriber listing (jitna API expose karta hai)
- Graceful capability detection — Baileys newsletter API version-dependent hai

**Done when:** Channel create + update + delete API se hota hai, unsupported ops clean error dete hain.

---

#### Phase 22 — Channel Auto Post & Analytics 🔹 *Feature #3, #4*
**Effort:** M | **Features:** Channel Auto Post, Channel Analytics
- Channel post queue: text, media, polls, link previews
- Scheduled + recurring channel posts, content calendar view
- Analytics collection: view count, reaction breakdown, follower delta over time
- Post performance leaderboard + CSV export

**Done when:** Scheduled channel post publish hota hai aur 24h baad views/reactions snapshot DB mein aata hai.

---

### 🚨 BLOCK E — Reliability (Phase 23–24)

#### Phase 23 — Error Reporting & Retry 🔹 *Feature #17, #18*
**Effort:** L | **Features:** Error Report Dashboard, Failed Message Retry
- Central `ErrorReporter`: normalized error codes, category, severity, session, entity, stack, context payload
- Error taxonomy: `AUTH`, `RATE_LIMIT`, `NOT_ON_WHATSAPP`, `MEDIA`, `NETWORK`, `PERMISSION`, `UNKNOWN`
- Retry policy per category: max attempts, exponential backoff, jitter
- Dead-letter queue + manual bulk retry from dashboard
- Error dashboard: filter by session/date/type, error-rate trend chart, top-N failing recipients

**Done when:** Ek forced failure retry hoke DLQ mein jaata hai aur dashboard se manually retry ho jata hai.

---

#### Phase 24 — Real-time Notifications & Observability 🔹 *Feature #19*
**Effort:** M | **Features:** Real-time Error Notifications
- WebSocket live error feed + toast in dashboard
- Alert channels: WhatsApp admin DM, Telegram, email, generic webhook (Slack/Discord)
- Alert rules: severity threshold, error-rate spike, session disconnect, queue backlog
- Deduplication + rate-limited alerts (alert storm se bachne ke liye)
- `/metrics` Prometheus endpoint (messages sent, queue depth, error rate, session up)

**Done when:** Session disconnect hone pe 5 second ke andar admin ko WhatsApp/webhook alert milta hai.

---

### 🖥️ BLOCK F — Control Plane (Phase 25–27)

#### Phase 25 — REST API & Auth
**Effort:** L | **Features:** foundation for #33
- Express REST API, versioned (`/api/v1`), zod request validation
- JWT auth + refresh tokens, API keys for machine access
- RBAC: `owner` / `admin` / `operator` / `viewer`
- Per-key rate limiting, request logging, audit trail
- OpenAPI 3 spec + Swagger UI

**Done when:** Swagger se saare endpoints test hote hain, unauthorized calls 401/403 dete hain.

---

#### Phase 26 — Web Dashboard 🔹 *Feature #33*
**Effort:** XL | **Features:** API Dashboard
- React + Vite + Tailwind, shadcn/ui components
- Screens: Login · Overview · Sessions (QR scan) · Contacts · Groups · Channels · Campaign Builder · Templates · Schedules · Queue Monitor · Error Center · Extractor · Settings
- Live updates via WebSocket (ticks, progress bars, error feed)
- Charts: delivery funnel, hourly send volume, error trend
- Dark mode, mobile-responsive, i18n (English + Hindi)

**Done when:** Naya user QR scan se login karke pura campaign dashboard se bina CLI chala sakta hai.

---

#### Phase 27 — Contact Management 🔹 *Feature #37*
**Effort:** M | **Features:** Contact Management (vCard)
- Contact CRUD, custom fields, tags, notes
- vCard 3.0/4.0 import + export, CSV/XLSX import with column mapping wizard
- WhatsApp phonebook sync + `onWhatsApp()` validation on import
- Contact lists / segments with rule-based dynamic membership
- Global blocklist + opt-out/unsubscribe handling (STOP keyword auto-honor)

**Done when:** 1000-contact CSV import hota hai, invalid numbers flag hote hain, opt-out list respect hoti hai.

---

### 🚀 BLOCK G — Production (Phase 28–30)

#### Phase 28 — Process Management & Deployment 🔹 *Feature #32*
**Effort:** M | **Features:** PM2 Process Management
- `ecosystem.config.js`: cluster/fork mode, memory restart, log rotation
- Zero-downtime reload, `pm2 startup` boot persistence
- Dockerfile (multi-stage) + docker-compose (app + redis + postgres + nginx)
- GitHub Actions CI: lint → test → build → image push
- Backup/restore scripts for sessions + DB, `.env` secret management

**Done when:** `pm2 reload` se downtime ke bina deploy hota hai; container se full stack up hota hai.

---

#### Phase 29 — Multi-Device Orchestration 🔹 *Feature #35 (advanced)*
**Effort:** L | **Features:** Multi-Device Support at scale
- Number pool with load balancing strategies: round-robin, least-used, weighted, health-aware
- Sticky routing (ek contact ko hamesha same number se) for conversation continuity
- Auto-failover jab number ban/disconnect ho
- Per-number daily quota + warm-up stage tracking
- Optional horizontal scaling: worker nodes + shared Redis queue

**Done when:** 5 numbers ke pool pe 1000-msg campaign evenly distribute hota hai, ek number gir jaane pe baaki continue karte hain.

---

#### Phase 30 — Hardening, Testing & v1.0 Release
**Effort:** L | **Features:** quality gate
- Unit tests (services, utils) + integration tests (mocked Baileys socket) — target 70%+ on core
- Load test: 10k queued messages ka soak run, memory-leak check
- Security pass: input sanitization, path traversal on media/exports, secret scanning, dependency audit
- Docs: README, setup guide, API reference, troubleshooting, FAQ, disclaimer
- Data retention & purge policy, GDPR-style delete-my-data
- Version tag `v1.0.0` + CHANGELOG + release notes

**Done when:** CI green, load test pass, docs complete, `v1.0.0` tagged.

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
| 32 | PM2 Process Management | 28 |
| 33 | API Dashboard | 25, 26 |
| 34 | Anti-Ban Protection | 9 |
| 35 | Multi-Device Support | 3, 29 |
| 36 | Message Templates | 11 |
| 37 | Contact Management (vCard) | 27 |

---

## 4. Milestones

| Milestone | Phases | Kya usable ho jayega |
|-----------|--------|----------------------|
| **M1 — Connected** | 0–4 | Multi-session connect + auto reconnect, DB ready |
| **M2 — Messaging MVP** | 5–12 | Single/dual/bulk send, ticks, queue, anti-ban, media, templates, scheduling |
| **M3 — Group Suite** | 13–20 | Full group control, welcome, extraction, export, tagall |
| **M4 — Channels** | 21–22 | Channel manage + auto post + analytics |
| **M5 — Reliable** | 23–24 | Error center, retries, live alerts, metrics |
| **M6 — Product** | 25–27 | REST API + web dashboard + contact CRM |
| **M7 — v1.0** | 28–30 | PM2/Docker deploy, number pool scaling, tested & documented |

**Suggested build order:** M1 → M2 → M5 (partial: Phase 23) → M3 → M6 → M4 → M7.
Reason: error reporting ko messaging ke turant baad laane se aage ke saare phases debug karna aasan ho jata hai.

---

## 5. Cross-Cutting Concerns (har phase mein apply)

- **Logging:** structured, session-scoped, PII masked (phone numbers partially redacted in logs)
- **Config:** sab tunable value `.env` / DB settings se, hardcode kuch nahi
- **Idempotency:** har send/bulk operation retry-safe
- **Backpressure:** queue depth threshold cross hone pe new enqueues throttle
- **Audit trail:** kisne kab kya bheja / kis group ko modify kiya
- **Compliance:** opt-out honor, rate caps hard-enforced, disclaimer in UI

---

## 6. Key Risks

| Risk | Impact | Mitigation |
|------|--------|------------|
| Number ban (bulk messaging) | High | Phase 9 anti-ban, warm-up, quota, number pool (Phase 29) |
| Baileys breaking changes | Medium | Version pin, adapter layer around socket, capability detection |
| Channel/newsletter API instability | Medium | Feature-flag Phase 21–22, graceful degradation |
| Session auth corruption | Medium | Auth backup + restore script (Phase 28) |
| Memory growth with many sessions | Medium | Session limit, store pruning, PM2 memory restart |
| ToS / legal exposure | High | Opt-in only workflow, disclaimer, opt-out enforcement |

---

## 7. Rough Timeline (1 developer, full-time)

| Block | Phases | Estimate |
|-------|--------|----------|
| A — Foundation | 0–4 | ~2 weeks |
| B — Messaging | 5–12 | ~4 weeks |
| C — Groups | 13–20 | ~3 weeks |
| D — Channels | 21–22 | ~1.5 weeks |
| E — Reliability | 23–24 | ~1.5 weeks |
| F — Control Plane | 25–27 | ~4 weeks |
| G — Production | 28–30 | ~3 weeks |
| **Total** | **31 phases** | **~19–20 weeks** |

Parallel team (2–3 devs: backend + frontend) mein ~10–12 weeks tak compress ho sakta hai.
