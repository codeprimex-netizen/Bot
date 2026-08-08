# Implementation Plan — WhatsApp Auto Messenger Bot

Tasks 31 phases mein organized hain (ROADMAP.md ke saath 1:1). Har task incremental hai — pichhle tasks ke output pe build karta hai, aur test-first jahan practical ho. `_Requirements:` references `requirements.md` ke numbered acceptance criteria point karte hain.

**Execution rule:** ek phase ke saare sub-tasks complete + verified hone ke baad hi next phase. Har phase ke end pe commit → push → PR → merge.

---

## Phase 0 — Project Bootstrap

- [ ] 0.1 Node 20 ESM project initialize karo aur tooling setup karo
  - `package.json` with `"type": "module"`, scripts: `dev`, `start`, `test`, `lint`, `format`, `migrate`
  - ESLint + Prettier config, Husky pre-commit hook (lint-staged)
  - Vitest configure karo with coverage reporter aur 70% threshold on `src/services`, `src/queue`, `src/utils`
  - `.gitignore`: `node_modules`, `.env`, `sessions/`, `exports/`, `logs/`, `*.db`
  - _Requirements: 34.1, 34.3_

- [ ] 0.2 Config loader zod schema ke saath implement karo
  - `src/config/schema.js` — saare env vars typed: DB, Redis, rate limits, delays, warm-up, quiet hours, media caps, JWT secrets, alert channels
  - Missing/invalid config pe startup fail with descriptive message listing all problems at once
  - Documented safe defaults har optional setting ke liye
  - `.env.example` generate karo schema se, comments ke saath
  - Unit test: valid config passes, missing required fails, defaults apply
  - _Requirements: 33.1, 33.2, 33.4_

- [ ] 0.3 Structured logger with PII redaction banao
  - Pino setup: JSON output, level from config, pretty transport dev mein
  - Custom serializer jo phone-number-shaped strings ko mask kare (`91XXXX**7890`)
  - Child-logger factory: `logger.child({ sessionId, correlationId })`
  - File transport with rotation, configurable retention
  - Unit test: phone numbers masked, message bodies never emitted
  - _Requirements: 23.3, 32.2, 32.4, 30.5_

- [ ] 0.4 Error class hierarchy define karo
  - `AppError` base: `code`, `category`, `severity`, `retryable`, `httpStatus`, `context`
  - Subclasses: `ValidationError`, `AuthError`, `NotFoundError`, `RateLimitError`, `WhatsAppError`, `SessionNotConnectedError`, `NotOnWhatsAppError`, `NotGroupAdminError`, `UnsupportedOperationError`, `MediaError`
  - `toJSON()` uniform error response shape ke liye
  - Unit test: har class correct category/retryable flag deti hai
  - _Requirements: 23.1, 23.2_

- [ ] 0.5 Local dev infrastructure aur app skeleton
  - `docker-compose.dev.yml`: Redis + Postgres with volumes
  - `src/index.js` entrypoint with `APP_MODE` switch (`all` | `api` | `worker` | `scheduler`)
  - Graceful shutdown handler: SIGTERM/SIGINT → stop intake → drain → close connections → exit 0
  - `unhandledRejection` / `uncaughtException` global handlers
  - _Requirements: 30.4, 30.6_

---

## Phase 1 — WhatsApp Connection Core

- [ ] 1.1 BaileysAdapter wrapper banao messaging primitives ke saath
  - `makeWASocket` wrapper with multi-file auth state
  - `sendMessage`, `sendPresence`, `onWhatsApp` methods
  - Baileys errors ko `WhatsAppError` subclasses mein map karo
  - _Requirements: 3.1, 3.3_

- [ ] 1.2 Capability detection implement karo
  - Boot pe check karo ki adapter ke expected methods (groups, newsletters) library build mein exist karte hain
  - `capabilities` set expose karo; missing method call pe `UnsupportedOperationError`
  - Startup pe capability matrix log karo
  - Unit test: missing method ka call clean error deta hai, crash nahi
  - _Requirements: 21.6, 21.7_

- [ ] 1.3 QR aur pairing-code login flows
  - QR generation: terminal ASCII + base64 PNG dono
  - QR refresh on expiry, max 5 attempts phir `QR_TIMEOUT`
  - Pairing-code request with phone number → 8-char code
  - _Requirements: 1.2, 1.3, 1.4_

- [ ] 1.4 Connection state machine aur credential persistence
  - States: `INITIALIZING`, `QR_PENDING`, `QR_TIMEOUT`, `CONNECTING`, `CONNECTED`, `RECONNECTING`, `THROTTLED`, `CLOSING`, `CLOSED`, `LOGGED_OUT`, `REPLACED`, `FAILED`
  - Valid transitions enforce karo, invalid transition pe throw
  - `creds.update` pe auth state disk pe persist
  - Successful auth pe phone, pushName, deviceId capture
  - Unit test: state machine legal/illegal transitions
  - _Requirements: 1.5, 1.8_

- [ ] 1.5 Event normalizer banao
  - Baileys events (`connection.update`, `messages.upsert`, `messages.update`, `group-participants.update`, `groups.update`) → internal stable-shaped events, session-tagged
  - Internal `EventBus` (typed emitter)
  - Har handler apne try/catch mein — ek handler ka throw session na girae
  - _Requirements: 32.3_

---

## Phase 2 — Database Layer

