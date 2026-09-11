<?php

declare(strict_types=1);

namespace App\Services\Bridge;

/**
 * One answer from the protocol's `onWhatsApp` lookup: does this number have a WhatsApp
 * account, and if so, under which JID (Req 3.1 / A3; the active-number filter of Req 6.x).
 *
 * ## Why "unknown" is a third state and not a `false`
 *
 * A lookup can come back *unanswered* — the bridge returned a row for a number without
 * saying either way, which happens when the protocol rate-limits a batch part-way through.
 * Collapsing that into "not on WhatsApp" would let a rate limit be recorded as a permanent
 * fact about somebody's phone number: the contact would be marked inactive and skipped by
 * every later campaign, and nothing would ever re-check it.
 *
 * So `exists` is nullable, `isUnknown()` names the state, and callers that persist a result
 * (the contact `wa_status` writer of a later phase) are expected to write nothing for it and
 * let the number be re-checked.
 */
final readonly class NumberCheck
{
    /**
     * @param  string  $number  the number as asked, in the caller's own spelling
     * @param  bool|null  $exists  true/false when the protocol answered, null when it did not
     * @param  string|null  $jid  the account's JID, only ever present when `exists` is true
     */
    public function __construct(
        public string $number,
        public ?bool $exists,
        public ?string $jid = null,
    ) {}

    /**
     * The number has a WhatsApp account and can be addressed.
     */
    public function isOnWhatsApp(): bool
    {
        return $this->exists === true;
    }

    /**
     * The protocol declined to answer for this number — a transient state, never a fact
     * about the number.
     */
    public function isUnknown(): bool
    {
        return $this->exists === null;
    }

    /**
     * Read one entry of a decoded `numbers/check` response.
     *
     * A row whose `exists` is absent stays unknown, and a row claiming `exists: true`
     * without a JID also stays unknown: the JID *is* the address, so an existence claim
     * nothing can be sent to is not a usable answer.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(string $number, array $payload): self
    {
        $exists = BridgeWire::boolOrNull($payload['exists'] ?? null);
        $jid = BridgeWire::stringOrNull($payload['jid'] ?? null);

        if ($exists === true && $jid === null) {
            return new self($number, null);
        }

        return new self($number, $exists, $exists === true ? $jid : null);
    }
}
