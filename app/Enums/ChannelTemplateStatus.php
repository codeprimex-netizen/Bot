<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Approval state of one entry in the `cloud_api_templates` registry, as reported by the
 * provider (Req 8.12 / A8; design § Channel Mode data model:
 * `status(PENDING|APPROVED|REJECTED|PAUSED)`).
 *
 * The states are Meta's, mirrored: a template is submitted, reviewed, and can later be
 * paused for poor quality. The platform does not decide them — task 8.4 syncs them — and
 * exactly one of them permits a send, which is the whole reason the column exists.
 */
enum ChannelTemplateStatus: string
{
    /** Submitted, awaiting review. */
    case Pending = 'PENDING';

    /** Approved: the only state in which a template may be sent. */
    case Approved = 'APPROVED';

    /** Rejected by review. Must be edited and resubmitted. */
    case Rejected = 'REJECTED';

    /** Paused by the provider for quality reasons — recoverable without resubmission. */
    case Paused = 'PAUSED';

    /**
     * The state a locally created template starts in, before the provider has spoken.
     */
    public static function default(): self
    {
        return self::Pending;
    }

    /**
     * Whether a message may be sent with this template.
     *
     * The predicate task 8.4's `TemplateRequiredException` check reads: outside the
     * 24-hour session window, free-form content is blocked unless an **approved** template
     * is used. `PAUSED` is deliberately not sendable — the provider has stopped accepting
     * it, so attempting the send would fail remotely instead of locally.
     */
    public function isSendable(): bool
    {
        return match ($this) {
            self::Approved => true,
            self::Pending, self::Rejected, self::Paused => false,
        };
    }

    /**
     * Whether the provider may still change this state on its own.
     *
     * `PENDING` (review in progress) and `APPROVED`/`PAUSED` (quality rating moves both
     * ways) can change under the platform's feet, so a sync keeps re-reading them.
     * `REJECTED` only changes when the tenant resubmits, which creates a new submission.
     */
    public function isSyncable(): bool
    {
        return match ($this) {
            self::Pending, self::Approved, self::Paused => true,
            self::Rejected => false,
        };
    }
}
