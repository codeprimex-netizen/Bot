# Requirements Document — WhatsApp Auto Messenger Bot

## Introduction

WhatsApp Auto Messenger Bot ek Node.js based automation platform hai jo WhatsApp Multi-Device protocol (Baileys) ke through multiple WhatsApp numbers ko ek hi control panel se manage karta hai. Platform ka scope: multi-session management, single/dual/bulk messaging with delivery tracking, scheduled campaigns, group aur channel administration, auto welcome messages, member number extraction, auto tagging, comprehensive error reporting, aur ek web-based dashboard.

Yeh document 37 features ko 34 formal requirements mein translate karta hai. Har requirement ke acceptance criteria EARS format (`WHEN … THEN the system SHALL …`) mein likhe gaye hain taaki implementation aur testing dono unambiguous rahen.

### Scope Boundaries

- **In scope:** owned numbers, owned groups/channels, opt-in aur consented contact lists
- **Out of scope:** unsolicited cold outreach, ToS-bypass techniques, scraping of non-owned groups
- Platform rate limits ko **enforce** karta hai, unko circumvent nahi karta

### Glossary

| Term | Meaning |
|------|---------|
| Session | Ek connected WhatsApp number ka authenticated socket + auth state |
| JID | WhatsApp identifier (`91XXXXXXXXXX@s.whatsapp.net`, `…@g.us`, `…@newsletter`) |
| Campaign | Ek recipient list + template + send mode + optional schedule ka bundle |
| Single mode | Sequential send — ek message ka ack aane ke baad next |
| Dual mode | Parallel send — N concurrent workers se simultaneous fan-out |
| Warm-up | Naye number ka daily quota gradually badhane ki process |
| DLQ | Dead-letter queue — max retries ke baad fail hue jobs ka store |

---

## Requirements

### Requirement 1: Multi-Session Management

**User Story:** As an operator, I want multiple WhatsApp numbers ko ek hi bot instance se connect karna, so that main different numbers ko different purposes (sales, support, alerts) ke liye parallel use kar sakoon.

#### Acceptance Criteria

1. WHEN operator ek naya session create karta hai with a unique name THEN the system SHALL uska dedicated auth directory banaye aur session record DB mein persist kare with status `INITIALIZING`
2. WHEN session start hota hai AND koi valid credential nahi hai THEN the system SHALL QR code (terminal ASCII + base64 PNG) aur pairing-code option dono expose kare
3. WHEN QR code 60 second tak scan nahi hota THEN the system SHALL naya QR generate kare aur maximum 5 attempts ke baad session ko `QR_TIMEOUT` status pe rok de
4. WHEN operator pairing-code login choose karta hai with a phone number THEN the system SHALL 8-character pairing code return kare
5. WHEN session successfully authenticate hota hai THEN the system SHALL credentials disk pe persist kare aur status `CONNECTED` set kare with phone number, push name aur device id
6. WHEN bot process restart hota hai THEN the system SHALL saare previously `CONNECTED` sessions ko automatically restore kare without human intervention
7. WHEN ek session runtime error se crash hota hai THEN the system SHALL baaki sessions ko unaffected chalne de
8. WHEN operator session delete karta hai THEN the system SHALL socket logout kare, auth directory remove kare aur DB record soft-delete kare
9. IF configured maximum concurrent session limit reach ho gaya hai THEN the system SHALL naya session start karne se refuse kare with a clear error
10. WHEN koi bhi log line likhi jaati hai THEN the system SHALL usme session identifier include kare

---

### Requirement 2: Auto Reconnect and Connection Health

**User Story:** As an operator, I want bot network drop ya WhatsApp-side restart pe khud reconnect ho jaye, so that mujhe manually restart na karna pade.

#### Acceptance Criteria

1. WHEN connection unexpectedly close hoti hai THEN the system SHALL exponential backoff (1s base, ×2 growth, 5 min cap) plus random jitter ke saath reconnect attempt kare
2. WHEN disconnect reason `loggedOut` hai THEN the system SHALL reconnect attempt NOT kare aur session ko `LOGGED_OUT` mark karke operator ko notify kare
3. WHEN disconnect reason `restartRequired` ya `connectionLost` ya `timedOut` hai THEN the system SHALL backoff schedule ke hisaab se retry kare
4. WHEN disconnect reason `connectionReplaced` hai THEN the system SHALL retry rok de aur `REPLACED` status set kare
5. WHEN reconnect successful hota hai THEN the system SHALL backoff counter reset kare aur reconnect count metric increment kare
6. WHEN socket configured heartbeat interval tak koi event nahi bhejta THEN the system SHALL socket ko stale treat karke forced reconnect kare
7. WHEN `/health` endpoint call hota hai THEN the system SHALL per-session status, uptime, last-seen timestamp aur reconnect count return kare
8. WHILE session disconnected hai THE system SHALL uske outgoing messages ko queue mein hold kare instead of failing them immediately

---

### Requirement 3: Single Mode Messaging

**User Story:** As an operator, I want ek-ek karke message bhejna with confirmation, so that main critical messages ka delivery individually verify kar sakoon.

#### Acceptance Criteria

