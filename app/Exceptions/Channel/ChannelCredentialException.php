<?php

declare(strict_types=1);

namespace App\Exceptions\Channel;

use App\Enums\BspProvider;
use App\Enums\ChannelMode;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * A session's mode has no credentials the platform could authenticate with — so nothing is
 * sent, and in particular nothing is sent through a *different* mode (Req 8.13 / A8;
 * design § Channel Mode 2.7).
 *
 * ```php
 * // ChannelRouter::driverFor() — the primary mode is not negotiable
 * $driver = $router->driverFor($session);   // CLOUD_API with no credential row ⇒ throws
 * ```
 *
 * ## Why this is an error and not a reroute
 *
 * Req 8.13's last clause — *"keep the platform operating on the `BAILEYS` default session
 * behavior"* — is about the **platform**, not about this session: a tenant that has not
 * configured Cloud API keeps running its Baileys sessions normally, and the Cloud API mode
 * simply cannot be selected. It is emphatically not a licence to send *this* session's
 * traffic over Baileys instead.
 *
 * That distinction is the whole reason this exception exists. A silent reroute would take a
 * message a tenant sent on its official, template-approved, quality-rated number and put it
 * on an unregistered WhatsApp Web number with a different ban-risk profile, different
 * pacing rules and a different sender identity — a Property 22 violation ("exactly one
 * driver, the one for `session.channel_mode`") whose symptom is not an error anywhere but a
 * customer receiving a message from a number the brand never advertised.
 *
 * ## The asymmetry with `failoverChain()`
 *
 * `ChannelRouter::driverFor()` raises this when the **primary** mode has no credentials.
 * `failoverChain()` **omits** a fallback mode in the same state, silently, because a
 * fallback that cannot authenticate is not a fallback and refusing the whole dispatch over
 * a half-configured *optional* chain would turn a working primary into an outage. The two
 * behaviours are opposite on purpose and both are documented at their call sites.
 *
 * ## 422, and never retried
 *
 * **422 Unprocessable Content**, for the same reason as `ModeCapabilityException`: the
 * request was understood and addressed correctly, and the platform's stored configuration
 * cannot carry it out. `PlatformErrorClassifier`'s HTTP-status fallback maps it to
 * `ErrorClass::Validation` — zero retry attempts — which is the right answer, because a
 * credential set appears when a human enters one, not after a backoff.
 *
 * ## Scope, and who extends it
 *
 * Task 6.3 raises exactly one flavour of this: *absent*. Task 7.6 owns validate-before-
 * activate (Req 8.6) and adds the *rejected* flavour — credentials that exist and that the
 * driver's `healthCheck()` refused — as further named constructors here, so a tenant screen
 * can tell "you have not set this up" apart from "your token expired" without matching on a
 * message.
 *
 * ## What may be said out loud
 *
 * The mode and the provider are platform vocabulary (`ChannelMode::label()`,
 * `BspProvider`), and the tenant is being told about **its own** configuration, so naming
 * them is the point rather than a leak. The tenant id is fingerprinted the way
 * `CrossTenantAccessException` fingerprints one — the internal message is for an operator
 * correlating log lines, and a tenant id in a response body is of no use to the tenant it
 * belongs to and of some use to anybody else. No secret, no phone number, and no credential
 * label reaches a message.
 */
final class ChannelCredentialException extends RuntimeException implements HttpExceptionInterface
{
    /**
     * The stored configuration cannot carry out an otherwise valid request — see the class
     * docblock for why this is not a 403 and not a 503.
     */
    public const int STATUS = 422;

    /**
     * Stable machine-readable code for API clients and the panel.
     */
    public const string ERROR_CODE = 'channel_credentials_missing';

    private function __construct(
        public readonly ChannelMode $mode,
        public readonly ?BspProvider $provider,
        string $message,
        private readonly string $publicMessage,
    ) {
        parent::__construct($message);
    }

    /**
     * No usable credential set exists for `(tenant, mode, provider)`.
     *
     * "Usable" is `ChannelCredentialStore::rowFor()`'s answer, so this covers all three
     * ways a tenant arrives here — never configured, configured and switched off, or
     * configured and marked `INVALID` by task 7.6 — because from a send's point of view
     * they are one state: there is nothing to authenticate with.
     */
    public static function missing(ChannelMode $mode, string $tenantId, ?BspProvider $provider = null): self
    {
        return new self($mode, $provider, sprintf(
            'Tenant %s has no usable credentials for %s%s, so a session on that mode cannot send. '
            .'The message is refused rather than rerouted: sending it through another mode would put '
            .'it on a different number, with different pacing rules and a different ban-risk profile.',
            self::fingerprint($tenantId),
            $mode->value,
            $provider === null ? '' : sprintf(' via provider [%s]', $provider->value),
        ), sprintf(
            '%s is not connected for this account yet. Add its credentials, or use a session on a '
            .'mode that is connected.',
            $mode->label(),
        ));
    }

    public function getStatusCode(): int
    {
        return self::STATUS;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return [];
    }

    /**
     * The sentence a client may be shown: what to do about it, with no identifiers in it.
     */
    public function publicMessage(): string
    {
        return $this->publicMessage;
    }

    /**
     * A short, stable, non-reversible stand-in for an identifier — the discipline
     * `CrossTenantAccessException` sets, applied for the same reason.
     */
    private static function fingerprint(string $id): string
    {
        return $id === '' ? '<none>' : '#'.substr(hash('sha256', $id), 0, 8);
    }
}
