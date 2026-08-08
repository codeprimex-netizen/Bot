# WhatsApp Auto Messenger Bot

PHP + MySQL based WhatsApp automation platform — multi-session, bulk/scheduled messaging, group & channel management, welcome messages, number extraction, auto tagging, error reporting aur web dashboard.

**Deployment target:** `https://bot.getxtrra.in`

## Status

🚧 **Planning stage — no application code yet.**

- [ROADMAP.md](./ROADMAP.md) — 31 phases, 7 milestones, timeline
- [.kiro/specs/whatsapp-auto-messenger/requirements.md](./.kiro/specs/whatsapp-auto-messenger/requirements.md) — 36 requirements, EARS acceptance criteria
- [.kiro/specs/whatsapp-auto-messenger/design.md](./.kiro/specs/whatsapp-auto-messenger/design.md) — architecture, data model, API, security
- [.kiro/specs/whatsapp-auto-messenger/tasks.md](./.kiro/specs/whatsapp-auto-messenger/tasks.md) — ~130 implementation sub-tasks

## Stack

| Layer | Technology |
|---|---|
| Language | PHP 8.3 |
| Framework | Laravel 11 |
| Database | MySQL 8.0 |
| Queue | Laravel Queue — MySQL `jobs` table |
| Cache / Locks | Laravel Cache — MySQL `cache` table |
| Scheduler | Laravel Scheduler (one cron entry) |
| Frontend | Blade + Livewire 3 + Alpine.js + Tailwind |
| Real-time | Livewire polling (default), Laravel Reverb (optional) |
| Process supervision | Supervisor + nginx + PHP-FPM |
| Protocol bridge | Thin Node.js sidecar (see below) |

Redis optional hai — MySQL drivers default hain taaki deployment sirf PHP + MySQL pe chal jaye.

## Why there is one Node process

PHP-FPM WhatsApp ka persistent encrypted WebSocket hold nahi kar sakta, aur is protocol ka koi maintained pure-PHP implementation exist nahi karta. Meta ki official Cloud API in features ko cover nahi karti — usme group support nahi hai aur free-form messaging 24-hour window tak limited hai.

**Isliye:** 100% application logic PHP + MySQL mein hai. Ek thin Node "WA Bridge" sidecar (~600 lines) sirf protocol socket hold karta hai — koi business logic, koi database access, koi scheduling nahi. Woh `BridgeClient` PHP interface ke peeche hai, isliye replaceable hai.

Full reasoning aur pure-PHP alternative ka trade-off: [requirements.md](./.kiro/specs/whatsapp-auto-messenger/requirements.md#️-critical-architectural-constraint-please-read)

## Default Admin

| Field | Value |
|---|---|
| Username | `admin` |
| Password | `admin` |

⚠️ **Ye ek known, guessable credential pair hai aur domain public hai.** Isliye spec mein ye guard rails mandatory hain (Requirement 27 / Phase 25):

- First login pe forced password change
- Jab tak password change na ho, **bulk-send aur destructive operations blocked** rehte hain
- Dashboard pe persistent security warning banner
- Login throttling per-IP aur per-username with lockout
- Optional IP allowlist for the admin panel
- Idle session timeout aur every login attempt audit-logged

Deploy ke turant baad password change karna zaroori hai.

## Disclaimer

Bulk aur unsolicited messaging WhatsApp ke Terms of Service ke against hai aur number ban ho sakta hai. Yeh project **opt-in audience, apne owned groups/channels aur consented contacts** ke liye hai. Opt-out enforcement aur rate limits deliberately non-bypassable design kiye gaye hain. Use responsibly.