1. WHEN operator single mode send trigger karta hai THEN the system SHALL ek time pe ek hi message bheje aur next message se pehle previous ka server ack wait kare
2. WHEN recipient number provide hota hai in any common format THEN the system SHALL usko normalize karke valid JID banaye
3. WHEN recipient number WhatsApp pe registered nahi hai THEN the system SHALL send skip kare aur error code `NOT_ON_WHATSAPP` record kare
4. WHEN message successfully bhej diya jata hai THEN the system SHALL DB mein record kare with local id, WhatsApp message id, session id, recipient, content hash aur timestamp
5. IF ack configured timeout ke andar nahi aata THEN the system SHALL message ko `PENDING_TIMEOUT` mark kare aur sequence continue kare
6. WHEN send fail hota hai THEN the system SHALL error ko normalized code ke saath capture kare aur retry policy ke hawale kar de

---

### Requirement 4: Dual Mode and Bulk Messaging

**User Story:** As an operator, I want bulk mein multiple contacts ko simultaneously message bhejna, so that bade audience tak jaldi pahunch sakoon.

#### Acceptance Criteria

1. WHEN operator dual mode select karta hai THEN the system SHALL configured number of concurrent workers se messages parallel bheje
2. WHEN campaign create hota hai THEN the system SHALL recipient list accept kare from manual entry, CSV upload, saved contact list, ya group member extraction
3. WHEN campaign start hota hai THEN the system SHALL har recipient ke liye ek queue job enqueue kare with idempotency key
4. WHEN duplicate recipient same campaign mein exist karta hai THEN the system SHALL usko de-duplicate kare before enqueueing
5. WHILE campaign chal raha hai THE system SHALL live progress expose kare — total, sent, delivered, read, failed, remaining
6. WHEN operator campaign pause karta hai THEN the system SHALL in-flight message complete hone de but naye job dispatch rok de
7. WHEN paused campaign resume hota hai THEN the system SHALL exactly wahi se continue kare aur already-sent recipients ko dobara message NOT kare
8. WHEN operator campaign cancel karta hai THEN the system SHALL pending jobs remove kare aur campaign ko `CANCELLED` mark kare with partial stats preserved
9. WHEN campaign ke saare jobs process ho jaate hain THEN the system SHALL campaign ko `COMPLETED` mark kare aur final summary report generate kare

---

### Requirement 5: Delivery Status and Check Mark Tracking

**User Story:** As an operator, I want har message ka delivery status (✓ sent, ✓✓ delivered, 🔵✓✓ read) dekhna, so that main campaign effectiveness measure kar sakoon.

#### Acceptance Criteria

1. WHEN message enqueue hota hai THEN the system SHALL uska status `PENDING` set kare
2. WHEN WhatsApp server message accept karta hai THEN the system SHALL status `SENT` (✓) update kare
3. WHEN recipient ke device pe message deliver hota hai THEN the system SHALL status `DELIVERED` (✓✓) update kare
4. WHEN recipient message read karta hai THEN the system SHALL status `READ` (🔵✓✓) update kare
5. WHEN voice note play hoti hai THEN the system SHALL status `PLAYED` update kare
6. WHEN send permanently fail hota hai THEN the system SHALL status `FAILED` (✗) set kare with reason
7. WHEN koi bhi status transition hota hai THEN the system SHALL uska timestamp status-history timeline mein append kare
8. WHEN status change hota hai for a campaign message THEN the system SHALL campaign aggregate counters update kare aur WebSocket pe live event push kare
9. IF recipient ke privacy settings read receipts disable karte hain THEN the system SHALL `DELIVERED` ko terminal success state treat kare

---

### Requirement 6: Message Queue and Rate Limiting

**User Story:** As an operator, I want bulk sends ek controlled queue se jaayein, so that platform limits cross na hon aur mera number safe rahe.

#### Acceptance Criteria

1. WHEN message send request aata hai THEN the system SHALL usko persistent queue mein enqueue kare instead of directly sending
2. WHILE queue process ho raha hai THE system SHALL per-session concurrency limit enforce kare
3. WHEN configured per-minute, per-hour ya per-day rate limit reach hota hai THEN the system SHALL dispatch rok de until window reset ho jaye
4. WHEN different priority ke jobs queue mein hain THEN the system SHALL `transactional > welcome > campaign` order mein process kare
5. WHEN operator queue pause karta hai THEN the system SHALL naye jobs dispatch rokey but enqueue accept karta rahe
6. WHEN bot restart hota hai THEN the system SHALL pending queue jobs ko lose NOT kare
7. WHEN same idempotency key ka job dobara enqueue hota hai THEN the system SHALL duplicate send NOT kare
8. WHEN queue depth configured threshold cross karta hai THEN the system SHALL backpressure signal kare aur new enqueues throttle kare
9. WHEN operator queue stats maangta hai THEN the system SHALL waiting, active, completed, failed aur delayed job counts return kare

---

### Requirement 7: Anti-Ban Protection and Custom Delays

**User Story:** As an operator, I want bot human-like behave kare with smart delays, so that mera number ban hone ka risk kam ho.

#### Acceptance Criteria

