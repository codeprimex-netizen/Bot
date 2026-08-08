# Implementation Plan — WhatsApp Auto Messenger Bot (PHP + MySQL)

**Stack:** PHP 8.3 · Laravel 11 · MySQL 8 · Livewire 3 · Tailwind · Supervisor · nginx + PHP-FPM
**Target:** `https://bot.getxtrra.in`
**Non-PHP component:** one thin Node bridge sidecar (Phase 1) — protocol transport only, no business logic

Tasks 31 phases mein organized hain. Har task incremental hai aur pichhle tasks ke output pe build karta hai. `_Requirements:` references `requirements.md` ke numbered acceptance criteria point karte hain.

**Execution rule:** ek phase ke saare sub-tasks complete + verified hone ke baad hi next phase. Har phase ke end pe commit → push → PR → merge.

---

## Phase 0 — Laravel Bootstrap

- [ ] 0.1 Laravel 11 project initialize karo
  - `composer create-project laravel/laravel`, PHP 8.3 constraint
  - Packages: `laravel/sanctum`, `livewire/livewire`, `intervention/image`, `maatwebsite/excel`, `dragonmantank/cron-expression`, `guzzlehttp/guzzle`
  - Dev: `pestphp/pest`, `laravel/pint`, `phpstan/phpstan` + `larastan`
  - `composer.json` scripts: `test`, `lint`, `analyse`
  - `.gitignore`: `.env`, `storage/wa-sessions/`, `storage/app/exports/`, `vendor/`, `node_modules/`
  - _Requirements: 36.1, 36.3_

- [ ] 0.2 MySQL-only driver configuration
  - `.env.example`: `DB_CONNECTION=mysql`, `QUEUE_CONNECTION=database`, `CACHE_STORE=database`, `SESSION_DRIVER=database`, `APP_URL=https://bot.getxtrra.in`
  - Laravel queue/cache/session tables publish karo (`jobs`, `job_batches`, `failed_jobs`, `cache`, `cache_locks`, `sessions`)
  - `config/wa.php`: delays, rate limits, warm-up ramp, quiet hours, media caps, batch size, mention chunk size, bridge url/token, alert channels
  - Boot-time validation: required config missing/invalid pe descriptive exception
  - Test: missing required config pe boot fail, defaults apply hon
  - _Requirements: 34.1, 34.2, 34.4, 6.6_

- [ ] 0.3 Structured logging with PII redaction
  - Monolog JSON formatter, daily channel with retention
  - Custom processor jo phone-number-shaped strings mask kare (`91XXXX**7890`)
  - Correlation id middleware + log context binding
  - Test: phone numbers masked, message bodies never emitted
  - _Requirements: 23.3, 33.2, 33.3, 33.4, 31.5_

- [ ] 0.4 Exception hierarchy define karo
  - `AppException` base: `code`, `category`, `severity`, `retryable`, `httpStatus`, `context`
  - Subclasses: `ValidationException`, `AuthException`, `NotFoundException`, `RateLimitException`, `BridgeUnreachableException`, `WhatsAppException` (+ `SessionNotConnected`, `NotOnWhatsApp`, `NotGroupAdmin`, `UnsupportedOperation`), `MediaException`
  - `bootstrap/app.php` handler → uniform JSON/Blade error with correlation id
  - Test: har class ka correct category + retryable flag; API response shape
  - _Requirements: 23.1, 23.2, 26.2_

- [ ] 0.5 Local dev setup aur health skeleton
  - `docker-compose.dev.yml`: MySQL 8 (Redis optional, commented)
  - `GET /api/v1/health` skeleton, `GET /api/v1/metrics` skeleton
  - `php artisan serve` + `queue:work` dev instructions README mein
  - _Requirements: 33.5_

---

## Phase 1 — WA Bridge Sidecar + BridgeClient Contract

> Yeh **only** non-PHP part hai. Isko deliberately thin rakhna hai — koi DB, koi business logic, koi scheduling nahi (Req 35.1).

- [ ] 1.1 `BridgeClient` PHP interface define karo
  - `app/Contracts/BridgeClient.php` — session, messaging, group, channel, capability methods (design.md ka full signature set)
  - DTOs: `SessionInitDto`, `SessionStateDto`, `SentMessageDto`, `GroupDto`, `ChannelDto`, `MediaPayload`
  - Service container binding config-driven (`WA_BRIDGE_DRIVER=http|fake`)
  - _Requirements: 35.1_

- [ ] 1.2 Node bridge service implement karo
  - `bridge/` — Express + Baileys, multi-session auth state per `storage/wa-sessions/<id>/`
  - HTTP endpoints mirroring `BridgeClient`; bearer token auth
  - **Bind `127.0.0.1` only** — external network se reachable nahi
  - Zero database access, zero business decisions
  - _Requirements: 35.1, 35.2, 35.3_

- [ ] 1.3 Bridge → PHP webhook channel
  - Bridge events (`connection.update`, `messages.upsert`, `messages.update`, `group-participants.update`, `groups.update`) → `POST /api/bridge/events` with HMAC-SHA256 signature
  - PHP: signature verify, invalid → reject; per-event try/catch so ek bad event batch na girae
  - Bridge side: bounded retry with backoff + local buffer for undelivered events
  - Test: invalid signature rejected; valid event routed to correct handler
  - _Requirements: 35.4, 35.5, 35.6_

- [ ] 1.4 `HttpBridgeClient` implement karo
  - Guzzle client, per-call timeout, retry-free (retry queue layer ka kaam hai)
  - Connection failure → `BridgeUnreachableException`
  - `isReachable()` health probe
  - _Requirements: 2.9, 35.8_

