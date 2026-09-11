<?php

declare(strict_types=1);

namespace App\Services\Abuse;

use App\Enums\AbuseSignal;
use App\Enums\AbuseVector;
use App\Enums\GuardAction;
use App\Exceptions\Security\SessionKilledException;
use App\Models\AbuseEvent;
use App\Models\Builders\TenantScopedBuilder;
use App\Services\Audit\AuditService;
use App\Support\Cache\VersionedCache;
use Illuminate\Support\Carbon;

/**
 * The per-session kill-switch (design § Abuse / anti-fraud: *"kill-switch for
 * offending sessions"*; design § Admin Panel row 26 "ToS enforcement … offending
 * session kill-switch"; Req 32.7 / NFR3).
 *
 * ```php
 * $killSwitch->kill($sessionId, 'ToS: bulk unsolicited messaging', seconds: null);  // operator flip
 * $killSwitch->isKilled($sessionId);        // cheap, cached — safe on the send path
 * $killSwitch->assertUsable($sessionId);    // typed refusal + recorded attempt
 * $killSwitch->release($sessionId, 'reviewed: false positive');
 * ```
 *
 * ## Where the state lives, and why not in a table of its own
 *
 * The switch is **derived from `abuse_events`**: engaging it appends a
 * `SESSION_RISK`/`KILL_SWITCH_ENGAGED` row, releasing it appends
 * `KILL_SWITCH_RELEASED`, and the effective state is the most recent of the two. Three
 * properties fall out of that, all of which a dedicated `session_kill_switches` table
 * would have to re-earn:
 *
 * - **durable, and append-only.** A cache-only kill-switch is lifted by a cache flush,
 *   an eviction, or a Redis restart — i.e. it fails *open*, which for a control whose
 *   whole job is to stop a session that is getting the tenant banned is the wrong
 *   direction. Here the durable record is in an append-only table, so not even a flip
 *   can be edited away afterwards.
 * - **explainable.** Every flip carries its reason, its actor (via the parallel
 *   `audit_logs` entry), and its evidence, and the *history* of flips is readable — "this
 *   session has been killed four times this month" is a query, not an inference.
 * - **no new dependency.** `sessions_wa` does not exist yet (task 6.x), so a foreign-key
 *   table could not be built now; the switch keys on an opaque session id and works the
 *   day sessions arrive.
 *
 * The cache is a *fast path* over that record, not the record: a miss re-reads the
 * table, so losing the cache costs one indexed query and changes no answer.
 *
 * ## Cheap enough to check everywhere it matters
 *
 * `isKilled()` is one versioned-cache read on the hot path, and the guardrail calls it
 * on **every** inspection (`LayeredGuardrail::inspectInput`) — which is the point of
 * design's kill-switch: one that is only consulted by an admin screen is decoration.
 *
 * The inbound path is the one that exists today. The outbound one is `assertUsable()`,
 * and the tasks that build a send call it: the anti-ban send gate (task 9.6) and the
 * channel router (task 8.x), both of which already have the session in hand. It is a
 * *typed refusal* rather than a boolean precisely so those call sites cannot treat a
 * killed session as an ordinary "nothing to do".
 *
 * The
 * cached value is a plain integer (`0` = live, `-1` = killed indefinitely, otherwise the
 * epoch second the kill expires) so it survives deploys and stores that cannot hold
 * objects.
 *
 * ## Bounded automatic kills
 *
 * `killForBurst()` is the automatic arm: repeated blocked messages in one conversation
 * trip it, and such a kill always carries an expiry
 * (`wa.security.guardrail.conversation.auto_kill_seconds`), because a heuristic that can
 * silence a paying tenant's session **for ever** is a denial-of-service an attacker can
 * aim at that tenant. Only an operator flip may be indefinite.
 */
final class SessionKillSwitch
{
    /**
     * Cached "not killed".
     */
    private const int LIVE = 0;

    /**
     * Cached "killed, no expiry".
     */
    private const int INDEFINITE = -1;

    public function __construct(
        private readonly AbuseRecorder $recorder,
        private readonly AuditService $audit,
        private readonly VersionedCache $cache,
        private readonly int $attemptRecordSeconds = 300,
    ) {}