1. WHEN consecutive messages bheje jaate hain THEN the system SHALL configured min–max range mein randomized delay insert kare
2. WHEN delay compute hota hai THEN the system SHALL uniform-random ke bajaye jitter distribution use kare taaki pattern detectable na ho
3. WHEN message send hone wala hai THEN the system SHALL pehle `composing` presence bheje, message length ke proportional typing duration wait kare, phir `paused` bheje
4. WHEN session ki age configured warm-up period ke andar hai THEN the system SHALL daily send quota ko warm-up ramp schedule ke hisaab se limit kare
5. WHEN template mein spintax syntax (`{option1|option2}`) hai THEN the system SHALL har recipient ke liye randomly ek variant choose kare
6. WHEN configured batch size ke messages bhej diye jaate hain THEN the system SHALL configured cool-down break le before next batch
7. WHEN current time configured quiet-hours window mein hai THEN the system SHALL campaign sends defer kare until window khatam ho
8. WHEN rolling window mein delivery failure rate configured threshold cross karta hai THEN the system SHALL send rate auto-throttle kare aur operator ko alert kare
9. WHEN naye unknown recipients ko message ja raha hai THEN the system SHALL per-day new-contact limit enforce kare

---

### Requirement 8: Bulk Media Messaging

**User Story:** As an operator, I want images, videos aur documents bulk mein bhejna, so that rich content campaigns chala sakoon.

#### Acceptance Criteria

1. WHEN operator media message compose karta hai THEN the system SHALL image, video, audio, voice note, document aur sticker types support kare
2. WHEN media local upload se aata hai THEN the system SHALL uska MIME type content-sniffing se verify kare, extension pe bharosa NOT kare
3. WHEN media size configured limit se bada hai THEN the system SHALL send reject kare with a clear validation error
4. WHEN media remote URL se aata hai THEN the system SHALL usko download kare with timeout aur size cap
5. WHEN image configured dimension ya size threshold se badi hai THEN the system SHALL usko compress kare before sending
6. WHEN video bheja jata hai THEN the system SHALL thumbnail generate karke attach kare
7. WHEN same media multiple recipients ko ja raha hai THEN the system SHALL uploaded media reference reuse kare instead of re-uploading per recipient
8. WHEN media message mein caption hai THEN the system SHALL caption pe bhi template variable interpolation apply kare
9. IF media upload fail hota hai THEN the system SHALL error `MEDIA_UPLOAD_FAILED` record kare aur us recipient ka job retry queue mein daale

---

### Requirement 9: Message Templates and Personalization

**User Story:** As an operator, I want reusable templates variables ke saath save karna, so that personalized messages bina manual editing bhej sakoon.

#### Acceptance Criteria

1. WHEN operator template create karta hai THEN the system SHALL name, category, body, optional media aur variable list ke saath usko persist kare
2. WHEN template edit hota hai THEN the system SHALL naya version create kare aur purane version ka history preserve kare
3. WHEN template render hota hai THEN the system SHALL `{{name}}`, `{{group}}`, `{{date}}` aur `{{custom.*}}` placeholders ko recipient data se replace kare
4. IF koi variable ki value missing hai AND fallback defined hai THEN the system SHALL fallback value use kare
5. IF koi variable ki value missing hai AND strict mode enabled hai THEN the system SHALL us recipient ka send block kare with `MISSING_VARIABLE` error
6. WHEN operator template preview maangta hai THEN the system SHALL sample data ke saath rendered output return kare without sending
7. WHEN operator test-send karta hai THEN the system SHALL message sirf connected session ke apne number pe bheje

---

### Requirement 10: Scheduled Messaging

**User Story:** As an operator, I want messages future time pe ya recurring basis pe auto bhejna, so that mujhe manually trigger na karna pade.

#### Acceptance Criteria

1. WHEN operator one-time schedule set karta hai with datetime aur timezone THEN the system SHALL message us exact local time pe dispatch kare
2. WHEN operator recurring schedule set karta hai with a cron expression THEN the system SHALL har matching occurrence pe campaign trigger kare
3. WHEN schedule create hota hai THEN the system SHALL next 5 planned run times preview mein dikhaye
4. WHEN bot restart hota hai THEN the system SHALL saare active schedules DB se reload kare
5. IF downtime ke dauran koi scheduled run miss ho gaya THEN the system SHALL configured recovery policy (`skip`, `run-once`, ya `catch-up`) apply kare
6. WHEN daylight-saving shift hoti hai THEN the system SHALL schedule ko operator ke intended local time pe hi rakhe
7. WHEN operator schedule disable karta hai THEN the system SHALL future runs rok de but history preserve kare
8. WHEN operator "run now" trigger karta hai THEN the system SHALL immediately execute kare without regular schedule ko disturb kiye

---

### Requirement 11: Group Creation and Lifecycle

**User Story:** As an operator, I want groups programmatically create aur manage karna, so that manual setup effort bache.

#### Acceptance Criteria