- [ ] 1.5 Capability handshake
  - Bridge start pe apni supported operation list report kare; PHP `settings` mein store + log
  - `Bridge::supports($op)` helper; unsupported call → `UnsupportedOperationException`
  - Test: capability missing pe clean error, crash nahi
  - _Requirements: 21.6, 21.7, 35.9_

- [ ] 1.6 `FakeBridgeClient` test double
  - In-memory: send success/failure/timeout injection, fake status event emission, group/channel op simulation
  - Test env mein default binding
  - _Requirements: 36.2_

- [ ] 1.7 Auth state storage hardening
  - `storage/wa-sessions/` web root ke bahar, `0700` permissions
  - Bridge sirf isi path se read/write kare
  - _Requirements: 35.10_

---

## Phase 2 — Database Schema

- [ ] 2.1 Domain migrations likho
  - Tables: `users`, `api_keys`, `sessions_wa`, `contacts`, `opt_outs`, `contact_lists`, `contact_list_items`, `groups`, `group_members`, `join_requests`, `welcome_configs`, `channels`, `channel_posts`, `channel_analytics_snapshots`, `templates`, `campaigns`, `messages`, `message_status_events`, `schedules`, `error_logs`, `audit_logs`, `settings`
  - Indexes design.md ke hisaab se; `messages.idempotency_key` unique
  - `sessions_wa` naming (Laravel ke `sessions` table se collision avoid)
  - _Requirements: 6.7_

- [ ] 2.2 Eloquent models aur backed enums
  - Models with casts, relationships, scopes
  - Enums: `SessionStatus` (+ `allowedNext()` map), `MessageStatus` (+ `rank()`), `WaStatus`, `ErrorCategory`
  - Test: `MessageStatus::rank()` ordering, `SessionStatus::allowedNext()` legality
  - _Requirements: 5.10_

- [ ] 2.3 Seeders
  - `AdminUserSeeder`: username `admin`, password `admin` (bcrypt), role `owner`, `must_change_password = true`
  - `SettingsSeeder`: documented safe defaults for saare runtime knobs
  - `TemplateSeeder`: ek sample template
  - Test: seeded admin login kar sakta hai aur `must_change_password` true hai
  - _Requirements: 27.1, 27.5, 34.4_

- [ ] 2.4 Settings service (runtime config)
  - `settings` table read with cache; write pe cache invalidate
  - Runtime-changeable values (delays, rate limits, quiet hours) restart ke bina apply hon
  - `secret` flagged values API/UI pe redacted
  - Test: update turant effective; secret masked
  - _Requirements: 34.3, 34.5_

---

## Phase 3 — Session Manager

- [ ] 3.1 `SessionManager` core
  - `create`, `start`, `stop`, `delete`, `restoreAll`, `healthy`, `markState`
  - Bridge provision/start/stop calls; state MySQL mein (process memory mein nahi)
  - Session-scoped log context
  - _Requirements: 1.1, 1.5, 1.8, 1.10_

- [ ] 3.2 Session state machine enforcement
  - `SessionStatus::allowedNext()` se transition validate; illegal transition pe throw
  - Out-of-order webhook events safely rejected
  - Test: legal + illegal transition matrix
  - _Requirements: 1.5_

- [ ] 3.3 QR aur pairing-code flows
  - QR base64 PNG store + expiry timestamp; 60s pe refresh, 5 attempts ke baad `QR_TIMEOUT`
  - Pairing-code request → 8-char code
  - Test: 5 failed attempts pe `QR_TIMEOUT`
  - _Requirements: 1.2, 1.3, 1.4_

- [ ] 3.4 Isolation, limits aur restore
  - Ek session ka failure doosre ko affect na kare (per-session error boundary)
  - Configured max concurrent session limit → clear refusal
  - `restoreAll` post-deploy aur scheduled — previously `CONNECTED` sessions reconnect
  - Test: fake bridge se ek session fail karo, doosra connected rahe; restore verify
  - _Requirements: 1.6, 1.7, 1.9_

- [ ] 3.5 QuotaTracker
  - Per-session daily counters cache mein; `wa:reset-quotas` scheduled command local-midnight pe reset
  - `sessions_wa` stats: `reconnects`, `sent_today`, `last_seen_at`
  - _Requirements: 7.4, 32.4_

---

## Phase 4 — Auto Reconnect and Health

- [ ] 4.1 `ReconnectPolicy` implement karo
  - Retryable vs terminal reason sets
  - `delaySeconds()`: 1s base, ×2, 300s cap, ±50% jitter
  - Success pe attempt reset, `reconnects` increment
  - Test: backoff bounds, jitter applied, cap respected
  - _Requirements: 2.1, 2.3, 2.5_

- [ ] 4.2 Terminal reason handling
  - `loggedOut` → `LOGGED_OUT` + alert; `connectionReplaced` → `REPLACED`; `badSession`/`multideviceMismatch` → `FAILED`
  - Koi retry NOT
  - Test: terminal reasons pe zero retry attempts
  - _Requirements: 2.2, 2.4_

- [ ] 4.3 `wa:health-check` scheduled command
  - Har minute: `last_seen_at` vs heartbeat timeout → stale session pe forced reconnect
  - Bridge `isReachable()` probe; unreachable → CRITICAL alert
  - _Requirements: 2.6, 2.9_

- [ ] 4.4 Health endpoint aur disconnected-message hold
  - `/api/v1/health`: per-session status, uptime, last-seen, reconnect count, bridge reachability
  - Session disconnected → send job `release()` kare (fail NOT), reconnect pe process ho
  - Bridge unreachable → affected jobs release + `BRIDGE` category error
  - Test: session down karo, jobs hold hon, up hone pe deliver hon
  - _Requirements: 2.7, 2.8, 2.9, 33.5_

