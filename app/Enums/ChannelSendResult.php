<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What became of one attempt to send through a channel driver — the outcome recorded in
 * `channel_send_log.result` (design § Channel Mode data model:
 * `result(SENT|BLOCKED|FAILED|FAILED_OVER)`; § Error handling, row *"Unsupported op for
 * mode"*).
 *
 * The four values are two axes, and keeping them apart is the point of the column:
 *
 * | Result | Did the platform call the provider? | Who decided? |
 * |---|:---:|---|
 * | `SENT` | yes, and it was accepted | the provider |
 * | `BLOCKED` | **no** | the platform's own gate — capability, template/window, anti-ban, opt-out |
 * | `FAILED` | yes, and it failed | the provider or the network |
 * | `FAILED_OVER` | yes, and it failed — then another mode was tried | the failover chain (task 8.5) |
 *
 * `BLOCKED` is the one the audit exists for. Req 8.3 requires an unsupported operation to
 * be refused *before any driver call*, with no side effect — which means the only evidence
 * it ever happened is this row. A schema that recorded merely "not sent" could not
 * distinguish a capability refusal from a provider outage, and Properties 21 and 26 are
 * precisely about that distinction.
 */
enum ChannelSendResult: string
{
    /** Handed to the provider and accepted. */
    case Sent = 'SENT';

    /** Refused by a platform gate before any provider call — no side effect. */
    case Blocked = 'BLOCKED';

    /** Attempted and failed, with no further mode to try. */
    case Failed = 'FAILED';

    /** Attempted, failed, and the dispatch moved on to the next mode in the chain. */
    case FailedOver = 'FAILED_OVER';

    /**
     * Whether the provider was contacted at all.
     *
     * The property Req 8.3 / Property 26 assert on: a blocked operation must leave no
     * trace anywhere but this log.
     */
    public function reachedProvider(): bool
    {
        return match ($this) {
            self::Sent, self::Failed, self::FailedOver => true,
            self::Blocked => false,
        };
    }

    /**
     * Whether the message was accepted for delivery.
     */
    public function isSuccess(): bool
    {
        return match ($this) {
            self::Sent => true,
            self::Blocked, self::Failed, self::FailedOver => false,
        };
    }

    /**
     * Whether this row is a step in a failover chain rather than a terminal outcome for
     * the message.
     *
     * A `FAILED_OVER` row is always followed by another row for the same dispatch — on the
     * next mode — so a report counting failures must not count it as a lost message
     * (Req 8.10, 8.11 / A8).
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Sent, self::Blocked, self::Failed => true,
            self::FailedOver => false,
        };
    }
}
