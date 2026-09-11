<?php

declare(strict_types=1);

use App\Enums\AbuseVector;
use App\Enums\GuardAction;
use App\Support\Database\AppendOnlyTable;
use App\Support\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The abuse trail: one row per guardrail refusal, anti-fraud decision, and
 * kill-switch flip (Req 13.8 / B4 — "record an entry in the abuse-events store";
 * Req 32.7 / NFR3; design § "Abuse / anti-fraud").
 *
 * ## Append-only, following the `audit_logs` precedent — and where it stops
 *
 * Like `audit_logs` (task 4.3), this table is append-only and enforced by the same
 * three layers: `BEFORE UPDATE`/`BEFORE DELETE` triggers on both engines
 * (`AppendOnlyTable::guard`), the production `REVOKE UPDATE, DELETE` grants
 * (`AppendOnlyTable::revokeStatements`), and the model-level `AppendOnly` guard. An
 * abuse row is evidence about a suspected attacker, and "the attacker could edit the
 * record of the attack" is not a property a security control may have.
 *
 * It deliberately stops short of the audit trail's **hash chain**. The chain exists
 * because an audit row can be a company's only evidence of a privileged action, and
 * it costs a serialized append per chain (a lock plus `unique(chain_key, sequence)`).
 * Abuse events are written on the inbound message path — per message, at inbound
 * volume, by workers that must never contend with each other — and, crucially, an
 * abuse event must **never fail the request it is protecting**: it is written
 * best-effort. A chain whose appends may be abandoned is not a chain, so pretending
 * to have one here would be worse than not having one. Tamper-*resistance* is
 * therefore the append-only guards; the privileged flips that need
 * tamper-*evidence* — engaging and releasing a kill-switch — are additionally
 * written to `audit_logs`, where the chain is real.
 *
 * ## `tenant_id` is nullable, on purpose, and carries no foreign key
 *
 * Two independent reasons, the same two the audit trail has:
 *
 * 1. **Signup and OTP abuse happen before a tenant exists.** The registration path
 *    (design § User Panel, row 1) is anonymous: velocity and device/IP heuristics run
 *    with no tenant bound, so their rows have `tenant_id = NULL`. A tenant-scoped read
 *    (`where tenant_id = ?`) excludes `NULL` rows structurally, so one tenant can never
 *    see another's — or the platform's — abuse events.
 * 2. **No cascade into an append-only table.** SQLite fires triggers for cascaded
 *    deletes and MySQL does not, so a cascading foreign key would make the guards
 *    behave differently on the test engine than in production — the one thing a
 *    security control must never do. Abuse rows outlive the tenant they describe, and
 *    removing them is an explicit retention action (task 34.3) rather than a side
 *    effect.
 *
 * ## What is stored, and what is not
 *
 * Never the message. `content_hash` is a SHA-256 of the inspected text and
 * `content_length` its size — enough to recognise the same payload arriving again,
 * to correlate with `messages_inbound` once that table exists, and to prove two
 * events came from the same body, with nothing readable to leak. Identities on the
 * signup path are stored the same way: `subject_hash` is a keyed digest of the
 * normalized email/phone/device, never the value (design § Observability: "phone
 * numbers redacted, message bodies never logged").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('abuse_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // Nullable (pre-tenant signup/OTP events), no foreign key (see the class
            // docblock), and indexed leading with `tenant_id` + the abuse feed's
            // default ordering.
            TenantSchema::tenantId($table, ['created_at'], constrained: false)->nullable();

            /*
            |------------------------------------------------------------------
            | What happened
            |------------------------------------------------------------------
            */
            // Which row of design § "Abuse / anti-fraud" this is.
            $table->enum('vector', AbuseVector::values());

            // What the platform did about it: FLAG (recorded, allowed) or BLOCK
            // (refused/suppressed). ALLOW is never stored — see GuardAction::isRecordable().
            $table->enum('action', GuardAction::values());

            // The `AbuseSignal` values that produced the action, in detection order.
            // JSON rather than a child table: a row's signals are read as a set, always
            // together with the row, and never joined against.
            $table->json('signals');

            // Everything the decision was made from that is safe to keep: counter
            // values and their thresholds, window lengths, detector ids, the layer the
            // text arrived in. Never the text itself.
            $table->json('evidence');

            /*
            |------------------------------------------------------------------
            | What it was about
            |------------------------------------------------------------------
            */
            // Where the inspection happened: 'guardrail.input', 'guardrail.output',
            // 'signup', 'otp.request', 'otp.verify', 'session.kill_switch'.
            $table->string('surface', 64);

            // The session and conversation the text belonged to, as opaque keys rather
            // than foreign keys: `sessions_wa` and `conversations` arrive in later
            // phases (tasks 6.x, 12.x), and an abuse row must not wait for them — nor
            // be deleted with them.
            $table->string('session_key', 128)->nullable();
            $table->string('conversation_key', 128)->nullable();

            // Keyed digest of the identity the event is about (normalized email, phone,
            // or device fingerprint). Never the identity.
            $table->char('subject_hash', 64)->nullable();

            /*
            |------------------------------------------------------------------
            | The inspected text, as evidence only
            |------------------------------------------------------------------
            */
            $table->char('content_hash', 64)->nullable();
            $table->unsignedInteger('content_length')->nullable();

            /*
            |------------------------------------------------------------------
            | Where it came from (design § Observability correlation)
            |------------------------------------------------------------------
            */
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('request_id', 64)->nullable();
            $table->string('trace_id', 64)->nullable();

            /*
            |------------------------------------------------------------------
            | Kill-switch state, when this row is one
            |------------------------------------------------------------------
            | A `SESSION_RISK` row with signal `KILL_SWITCH_ENGAGED` *is* the durable
            | kill-switch: `SessionKillSwitch` resolves a session's state from the most
            | recent engage/release row and caches it. That is why the state needs no
            | table of its own — and why a cache flush cannot resurrect a killed
            | session, which a cache-only kill-switch would.
            |
            | `expires_at` bounds an automatic kill in time (a burst of blocked messages
            | trips it); NULL means indefinite, which only an operator flip sets.
            */
            $table->dateTime('expires_at')->nullable();

            // Microsecond precision, and no `updated_at`: the row is never updated.
            $table->dateTime('created_at', 6);

            /*
            |------------------------------------------------------------------
            | Indexes
            |------------------------------------------------------------------
            | Deliberately few — this table is insert-heavy on the inbound path.
            */

            // The kill-switch lookup: latest engage/release row for one session. Leads
            // with `session_key` because that read happens with no tenant filter of its
            // own (the scope adds one) and must be a single index seek.
            $table->index(['session_key', 'created_at'], 'abuse_events_session_index');

            // The admin abuse feed's filters, and the "same payload again?" lookup.
            $table->index(['vector', 'created_at'], 'abuse_events_vector_index');
            $table->index(['subject_hash', 'created_at'], 'abuse_events_subject_index');
            $table->index('created_at', 'abuse_events_created_at_index');
        });

        AppendOnlyTable::guard('abuse_events');
    }

    public function down(): void
    {
        AppendOnlyTable::unguard('abuse_events');

        Schema::dropIfExists('abuse_events');
    }
};