---

## Phase 5 — Message Sending Core (Single Mode)

- [ ] 5.1 JID normalizer
  - `+91 98765-43210`, `919876543210`, `09876543210` → valid JID; group/newsletter JID detection
  - Test: comprehensive format table
  - _Requirements: 3.2_

- [ ] 5.2 Number validation with cache
  - `Bridge::checkNumbers()` wrapper, batched, cache `wa:onwa:{number}` TTL 7 days
  - Unregistered → `NotOnWhatsAppException` (non-retryable)
  - _Requirements: 3.3, 18.1, 18.5_

- [ ] 5.3 `MessagingService::sendText`
  - `messages` row create → bridge call → `wa_message_id` persist
  - `content_hash` store; raw body log NOT ho
  - _Requirements: 3.4_

- [ ] 5.4 Single-mode sequential send
  - `transactional` queue, worker `numprocs=1`, `WithoutOverlapping("session:{id}")`
  - Ack wait up to `ACK_TIMEOUT`; timeout pe `PENDING_TIMEOUT` aur sequence continue
  - Test (fake bridge): ack timeout sequence ko block na kare
  - _Requirements: 3.1, 3.5, 3.6, 6.2_

---

## Phase 6 — Delivery Status Tracking

- [ ] 6.1 Status mapping from webhook
  - Bridge ack levels → `SENT` / `DELIVERED` / `READ` / `PLAYED`; failures → `FAILED`
  - `applyStatus()` rank guard — out-of-order ack pe downgrade NOT
  - Test: transition table including out-of-order events
  - _Requirements: 5.1–5.6, 5.10_

- [ ] 6.2 Append-only status timeline
  - Har transition pe `message_status_events` row
  - `messages` pe denormalized `sent_at`, `delivered_at`, `read_at`
  - _Requirements: 5.7_

- [ ] 6.3 Campaign aggregate counters
  - Atomic increment on transition; read-receipt-disabled recipients ke liye `DELIVERED` terminal success
  - Test: counters transitions se match karein
  - _Requirements: 5.8, 5.9_

- [ ] 6.4 Live update transport
  - Livewire polling default (`wire:poll`) for status/progress
  - Optional Reverb broadcast events behind `BROADCAST_CONNECTION` check
  - _Requirements: 5.8, 25.1, 28.4_

---

## Phase 7 — Queue and Rate Limiting

- [ ] 7.1 `SendMessageJob` skeleton
  - `ShouldQueue` + `ShouldBeUnique` (`uniqueId` = `idempotency_key`)
  - `WithoutOverlapping("session:{id}")` middleware → per-session concurrency 1
  - Named queues: `transactional`, `welcome`, `campaign`, `extraction`
  - Test: duplicate dispatch pe ek hi send
  - _Requirements: 6.1, 6.2, 6.4, 6.7_

- [ ] 7.2 Rate limiter (MySQL-backed)
  - Per-session per-minute / per-hour / per-day counters via cache store + `Cache::lock()`
  - Limit hit → `$this->release($secondsUntilReset)`, job drop NOT
  - Test: 500 jobs configured rate cross NOT karein
  - _Requirements: 6.3_

- [ ] 7.3 Queue durability aur controls
  - MySQL `jobs` table — worker restart pe jobs survive
  - Pause/resume: `settings` flag; paused pe job self-release, dispatch accept jaari
  - `queue:stats` — pending, reserved, delayed, failed counts
  - Test: restart ke baad jobs process hon
  - _Requirements: 6.5, 6.6, 6.9_

- [ ] 7.4 Backpressure
  - `jobs` depth threshold cross pe new dispatch throttle + warning event
  - _Requirements: 6.8_

---

## Phase 8 — Dual Mode and Bulk Campaigns

- [ ] 8.1 `RecipientResolver`
  - Sources: manual list, CSV upload, saved contact list, extraction result
  - Pipeline: normalize → **opt-out filter (mandatory, no bypass param)** → blocklist filter → de-duplicate
  - Test: duplicates removed; opted-out number kisi bhi path se na nikle
  - _Requirements: 4.2, 4.4, 30.2, 30.7_

- [ ] 8.2 Campaign create aur dual-mode dispatch
  - `CampaignService::create` + `start` → `Bus::batch()` of `SendMessageJob`
  - Mode = worker allocation: SINGLE → `transactional`/1 worker; DUAL → `campaign`/N workers
  - `idempotency_key = sha256(campaign_id . recipient_jid)`
  - _Requirements: 4.1, 4.3_

- [ ] 8.3 Campaign lifecycle controls
  - State machine: `DRAFT` → `QUEUEING` → `RUNNING` → `COMPLETED`/`PAUSED`/`CANCELLED`/`FAILED`
  - `pause`: status flag; job first-line check → self-release; in-flight complete
  - `resume`: sirf `PENDING`/`FAILED` messages re-dispatch
  - `cancel`: batch cancel + pending jobs delete, partial stats preserve
  - Test: pause→resume mein zero double-send (verify unique `wa_message_id` count)
  - _Requirements: 4.6, 4.7, 4.8_

- [ ] 8.4 Progress aur completion
  - `progress()`: total, sent, delivered, read, failed, remaining
  - Batch finish → `COMPLETED` + summary report
  - _Requirements: 4.5, 4.9_

---

## Phase 9 — Anti-Ban Engine

- [ ] 9.1 Gaussian delay randomizer
  - Box-Muller truncated normal within min–max
  - Test: bounds respected; variance uniform distribution se distinguishable
  - _Requirements: 7.1, 7.2_