    /**
     * Whether `$sessionKey` may not be used right now.
     */
    public function isKilled(string $sessionKey): bool
    {
        if ($sessionKey === '') {
            return false;
        }

        $until = $this->until($sessionKey);

        if ($until === self::LIVE) {
            return false;
        }

        if ($until === self::INDEFINITE) {
            return true;
        }

        if ($until > Carbon::now()->getTimestamp()) {
            return true;
        }

        // Expired: collapse the cached expiry to "live" so the next check is a plain hit
        // rather than an arithmetic near-miss for the rest of the TTL.
        $this->cache->put($this->key($sessionKey), self::LIVE);

        return false;
    }

    /**
     * When the current kill lifts: `null` when the session is live or the kill is
     * indefinite. For panels and for the refusal message.
     */
    public function killedUntil(string $sessionKey): ?Carbon
    {
        $until = $this->until($sessionKey);

        return $until > 0 ? Carbon::createFromTimestamp($until) : null;
    }

    /**
     * Refuse to proceed when the session is killed — the guard a send, a flow step, or
     * an inspection calls.
     *
     * The refused attempt is itself recorded (throttled to one row per session per
     * `attemptRecordSeconds`, so a campaign hammering a killed session cannot flood the
     * trail), because "the kill-switch is being hit" is what tells an operator the flip
     * is doing something.
     *
     * @throws SessionKilledException
     */
    public function assertUsable(string $sessionKey, ?GuardContext $context = null): void
    {
        if (! $this->isKilled($sessionKey)) {
            return;
        }

        $this->recordAttempt($sessionKey, $context);

        throw SessionKilledException::forSession($sessionKey);
    }

    /**
     * Engage the switch.
     *
     * @param  int|null  $seconds  bounded kill; null is indefinite and only an operator decision
     * @param  array<string, mixed>  $evidence  what justified it — counters, thresholds, rule ids
     */
    public function kill(string $sessionKey, string $reason, ?int $seconds = null, array $evidence = []): void
    {
        if ($sessionKey === '') {
            return;
        }

        $expiresAt = $seconds !== null && $seconds > 0 ? Carbon::now()->addSeconds($seconds) : null;

        $this->recorder->record(new AbuseEventDraft(
            vector: AbuseVector::SessionRisk,
            action: GuardAction::Block,
            signals: [AbuseSignal::KillSwitchEngaged],
            evidence: [...$evidence, 'reason' => $reason, 'expires_in_seconds' => $seconds],
            surface: 'session.kill_switch',
            sessionKey: $sessionKey,
            expiresAt: $expiresAt,
        ));

        // The flip is a privileged act, so it also goes to the hash-chained trail, where
        // tamper-*evidence* exists (`abuse_events` is append-only but not chained — see
        // its migration). Audit failures must not leave the switch un-engaged, so the
        // cache is written last but the audit write is not wrapped: an audit outage is a
        // platform-level failure the operator should see immediately, not one to hide
        // behind a half-applied kill.
        $this->audit->write('session.kill_switch.engaged', [
            'session' => $this->fingerprint($sessionKey),
            'reason' => $reason,
            'expires_at' => $expiresAt?->toIso8601String(),
            'evidence' => $evidence,
        ]);

        $this->cache->put($this->key($sessionKey), $expiresAt === null ? self::INDEFINITE : $expiresAt->getTimestamp());
    }

    /**
     * The automatic arm: a bounded kill from a burst of blocked messages.
     *
     * @param  array<string, mixed>  $evidence
     */
    public function killForBurst(string $sessionKey, int $seconds, array $evidence = []): void
    {
        $this->kill(
            $sessionKey,
            'automatic: repeated guardrail blocks in one conversation',
            max(1, $seconds),
            [...$evidence, 'automatic' => true],
        );
    }

    /**
     * Release the switch after review.
     */
    public function release(string $sessionKey, string $reason = 'reviewed'): void
    {
        if ($sessionKey === '') {
            return;
        }

        $this->recorder->record(new AbuseEventDraft(
            vector: AbuseVector::SessionRisk,
            action: GuardAction::Flag,
            signals: [AbuseSignal::KillSwitchReleased],
            evidence: ['reason' => $reason],
            surface: 'session.kill_switch',
            sessionKey: $sessionKey,
        ));

        $this->audit->write('session.kill_switch.released', [
            'session' => $this->fingerprint($sessionKey),
            'reason' => $reason,
        ]);

        $this->cache->put($this->key($sessionKey), self::LIVE);
    }