- [ ] 2.1 Prisma schema author karo saare models ke saath
  - Models: `Session`, `Contact`, `OptOut`, `ContactList`, `ContactListItem`, `Group`, `GroupMember`, `JoinRequest`, `WelcomeConfig`, `Channel`, `ChannelPost`, `ChannelAnalyticsSnapshot`, `Template`, `Campaign`, `Message`, `MessageStatusEvent`, `Schedule`, `ErrorLog`, `AuditLog`, `User`, `ApiKey`, `Setting`
  - Enums: `SessionStatus`, `MessageStatus`, `WaStatus`
  - Indexes design.md ke hisaab se; `Message.idempotencyKey` unique
  - _Requirements: 6.7_

- [ ] 2.2 Migration workflow aur seed script
  - Initial migration generate karo, SQLite (dev) aur Postgres (prod) dono pe verify karo
  - Seed: default owner user, default settings rows, sample template
  - _Requirements: 33.4_

- [ ] 2.3 Repository layer implement karo
  - Har model ke liye repository module; services Prisma client directly touch NOT karte
  - Common helpers: pagination, soft-delete filter, transaction wrapper
  - Prisma client singleton with connection lifecycle tied to graceful shutdown
  - _Requirements: 34.1_

- [ ] 2.4 Runtime settings service
  - `Setting` table se read, in-memory cache with invalidation on write
  - Runtime-changeable values (delays, rate limits, quiet hours) restart ke bina apply hon
  - API-facing read secret values redact kare
  - Unit test: setting update cache invalidate karta hai, secret redacted
  - _Requirements: 33.3, 33.5_

---

## Phase 3 — Multi-Session Manager

- [ ] 3.1 SessionManager core implement karo
  - `create`, `start`, `stop`, `delete`, `get`, `list` — in-memory registry + DB persistence
  - Per-session isolated auth directory (`sessions/<sessionId>/`), `chmod 700`
  - Session-scoped child logger har instance ke liye
  - _Requirements: 1.1, 1.5, 1.8, 1.10_

- [ ] 3.2 Session isolation aur limits
  - Har session ka lifecycle apne error boundary mein — ek crash doosre ko affect na kare
  - Configured max concurrent session limit; exceed pe clear refusal error
  - Memory guard: per-session store pruning
  - Integration test: mock adapter se ek session ko crash karo, doosra connected rahe
  - _Requirements: 1.7, 1.9_

- [ ] 3.3 Boot-time session restore
  - Startup pe saare previously `CONNECTED` sessions DB se reload aur reconnect
  - Staggered start (delay between sessions) taaki simultaneous connect burst na ho
  - Integration test: restart simulate karo, sessions restore hon
  - _Requirements: 1.6_

- [ ] 3.4 QuotaTracker aur session stats
  - Per-session daily counters (Redis, midnight-reset by session timezone)
  - Stats: `reconnects`, `sentToday`, `lastEventAt`
  - _Requirements: 7.4, 31.4_

---

## Phase 4 — Auto Reconnect and Health

- [ ] 4.1 ReconnectPolicy implement karo
  - Retryable vs terminal disconnect reason sets
  - Exponential backoff: 1s base, ×2, 5 min cap, ±50% jitter
  - Success pe attempt counter reset, reconnect metric increment
  - Unit test: backoff sequence bounds ke andar, jitter applied
  - _Requirements: 2.1, 2.3, 2.5_

- [ ] 4.2 Terminal reason handling
  - `loggedOut` → `LOGGED_OUT`, no retry, operator alert
  - `connectionReplaced` → `REPLACED`, no retry
  - `badSession` / `multideviceMismatch` → `FAILED`, no retry
  - Unit test: terminal reasons pe retry loop nahi banta
  - _Requirements: 2.2, 2.4_

- [ ] 4.3 Heartbeat watchdog
  - `lastEventAt` monitor; configured timeout cross pe socket stale maan ke forced reconnect
  - Periodic presence ping
  - _Requirements: 2.6_

- [ ] 4.4 Health endpoint aur disconnected-message hold
  - `/health` — per-session status, uptime, last-seen, reconnect count
  - Session disconnected hone pe uske queue jobs hold/delay hon, fail NOT hon
  - Integration test: session down karo, jobs queue mein rukein, reconnect pe process hon
  - _Requirements: 2.7, 2.8_

---

## Phase 5 — Message Sending Core (Single Mode)

- [ ] 5.1 JID normalization utility
  - Common number formats (`+91 98765-43210`, `919876543210`, `09876543210`) → valid JID
  - Group aur newsletter JID detection
  - Unit test: comprehensive format table
  - _Requirements: 3.2_

- [ ] 5.2 Number validation with caching
  - `onWhatsApp()` wrapper, batched, result cache Redis mein TTL ke saath
  - Unregistered → `NotOnWhatsAppError` (non-retryable)
  - _Requirements: 3.3, 18.1, 18.5_

- [ ] 5.3 MessagingService text send implement karo
  - `sendText(sessionId, jid, text, opts)` → Message row create + adapter call
  - Local id ↔ `waMessageId` mapping persist
  - Content hash store karo (body raw form mein log NOT ho)
  - _Requirements: 3.4_