- [ ] 9.2 Typing simulator
  - `composing` → `clamp(strlen * MS_PER_CHAR, 800, 6000)` sleep → `paused` → send
  - Test: duration length se scale kare, clamp bounds respect hon
  - _Requirements: 7.3_

- [ ] 9.3 Warm-up ramp aur quota guard
  - `warmup_start_at` se session age → daily cap + new-contact cap (ramp table)
  - Verdict: `allow` | `defer` | `block`; caps runtime-override-proof
  - Test: har ramp stage ka correct cap; override attempt ignored
  - _Requirements: 7.4, 7.9, 7.10_

- [ ] 9.4 Spintax engine
  - `{a|b|c}` parse + nesting; seed = recipient id (`mt_srand(crc32($seed))`)
  - Test: same recipient stable variant; corpus spread across recipients
  - _Requirements: 7.5_

- [ ] 9.5 Batch cool-down aur quiet hours
  - N messages ke baad configured cool-down (job release)
  - Quiet-hours window timezone-aware; active → release until window end
  - Test: quiet hours boundary + DST-safe evaluation
  - _Requirements: 7.6, 7.7_

- [ ] 9.6 Risk scoring aur auto-throttle
  - Rolling 100-outcome window per session; `>15%` → rate halve + alert; `>30%` → `THROTTLED`
  - `AntiBanEngine::gate()` + `pace()` ko `SendMessageJob` mein wire karo (rate limiter ke baad, bridge se pehle)
  - Test: injected failure burst throttle trigger kare
  - _Requirements: 7.8_

---

## Phase 10 — Bulk Media Messaging

- [ ] 10.1 `MediaService` with validation
  - Types: image, video, audio, voice note (PTT), document, sticker
  - `finfo` MIME sniff (extension pe trust nahi), size cap, extension allowlist
  - Oversize → validation error
  - Store as server-generated ULID filename outside web root
  - Test: mismatched extension detected; oversize rejected; path traversal attempt rejected
  - _Requirements: 8.1, 8.2, 8.3, 8.10, 36.5_

- [ ] 10.2 Remote media fetch
  - Guzzle download with timeout, max-size cap, redirect limit
  - _Requirements: 8.4_

- [ ] 10.3 Image compression aur video thumbnail
  - Intervention Image: threshold cross pe resize/compress
  - ffmpeg (via process wrapper): video thumbnail generate + attach
  - _Requirements: 8.5, 8.6_

- [ ] 10.4 Media reuse aur caption interpolation
  - Ek media multiple recipients → bridge-side uploaded reference reuse, per-recipient re-upload nahi
  - Caption pe template variable interpolation
  - Upload failure → `MEDIA_UPLOAD_FAILED` + retry
  - Test: N recipients pe ek hi upload call
  - _Requirements: 8.7, 8.8, 8.9_

---

## Phase 11 — Templates and Personalization

- [ ] 11.1 Template CRUD with versioning
  - Edit pe naya version row, purana preserve, `is_current` flag
  - _Requirements: 9.1, 9.2_

- [ ] 11.2 Variable interpolation
  - `{{name}}`, `{{group}}`, `{{date}}`, `{{custom.*}}` resolution
  - Fallback values; strict mode → `MISSING_VARIABLE` block
  - Test: fallback path, strict path, nested custom fields
  - _Requirements: 9.3, 9.4, 9.5_

- [ ] 11.3 Preview aur test-send
  - `preview(template, sampleData)` → rendered output, koi send nahi
  - `testSend` sirf connected session ke apne number pe
  - _Requirements: 9.6, 9.7_

---

## Phase 12 — Scheduler

- [ ] 12.1 Schedule model aur due-dispatch command
  - `ONCE` (datetime + IANA tz) aur `CRON` kinds; Carbon + cron-expression
  - `schedule:run` cron entry → `DispatchDueSchedules` har minute
  - Test: DST transition ke aar-paar correct local time
  - _Requirements: 10.1, 10.2, 10.4, 10.6, 31.4_

- [ ] 12.2 Next-runs preview aur enable/disable
  - `nextRuns(5)`; disable pe future runs stop, history preserve
  - _Requirements: 10.3, 10.7_

- [ ] 12.3 Missed-run recovery
  - `SKIP` / `RUN_ONCE` / `CATCH_UP` policies from `last_run_at`
  - Test: har policy ka correct behaviour after simulated downtime
  - _Requirements: 10.5_

- [ ] 12.4 Multi-server safety aur run-now
  - `->onOneServer()` + `Cache::lock('scheduler:leader')`
  - `runNow` immediate dispatch without `next_run_at` disturb
  - _Requirements: 10.8, 10.9_

---

## Phase 13 — Group Core

- [ ] 13.1 Group create aur metadata cache
  - Create with subject + participants → JID + metadata; group + members MySQL cache
  - `metadata($jid, fresh: bool)` — cache ya live fetch
  - _Requirements: 11.1, 11.2, 11.7_

- [ ] 13.2 Invite link management
  - Get code, revoke (naya generate + purana invalidate), join by link
  - _Requirements: 11.3, 11.4, 11.5_

- [ ] 13.3 Leave aur listing
  - Leave → local `LEFT`; paginated list with search/filter
  - _Requirements: 11.6, 11.8_

---

## Phase 14 — Group Admin and Members

- [ ] 14.1 Admin guard
  - Cached `groups.is_bot_admin` check before admin-requiring op → `NotGroupAdminException` bina bridge call
  - Test: non-admin case pe bridge call NOT hoti
  - _Requirements: 12.5_

- [ ] 14.2 Participant operations with result mapping
  - add / remove / promote / demote
  - WA status codes → `ADDED` / `INVITE_SENT` / `RECENTLY_LEFT` / `ALREADY_MEMBER` / `FAILED`
  - Local member cache update
  - Test: status code mapping table
  - _Requirements: 12.1, 12.2, 12.3, 12.4_

