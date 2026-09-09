<?php

declare(strict_types=1);

namespace App\Services\Abuse;

use App\Models\AbuseEvent;

/**
 * The one way anything reaches the `abuse_events` table (Req 13.8 / B4 — *"record an
 * entry in the abuse-events store"*; Req 32.7 / NFR3).
 *
 * ## The contract that makes this an interface
 *
 * **Recording must never fail the request it is protecting.** A guardrail that refuses
 * an injection attempt and *then* throws because the abuse table was momentarily
 * unavailable has converted a successful defence into an outage — and, worse, into an
 * outage an attacker can trigger deliberately. So `record()` does not throw: it returns
 * the stored row, or `null` when the row could not be written.
 *
 * **And it must not be silently dropped.** The two obligations look contradictory and
 * are not: an implementation that cannot insert must escalate through a channel that is
 * not the request — `DatabaseAbuseRecorder` writes a `critical` log line carrying the
 * whole (content-free) event, so the evidence survives in the log pipeline and the
 * failure is alertable. "Swallowed" would mean the platform loses both the event and the
 * knowledge that it lost it; that is the failure mode this contract exists to forbid.
 *
 * Callers should treat `null` as "recorded elsewhere, keep going", never as "no abuse
 * happened".
 */
interface AbuseRecorder
{
    /**
     * Append one abuse event.
     *
     * @return AbuseEvent|null the stored row, or null when it could not be stored (never throws)
     */
    public function record(AbuseEventDraft $draft): ?AbuseEvent;
}