- [ ] 5.4 Single-mode sequential sender
  - Strictly one-at-a-time; next se pehle server ack wait
  - `awaitAck(messageId, timeout)`; timeout pe `PENDING_TIMEOUT` mark aur sequence continue
  - Integration test (mock adapter): ack timeout sequence ko block na kare
  - _Requirements: 3.1, 3.5, 3.6_

---

## Phase 6 — Delivery Status Tracking

- [ ] 6.1 Status mapping aur transition handling
  - Baileys ack levels → `PENDING` → `SENT` → `DELIVERED` → `READ` → `PLAYED`; failures → `FAILED`
  - Out-of-order ack safe: kabhi status downgrade na ho
  - Unit test: transition table including out-of-order events
  - _Requirements: 5.1–5.6_

- [ ] 6.2 Append-only status timeline
  - Har transition pe `MessageStatusEvent` row with timestamp
  - `Message` pe denormalized `sentAt`, `deliveredAt`, `readAt`
  - _Requirements: 5.7_

- [ ] 6.3 Campaign aggregate counters
  - Redis hash counters, periodic DB flush
  - Read-receipt-disabled recipients ke liye `DELIVERED` terminal success treat karo
  - Unit test: counters status transitions se match karein
  - _Requirements: 5.8, 5.9_

- [ ] 6.4 WebSocket broadcaster
  - WS server, topic subscription (`message.status`, `campaign.progress`, `error.new`, `session.status`, `queue.stats`)
  - Status change pe live event push
  - _Requirements: 5.8, 25.1_

---

## Phase 7 — Message Queue and Rate Limiting

- [ ] 7.1 BullMQ setup with send queue
  - Redis connection, `send-queue` producer + worker
  - Per-session concurrency = 1 enforced via session-scoped worker/lock
  - Job payload schema validated
  - _Requirements: 6.1, 6.2_

- [ ] 7.2 Token-bucket RateLimiter
  - Per-session per-minute / per-hour / per-day buckets in Redis (atomic Lua script)
  - Limit reached → job delayed until window reset, dropped NOT
  - Unit test: 500 jobs configured rate cross NOT karein
  - _Requirements: 6.3_

- [ ] 7.3 Priority lanes aur idempotency
  - Priorities: `transactional` > `welcome` > `campaign`
  - `idempotencyKey` dedup — duplicate enqueue second send trigger NOT kare
  - Unit test: duplicate key pe ek hi send
  - _Requirements: 6.4, 6.7_

- [ ] 7.4 Queue controls, persistence aur backpressure
  - `pause`, `resume`, `drain`, `purge`; pause pe enqueue accept but dispatch stop
  - Restart pe pending jobs survive
  - Queue depth threshold pe backpressure signal + new-enqueue throttle
  - `stats()`: waiting, active, completed, failed, delayed
  - Integration test: restart ke baad jobs process hon
  - _Requirements: 6.5, 6.6, 6.8, 6.9_

---

## Phase 8 — Dual Mode and Bulk Campaigns

- [ ] 8.1 Campaign model aur recipient resolution
  - `CampaignService.create` — recipients from manual list, CSV upload, saved contact list, ya group extraction
  - Resolution pipeline: normalize → opt-out filter (mandatory) → blocklist filter → de-duplicate
  - Unit test: duplicates removed, opted-out excluded
  - _Requirements: 4.2, 4.4, 29.2_

- [ ] 8.2 Dual-mode parallel dispatch
  - Configured concurrent worker count; single mode ke saath mode switch
  - Per-recipient job enqueue with idempotency key
  - _Requirements: 4.1, 4.3_

- [ ] 8.3 Campaign state machine aur lifecycle controls
  - `DRAFT` → `QUEUEING` → `RUNNING` → `COMPLETED` / `PAUSED` / `CANCELLED` / `FAILED`
  - `pause`: in-flight complete, new dispatch stop
  - `resume`: sirf `PENDING`/`FAILED` recipients re-enqueue, `SENT+` skip
  - `cancel`: pending jobs remove, partial stats preserve
  - Integration test: pause→resume mein zero double-sends
  - _Requirements: 4.6, 4.7, 4.8_

- [ ] 8.4 Progress reporting aur completion
  - `progress()`: total, sent, delivered, read, failed, remaining
  - Saare jobs settle hone pe `COMPLETED` + final summary report
  - _Requirements: 4.5, 4.9_

---

## Phase 9 — Anti-Ban Engine

- [ ] 9.1 Gaussian delay randomizer
  - Truncated-gaussian delay generator min–max range mein
  - Unit test: distribution bounds ke andar, uniform se distinguishable (variance check)
  - _Requirements: 7.1, 7.2_

- [ ] 9.2 Typing simulator
  - `composing` presence → length-proportional typing duration (clamped) → `paused` → send
  - Unit test: typing duration text length se scale kare, clamp bounds respect ho
  - _Requirements: 7.3_

- [ ] 9.3 Warm-up ramp aur quota guard
  - Session age → daily cap + new-contact cap (ramp table)
  - Quota verdict: `allow` | `defer` | `block`; caps hard-enforced (runtime override nahi)
  - Unit test: har ramp stage ka correct cap
  - _Requirements: 7.4, 7.9_

- [ ] 9.4 Spintax engine
  - `{a|b|c}` parse, nested support, per-recipient deterministic seed
  - Unit test: same recipient → same variant, different recipients → distribution spread
  - _Requirements: 7.5_