- [ ] 14.3 Chunked bulk ops via queued job
  - `BulkParticipantJob` — chunk size + inter-chunk delay, HTTP request mein NOT
  - CSV bulk import → per-row result report with reasons
  - _Requirements: 12.6, 12.7_

- [ ] 14.4 Member reconciliation
  - `syncMembers` → added / removed / roleChanged report
  - _Requirements: 12.8_

---

## Phase 15 — Group Settings and Join Requests

- [ ] 15.1 Settings control
  - `announcement`, `locked`, `ephemeral` duration, `approvalMode`
  - Subject / description / icon update + cache refresh
  - _Requirements: 13.1–13.5_

- [ ] 15.2 Audit logging
  - Har settings change pe `audit_logs` row: actor, action, before/after, ip
  - _Requirements: 13.6_

- [ ] 15.3 Join request inbox
  - Pending list + notify on new request
  - Single aur bulk approve/reject with per-request result
  - _Requirements: 14.1, 14.2, 14.3, 14.4_

- [ ] 15.4 Auto-approve rule engine
  - Ordered JSON rules: blocklist, country-code, regex; first match wins; no match → manual pending
  - Decision + matched rule audit-logged
  - Test: rule precedence, no-match fallthrough
  - _Requirements: 14.5, 14.6, 14.7, 14.8_

---

## Phase 16 — Welcome Message Engine

- [ ] 16.1 Participant event handler
  - `group-participants.update` webhook → add / remove / promote / demote
  - Per-group welcome + goodbye flags respected
  - _Requirements: 15.1, 15.5, 15.8_

- [ ] 16.2 Welcome rendering with mentions
  - Variables: `{{member}}`, `{{group}}`, `{{memberCount}}`, `{{rules}}`
  - Group message mein proper mention; `DM` target mode
  - Test: mention array correct; DM mode group mein post NOT kare
  - _Requirements: 15.2, 15.3, 15.4_

- [ ] 16.3 Duplicate guard aur batching
  - `Cache::add("welcome:{group}:{member}", ttl)` atomic guard
  - `COMBINED` vs `PER_MEMBER` batching; `welcome` queue lane
  - Test: cooldown window mein second welcome suppressed
  - _Requirements: 15.6, 15.7_

---

## Phase 17 — Welcome Templates and Media

- [ ] 17.1 Template rotation
  - `SEQUENTIAL` (cursor), `RANDOM`, `WEIGHTED`
  - Test: sequential cursor wrap; weighted distribution
  - _Requirements: 16.1_

- [ ] 17.2 Media welcome aur dynamic card
  - Image / video / GIF with caption
  - Intervention Image (GD) card: member name + avatar + count
  - Generation failure → text-only fallback, error logged (welcome miss NOT ho)
  - _Requirements: 16.2, 16.3, 16.4_

- [ ] 17.3 A/B variant tracking
  - Even distribution + per-variant delivery stats
  - _Requirements: 16.5_

---

## Phase 18 — Group Number Extraction

- [ ] 18.1 Generator-based extraction job
  - `ExtractGroupJob` on `extraction` queue; `\Generator` yield per member
  - Number, push name, admin role, join metadata
  - Self-number excluded; `NOT_GROUP_MEMBER` guard
  - Chunked `insertOrIgnore(500)` writes
  - Test: memory stable on large fixture; self excluded
  - _Requirements: 17.1, 17.3, 17.7, 17.8_

- [ ] 18.2 Cross-group de-dup aur filters
  - Per-run temp table for de-dup (in-memory array NOT)
  - Filters: country code, admins-only, exclude-existing-contacts (composable chain)
  - Test: dedup across 3 groups
  - _Requirements: 17.2, 17.4, 17.5, 17.6_

- [ ] 18.3 Active-number classification
  - Batched `checkNumbers()` (20/call) + inter-batch delay
  - `ACTIVE` / `INACTIVE` + `is_business`, `has_photo`
  - Result cache with TTL; rate-limit error → release with backoff, resume
  - Test: rate-limit injection pe run complete ho
  - _Requirements: 18.1–18.6_

---

## Phase 19 — Export Engine

- [ ] 19.1 Streaming exporters
  - CSV (UTF-8 BOM, `fputcsv`, configurable delimiter), TXT, JSON, XLSX (queued), vCard
  - Column selection + ordering; `lazyById(1000)` source
  - Test: 10k rows memory stable; CSV BOM present; vCard valid structure
  - _Requirements: 19.1, 19.2, 19.3, 19.4, 19.7_

- [ ] 19.2 Signed download route
  - Files in `storage/app/exports` (outside web root); ULID filenames
  - `Route::signedRoute` with expiry; expired → 403
  - Test: path traversal attempt rejected; expired link rejected
  - _Requirements: 19.5, 19.8, 36.5_

- [ ] 19.3 Error report exporter
  - Columns: code, category, message, session, recipient, timestamp, retry count
  - _Requirements: 19.6_

---

## Phase 20 — Auto Tagging

- [ ] 20.1 Tag-all with hidden mentions
  - Mentions via `contextInfo.mentionedJid`; hidden mode body mein numbers NOT
  - Visible mode `@number` list append
  - Test: hidden mode body mein koi number nahi; mention array complete
  - _Requirements: 20.1, 20.2_

- [ ] 20.2 Selective selectors
  - `ADMINS`, `NON_ADMINS`, `CUSTOM` list, `REGEX`
  - _Requirements: 20.3_