1. WHEN operator group create karta hai with subject aur initial participants THEN the system SHALL group banaye aur uska JID plus metadata return kare
2. WHEN group create hota hai THEN the system SHALL group record aur uske members local DB mein cache kare
3. WHEN operator invite link maangta hai THEN the system SHALL current invite link return kare
4. WHEN operator invite link revoke karta hai THEN the system SHALL naya link generate kare aur purana invalidate kare
5. WHEN operator invite link se group join karta hai THEN the system SHALL join kare aur group metadata sync kare
6. WHEN operator group leave karta hai THEN the system SHALL exit kare aur local record ko `LEFT` mark kare
7. WHEN group metadata fetch hota hai THEN the system SHALL subject, description, owner, participant count, settings aur admin list include kare
8. WHEN group list request hoti hai THEN the system SHALL paginated results with filter aur search support kare

---

### Requirement 12: Group Admin and Member Management

**User Story:** As an operator, I want group members aur admins bulk mein add/remove/promote karna, so that bade groups efficiently manage kar sakoon.

#### Acceptance Criteria

1. WHEN operator participant add karta hai THEN the system SHALL add attempt kare aur per-number result (added / invite-sent / failed) return kare
2. IF privacy settings ki wajah se direct add possible nahi hai THEN the system SHALL invite send kare aur result ko `INVITE_SENT` mark kare
3. WHEN operator participant remove karta hai THEN the system SHALL usko group se hataye aur local member cache update kare
4. WHEN operator member ko promote ya demote karta hai THEN the system SHALL admin role change kare
5. IF bot us group mein admin nahi hai THEN the system SHALL admin-only action attempt karne se pehle reject kare with `NOT_GROUP_ADMIN`
6. WHEN bulk participant operation CSV se hoti hai THEN the system SHALL row-by-row result report generate kare with success aur failure reasons
7. WHEN bulk add chalta hai THEN the system SHALL participants ko chunks mein process kare with delay between chunks
8. WHEN member list sync hota hai THEN the system SHALL local cache ko WhatsApp ke actual participant list se reconcile kare

---

### Requirement 13: Group Settings Control

**User Story:** As an operator, I want group settings aur info control karna, so that group behaviour apne rules ke hisaab se set kar sakoon.

#### Acceptance Criteria

1. WHEN operator announcement mode enable karta hai THEN the system SHALL group ko aisa set kare ki sirf admins message bhej sakein
2. WHEN operator locked mode enable karta hai THEN the system SHALL group info editing sirf admins tak restrict kare
3. WHEN operator disappearing messages set karta hai THEN the system SHALL chosen duration apply kare
4. WHEN operator subject, description ya group icon update karta hai THEN the system SHALL change apply kare aur local cache refresh kare
5. WHEN operator membership approval mode toggle karta hai THEN the system SHALL group ka approval requirement update kare
6. WHEN koi bhi settings change hota hai THEN the system SHALL audit log entry banaye with actor, timestamp aur old/new value

---

### Requirement 14: Join Request Approval

**User Story:** As an operator, I want group join requests handle karna manually ya rules se, so that unwanted members group mein na aayein.

#### Acceptance Criteria

1. WHEN naya join request aata hai THEN the system SHALL usko pending list mein store kare aur operator ko notify kare
2. WHEN operator request approve karta hai THEN the system SHALL requester ko group mein add kare
3. WHEN operator request reject karta hai THEN the system SHALL request decline kare
4. WHEN operator bulk approve ya reject karta hai THEN the system SHALL saare selected requests process kare aur per-request result return kare
5. WHEN auto-approve rule configured hai AND requester allowlist criteria (country code, regex pattern) match karta hai THEN the system SHALL usko automatically approve kare
6. WHEN requester blocklist mein hai THEN the system SHALL request automatically reject kare
7. WHEN koi request auto-processed hota hai THEN the system SHALL decision, matched rule aur timestamp ke saath audit log likhe
8. IF koi rule match nahi karta THEN the system SHALL request ko manual review ke liye pending rakhe

---

### Requirement 15: Auto Welcome Message

**User Story:** As a group owner, I want naye member ke join hone pe auto welcome message jaye, so that onboarding automatic ho.

#### Acceptance Criteria

1. WHEN group mein naya participant add hota hai AND us group ke liye welcome enabled hai THEN the system SHALL configured welcome message bheje
2. WHEN welcome message render hota hai THEN the system SHALL `{{member}}`, `{{group}}`, `{{memberCount}}` aur `{{rules}}` variables replace kare
3. WHEN welcome message group mein bheja jata hai THEN the system SHALL naye member ko properly mention kare
4. WHEN welcome delivery target `DM` configured hai THEN the system SHALL message group ke bajaye member ko privately bheje
5. WHEN participant group chhodta hai AND goodbye enabled hai THEN the system SHALL goodbye message bheje
6. WHEN ek hi member short window mein multiple times join karta hai THEN the system SHALL cooldown apply karke duplicate welcome NOT bheje
7. WHEN ek hi event mein multiple members add hote hain THEN the system SHALL configuration ke hisaab se ek combined message ya per-member messages bheje
8. WHEN welcome us group ke liye disabled hai THEN the system SHALL koi message NOT bheje

---

### Requirement 16: Welcome Templates and Media

**User Story:** As a group owner, I want multiple welcome templates media ke saath, so that welcome messages repetitive na lagein.

#### Acceptance Criteria