- [ ] 9.5 Batch cool-down aur quiet hours
  - N messages ke baad configured cool-down pause
  - Quiet-hours window (timezone-aware) mein campaign sends defer
  - Unit test: quiet hours boundary + DST-safe evaluation
  - _Requirements: 7.6, 7.7_

- [ ] 9.6 Risk scoring aur auto-throttle
  - Rolling failure-rate window per session
  - Threshold breach → rate limit reduce + alert; severe breach → `THROTTLED` (sirf transactional)
  - Anti-ban gate ko send pipeline mein wire karo (rate limiter ke baad, adapter se pehle)
  - Integration test: injected failure burst throttle trigger kare
  - _Requirements: 7.8_

---

## Phase 10 — Bulk Media Messaging

- [ ] 10.1 MediaService with validation
  - Types: image, video, audio, voice note (PTT), document, sticker
  - MIME content-sniffing (extension pe trust nahi), size cap, extension allowlist
  - Oversize → clean validation error
  - Unit test: mismatched extension detect ho, oversize reject ho
  - _Requirements: 8.1, 8.2, 8.3, 34.5_

- [ ] 10.2 Remote media fetch
  - URL download with timeout, max-size cap, redirect limit
  - _Requirements: 8.4_

- [ ] 10.3 Image compression aur video thumbnail
  - Sharp: threshold cross karne pe resize/compress
  - ffmpeg: video thumbnail generate + attach
  - _Requirements: 8.5, 8.6_

- [ ] 10.4 Media reference reuse aur caption interpolation
  - Ek hi media multiple recipients ko → uploaded reference reuse, per-recipient re-upload nahi
  - Caption pe template variable interpolation
  - Upload failure → `MEDIA_UPLOAD_FAILED` + retry queue
  - Integration test: N recipients pe ek hi upload call
  - _Requirements: 8.7, 8.8, 8.9_

---

## Phase 11 — Templates and Personalization

- [ ] 11.1 Template CRUD with versioning
  - Create/read/update/delete; edit pe naya version, purana history preserve
  - `isCurrent` flag current version ke liye
  - _Requirements: 9.1, 9.2_

- [ ] 11.2 Variable interpolation engine
  - `{{name}}`, `{{group}}`, `{{date}}`, `{{custom.*}}` resolution
  - Fallback values; strict mode mein missing variable → `MISSING_VARIABLE` block
  - Unit test: fallback path, strict path, nested custom fields
  - _Requirements: 9.3, 9.4, 9.5_

- [ ] 11.3 Preview aur test-send
  - `preview(templateId, sampleData)` → rendered output, koi send nahi
  - `testSend` sirf connected session ke apne number pe
  - _Requirements: 9.6, 9.7_

---

## Phase 12 — Scheduler

- [ ] 12.1 Schedule model aur cron/one-time execution
  - `ONCE` (datetime + IANA timezone) aur `CRON` kinds
  - Timezone-aware next-run computation (luxon + cron-parser)
  - Unit test: DST transition ke aar-paar correct local time
  - _Requirements: 10.1, 10.2, 10.6_

- [ ] 12.2 Next-runs preview aur enable/disable
  - `nextRuns(id, 5)` preview
  - Disable pe future runs stop, history preserve
  - _Requirements: 10.3, 10.7_

- [ ] 12.3 Boot reload aur missed-run recovery
  - Startup pe active schedules reload
  - Recovery policies: `SKIP`, `RUN_ONCE`, `CATCH_UP`
  - Integration test: har policy ka correct behaviour after simulated downtime
  - _Requirements: 10.4, 10.5_

- [ ] 12.4 Leader lock aur run-now
  - Redis leader lock (TTL-renewed) — multi-instance mein duplicate dispatch nahi
  - `runNow` regular `nextRunAt` disturb na kare
  - _Requirements: 10.8, 31.6_

---

## Phase 13 — Group Core

- [ ] 13.1 Group create aur metadata caching
  - Create with subject + initial participants → JID + metadata
  - Group + members local DB mein cache
  - `metadata(jid, { fresh })` — cache ya live fetch
  - _Requirements: 11.1, 11.2, 11.7_

- [ ] 13.2 Invite link management
  - Get invite code, revoke (naya generate + purana invalidate), join by link
  - _Requirements: 11.3, 11.4, 11.5_

- [ ] 13.3 Leave group aur group listing
  - Leave → local record `LEFT`
  - Paginated list with search + filter
  - _Requirements: 11.6, 11.8_

---

## Phase 14 — Group Admin and Members

- [ ] 14.1 Admin guard implement karo
  - Admin-requiring operation se pehle cached metadata se bot admin check
  - Nahi → `NotGroupAdminError` bina socket call
  - Unit test: non-admin case pe adapter call nahi hoti
  - _Requirements: 12.5_

- [ ] 14.2 Participant add/remove/promote/demote with result mapping
  - WA per-participant status codes → `ADDED` / `INVITE_SENT` / `RECENTLY_LEFT` / `ALREADY_MEMBER` / `FAILED`
  - Local member cache update
  - Unit test: status code mapping table
  - _Requirements: 12.1, 12.2, 12.3, 12.4_

- [ ] 14.3 Chunked bulk operations with CSV report
  - Configured chunk size, inter-chunk delay
  - CSV bulk import → per-row result report with failure reasons
  - _Requirements: 12.6, 12.7_