- [ ] 20.3 Tag with message/media
  - Mentions attach to text ya media caption
  - _Requirements: 20.4_

- [ ] 20.4 Chunking, cooldown, permission
  - Count > `MENTION_CHUNK_SIZE` (200) → multiple queued messages with delay
  - `Cache::add("tagall:{group}", ttl)` cooldown → reject with remaining seconds
  - Admin-only enforcement when configured
  - Test: 300-member group correct chunk count; cooldown blocks second call
  - _Requirements: 20.5, 20.6, 20.7_

---

## Phase 21 — Channel Management

- [ ] 21.1 Channel CRUD (capability-gated)
  - Create → identifier + invite link; update name/description/picture; delete → `DELETED`
  - Har method pehle `Bridge::supports()` check
  - _Requirements: 21.1, 21.2, 21.3, 21.6_

- [ ] 21.2 Follow / unfollow / mute / unmute
  - _Requirements: 21.4_

- [ ] 21.3 Subscriber aur admin info
  - Jo bridge expose kare wahi return; unsupported pe clean error
  - _Requirements: 21.5, 21.6_

- [ ] 21.4 Feature flag
  - `FEATURE_CHANNELS`; unsupported ops UI mein hidden
  - Test: capability missing pe `UNSUPPORTED_OPERATION`, crash nahi
  - _Requirements: 21.7_

---

## Phase 22 — Channel Auto Post and Analytics

- [ ] 22.1 Post composer
  - Text, media, poll, link-preview; `DRAFT`/`SCHEDULED`/`PUBLISHED`/`FAILED`
  - _Requirements: 22.1_

- [ ] 22.2 Scheduled aur recurring posts
  - SchedulerService wire (`target_type = CHANNEL_POST`)
  - _Requirements: 22.2, 22.3_

- [ ] 22.3 Content calendar data
  - Upcoming + published, date-wise grouped
  - _Requirements: 22.4_

- [ ] 22.4 Analytics snapshots aur delta
  - `channels:snapshot` scheduled (hourly) → views, reactions, follower count
  - Delta = consecutive snapshots ka difference (cumulative ko delta NOT samjho)
  - Engagement ranking + export
  - Test: delta computation from snapshot series
  - _Requirements: 22.5, 22.6, 22.7_

---

## Phase 23 — Error Reporting and Retry

- [ ] 23.1 `ErrorReporter` with classification
  - `capture()` → `error_logs` row; `classify()` → code, category, severity, retryable
  - Categories: `AUTH`, `RATE_LIMIT`, `NOT_ON_WHATSAPP`, `MEDIA`, `NETWORK`, `BRIDGE`, `PERMISSION`, `VALIDATION`, `UNKNOWN`
  - Phone numbers redacted in stored context
  - Test: bridge error samples correct category pe map hon
  - _Requirements: 23.1, 23.2, 23.3_

- [ ] 23.2 Retry matrix
  - Per-category max attempts + backoff (design.md matrix)
  - Non-retryable → zero retries; rate-limit → fixed cool-down
  - Retry success pe **original** `messages` row update, duplicate NOT
  - Test: har category ka attempt count aur backoff
  - _Requirements: 24.1, 24.2, 24.6, 24.7_

- [ ] 23.3 Failed jobs aur manual retry
  - Exhausted → `failed_jobs` with last error + attempts
  - Listing + bulk manual retry with fresh attempt counter
  - Test: forced failure → retries → failed_jobs → manual retry → success
  - _Requirements: 24.3, 24.4, 24.5_

- [ ] 23.4 Error query API aur retention
  - Filters: session, date range, category, severity
  - Error-rate trend aggregation; top failing recipients
  - `errors:purge` scheduled retention job
  - _Requirements: 23.4, 23.5, 23.6, 23.7_

- [ ] 23.5 Job aur webhook error boundaries
  - Job `failed()` hook → capture + message mark; worker crash NOT
  - Webhook per-event try/catch
  - `queue:work --max-time` + Supervisor restart for memory drift
  - _Requirements: 23.1, 31.2_

---

## Phase 24 — Alerts and Observability

- [ ] 24.1 Live error surfacing
  - New `error_logs` → dashboard (Livewire poll / Reverb broadcast)
  - _Requirements: 25.1_

- [ ] 24.2 `AlertRuleEngine`
  - Rules: severity threshold, error-rate spike, session disconnect, queue backlog
  - Disconnect alert within configured seconds
  - Test: har rule trigger condition
  - _Requirements: 25.2, 25.3, 25.4, 25.5_

- [ ] 24.3 Notifier chain with dedup
  - Laravel Notifications: WhatsApp admin DM → Telegram → Mail → Webhook
  - Fingerprint dedup `Cache::add("alert:{fp}", ttl)`
  - Delivery failure → log + next channel
  - Test: repeated same error ek hi alert
  - _Requirements: 25.6, 25.7_

- [ ] 24.4 Metrics aur correlation
  - `/api/v1/metrics` Prometheus text: messages sent, delivery rate, queue depth, error rate, sessions up
  - Correlation id propagated through jobs aur bridge calls
  - `/api/v1/health` full: uptime, sessions, queue, bridge, MySQL
  - _Requirements: 33.1, 33.3, 33.5_

---

## Phase 25 — API, Auth and Admin Security

- [ ] 25.1 API scaffolding
  - `routes/api.php` `/api/v1` group; Form Request validation → 422 structured
  - Uniform error middleware with correlation id
  - Force HTTPS in production
  - _Requirements: 26.1, 26.2, 26.10_

- [ ] 25.2 Sanctum auth aur API keys
  - Login → token; logout; change-password
  - API keys SHA-256 stored, plaintext once
  - Missing/invalid → 401
  - _Requirements: 26.3, 26.4_