    /**
     * The kill-switch history of one session, newest first — the operator view, and the
     * proof that a released switch leaves its record behind.
     *
     * @return list<AbuseEvent>
     */
    public function history(string $sessionKey, int $limit = 20): array
    {
        return $this->flips($sessionKey)->limit(max(1, $limit))->get()->all();
    }

    /*
    |--------------------------------------------------------------------------
    | State resolution
    |--------------------------------------------------------------------------
    */

    /**
     * The cached (or freshly resolved) expiry marker for a session.
     */
    private function until(string $sessionKey): int
    {
        if ($sessionKey === '') {
            return self::LIVE;
        }

        $cached = $this->cache->get($this->key($sessionKey));

        if (is_int($cached)) {
            return $cached;
        }

        $resolved = $this->resolveFromEvents($sessionKey);

        $this->cache->put($this->key($sessionKey), $resolved);

        return $resolved;
    }

    /**
     * Read the durable answer: the most recent flip for this session decides.
     */
    private function resolveFromEvents(string $sessionKey): int
    {
        $latest = $this->flips($sessionKey)->first();

        if (! $latest instanceof AbuseEvent || ! $latest->isActiveKill()) {
            return self::LIVE;
        }

        return $latest->expires_at === null ? self::INDEFINITE : $latest->expires_at->getTimestamp();
    }

    /**
     * The engage/release rows for one session, newest first.
     *
     * `withoutTenantScope()` is the sanctioned, greppable bypass and is correct here for
     * the same reason the outbox relay uses it: the kill-switch is consulted by workers
     * that have not bound a tenant yet (dispatch, relay, webhook intake), a session id is
     * globally unique, and the only thing this query returns about another tenant's row
     * is *that a session is stopped* — which is the one fact the caller must not be
     * allowed to miss. Failing closed with `MissingTenantContextException` here would
     * mean an unbound worker could not tell a killed session from a live one, and would
     * therefore send.
     *
     * @return TenantScopedBuilder<AbuseEvent>
     */
    private function flips(string $sessionKey): TenantScopedBuilder
    {
        return AbuseEvent::withoutTenantScope()
            ->where('session_key', $sessionKey)
            ->where('vector', AbuseVector::SessionRisk->value)
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /*
    |--------------------------------------------------------------------------
    | Bits and pieces
    |--------------------------------------------------------------------------
    */

    /**
     * Record that something tried to use a killed session — at most once per
     * `attemptRecordSeconds`.
     *
     * The throttle compares a stored timestamp rather than relying on a cache TTL,
     * because the cache namespace has one TTL for every entry in it. Losing the entry
     * early only makes the next attempt record sooner, which is the safe direction.
     */
    private function recordAttempt(string $sessionKey, ?GuardContext $context): void
    {
        $throttleKey = 'attempt:'.$sessionKey;
        $now = Carbon::now()->getTimestamp();
        $last = $this->cache->get($throttleKey);

        if (is_int($last) && $now - $last < $this->attemptRecordSeconds) {
            return;
        }

        $this->cache->put($throttleKey, $now);

        $this->recorder->record(new AbuseEventDraft(
            vector: AbuseVector::SessionRisk,
            action: GuardAction::Block,
            signals: [AbuseSignal::SessionKilled],
            evidence: [
                'throttled_for_seconds' => $this->attemptRecordSeconds,
                'killed_until' => $this->killedUntil($sessionKey)?->toIso8601String(),
            ],
            surface: $context === null ? 'session.kill_switch' : ($context->surface ?? 'session.kill_switch'),
            sessionKey: $sessionKey,
            conversationKey: $context?->conversationKey,
        ));
    }

    private function key(string $sessionKey): string
    {
        return 'session:'.$sessionKey;
    }

    /**
     * Session ids are opaque, but they are also credentials-adjacent (they name a
     * WhatsApp connection), so the audit payload carries a digest rather than the id.
     */
    private function fingerprint(string $sessionKey): string
    {
        return substr(hash('sha256', $sessionKey), 0, 32);
    }
}