- [ ] 14.4 Member list reconciliation
  - `syncMembers` — local cache vs actual participants, added/removed/roleChanged report
  - _Requirements: 12.8_

---

## Phase 15 — Group Settings and Join Requests

- [ ] 15.1 Group settings control
  - `announcement`, `locked`, `ephemeral` duration, `approvalMode` toggles
  - Subject / description / icon update with cache refresh
  - _Requirements: 13.1–13.5_

- [ ] 15.2 Audit logging for group changes
  - Har settings change pe `AuditLog` row: actor, action, before/after
  - _Requirements: 13.6_

- [ ] 15.3 Join request inbox
  - Pending request list store + notify on new request
  - Single aur bulk approve/reject with per-request result
  - _Requirements: 14.1, 14.2, 14.3, 14.4_

- [ ] 15.4 Auto-approve rule engine
  - Ordered rules: blocklist, country-code allow/reject, regex; first match wins
  - No match → manual pending
  - Decision + matched rule audit-logged
  - Unit test: rule precedence, no-match fallthrough
  - _Requirements: 14.5, 14.6, 14.7, 14.8_

---

## Phase 16 — Welcome Message Engine

- [ ] 16.1 Participant event handler
  - `group-participants.update` consume: `add` / `remove` / `promote` / `demote`
  - Per-group welcome + goodbye enable flags respect
  - _Requirements: 15.1, 15.5, 15.8_

- [ ] 16.2 Welcome rendering with mentions
  - Variables: `{{member}}`, `{{group}}`, `{{memberCount}}`, `{{rules}}`
  - Group message mein naye member ka proper mention
  - `DM` target mode support
  - Integration test: mention array correct, DM mode group mein post na kare
  - _Requirements: 15.2, 15.3, 15.4_

- [ ] 16.3 Duplicate guard aur multi-add batching
  - Redis cooldown key per (group, member) — rejoin spam pe skip
  - `COMBINED` vs `PER_MEMBER` batching for multi-member events
  - Welcome messages `welcome` priority lane se
  - Unit test: cooldown window mein second welcome suppress ho
  - _Requirements: 15.6, 15.7_

---

## Phase 17 — Welcome Templates and Media

- [ ] 17.1 Template rotation
  - `SEQUENTIAL` (cursor-based), `RANDOM`, `WEIGHTED` strategies
  - Unit test: sequential cursor wrap, weighted distribution
  - _Requirements: 16.1_

- [ ] 17.2 Media welcome aur dynamic card
  - Image / video / GIF welcome with caption
  - Canvas-based dynamic card: member name + avatar + count
  - Generation failure pe text-only fallback (welcome miss na ho), error logged
  - _Requirements: 16.2, 16.3, 16.4_

- [ ] 17.3 A/B variant tracking
  - Variants evenly distribute, per-variant delivery stats
  - _Requirements: 16.5_

---

## Phase 18 — Group Number Extraction

- [ ] 18.1 Streaming extraction
  - Async-iterable output: number, pushName, admin role, join metadata
  - Self-number always excluded
  - Multi-group extract with cross-group de-duplication
  - `NOT_GROUP_MEMBER` guard
  - Unit test: dedup across groups, self excluded
  - _Requirements: 17.1, 17.2, 17.3, 17.7, 17.8_

- [ ] 18.2 Extraction filters
  - Country code, admins-only, exclude-existing-contacts
  - Composable filter chain
  - _Requirements: 17.4, 17.5, 17.6_

- [ ] 18.3 Active-number classification
  - Batched `onWhatsApp()` with inter-batch delay
  - `ACTIVE` / `INACTIVE` + `isBusiness`, `hasProfilePhoto` flags
  - Result cache with configured TTL
  - Rate-limit error → pause, backoff, resume
  - Integration test: rate-limit injection pe resume complete ho
  - _Requirements: 18.1, 18.2, 18.3, 18.4, 18.5, 18.6_

---

## Phase 19 — Export Engine

- [ ] 19.1 Pluggable streaming exporters
  - CSV (UTF-8 BOM, configurable delimiter), TXT, JSON, XLSX, vCard
  - Column selection + ordering
  - `AsyncIterable` input → `Readable` output, backpressure respected
  - Unit test: 10k-row stream memory stable; CSV BOM present; vCard valid structure
  - _Requirements: 19.1, 19.2, 19.3, 19.4, 19.7_

- [ ] 19.2 Signed download endpoint
  - Server-generated filenames (user input se path NOT banaye)
  - HMAC-signed URL with expiry; expired → 403
  - Unit test: path traversal attempt reject ho
  - _Requirements: 19.5, 34.5_

- [ ] 19.3 Error report exporter
  - Columns: code, category, message, session, recipient, timestamp, retryCount
  - _Requirements: 19.6_

---

## Phase 20 — Auto Tagging

- [ ] 20.1 Tag-all with hidden mentions
  - Mentions via `contextInfo.mentionedJid`; hidden mode mein body mein numbers nahi
  - Visible mode mein `@number` list body mein append
  - Unit test: hidden mode body mein koi number nahi, mention array complete
  - _Requirements: 20.1, 20.2_

- [ ] 20.2 Selective tagging selectors
  - `ADMINS`, `NON_ADMINS`, `CUSTOM` jid list, `REGEX` pattern
  - _Requirements: 20.3_