- [ ] 25.3 RBAC gates
  - Roles `owner`/`admin`/`operator`/`viewer`; permission matrix per route
  - Insufficient → 403
  - Test: har role ka allowed/denied route set
  - _Requirements: 26.5, 26.6_

- [ ] 25.4 Default admin security guard rails
  - `ForcePasswordChange` middleware — `must_change_password` pe sab routes change-password pe redirect
  - `RequireStrongPassword` middleware — bulk-send aur destructive routes blocked until changed
  - Persistent warning banner while default password active
  - `RateLimiter::for('login')` per-IP + per-username, lockout after N attempts
  - `AdminIpAllowlist` middleware (CIDR list from settings)
  - Idle session timeout; every login attempt audit-logged with IP + user agent
  - Test: default password se bulk-send blocked; password change ke baad allowed; lockout after N failures; non-allowlisted IP rejected
  - _Requirements: 27.2, 27.3, 27.4, 27.6, 27.7, 27.8, 27.9_

- [ ] 25.5 Rate limiting aur audit middleware
  - Per-API-key limiter → 429 + retry-after
  - State-changing ops → `audit_logs`
  - _Requirements: 26.7, 26.8_

- [ ] 25.6 Saare resource routes
  - Sessions, messages, campaigns, templates, schedules, groups, tagging, extract, exports, channels, contacts, optouts, errors, queue, settings, health, metrics (design.md API table)
  - Contract tests per route
  - _Requirements: 26.1, 26.2_

- [ ] 25.7 OpenAPI spec aur UI
  - Spec generate + browsable docs route (auth-protected)
  - _Requirements: 26.9_

---

## Phase 26 — Web Dashboard

- [ ] 26.1 Layout aur auth screens
  - Blade layout + Tailwind + Alpine; Vite asset build
  - `Auth\LoginForm` (throttled), `Auth\ChangePassword` (forced flow)
  - Security warning banner component
  - _Requirements: 28.1, 27.2, 27.3_

- [ ] 26.2 Overview aur sessions
  - `Dashboard\Overview`: active sessions, today's counters, error rate, queue depth (`wire:poll.5s`)
  - `Sessions\Index`: status cards, QR modal with `wire:poll.3s` auto-refresh, start/stop/logout
  - Test: QR modal expiry pe naya QR without page reload
  - _Requirements: 28.2, 28.10_

- [ ] 26.3 Campaign builder aur detail
  - 5-step wizard: recipients → template → mode & session pool → delays & schedule → review
  - Bulk step pe non-dismissible compliance disclaimer + one-time acknowledgement
  - Detail: `wire:poll.2s` progress bar, tick funnel (✓ / ✓✓ / 🔵✓✓ / ✗), per-recipient table, pause/resume/cancel
  - _Requirements: 28.3, 28.4, 28.9_

- [ ] 26.4 Groups aur channels screens
  - Group index/show: members table, settings panel, join-request inbox, welcome config editor, tag-all action
  - Channels: list, post composer, content calendar, analytics charts
  - _Requirements: 28.2, 28.3_

- [ ] 26.5 Contacts, templates, schedules screens
  - Contacts table + 4-step import wizard + export
  - Template CRUD with live preview + version history
  - Schedules list with next-run preview + run-now
  - _Requirements: 28.3_

- [ ] 26.6 Queue monitor aur error center
  - Queue counts, pause/resume, throughput chart
  - Error table with filters, trend chart, top failing recipients, failed-jobs bulk retry, export
  - _Requirements: 28.5, 28.6_

- [ ] 26.7 Extractor wizard
  - Group multi-select, filter builder, active-check toggle, results preview, export
  - _Requirements: 28.3_

- [ ] 26.8 Charts, responsive, i18n
  - Delivery funnel, hourly send volume, error trend
  - Mobile-responsive, dark mode
  - `lang/en` + `lang/hi`, locale in user prefs
  - _Requirements: 28.6, 28.7, 28.8_

- [ ] 26.9 Settings screen
  - Delays, rate limits, quiet hours, warm-up, alert channels, opt-out keywords, users, API keys, IP allowlist
  - Secret values masked
  - _Requirements: 34.3, 34.5, 27.7_

---

## Phase 27 — Contacts and Compliance

- [ ] 27.1 Contact CRUD
  - Name, number, custom fields, tags, notes
  - _Requirements: 29.1_

- [ ] 27.2 Import pipeline
  - Upload → header parse → column mapping → `ImportContactsJob` validate/normalize/dedupe/chunk-upsert
  - `ImportReport`: total, imported, updated, skipped, invalid rows with reasons
  - Bada import queued job mein (HTTP request mein NOT)
  - Test: invalid rows flagged; duplicates handled; 10k row import memory stable
  - _Requirements: 29.2, 29.3, 29.9_

- [ ] 27.3 vCard import/export
  - vCard 3.0 + 4.0 parse; valid vCard export
  - _Requirements: 29.4, 29.5_

- [ ] 27.4 WhatsApp phonebook sync
  - Session contacts pull → local reconcile with sync report
  - _Requirements: 29.6_

- [ ] 27.5 Dynamic segments
  - Rule tree JSON → Eloquent `Builder` compile at query time
  - Test: contact edit ke baad membership turant reflect ho
  - _Requirements: 29.7_

- [ ] 27.6 Blocklist aur opt-out
  - Blocklist add → future campaigns se exclude
  - Inbound keyword matcher (`STOP`, `UNSUBSCRIBE`, `BAND KARO`) → `opt_outs` + confirmation reply
  - Filter inside `RecipientResolver` with no bypass parameter
  - Test: opted-out number kisi bhi resolution path se na nikle
  - _Requirements: 29.8, 30.1, 30.2, 30.3, 30.4, 30.7_