1. WHEN ek group ke liye multiple welcome templates configured hain THEN the system SHALL configured rotation strategy (sequential, random, ya weighted) se ek choose kare
2. WHEN welcome template mein media attached hai THEN the system SHALL image, video ya GIF ko caption ke saath bheje
3. WHEN dynamic welcome card enabled hai THEN the system SHALL member name, avatar aur member count ke saath image generate karke bheje
4. IF dynamic image generation fail hoti hai THEN the system SHALL text-only welcome pe fallback kare
5. WHEN A/B testing enabled hai THEN the system SHALL variants evenly distribute kare aur per-variant delivery stats track kare

---

### Requirement 17: Group Member Number Extraction

**User Story:** As an operator, I want apne groups se member numbers nikalna, so that unko contact list mein import kar sakoon.

#### Acceptance Criteria

1. WHEN operator group extraction trigger karta hai THEN the system SHALL har participant ka number, push name, admin role aur available join metadata return kare
2. WHEN multiple groups se extraction hoti hai THEN the system SHALL results combine kare aur numbers ko de-duplicate kare
3. WHEN extraction result mein bot ka apna number hai THEN the system SHALL usko exclude kare
4. WHEN operator country-code filter apply karta hai THEN the system SHALL sirf matching numbers return kare
5. WHEN operator admins-only filter apply karta hai THEN the system SHALL sirf group admins return kare
6. WHEN operator exclude-existing filter apply karta hai THEN the system SHALL already-saved contacts ko result se hataye
7. WHEN extraction bade group pe chalti hai THEN the system SHALL result stream kare without excessive memory use
8. IF bot us group ka member nahi hai THEN the system SHALL extraction reject kare with `NOT_GROUP_MEMBER`

---

### Requirement 18: Active Number Filtering

**User Story:** As an operator, I want extracted numbers mein se sirf active WhatsApp numbers rakhna, so that invalid numbers pe send waste na ho.

#### Acceptance Criteria

1. WHEN operator active filtering enable karta hai THEN the system SHALL har number ka WhatsApp registration check kare
2. WHEN registration check hota hai THEN the system SHALL numbers ko batches mein verify kare with delay between batches
3. WHEN number registered hai THEN the system SHALL usko `ACTIVE` classify kare, warna `INACTIVE`
4. WHEN classification hoti hai THEN the system SHALL business-account flag aur profile-photo presence bhi record kare
5. WHEN verification result aata hai THEN the system SHALL usko configured TTL tak cache kare taaki repeat checks avoid hon
6. IF verification rate limit hit hoti hai THEN the system SHALL process pause kare, backoff kare aur baad mein resume kare

---

### Requirement 19: Data Export

**User Story:** As an operator, I want numbers, reports aur error logs file mein export karna, so that main unhe bahar analyze kar sakoon.

#### Acceptance Criteria

1. WHEN operator export request karta hai THEN the system SHALL CSV, TXT, JSON, XLSX aur vCard formats support kare
2. WHEN CSV export hota hai THEN the system SHALL UTF-8 BOM include kare taaki Excel mein characters correctly khulein
3. WHEN operator columns select karta hai THEN the system SHALL sirf wahi columns chosen order mein export kare
4. WHEN export dataset bada hai THEN the system SHALL file ko stream karke likhe without entire dataset memory mein load kiye
5. WHEN export complete hota hai THEN the system SHALL signed download URL return kare jo configured time ke baad expire ho
6. WHEN error report export hota hai THEN the system SHALL error code, category, message, session, recipient, timestamp aur retry count include kare
7. WHEN vCard export hota hai THEN the system SHALL valid vCard output produce kare jo standard contact apps mein import ho jaye

---

### Requirement 20: Auto Tagging

**User Story:** As a group admin, I want group ke members ko ek saath ya selectively tag karna, so that important announcements sabko notify hon.

#### Acceptance Criteria

1. WHEN operator tag-all trigger karta hai THEN the system SHALL saare group participants ko message ke mentions mein include kare
2. WHEN hidden-mention mode enabled hai THEN the system SHALL participants ko mention kare without unke numbers message body mein dikhaye
3. WHEN operator selective tagging use karta hai THEN the system SHALL sirf chosen subset (admins, non-admins, custom list, ya regex match) ko tag kare
4. WHEN operator tag ke saath custom message ya media bhejta hai THEN the system SHALL mentions ko us content ke saath attach kare
5. WHEN group ka participant count safe mention limit se zyada hai THEN the system SHALL tagging ko chunks mein todke delay ke saath bheje
6. WHEN tagging recently us group mein already hui hai THEN the system SHALL per-group cooldown enforce kare aur request reject kare
7. IF requesting user group admin nahi hai AND admin-only tagging enforced hai THEN the system SHALL request reject kare

---

### Requirement 21: Channel Management

**User Story:** As an operator, I want WhatsApp channels create aur manage karna, so that broadcast audience handle kar sakoon.

#### Acceptance Criteria

1. WHEN operator channel create karta hai with name aur description THEN the system SHALL channel banaye aur uska identifier plus invite link return kare
2. WHEN operator channel metadata update karta hai THEN the system SHALL name, description ya picture change apply kare
3. WHEN operator channel delete karta hai THEN the system SHALL channel remove kare aur local record ko `DELETED` mark kare
4. WHEN operator follow, unfollow, mute ya unmute karta hai THEN the system SHALL corresponding action apply kare
5. WHEN operator subscriber ya admin info maangta hai THEN the system SHALL jitna underlying API expose karta hai wahi return kare
6. IF underlying library ka koi channel operation supported nahi hai THEN the system SHALL crash ke bajaye clear `UNSUPPORTED_OPERATION` error return kare
7. WHEN bot start hota hai THEN the system SHALL available channel capabilities detect karke log kare