- [ ] 20.3 Tag with custom message/media
  - Mentions attach to text ya media caption
  - _Requirements: 20.4_

- [ ] 20.4 Chunking, cooldown aur permission guard
  - Participant count > mention chunk size → multiple messages with inter-chunk delay
  - Per-group cooldown (Redis TTL) → reject with remaining seconds
  - Admin-only enforcement when configured
  - Integration test: 300-member group correct chunk count, cooldown blocks second call
  - _Requirements: 20.5, 20.6, 20.7_

---

## Phase 21 — Channel Management

- [ ] 21.1 Channel CRUD (capability-gated)
  - Create with name/description → identifier + invite link
  - Update name/description/picture; delete → local `DELETED`
  - Har method pehle `capabilities` check kare
  - _Requirements: 21.1, 21.2, 21.3, 21.6_

- [ ] 21.2 Follow / unfollow / mute / unmute
  - _Requirements: 21.4_

- [ ] 21.3 Subscriber aur admin info
  - Jo underlying API expose karta hai wahi return, unsupported pe clean error
  - _Requirements: 21.5, 21.6_

- [ ] 21.4 Feature flag aur capability logging
  - `FEATURE_CHANNELS` flag, boot pe capability matrix log
  - Integration test: capability missing pe `UNSUPPORTED_OPERATION`, crash nahi
  - _Requirements: 21.7_

---

## Phase 22 — Channel Auto Post and Analytics

- [ ] 22.1 Channel post composer
  - Text, media, poll, link-preview content types
  - `ChannelPost` rows with `DRAFT` / `SCHEDULED` / `PUBLISHED` / `FAILED`
  - _Requirements: 22.1_

- [ ] 22.2 Scheduled aur recurring channel posts
  - SchedulerService se wire (`targetType: CHANNEL_POST`)
  - _Requirements: 22.2, 22.3_

- [ ] 22.3 Content calendar view data
  - Upcoming + published posts date-wise grouped
  - _Requirements: 22.4_

- [ ] 22.4 Analytics snapshots aur delta computation
  - Periodic snapshot job: views, reaction breakdown, follower count
  - Delta = consecutive snapshots ka difference (cumulative ko delta na samjho)
  - Engagement ranking + export
  - Unit test: delta computation from snapshot series
  - _Requirements: 22.5, 22.6, 22.7_

---

## Phase 23 — Error Reporting and Retry

- [ ] 23.1 ErrorReporter with classification
  - `capture(err, ctx)` → normalized `ErrorLog` row
  - `classify()` → code, category, severity, retryable
  - Categories: `AUTH`, `RATE_LIMIT`, `NOT_ON_WHATSAPP`, `MEDIA`, `NETWORK`, `PERMISSION`, `VALIDATION`, `UNKNOWN`
  - Phone numbers redacted in stored context
  - Unit test: Baileys error samples correct category pe map hon
  - _Requirements: 23.1, 23.2, 23.3_

- [ ] 23.2 Retry policy matrix
  - Per-category max attempts + backoff (design.md matrix)
  - Non-retryable categories pe zero retries
  - Rate-limit pe fixed cool-down before retry
  - Retry success pe original Message row update, duplicate row NOT
  - Unit test: har category ka attempt count aur backoff
  - _Requirements: 24.1, 24.2, 24.6, 24.7_

- [ ] 23.3 Dead-letter queue aur manual retry
  - Max attempts exhaust → DLQ with last error + attempt count
  - DLQ listing; bulk manual retry fresh attempt counter ke saath re-enqueue kare
  - Integration test: forced failure → retries → DLQ → manual retry → success
  - _Requirements: 24.3, 24.4, 24.5_

- [ ] 23.4 Error query API aur retention
  - Filters: session, date range, category, severity
  - Error-rate trend aggregation; top failing recipients
  - Retention purge job for old error logs
  - _Requirements: 23.4, 23.5, 23.6, 23.7_

- [ ] 23.5 Worker aur process error boundaries
  - Job wrapper: har job try/catch → classify → retry/DLQ; worker kabhi crash na kare
  - Process-level handlers → CRITICAL capture + graceful shutdown
  - _Requirements: 23.1_

---

## Phase 24 — Real-time Notifications and Observability

- [ ] 24.1 Live error feed
  - Naya `ErrorLog` → EventBus → WS broadcast
  - _Requirements: 25.1_

- [ ] 24.2 AlertRuleEngine
  - Rules: severity threshold, error-rate spike, session disconnect, queue backlog
  - Session disconnect alert configured seconds ke andar
  - Unit test: har rule trigger condition
  - _Requirements: 25.2, 25.3, 25.4, 25.5_

- [ ] 24.3 Notifier chain with dedup
  - Channels: WhatsApp admin DM, Telegram, email, generic webhook
  - Fingerprint-based dedup + rate limit (Redis TTL) — alert storm suppress
  - Delivery failure → log + next channel try
  - Unit test: repeated same error ek hi alert bheje
  - _Requirements: 25.6, 25.7_

- [ ] 24.4 Metrics aur correlation ids
  - Prometheus `/metrics`: messages sent, delivery rate, queue depth, error rate, session up
  - Correlation id request/campaign ke through propagate, logs mein present
  - `/status`: uptime, active sessions, queue health, DB connectivity
  - _Requirements: 32.1, 32.3, 32.5_

---

## Phase 25 — REST API and Auth