- [ ] 27.7 Retention aur data deletion
  - Data-deletion: personal data purge, aggregate counters preserve
  - `data:purge` scheduled job — purane message bodies aur logs delete
  - _Requirements: 30.5, 30.6_

---

## Phase 28 — Deployment (bot.getxtrra.in)

- [ ] 28.1 nginx + PHP-FPM config
  - `bot.getxtrra.in` server block, root `public/`, PHP-FPM socket
  - TLS certificate (Let's Encrypt) + HTTP→HTTPS redirect
  - Security headers, upload size limits
  - Bridge port never proxied
  - _Requirements: 26.10, 31.6, 35.2_

- [ ] 28.2 Supervisor programs
  - `bot-worker-transactional` (numprocs=1), `bot-worker-campaign` (numprocs=N), `bot-worker-welcome`, `bot-worker-extraction`
  - `queue:work --max-time=3600 --tries=5`; autostart, autorestart
  - `bot-bridge` program for the Node sidecar
  - _Requirements: 31.1, 31.2, 31.10_

- [ ] 28.3 Cron entry
  - `* * * * * php artisan schedule:run`
  - _Requirements: 31.4_

- [ ] 28.4 Deploy script
  - `down` → pull → `composer install --no-dev -o` → `npm ci && npm run build` → `migrate --force` → `config:cache route:cache view:cache` → `queue:restart` → `up`
  - Rollback path documented
  - _Requirements: 31.3_

- [ ] 28.5 CI pipeline
  - GitHub Actions: `composer install` → Pint → PHPStan → Pest → `composer audit`
  - _Requirements: 31.7, 36.3_

- [ ] 28.6 Backup, logs, secrets
  - MySQL dump + `storage/wa-sessions` backup script; restore verification
  - Daily log rotation with retention
  - `.env`-only secrets, never logged or committed
  - _Requirements: 31.5, 31.8, 31.9_

---

## Phase 29 — Multi-Device Orchestration

- [ ] 29.1 `PoolSelector`
  - Strategies: round-robin, least-used, weighted, health-aware
  - Test: har strategy ki distribution
  - _Requirements: 32.1_

- [ ] 29.2 Sticky routing
  - Recipient → session mapping persisted; same recipient always same session
  - _Requirements: 32.2_

- [ ] 29.3 Failover aur quota exclusion
  - Unhealthy/logged-out session ke pending jobs healthy sessions pe reassign
  - Quota-exhausted session excluded until reset; warm-up session reduced share
  - Test: mid-campaign session kill karo, baaki complete karein without duplicates
  - _Requirements: 32.3, 32.4, 32.5_

- [ ] 29.4 Multi-server coordination
  - Shared MySQL queue across app servers; scheduler `onOneServer`
  - Verify zero duplicate processing
  - _Requirements: 32.6_

---

## Phase 30 — Hardening, Testing and Release

- [ ] 30.1 Test coverage completion
  - Pest coverage gate on `app/Services`, `app/Jobs`, `app/Support`
  - `FakeBridgeClient` feature-suite gaps fill
  - _Requirements: 36.1, 36.2, 36.3_

- [ ] 30.2 Load aur soak test
  - 10k messages dispatch → workers drain
  - Assert: rate limits respected, peak memory within threshold, zero duplicate `wa_message_id`, `jobs` table empty at end
  - _Requirements: 36.4_

- [ ] 30.3 Security pass
  - Path traversal, upload validation, CSRF, XSS, SQL injection review (no raw string SQL)
  - Default-admin guard rails end-to-end verify
  - `composer audit` + `npm audit` clean
  - _Requirements: 36.5, 36.6, 27.2–27.9, 31.9_

- [ ] 30.4 Documentation
  - README, server setup guide (nginx + PHP-FPM + Supervisor + cron + bridge), API reference, troubleshooting, FAQ
  - **Prominent first-login instruction: `admin`/`admin` password turant change karo**
  - Compliance disclaimer + responsible-use guidance
  - _Requirements: 36.7, 27.2_

- [ ] 30.5 Release v1.0.0
  - CHANGELOG, version tag, release notes
  - Final verification: saare 36 requirements ke acceptance criteria traced aur satisfied
  - _Requirements: 36.7_

---

## Coverage Check

| Requirement | Covered by tasks |
|---|---|
| 1 Multi-Session | 3.1–3.4 |
| 2 Auto Reconnect | 4.1–4.4 |
| 3 Single Mode | 5.1–5.4 |
| 4 Dual/Bulk | 8.1–8.4 |
| 5 Check Marks | 6.1–6.4, 2.2 |
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
| 21 Channels | 21.1–21.4, 1.5 |
| 22 Channel Posts/Analytics | 22.1–22.4 |
| 23 Error Reporting | 23.1, 23.4, 23.5, 0.3, 0.4 |
| 24 Retry | 23.2, 23.3 |
| 25 Alerts | 24.1–24.3 |
| 26 REST API | 25.1–25.3, 25.5–25.7 |
| 27 Default Admin Security | 2.3, 25.4, 26.1, 30.3, 30.4 |
| 28 Dashboard | 26.1–26.9 |
| 29 Contacts | 27.1–27.5 |
| 30 Compliance | 27.6, 27.7, 8.1 |
| 31 Deployment | 28.1–28.6, 23.5 |
| 32 Multi-Device | 29.1–29.4 |
| 33 Observability | 24.4, 0.3 |
| 34 Configuration | 0.2, 2.4, 26.9 |
| 35 Bridge Service | 1.1–1.7 |
| 36 Quality | 30.1–30.5, 0.1 |