---

### Requirement 22: Channel Auto Post and Analytics

**User Story:** As an operator, I want channel pe scheduled posts aur unka performance data, so that content strategy plan kar sakoon.

#### Acceptance Criteria

1. WHEN operator channel post create karta hai THEN the system SHALL text, media, poll aur link-preview content types support kare
2. WHEN channel post schedule hota hai THEN the system SHALL usko specified time pe publish kare
3. WHEN recurring channel post configured hota hai THEN the system SHALL har occurrence pe publish kare
4. WHEN operator content calendar dekhta hai THEN the system SHALL upcoming aur published posts date-wise dikhaye
5. WHEN published post ka analytics collect hota hai THEN the system SHALL view count aur reaction breakdown snapshot kare
6. WHEN analytics samples multiple points pe collect hote hain THEN the system SHALL follower count ka delta over time compute kare
7. WHEN operator performance report maangta hai THEN the system SHALL posts ko engagement ke hisaab se rank kare aur export allow kare

---

### Requirement 23: Centralized Error Reporting

**User Story:** As an operator, I want har error ka detailed structured log ek dashboard mein, so that problems jaldi diagnose kar sakoon.

#### Acceptance Criteria

1. WHEN system mein koi bhi error hota hai THEN the system SHALL usko normalized code, category, severity, session, related entity, message, stack aur context payload ke saath record kare
2. WHEN error categorize hota hai THEN the system SHALL `AUTH`, `RATE_LIMIT`, `NOT_ON_WHATSAPP`, `MEDIA`, `NETWORK`, `PERMISSION`, `VALIDATION` ya `UNKNOWN` assign kare
3. WHEN error log mein phone number hai THEN the system SHALL usko partially redact kare
4. WHEN operator error dashboard kholta hai THEN the system SHALL session, date range, category aur severity ke filters de
5. WHEN operator error trend dekhta hai THEN the system SHALL time ke against error rate chart dikhaye
6. WHEN repeated failures ek hi recipient pe hote hain THEN the system SHALL top failing recipients ki list surface kare
7. WHEN error logs configured retention period se purane ho jaate hain THEN the system SHALL unko purge kare

---

### Requirement 24: Failed Message Retry

**User Story:** As an operator, I want failed messages automatically retry hon, so that temporary problems se delivery na ruke.

#### Acceptance Criteria

1. WHEN send fail hota hai with a retryable error category THEN the system SHALL exponential backoff plus jitter ke saath retry kare
2. WHEN error category permanently non-retryable hai (jaise `NOT_ON_WHATSAPP`) THEN the system SHALL retry NOT kare
3. WHEN retry attempts configured maximum tak pahunch jaate hain THEN the system SHALL job ko dead-letter queue mein move kare
4. WHEN operator DLQ dekhta hai THEN the system SHALL failed jobs unke last error aur attempt count ke saath dikhaye
5. WHEN operator manual bulk retry trigger karta hai THEN the system SHALL selected DLQ jobs ko fresh attempt counter ke saath re-enqueue kare
6. WHEN retry successful hota hai THEN the system SHALL original message record update kare instead of duplicate banaye
7. WHEN rate-limit error aata hai THEN the system SHALL retry se pehle configured cool-down wait kare

---

### Requirement 25: Real-time Error Notifications

**User Story:** As an operator, I want error hone pe instant alert milna, so that main turant react kar sakoon.

#### Acceptance Criteria

1. WHEN naya error record hota hai THEN the system SHALL usko connected dashboard clients pe WebSocket se live push kare
2. WHEN error severity configured alert threshold ko meet karta hai THEN the system SHALL configured channels (WhatsApp admin DM, Telegram, email, generic webhook) pe alert bheje
3. WHEN session disconnect hota hai THEN the system SHALL configured seconds ke andar operator ko alert bheje
4. WHEN error rate spike detect hota hai THEN the system SHALL threshold-breach alert bheje
5. WHEN queue backlog configured limit cross karta hai THEN the system SHALL backlog alert bheje
6. WHEN same error short window mein repeat hota hai THEN the system SHALL alerts ko dedupe aur rate-limit kare taaki alert storm na ho
7. IF alert delivery fail hoti hai THEN the system SHALL failure log kare aur alternate configured channel try kare

---

### Requirement 26: REST API and Authentication

**User Story:** As a developer, I want ek authenticated REST API, so that main bot ko apne systems se integrate kar sakoon.

#### Acceptance Criteria

