<?php

declare(strict_types=1);

namespace App\Services\Abuse;

use App\Models\AbuseEvent;
use App\Services\Audit\AuditCorrelation;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Writes the abuse trail to `abuse_events` (Req 13.8 / B4; Req 32.7 / NFR3).
 *
 * ## Why the insert goes through the query builder
 *
 * Two reasons, the same two `HashChainAuditService` has:
 *
 * 1. `AbuseEvent` refuses model writes outright, because a row must be built exactly
 *    one way;
 * 2. rows written on the **signup path have no tenant** — registration is anonymous —
 *    and `BelongsToTenant`'s `creating` hook cannot write a null `tenant_id`: it raises
 *    `MissingTenantContextException` instead. Going through the model would therefore
 *    make anti-fraud events on the one path that needs them the most impossible to
 *    write, or (if a tenant were invented for them) attribute a stranger's signup
 *    attempt to an unrelated tenant, which is a cross-tenant data leak.
 *
 * The tenant is taken from `TenantContext` when one is bound and left null otherwise.
 * Reads stay tenant-scoped through the model, so a null-tenant row is visible to the
 * platform and to no tenant.
 *
 * ## Failure handling: best-effort insert, mandatory escalation
 *
 * `record()` never throws (see `AbuseRecorder`). When the insert fails, the event is
 * written to the log at `critical` with every field except the content — the log
 * pipeline is a different failure domain from the OLTP write, so the evidence survives
 * a table-level problem and the operator gets an alertable signal instead of a silent
 * hole. The design rule *"suppression must be recorded, never silent"* is satisfied by
 * that pair: the request is never failed, and the event is never lost without a trace.
 */
final class DatabaseAbuseRecorder implements AbuseRecorder
{
    /**
     * Log message the escalation uses. A constant so an alert rule can match on it.
     */
    public const string FAILURE_EVENT = 'abuse_event.record_failed';

    public function __construct(
        private readonly TenantContext $context,
        private readonly ConnectionInterface $connection,
    ) {}

    public function record(AbuseEventDraft $draft): ?AbuseEvent
    {
        $row = $this->row($draft);

        try {
            $this->connection->table((new AbuseEvent)->getTable())->insert($row);
        } catch (Throwable $exception) {
            // Never rethrown: the caller is in the middle of protecting a request.
            Log::critical(self::FAILURE_EVENT, [
                'reason' => $exception::class,
                // The exception message may quote SQL, which may quote the row — but the
                // row itself carries no content, only hashes and counters.
                'event' => $row,
            ]);

            return null;
        }

        return $this->hydrate($row);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(AbuseEventDraft $draft): array
    {
        $correlation = AuditCorrelation::capture();

        return [
            'id' => (string) Str::ulid(),
            'tenant_id' => $this->context->currentId(),
            'vector' => $draft->vector->value,
            'action' => $draft->action->value,
            'signals' => $this->encode($draft->signalValues()),
            'evidence' => $this->encode($draft->evidence),
            'surface' => $draft->surface,
            'session_key' => $draft->sessionKey,
            'conversation_key' => $draft->conversationKey,
            'subject_hash' => $draft->subjectHash,
            'content_hash' => $draft->contentHash,
            'content_length' => $draft->contentLength,
            'ip_address' => $correlation->ipAddress,
            'user_agent' => $correlation->userAgent,
            'request_id' => $correlation->requestId,
            'trace_id' => $correlation->traceId,
            'expires_at' => $draft->expiresAt?->format('Y-m-d H:i:s'),
            'created_at' => Carbon::now()->format('Y-m-d H:i:s.u'),
        ];
    }

    /**
     * JSON, with a guaranteed-valid fallback: a value that cannot be encoded must not
     * cost the platform the whole event, so the row records *that* instead.
     *
     * @param  array<array-key, mixed>  $value
     */
    private function encode(array $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return is_string($json) ? $json : '{"evidence_unencodable":true}';
    }

    /**
     * The stored row as a model instance, without going through `create()`.
     *
     * @param  array<string, mixed>  $row
     */
    private function hydrate(array $row): AbuseEvent
    {
        $event = new AbuseEvent;
        $event->setRawAttributes($row, sync: true);
        $event->exists = true;

        return $event;
    }
}