- [ ] 25.1 Express app with versioned routes aur validation
  - `/api/v1` router mount, zod request validators
  - Uniform error middleware → `{ error: { code, category, message, details, correlationId } }`
  - _Requirements: 26.1, 26.2_

- [ ] 25.2 Authentication
  - Login → short-lived access token + rotating refresh token; argon2id password hash
  - API keys: SHA-256 stored, plaintext only at creation
  - Missing/invalid credential → 401
  - _Requirements: 26.3, 26.4_

- [ ] 25.3 RBAC authorization
  - Roles `owner` / `admin` / `operator` / `viewer`; permission matrix per route
  - Insufficient permission → 403
  - Unit test: har role ka allowed/denied route set
  - _Requirements: 26.5, 26.6_

- [ ] 25.4 Rate limiting aur audit middleware
  - Per-API-key limiter → 429 with retry-after
  - State-changing operations pe `AuditLog` (actor, action, target, timestamp)
  - _Requirements: 26.7, 26.8_

- [ ] 25.5 Saare resource routes implement karo
  - Sessions, messages, campaigns, templates, schedules, groups, tagging, extract, exports, channels, contacts, opt-outs, errors, queue, settings, health, metrics (design.md ki API table)
  - Contract tests supertest se
  - _Requirements: 26.1, 26.2_

- [ ] 25.6 OpenAPI spec aur Swagger UI
  - zod schemas se OpenAPI 3 generate, browsable UI serve
  - _Requirements: 26.9_

---

## Phase 26 — Web Dashboard

- [ ] 26.1 Frontend scaffold
  - React + Vite + Tailwind + shadcn/ui, router, API client with token refresh
  - Login screen → overview redirect
  - _Requirements: 27.1_

- [ ] 26.2 WebSocket client layer
  - Single connection, topic subscribe, exponential-backoff reconnect
  - Reconnect pe REST se state refetch (WS delta, REST truth)
  - _Requirements: 27.4, 27.5_

- [ ] 26.3 Sessions aur overview screens
  - Session cards with status badge, QR modal, start/stop/logout, health stats
  - Overview: active sessions, today's counters, error rate, queue depth
  - _Requirements: 27.2_

- [ ] 26.4 Campaign builder wizard aur detail view
  - 5 steps: recipients → template → mode & session pool → delays & schedule → review
  - Bulk step pe non-dismissible compliance disclaimer + first-time acknowledgement
  - Detail: live progress bar, tick funnel (✓ / ✓✓ / 🔵✓✓ / ✗), per-recipient table, pause/resume/cancel
  - _Requirements: 27.3, 27.4, 27.9_

- [ ] 26.5 Groups, channels, contacts, templates, schedules screens
  - Group list + member table + settings panel + join-request inbox + welcome config + tag-all action
  - Channel list + post composer + calendar + analytics
  - Contacts table + import wizard + export
  - Template CRUD with live preview aur version history
  - Schedules list with next-run preview
  - _Requirements: 27.2, 27.3_

- [ ] 26.6 Queue monitor aur error center
  - Queue counts, pause/resume, throughput chart
  - Error table with filters, trend chart, top failing recipients, DLQ bulk retry, export
  - _Requirements: 27.5, 27.6_

- [ ] 26.7 Analytics charts, responsive layout aur i18n
  - Delivery funnel, hourly send volume, error trend
  - Mobile-responsive layout, dark mode
  - `react-i18next` with `en` + `hi` bundles
  - _Requirements: 27.6, 27.7, 27.8_

- [ ] 26.8 Settings screen
  - Delays, rate limits, quiet hours, warm-up, alert channels, opt-out keywords, users & API keys
  - Secret values masked
  - _Requirements: 33.3, 33.5_

---

## Phase 27 — Contact Management and Compliance

- [ ] 27.1 Contact CRUD
  - Name, number, custom fields, tags, notes
  - _Requirements: 28.1_

- [ ] 27.2 Import pipeline with column mapping
  - CSV / XLSX parse → detected columns → mapping → validate + normalize + dedupe → commit
  - `ImportReport`: total, imported, updated, skipped, invalid rows with reasons
  - Unit test: invalid rows flagged, duplicates handled
  - _Requirements: 28.2, 28.3_

- [ ] 27.3 vCard import/export
  - vCard 3.0 aur 4.0 parse; valid vCard export
  - _Requirements: 28.4, 28.5_

- [ ] 27.4 WhatsApp phonebook sync
  - Session contacts pull → local reconcile with sync report
  - _Requirements: 28.6_

- [ ] 27.5 Dynamic segments
  - Rule tree JSON → query-time Prisma `where` compile (materialization nahi)
  - Unit test: contact change pe membership turant reflect ho
  - _Requirements: 28.7_

- [ ] 27.6 Blocklist aur opt-out handling
  - Blocklist add → har future campaign se exclude
  - Inbound keyword matcher (`STOP`, `UNSUBSCRIBE`, etc.) → `OptOut` + confirmation reply
  - Opt-out filter service layer mein hard-enforced (bypass option nahi); opted-out pe send block + `OPTED_OUT` record
  - Unit test: opted-out number kisi bhi resolution path se na nikle
  - _Requirements: 28.8, 29.1, 29.2, 29.3, 29.4_

- [ ] 27.7 Data retention aur deletion
  - Data-deletion: personal data purge, aggregate counters preserve
  - Retention job: configured age se purane message bodies aur logs delete
  - _Requirements: 29.5, 29.6_

