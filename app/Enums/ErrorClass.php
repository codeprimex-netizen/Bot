<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What *kind* of failure this is — the one thing every retry decision, log line, and
 * error-dashboard row keys off (design.md § Error Handling; ROADMAP Phase 23
 * "`ErrorReporter` + classification"; Req 7.1 / A7; Req 31.1 / NFR2).
 *
 * A `Throwable` says what broke; an `ErrorClass` says what to *do* about it. Deriving the
 * second from the first is `ErrorClassifier`'s job and happens exactly once per failure;
 * everything downstream — `RetryPolicy`, the `err_class` field of the structured log
 * schema (design.md § Observability), the tenant's error dashboard, the retry matrix in
 * config — speaks in these values and never re-parses an exception.
 *
 * ## Where the case list comes from
 *
 * The nine names of ROADMAP Phase 23 — `AUTH`, `RATE_LIMIT`, `NOT_ON_WHATSAPP`, `MEDIA`,
 * `NETWORK`, `BRIDGE`, `PERMISSION`, `VALIDATION`, `UNKNOWN` — carried forward from the
 * single-tenant engine, plus the three rows design.md's retry matrix adds that none of
 * those nine can express: `TIMEOUT` (its own attempt budget and a shorter cap), `QUOTA`
 * (a defer measured by a plan period, not by a backoff), and `CIRCUIT_OPEN` (a fast-fail
 * that hands over to a fallback chain instead of retrying). Twelve classes, and every
 * failure the platform can produce lands in one of them — `UNKNOWN` exists so that
 * "unclassified" is a decision with a budget rather than a hole.
 *
 * ## The retryable / non-retryable line is structural, not configurable
 *
 * `disposition()` and `backoff()` live in code because they are *meaning*, not tuning: an
 * expired credential does not fix itself, a number that is not on WhatsApp does not join,
 * a plan does not grow a feature while a job sleeps. Making those retryable by
 * configuration would not add flexibility, it would add a way to turn a clean 4xx into a
 * silent five-minute stall. What is genuinely operational — *how many* attempts a
 * retryable class gets, and the base/cap of its window — is config
 * (`wa.reliability.retry`, read by `RetryMatrix`).
 *
 * So: config can shrink a retry budget to nothing, and cannot grow one where the platform
 * says there is nothing to wait for.
 *
 * | Class | Disposition | Backoff | Attempts (config default) | Source |
 * |---|---|---|---|---|
 * | `NETWORK` | retry | exp + full jitter | 5 | design "Transient network / 5xx"; ROADMAP `NETWORK 5` |
 * | `BRIDGE` | retry | exp + full jitter | 5 | ROADMAP `BRIDGE 5` |
 * | `TIMEOUT` | retry | exp + full jitter, shorter cap | 3 | design "Timeout … shorter cap" |
 * | `MEDIA` | retry | exp + full jitter | 3 | ROADMAP `MEDIA` (a transfer, see below) |
 * | `UNKNOWN` | retry | exp + full jitter | 3 | the documented default |
 * | `RATE_LIMIT` | **defer** | honour `Retry-After`, else exp | 8 | design "Rate-limited (429 from provider)" |
 * | `QUOTA` | **defer** | period reset | unlimited | design "Quota exceeded … never dropped" (Req 31.1) |
 * | `AUTH` | fail fast | — | 0 | design "Auth / signature invalid"; ROADMAP `AUTH` |
 * | `PERMISSION` | fail fast | — | 0 | ROADMAP `PERMISSION`; design 403 rows |
 * | `VALIDATION` | fail fast | — | 0 | design "Validation / 4xx" |
 * | `NOT_ON_WHATSAPP` | fail fast | — | 0 | ROADMAP `NOT_ON_WHATSAPP 0` |
 * | `CIRCUIT_OPEN` | fail fast | — | 0 | design "Circuit open … fallback chain handles it" |
 *
 * Two of those rows deserve their reasoning written down:
 *
 * - **`RATE_LIMIT` defers rather than retries, with 8 attempts.** design.md's matrix says
 *   *"Yes (defer) | 8 | honor `Retry-After` else exp"*; ROADMAP Phase 23 recorded the
 *   engine's older `RATE_LIMIT 3` *with a cool-down*. The design's numbers win — it is the
 *   spec of record for this platform, and it is the row that has a `Retry-After` to honour
 *   — and the engine's "cool-down" survives as a larger `base_ms` for this class (so the
 *   first window is a second, not a quarter of one) plus the deferring disposition, which
 *   keeps a provider 429 from eating a retry budget meant for real faults.
 * - **`NETWORK` is the transient-infrastructure bucket, not literally sockets.** A 503, a
 *   connection reset, and a lock the platform lost the race for (`AuditChainBusyException`)
 *   all behave identically: nothing is wrong with the request, the condition clears on its
 *   own, and the right response is a jittered re-attempt. Splitting them would give three
 *   classes with one policy.
 *
 * `MEDIA` covers a media **transfer** that failed — a download from the bridge, an upload
 * to object storage, a transcode. A media payload that was *refused*
 * (`MediaRejectedException`: wrong sniffed MIME, over the byte cap) is `VALIDATION`: those
 * bytes will not become acceptable on the fourth attempt.
 *
 * ## Classes with no exception yet
 *
 * `AUTH`, `BRIDGE`, `NOT_ON_WHATSAPP`, `MEDIA` and `CIRCUIT_OPEN` name failures whose
 * exceptions arrive with later phases (the bridge client and `CircuitOpenException` in
 * task 3.2/9.x, the Channel Mode drivers in tasks 6.x, media in 8.x). They are listed here
 * anyway — not as stubs, but because the matrix is *data*: a class must have a policy
 * before the code that raises it exists, or the first driver to hit a 429 would fall into
 * `UNKNOWN` and be retried on the wrong budget. `ErrorClassifier` is the seam those phases
 * register their own exceptions through, with no edit to this enum.
 */