1. WHEN API request aati hai THEN the system SHALL usko versioned path (`/api/v1/...`) pe serve kare
2. WHEN request body ya query params aate hain THEN the system SHALL unko schema se validate kare aur invalid input pe structured 400 error de
3. WHEN user valid credentials se login karta hai THEN the system SHALL short-lived access token aur refresh token issue kare
4. WHEN request bina valid token ya API key aati hai THEN the system SHALL 401 return kare
5. WHEN user ke role ke paas required permission nahi hai THEN the system SHALL 403 return kare
6. WHEN roles assign hote hain THEN the system SHALL `owner`, `admin`, `operator` aur `viewer` levels support kare
7. WHEN API key configured rate limit exceed karti hai THEN the system SHALL 429 return kare with retry-after
8. WHEN koi state-changing operation hoti hai THEN the system SHALL audit log likhe with actor, action, target aur timestamp
9. WHEN API documentation request hoti hai THEN the system SHALL OpenAPI spec aur browsable UI serve kare

---

### Requirement 27: Web Dashboard

**User Story:** As an operator, I want browser-based control panel, so that mujhe terminal use na karna pade.

#### Acceptance Criteria

1. WHEN operator dashboard kholta hai THEN the system SHALL login screen dikhaye aur successful auth ke baad overview pe le jaye
2. WHEN operator sessions page kholta hai THEN the system SHALL saare sessions unke status ke saath aur naya session QR scan karne ka option dikhaye
3. WHEN operator campaign builder use karta hai THEN the system SHALL recipient selection, template choice, send mode, delay settings aur schedule step-by-step guide kare
4. WHILE campaign chal raha hai THE dashboard SHALL live progress aur delivery tick counts update kare without page refresh
5. WHEN naya error aata hai THEN the dashboard SHALL live notification dikhaye
6. WHEN operator analytics dekhta hai THEN the dashboard SHALL delivery funnel, hourly send volume aur error trend charts dikhaye
7. WHEN dashboard mobile screen size pe khulta hai THEN the layout SHALL responsively adapt kare
8. WHEN operator language switch karta hai THEN the dashboard SHALL English aur Hindi ke beech UI text badle
9. WHEN operator bulk messaging feature access karta hai THEN the dashboard SHALL compliance disclaimer prominently dikhaye

---

### Requirement 28: Contact Management and vCard

**User Story:** As an operator, I want contacts ko organized rakhna aur import/export karna, so that audience targeting easy ho.

#### Acceptance Criteria

1. WHEN operator contact create ya edit karta hai THEN the system SHALL name, number, custom fields, tags aur notes store kare
2. WHEN operator CSV ya XLSX import karta hai THEN the system SHALL column-mapping step de before import commit kare
3. WHEN import chalta hai THEN the system SHALL numbers normalize kare, duplicates detect kare aur invalid rows ko reason ke saath flag kare
4. WHEN operator vCard import karta hai THEN the system SHALL vCard 3.0 aur 4.0 dono parse kare
5. WHEN operator contacts export karta hai THEN the system SHALL vCard aur tabular formats offer kare
6. WHEN operator WhatsApp phonebook sync karta hai THEN the system SHALL session ke contacts pull kare aur local records se reconcile kare
7. WHEN operator rule-based segment banata hai THEN the system SHALL membership dynamically evaluate kare jab contacts change hon
8. WHEN operator contact ko blocklist mein daalta hai THEN the system SHALL usko har future campaign se exclude kare

---

### Requirement 29: Opt-out and Compliance

**User Story:** As a responsible operator, I want opt-out requests automatically honor hon, so that main compliant rahoon.

#### Acceptance Criteria

1. WHEN recipient configured opt-out keyword (jaise `STOP`) bhejta hai THEN the system SHALL usko global opt-out list mein add kare
2. WHEN campaign recipients resolve hote hain THEN the system SHALL opt-out list ke saare numbers exclude kare
3. WHEN kisi opted-out number pe send attempt hota hai THEN the system SHALL send block kare aur `OPTED_OUT` record kare
4. WHEN opt-out register hota hai THEN the system SHALL recipient ko confirmation acknowledgement bheje
5. WHEN operator data-deletion request process karta hai THEN the system SHALL us contact ka personal data purge kare but aggregate counters preserve kare
6. WHEN retention policy chalti hai THEN the system SHALL configured age se purane message bodies aur logs delete kare

---

### Requirement 30: Process Management and Deployment

**User Story:** As an operator, I want bot background mein reliably chale aur easily deploy ho, so that production mein stable rahe.

#### Acceptance Criteria

1. WHEN bot process manager ke through start hota hai THEN the system SHALL background mein chale aur system reboot ke baad auto-start ho
2. WHEN process crash hota hai THEN the process manager SHALL usko automatically restart kare
3. WHEN process memory configured threshold cross karti hai THEN the process manager SHALL graceful restart kare
4. WHEN naya version deploy hota hai THEN the system SHALL zero-downtime reload support kare
5. WHEN logs likhe jaate hain THEN the system SHALL unko rotate kare aur configured period ke baad purge kare
6. WHEN container deployment use hota hai THEN the system SHALL app, Redis, Postgres aur reverse proxy ko ek compose stack se up kare
7. WHEN code push hota hai THEN CI pipeline SHALL lint, test aur build steps chalaye
8. WHEN backup script chalti hai THEN the system SHALL session auth state aur database ka restorable backup banaye
9. WHEN secrets configure hote hain THEN the system SHALL unko environment se read kare aur kabhi commit ya log NOT kare

---

### Requirement 31: Multi-Device Orchestration

