<?php

declare(strict_types=1);

namespace App\Exceptions\Channel;

use App\Enums\BspProvider;
use App\Enums\ChannelMode;
use App\Services\Channel\ChannelCredentials;
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
 * ## Two flavours, and why a panel must be able to tell them apart
 *
 * Task 6.3 raised one: *absent*. Task 7.6 added the other: *rejected* — credentials that
 * exist, that the tenant believes in, and that the driver's `healthCheck()` (or
 * `register()`) refused. They are one state to a send (there is nothing to authenticate
 * with) and two entirely different sentences to the tenant, so the difference is carried
 * rather than described:
 *
 * | Constructor | `errorCode()` | The tenant is told | What the tenant does |
 * |---|---|---|---|
 * | `missing()` | `channel_credentials_missing` | this mode is not connected yet | enter credentials |
 * | `rejected()` | `channel_credentials_rejected` | the provider refused these, **and why** | re-issue the token, fix the number |
 *
 * A panel that had to match on a message to tell those apart would break the first time a
 * word of prose changed, and the two remedies are far enough apart that showing the wrong
 * one sends a tenant to re-type a token that is fine. `ERROR_CODE` keeps its original value
 * and meaning; read `errorCode()` for the per-instance answer, exactly as
 * `FeatureNotInPlanException` does with its two codes.
 *
 * ## `rejected()` carries the provider's verdict, and cannot carry the provider's echo
 *
 * `detail()` is what the driver actually said, which is the whole value of this flavour: an
 * expired token, a number missing from the WABA, a partner refusing a sender id. It is also
 * the single most dangerous string in the channel subsystem, because a credential probe's
 * job is to send a secret somewhere and read the refusal — and Meta and several BSPs quote
 * part of the `Authorization` header back on a `401` (`ChannelRequestFailedException` makes
 * this argument at length).
 *
 * So `rejected()` accepts a detail **only from a type that has already scrubbed it**:
 * `ChannelHealth::$detail` and `RegistrationResult::$detail` are both passed through
 * `ChannelCredentials::redact()` by their own named constructors, neither type has a public
 * constructor, and that is why. This class re-applies the same length bound and nothing
 * else — it holds no credentials, so it *cannot* re-scrub values, and pretending otherwise
 * with a regex would be worse than stating the precondition.
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
     *
     * The *absent* flavour's code, and unchanged since task 6.3 — `ChannelExceptionsTest` and
     * `ChannelRouterTest` both pin it. A caller that needs the per-instance answer reads
     * `errorCode()`.
     */
    public const string ERROR_CODE = 'channel_credentials_missing';

    /**
     * The *rejected* flavour's code: credentials that exist and that the driver refused.
     */
    public const string ERROR_CODE_REJECTED = 'channel_credentials_rejected';

    private function __construct(
        public readonly ChannelMode $mode,
        public readonly ?BspProvider $provider,
        string $message,
        private readonly string $publicMessage,
        private readonly string $errorCode = self::ERROR_CODE,
        private readonly ?string $detail = null,
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

    /**
     * The credentials exist and the driver refused them (Req 8.6, 8.13 / A8) — task 7.6's
     * verdict when `healthCheck()` reports unhealthy, or `register()` cannot establish the
     * number.
     *
     * Raised **instead of** activating them, so the tenant's previous working set is still
     * the one that sends: a rotation that is refused changes nothing a customer could notice.
     *
     * `$detail` must come from `ChannelHealth::$detail` or `RegistrationResult::$detail` —
     * see the class docblock for why a raw provider body must never be handed to this, and
     * why this class cannot be the thing that scrubs it.
     */
    public static function rejected(
        ChannelMode $mode,
        string $tenantId,
        string $detail,
        ?BspProvider $provider = null,
    ): self {
        $detail = self::bounded($detail);

        return new self($mode, $provider, sprintf(
            'Tenant %s offered credentials for %s%s that the driver refused: %s. They were not '
            .'activated, so whatever was working before is still working and still sending; '
            .'nothing was rerouted onto another mode.',
            self::fingerprint($tenantId),
            $mode->value,
            $provider === null ? '' : sprintf(' via provider [%s]', $provider->value),
            $detail === '' ? 'no reason was given' : $detail,
        ), sprintf(
            '%s refused these credentials%s Your previous credentials are unchanged and still '
            .'in use.',
            $mode->label(),
            $detail === '' ? '.' : sprintf(': %s', $detail),
        ), self::ERROR_CODE_REJECTED, $detail === '' ? null : $detail);
    }

    public function getStatusCode(): int
    {
        return self::STATUS;
    }

    /**
     * Which flavour this is, machine-readably — `ERROR_CODE` or `ERROR_CODE_REJECTED`.
     *
     * The `FeatureNotInPlanException` shape: one class, two codes, and the instance knows
     * which it is so no caller has to read a sentence to find out.
     */
    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * Whether the driver refused credentials that exist, as opposed to there being none.
     */
    public function isRejection(): bool
    {
        return $this->errorCode === self::ERROR_CODE_REJECTED;
    }

    /**
     * What the driver said, scrubbed and bounded — `null` for the *absent* flavour, which has
     * no driver verdict to report because no driver was asked.
     */
    public function detail(): ?string
    {
        return $this->detail;
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

    /**
     * A driver's verdict, trimmed and held to the same length bound the two types that
     * produce one already apply.
     *
     * The bound is re-applied rather than trusted: `ChannelCredentials::MAX_DETAIL_LENGTH` is
     * the platform's one answer to "how much provider prose may travel", and a `detail`
     * reaching this constructor from anywhere else must obey it too — an unbounded provider
     * body is how a response that happens to quote a token ends up copied into a response
     * payload in full.
     */
    private static function bounded(string $detail): string
    {
        return mb_strimwidth(trim($detail), 0, ChannelCredentials::MAX_DETAIL_LENGTH, '…');
    }
}