enum ErrorClass: string
{
    /** Credentials/signature rejected: expired token, bad HMAC, unpaired session. */
    case Auth = 'AUTH';

    /** A provider said "too fast" — a 429 with, usually, a `Retry-After`. */
    case RateLimit = 'RATE_LIMIT';

    /** The recipient is not a WhatsApp user; there is nothing to send to. */
    case NotOnWhatsApp = 'NOT_ON_WHATSAPP';

    /** A media transfer failed (download, upload, transcode). */
    case Media = 'MEDIA';

    /** Transient infrastructure: connection reset, 5xx, lock contention. */
    case Network = 'NETWORK';

    /** The WA bridge itself: not connected, session dropped, bridge 5xx. */
    case Bridge = 'BRIDGE';

    /** The actor may not do this: cross-tenant, suspended tenant, plan gate. */
    case Permission = 'PERMISSION';

    /** The request is malformed or the state is impossible; a 4xx we produced. */
    case Validation = 'VALIDATION';

    /** A call did not answer in time. */
    case Timeout = 'TIMEOUT';

    /** The tenant's plan allowance is spent for this period (Req 31.1). */
    case Quota = 'QUOTA';

    /** A circuit breaker is OPEN; the fallback chain takes over (Req 31.3). */
    case CircuitOpen = 'CIRCUIT_OPEN';

    /** Unclassified — retried on a deliberately modest budget. */
    case Unknown = 'UNKNOWN';

    /**
     * What a caller does with a failure of this class.
     *
     * Structural: no configuration can turn a fail-fast class into a retrying one.
     */
    public function disposition(): RetryDisposition
    {
        return match ($this) {
            self::Network, self::Bridge, self::Timeout, self::Media, self::Unknown => RetryDisposition::Retry,
            self::RateLimit, self::Quota => RetryDisposition::Defer,
            self::Auth, self::Permission, self::Validation,
            self::NotOnWhatsApp, self::CircuitOpen => RetryDisposition::FailFast,
        };
    }

    /**
     * How the wait before the next attempt is arrived at.
     */
    public function backoff(): BackoffShape
    {
        return match ($this) {
            self::Network, self::Bridge, self::Timeout, self::Media, self::Unknown => BackoffShape::ExponentialFullJitter,
            self::RateLimit => BackoffShape::RetryAfterElseExponential,
            self::Quota => BackoffShape::PeriodReset,
            self::Auth, self::Permission, self::Validation,
            self::NotOnWhatsApp, self::CircuitOpen => BackoffShape::None,
        };
    }

    /**
     * Whether a failure of this class can ever be attempted again.
     *
     * The question `RetryMatrix` asks before it looks at config at all: a `false` here
     * means the configured attempt count is irrelevant, not merely overridden.
     */
    public function isRetryableByNature(): bool
    {
        return $this->disposition()->keepsWork();
    }

    /**
     * Whether the wait for this class is named by somebody else (a `Retry-After`, a
     * period reset) rather than computed.
     */
    public function isDeferrable(): bool
    {
        return $this->disposition()->isDeferred();
    }

    /**
     * Human-readable label for the error dashboard (Req 7.1 / A7) and operator views.
     */
    public function label(): string
    {
        return match ($this) {
            self::Auth => 'Authentication rejected',
            self::RateLimit => 'Rate limited by provider',
            self::NotOnWhatsApp => 'Not a WhatsApp number',
            self::Media => 'Media transfer failed',
            self::Network => 'Transient network or dependency failure',
            self::Bridge => 'WA bridge unavailable',
            self::Permission => 'Not permitted',
            self::Validation => 'Invalid request',
            self::Timeout => 'Timed out',
            self::Quota => 'Plan allowance exhausted',
            self::CircuitOpen => 'Circuit breaker open',
            self::Unknown => 'Unclassified error',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * The class named by $value, or null when it names none.
     *
     * Used by `RetryMatrix` and `CompositeErrorClassifier` to read config without a
     * malformed key aborting the whole matrix: an unrecognised name is reported by the
     * caller, never guessed at.
     */
    public static function tryFromName(?string $value): ?self
    {
        if ($value === null) {
            return null;
        }

        return self::tryFrom(strtoupper(trim($value)));
    }
}