**User Story:** As an operator, I want multiple numbers ka pool load-balanced tarike se use karna, so that volume distribute ho aur ek number ban hone pe kaam na ruke.

#### Acceptance Criteria

1. WHEN campaign multiple sessions ke pool pe chalta hai THEN the system SHALL configured strategy (round-robin, least-used, weighted, ya health-aware) se number select kare
2. WHEN sticky routing enabled hai THEN the system SHALL ek hi recipient ko hamesha same session se message bheje
3. WHEN koi session unhealthy ya logged-out ho jata hai THEN the system SHALL uske pending jobs healthy sessions pe failover kare
4. WHEN kisi session ka daily quota exhaust ho jata hai THEN the system SHALL usko selection se exclude kare until quota reset ho
5. WHEN session warm-up stage mein hai THEN the system SHALL usko reduced share of traffic assign kare
6. WHEN multiple worker nodes deploy hote hain THEN the system SHALL shared queue se coordinate kare without duplicate processing

---

### Requirement 32: Observability and Metrics

**User Story:** As an operator, I want system metrics aur structured logs, so that main health monitor kar sakoon.

#### Acceptance Criteria

1. WHEN metrics endpoint scrape hota hai THEN the system SHALL messages-sent, delivery-rate, queue-depth, error-rate aur session-up metrics expose kare
2. WHEN koi log line emit hoti hai THEN the system SHALL structured JSON use kare with level, timestamp, session aur correlation id
3. WHEN ek request ya campaign multiple components se guzarta hai THEN the system SHALL correlation id propagate kare
4. WHEN sensitive data log ho sakta hai THEN the system SHALL usko mask kare before writing
5. WHEN operator system status maangta hai THEN the system SHALL uptime, active sessions, queue health aur DB connectivity return kare

---

### Requirement 33: Configuration Management

**User Story:** As an operator, I want saari tuning knobs config se control karna, so that behaviour change karne ke liye code edit na karna pade.

#### Acceptance Criteria

1. WHEN application start hoti hai THEN the system SHALL configuration environment se load kare aur schema se validate kare
2. IF required configuration missing ya invalid hai THEN the system SHALL startup pe fail kare with a descriptive message
3. WHEN operator runtime settings (delays, rate limits, quiet hours) badalta hai THEN the system SHALL unhe process restart ke bina apply kare
4. WHEN kisi setting ki value nahi di gayi THEN the system SHALL documented safe default use kare
5. WHEN configuration expose hoti hai via API THEN the system SHALL secret values redact kare

---

### Requirement 34: Quality and Reliability Baseline

**User Story:** As a maintainer, I want tested aur documented codebase, so that changes safely ship ho sakein.

#### Acceptance Criteria

1. WHEN core services implement hote hain THEN the system SHALL unke unit tests include kare
2. WHEN WhatsApp interactions test hoti hain THEN the test suite SHALL mocked socket use kare, real network NOT
3. WHEN test suite chalti hai THEN the system SHALL core modules pe configured minimum coverage threshold meet kare
4. WHEN load test chalta hai with large queued volume THEN the system SHALL memory leak ke bina complete kare
5. WHEN file path ya media input handle hota hai THEN the system SHALL path traversal aur injection ke against sanitize kare
6. WHEN release publish hoti hai THEN the repository SHALL setup guide, API reference, troubleshooting aur changelog include kare

---

## Requirement → Feature Traceability

| Req | Title | Original Feature # |
|-----|-------|--------------------|
| 1 | Multi-Session Management | 30, 35 |
| 2 | Auto Reconnect | 31 |
| 3 | Single Mode Messaging | 9 |
| 4 | Dual Mode & Bulk | 10, 13 |
| 5 | Check Mark Status | 11 |
| 6 | Queue & Rate Limiting | 15 |
| 7 | Anti-Ban & Delays | 16, 34 |
| 8 | Bulk Media Send | 14 |
| 9 | Message Templates | 36 |
| 10 | Scheduled Messaging | 12 |
| 11 | Group Create/Delete | 5 |
| 12 | Group Admin/Member Mgmt | 6, 2 |
| 13 | Group Settings Control | 7 |
| 14 | Join Request Approve/Reject | 8 |
| 15 | Auto Welcome Message | 21 |
| 16 | Welcome Templates & Media | 22, 23 |
| 17 | Number Extraction | 24 |
| 18 | Active Number Filter | 26 |
| 19 | Export Engine | 20, 25 |
| 20 | Auto Tagging | 27, 28, 29 |
| 21 | Channel Management | 1, 2 |
| 22 | Channel Auto Post & Analytics | 3, 4 |
| 23 | Error Reporting | 17 |
| 24 | Failed Message Retry | 18 |
| 25 | Real-time Notifications | 19 |
| 26 | REST API & Auth | 33 |
| 27 | Web Dashboard | 33 |
| 28 | Contact Management (vCard) | 37 |
| 29 | Opt-out & Compliance | — (new, compliance) |
| 30 | Process Mgmt & Deployment | 32 |
| 31 | Multi-Device Orchestration | 35 |
| 32 | Observability | — (new, ops) |
| 33 | Configuration Management | — (new, ops) |
| 34 | Quality Baseline | — (new, quality) |
