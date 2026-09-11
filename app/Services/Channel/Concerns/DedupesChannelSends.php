<?php

declare(strict_types=1);

namespace App\Services\Channel\Concerns;

use App\Enums\ChannelCapability;
use App\Enums\ChannelMode;
use App\Models\Session;
use App\Services\Reliability\IdempotencyOptions;

/**
 * The `ChannelDriver::send()` idempotency convention — the dedup **scope**, the request
 * fingerprint, and the claim mechanics — written once for the drivers of tasks 7.2–7.4
 * (Req 8.1 / A8; Req 31.2 / NFR2; design § 2.4: *"MUST be idempotent on
 * `$content->idempotencyKey`"*).
 *
 * ```php
 * $outcome = $this->idempotency->once(
 *     self::sendScope($session),
 *     $content->idempotencyKey(),
 *     fn (): array => $this->dispatch(...),
 *     $this->sendOptions($session, $fingerprint),
 * );
 * ```
 *
 * ## Why the tenant is in the **scope** and not only in the key
 *
 * `idempotency_keys` is deliberately not tenant-scoped — its `tenant_id` is attribution,
 * because gateway intake dedups before it knows whose event it is — so that store's own rule
 * is that *"any key whose natural form could collide across tenants must name the tenant in
 * its scope"*. A send key is chosen by the pipeline (a message ULID, a campaign row id), and
 * two tenants can perfectly well pick the same one. Without the tenant in the scope, the
 * second tenant's send would be answered with the **first tenant's receipt** — a message
 * silently not sent, and a provider message id from another tenant's traffic recorded against
 * it.
 *
 * ## One convention, three drivers, and one deliberate duplication
 *
 * `BaileysChannelDriver` (task 7.1) declares its own `SEND_SCOPE_PREFIX` with the same value
 * and does not use this trait. That is on purpose: 7.1 is shipped and its tests pin the
 * literal scope string, and rewriting a landed driver to route through a new trait would be a
 * change with no behavioural upside and a real chance of a subtle one.
 *
 * The duplication is therefore *guarded* rather than accepted: `CloudApiChannelDriverTest`
 * asserts `BaileysChannelDriver::SEND_SCOPE_PREFIX === CloudApiChannelDriver::SEND_SCOPE_PREFIX`,
 * so the day someone changes one of them the suite says so. A shared constant would be
 * better; a shared constant plus a rewrite of a landed driver is not.
 *
 * ## The claim mechanics, and why they are not the caller's choice
 *
 * `sendOptions()` fixes three things every driver-level send needs and no driver should decide
 * differently:
 *
 * | Option | Why |
 * |---|---|
 * | `forTenant()` | attribution for cost and cascade; isolation is the scope's job, per above |
 * | `matching($fingerprint)` | so one key cannot answer two different sends with the first one's receipt (`IdempotencyKeyReuseException`, 422) rather than delivering a message to the wrong person |
 * | `failingFast()` | a send runs inside a queued job, so its own backoff is a better place to wait for a live holder than a blocked worker slot |
 *
 * The default `IdempotencyMode::Lease` is kept: a send is an **external** effect, so the key
 * must be released when the provider refuses (`ErrorClass` decides whether a retry follows),
 * and it must not be burned before the wire is touched.
 */
trait DedupesChannelSends
{
    /**
     * Dedup namespace of a driver-level send; the tenant id is appended by `sendScope()`.
     *
     * The literal is 7.1's, and the test named in the class docblock is what keeps them equal.
     */
    public const string SEND_SCOPE_PREFIX = 'channel.send:';

    /**
     * Which backend this is — the one member this trait needs, and the one thing a driver
     * always knows about itself. Declared abstract so the fingerprint below cannot be read as
     * mode-independent: two modes sending the same text to the same recipient under one key
     * are two different sends.
     */
    abstract public function mode(): ChannelMode;

    /**
     * The dedup namespace for `$session`'s tenant.
     */
    protected static function sendScope(Session $session): string
    {
        return self::SEND_SCOPE_PREFIX.$session->tenant_id;
    }

    /**
     * The claim mechanics of a driver-level send — see the table in the class docblock.
     *
     * @param  array<string, string>  $fingerprint  what the key is bound to; see `sendFingerprint()`
     */
    protected function sendOptions(Session $session, array $fingerprint): IdempotencyOptions
    {
        return IdempotencyOptions::default()
            ->forTenant($session->tenant_id)
            ->matching($fingerprint)
            ->failingFast();
    }

    /**
     * The fingerprint an idempotency key is bound to.
     *
     * Message content is included as a **hash**: the fingerprint exists to make "same key,
     * different send" detectable, and Req 7.3 / A7 permits a content digest and never the body.
     * `IdempotencyKey::fingerprint()` hashes the whole structure again, so nothing legible is
     * stored either way — the digest is here so nothing legible is *passed*.
     *
     * `$discriminator` is what distinguishes a template send from a free-form one with the same
     * key: a template's identity plus its variables, hashed. Without it, a caller that sent
     * text and then a template under one key would be handed the text send's receipt with a
     * `templateName` of null.
     *
     * @return array<string, string>
     */
    protected function sendFingerprint(
        Session $session,
        ChannelCapability $capability,
        string $recipient,
        string $content,
        ?string $discriminator = null,
    ): array {
        $fingerprint = [
            'session' => $session->id,
            'mode' => $this->mode()->value,
            'capability' => $capability->value,
            'recipient' => $recipient,
            'content' => hash('sha256', $content),
        ];

        if ($discriminator !== null) {
            $fingerprint['shape'] = hash('sha256', $discriminator);
        }

        return $fingerprint;
    }
}
