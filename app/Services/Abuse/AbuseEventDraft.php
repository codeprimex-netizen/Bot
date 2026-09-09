<?php

declare(strict_types=1);

namespace App\Services\Abuse;

use App\Enums\AbuseSignal;
use App\Enums\AbuseVector;
use App\Enums\GuardAction;
use Illuminate\Support\Carbon;

/**
 * One row of the abuse trail, before it is written (Req 13.8 / B4).
 *
 * A draft rather than a model instance because `AbuseEvent` refuses direct writes: the
 * insert happens in `AbuseRecorder`, which owns the "must never fail the request it is
 * protecting" policy and the pre-tenant `tenant_id = NULL` case. Assembling the row as
 * a value first also means the *only* thing a detector has to get right is the
 * evidence — the tenant, the correlation ids, and the timestamp are the recorder's job.
 *
 * Nothing here may carry message content. `contentHash`/`contentLength` describe the
 * inspected text; `subjectHash` describes an identity as a keyed digest
 * (`IdentityDigest`); `evidence` holds counters, thresholds, and rule ids.
 */
final readonly class AbuseEventDraft
{
    /**
     * @param  list<AbuseSignal>  $signals
     * @param  array<string, mixed>  $evidence  counters, thresholds, rule ids — never text
     * @param  Carbon|null  $expiresAt  for a kill-switch engagement: when it lifts itself
     */
    public function __construct(
        public AbuseVector $vector,
        public GuardAction $action,
        public array $signals,
        public array $evidence,
        public string $surface,
        public ?string $sessionKey = null,
        public ?string $conversationKey = null,
        public ?string $subjectHash = null,
        public ?string $contentHash = null,
        public ?int $contentLength = null,
        public ?Carbon $expiresAt = null,
    ) {}

    /**
     * The row a guardrail verdict implies.
     *
     * @param  array<string, mixed>  $extraEvidence
     */
    public static function fromVerdict(GuardVerdict $verdict, GuardContext $context, array $extraEvidence = []): self
    {
        return new self(
            vector: $verdict->vector,
            action: $verdict->action,
            signals: $verdict->signals,
            evidence: [...$verdict->toArray(), ...$extraEvidence],
            surface: $verdict->surface,
            sessionKey: $context->sessionKey,
            conversationKey: $context->conversationKey,
            contentHash: $verdict->contentHash,
            contentLength: $verdict->contentLength,
        );
    }

    /**
     * @return list<string>
     */
    public function signalValues(): array
    {
        return array_values(array_map(
            static fn (AbuseSignal $signal): string => $signal->value,
            $this->signals,
        ));
    }
}