---

## Phase 28 — Deployment and Process Management

- [ ] 28.1 PM2 ecosystem config
  - Process definitions per `APP_MODE`, memory-restart threshold, auto-restart on crash
  - `pm2 startup` boot persistence, log rotation
  - Zero-downtime reload verified with graceful shutdown
  - _Requirements: 30.1, 30.2, 30.3, 30.4, 30.5_

- [ ] 28.2 Docker aur compose stack
  - Multi-stage Dockerfile (build → slim runtime, non-root user)
  - `docker-compose.yml`: app + Redis + Postgres + nginx reverse proxy
  - Healthchecks aur volume mounts for `sessions/`, `exports/`
  - _Requirements: 30.6_

- [ ] 28.3 CI pipeline
  - GitHub Actions: install → lint → test (coverage gate) → build → `npm audit`
  - _Requirements: 30.7, 34.3_

- [ ] 28.4 Backup/restore aur secret handling
  - Session auth state + DB backup script, restore verification script
  - Secrets env-only, boot validation, never logged or committed
  - _Requirements: 30.8, 30.9_

---

## Phase 29 — Multi-Device Orchestration

- [ ] 29.1 Session pool selector
  - Strategies: round-robin, least-used, weighted, health-aware
  - Unit test: har strategy ki distribution
  - _Requirements: 31.1_

- [ ] 29.2 Sticky routing
  - Recipient → session mapping persisted; same recipient hamesha same session
  - _Requirements: 31.2_

- [ ] 29.3 Failover aur quota exclusion
  - Unhealthy/logged-out session ke pending jobs healthy sessions pe reassign
  - Daily quota exhausted session selection se excluded until reset
  - Warm-up stage session ko reduced traffic share
  - Integration test: mid-campaign session kill karo, baaki complete karein without duplicates
  - _Requirements: 31.3, 31.4, 31.5_

- [ ] 29.4 Multi-node coordination
  - Shared Redis queue, worker-mode nodes; scheduler leader lock
  - Duplicate processing prevention verify karo
  - _Requirements: 31.6_

---

## Phase 30 — Hardening, Testing and Release

- [ ] 30.1 Test coverage completion
  - Core modules (`services/`, `queue/`, `utils/`) pe 70% line coverage gate meet karo
  - MockBaileysAdapter ke saath integration suite ka gap fill
  - _Requirements: 34.1, 34.2, 34.3_

- [ ] 30.2 Load aur soak test
  - 10k queued messages soak run
  - Assert: rate limits respected, heap stable start-vs-end, zero duplicate sends, queue drains clean
  - _Requirements: 34.4_

- [ ] 30.3 Security pass
  - Path traversal, injection, upload validation, secret scanning, dependency audit
  - Full checklist verify (design.md security table)
  - _Requirements: 34.5, 30.9_

- [ ] 30.4 Documentation
  - README, setup guide, API reference (OpenAPI link), troubleshooting, FAQ
  - Prominent compliance disclaimer + responsible-use guidance
  - _Requirements: 34.6_

- [ ] 30.5 Release v1.0.0
  - CHANGELOG, version tag, release notes
  - Final verification: saare 34 requirements ke acceptance criteria traced aur satisfied
  - _Requirements: 34.6_

---

## Coverage Check

| Requirement | Covered by tasks |
|---|---|
| 1 Multi-Session | 3.1–3.4, 1.3, 1.4 |
| 2 Auto Reconnect | 4.1–4.4 |
| 3 Single Mode | 5.1–5.4 |
| 4 Dual/Bulk | 8.1–8.4 |
| 5 Check Marks | 6.1–6.4 |
| 6 Queue & Rate Limit | 7.1–7.4 |
| 7 Anti-Ban | 9.1–9.6 |
| 8 Media | 10.1–10.4 |
| 9 Templates | 11.1–11.3 |
| 10 Scheduling | 12.1–12.4 |
| 11 Group Core | 13.1–13.3 |
| 12 Admin/Members | 14.1–14.4 |
| 13 Group Settings | 15.1, 15.2 |
| 14 Join Requests | 15.3, 15.4 |
| 15 Welcome | 16.1–16.3 |
| 16 Welcome Templates | 17.1–17.3 |
| 17 Extraction | 18.1, 18.2 |
| 18 Active Filter | 18.3, 5.2 |
| 19 Export | 19.1–19.3 |
| 20 Tagging | 20.1–20.4 |
| 21 Channels | 21.1–21.4, 1.2 |
| 22 Channel Posts/Analytics | 22.1–22.4 |
| 23 Error Reporting | 23.1, 23.4, 23.5, 0.3, 0.4 |
| 24 Retry | 23.2, 23.3 |
| 25 Alerts | 24.1–24.3 |
| 26 REST API | 25.1–25.6 |
| 27 Dashboard | 26.1–26.8 |
| 28 Contacts | 27.1–27.5 |
| 29 Compliance | 27.6, 27.7, 8.1 |
| 30 Deployment | 28.1–28.4, 0.5 |
| 31 Multi-Device | 29.1–29.4 |
| 32 Observability | 24.4, 0.3, 1.5 |
| 33 Configuration | 0.2, 2.4, 26.8 |
| 34 Quality | 30.1–30.5, 0.1 |
