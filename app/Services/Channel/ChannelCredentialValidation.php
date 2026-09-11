<?php

declare(strict_types=1);

namespace App\Services\Channel;

use App\Enums\BspProvider;
use App\Enums\ChannelMode;
use App\Models\ChannelCredential;
use Carbon\CarbonImmutable;

/**
 * What `ChannelCredentialValidator` established about one credential set — the value a
 * successful save, rotation or re-validation returns (Req 8.6, 8.13 / A8; design § Channel
 * Mode 2.7).
 *
 * ```php
 * $validation = $validator->save($tenant, ChannelMode::CloudApi, ['access_token' => $token]);
 *
 * $validation->credential->label;      // which set now serves
 * $validation->health->detail;         // what the provider said, scrubbed
 * $validation->isNumberLive();         // false ⇒ the provider is still verifying the number
 * $validation->retainedLabel;          // the set that was serving until this one was activated
 * ```
 *
 * ## Only an accepted outcome is a value
 *
 * There is no `rejected` state on this object and no `accepted` flag to check. A refused
 * rotation raises `ChannelCredentialException::rejected()`, for the reason
 * `RegistrationResult` gives about failures: this type describes *the state credentials are
 * now in*, and a caller that could forget to check a boolean would activate nothing and
 * report success. A panel or a command therefore either has a validation in hand — in which
 * case the set is stored, verified and serving — or is handling a typed exception.
 *
 * The one thing that is genuinely two-valued is **number registration**, which is why
 * `registration` is nullable and `isNumberLive()` exists. Credential validity and session
 * liveness are different facts: Meta can accept an access token for a number whose
 * verification it has not finished, and collapsing the two would either refuse a perfectly
 * good token or mark a session sendable whose first send the provider rejects
 * (`RegistrationResult` makes that argument in full). So the credentials are activated on the
 * strength of the health check, and the pending registration is reported here for task 9.1 to
 * act on.
 *
 * ## `retainedLabel` is the audit of the thing that did *not* happen
 *
 * Req 8.6's failure clause — retain the previous working credentials — is structural: a
 * rotation is validated before anything is written, so a refusal touches nothing. On the
 * *accepted* path the previous set is still not deleted; it is simply outranked, because
 * `ChannelCredentialStore::rowFor()` prefers the newest verified row. `retainedLabel` names
 * it, so a panel can offer "roll back to `default`" and an operator reading the audit trail
 * can see there is something to roll back to. It is `null` when this was the tenant's first
 * set for the mode, or when the same label was edited in place and so there is no second row.
 */
final readonly class ChannelCredentialValidation
{
    /**
     * @param  ChannelCredential  $credential  the stored set, now `ACTIVE` and `verified_at`-stamped
     * @param  ChannelHealth  $health  the driver's verdict that permitted the activation
     * @param  RegistrationResult|null  $registration  present only where the mode needed provider-side registration and a session was named
     * @param  string|null  $retainedLabel  the set that was serving before this one, and still exists
     * @param  bool  $firstSet  whether the tenant had no usable set for this mode before this call
     */
    public function __construct(
        public ChannelCredential $credential,
        public ChannelHealth $health,
        public ?RegistrationResult $registration = null,
        public ?string $retainedLabel = null,
        public bool $firstSet = false,
    ) {}

    public function mode(): ChannelMode
    {
        return $this->credential->mode;
    }

    public function provider(): ?BspProvider
    {
        return $this->credential->provider;
    }

    /**
     * Which named set now answers a send on this mode.
     */
    public function label(): string
    {
        return $this->credential->label;
    }

    /**
     * When the driver confirmed these credentials — the probe's own timestamp, which is what
     * was stamped on the row.
     */
    public function verifiedAt(): CarbonImmutable
    {
        return $this->health->checkedAt;
    }

    /**
     * Whether the provider's number registration finished, so a session on this mode may be
     * marked live (task 9.1).
     *
     * `false` when the mode needed registration and the provider is still verifying, and
     * `true` when registration was not part of this validation at all — the credentials are
     * usable either way, and a caller with no session in hand has nothing to mark live.
     */
    public function isNumberLive(): bool
    {
        return $this->registration === null || $this->registration->isLive();
    }

    /**
     * Whether the provider still has verification to finish.
     */
    public function isRegistrationPending(): bool
    {
        return $this->registration !== null && $this->registration->isPending();
    }

    /**
     * Whether a previous working set is still stored and could be rolled back to.
     */
    public function hasRetainedSet(): bool
    {
        return $this->retainedLabel !== null;
    }

    /**
     * A one-line summary for a console command or a panel toast — no secret, no identifier.
     */
    public function summary(): string
    {
        return sprintf(
            '%s [%s] verified at %s%s%s',
            $this->mode()->value,
            $this->label(),
            $this->verifiedAt()->toDateTimeString(),
            $this->isRegistrationPending() ? '; number registration pending' : '',
            $this->retainedLabel === null ? '' : sprintf('; [%s] retained', $this->retainedLabel),
        );
    }
}
