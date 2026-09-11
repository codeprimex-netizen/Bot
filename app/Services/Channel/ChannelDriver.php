<?php

declare(strict_types=1);

namespace App\Services\Channel;

use App\Enums\ChannelCapability;
use App\Enums\ChannelMode;
use App\Exceptions\Bridge\BridgeRequestFailedException;
use App\Exceptions\Bridge\BridgeUnreachableException;
use App\Exceptions\Channel\WebhookVerificationException;
use App\Models\Session;
use App\Services\Bridge\BridgeClient;
use Illuminate\Http\Request;

/**
 * One pluggable messaging backend — the **driver contract**, which is `BridgeClient` plus
 * exactly the things a transport refuses to know about (Req 8.1 / A8; Req 33.1 / NFR4;
 * design § Channel Mode 2.1, 2.4).
 *
 * ```php
 * // Every send, inbound webhook, and management op goes through one of these,
 * // resolved from the session's mode by ChannelRouter (task 6.3).
 * $driver = $router->driverFor($session);
 *
 * $router->assertSupported($session, $content->capability());
 * $receipt = $driver->send($session, $content);
 * ```
 *
 * ## `extends BridgeClient`, and why that is genuinely additive
 *
 * `BridgeClient`'s docblock carries a table of the seven things that *belong to
 * `ChannelDriver`, not there*, with a single test for the boundary: **could the Node sidecar
 * carry this out knowing nothing but a session id and the arguments?** Every transport method
 * passes it; the eight members below are precisely the ones that do not. This interface is
 * the other half of that argument, member for member:
 *
 * | Member | Why it is not transport |
 * |---|---|
 * | `mode()` | a routing fact; the wire does not vary by it |
 * | `supports()` | a declaration about a backend, answered with no I/O |
 * | `requiresAntiBan()` | a policy input for the send gate, not an operation |
 * | `send()` | the driver's send — typed content, capability degradation, idempotency — built *on top of* `sendText`/`sendMedia` |
 * | `sendTemplate()` | approved-template semantics only official modes have |
 * | `parseWebhook()` | the opposite direction, and provider-specific |
 * | `register()`, `healthCheck()` | credential-aware; the transport has no notion of credentials |
 *
 * Nothing here re-shapes a transport method, which is what makes task 7.1 possible: wrapping
 * the existing `HttpBridgeClient` as `BaileysChannelDriver` is *additive* — the eleven
 * transport methods delegate unchanged, so existing tenants see no behavioural difference
 * (design § 2.1: *"no behavioural change for existing tenants"*).
 *
 * ## `send()` versus `sendText()` / `sendMedia()`
 *
 * They are one layer apart and the difference is *what decides*:
 *
 * | | `BridgeClient::sendText()` / `sendMedia()` | `ChannelDriver::send()` |
 * |---|---|---|
 * | Argument | a session **id**, a JID, a string or a `MediaPayload` | a `Session` and an `OutboundContent` |
 * | Knows about | one protocol operation | modes, capabilities, idempotency, degradation |
 * | Idempotent | no — it puts one message on the wire each time it is called | **yes**, on `$content->idempotencyKey()` |
 * | Returns | `SentMessageDto` — the wire's ack | `SendReceipt` — the ack plus mode, key, capability, and whether it degraded |
 * | Rich content | cannot express it | flattens it when the mode cannot render it |
 *
 * `send()` is the *inner* send: it is called by `channelSendGate` (Algorithm 9, task 8.1)
 * once the capability gate and the anti-ban-or-provider-rules branch have allowed the
 * message, and `tenantSendGate` (Algorithm 3, task 9.3) composes in front of that with the
 * plan gate, quota, and opt-out. So the composition is
 * `tenantSendGate → channelSendGate → driver->send() → sendText()/sendMedia()`, and each
 * layer adds decisions the one below it does not make. Neither `send()` nor the transport
 * methods may be used to skip a layer — the pipeline is the only intended caller of either,
 * exactly as `BridgeClient` already says of `sendText()`.
 *
 * ## Two answers a driver does not get to invent
 *
 * `requiresAntiBan()` and `supports()` are both *derived* — from `ChannelMode` and from the
 * capability matrix — and both have a failure mode where a wrong answer is worse than a
 * crash: an unofficial backend sending without the warm-up ramp gets a tenant's number
 * banned (Req 8.8 says the gate must not be disableable), and a driver reporting `true` for
 * `GROUPS` on Cloud API turns a typed pre-dispatch refusal into a provider error mid-send
 * (Req 8.3 / Property 21).
 *
 * An interface cannot force a body, so this contract does not rely on one. Two mechanisms
 * back the guarantee, both testable:
 *
 * 1. **`Concerns\DerivesChannelPolicy`** — the trait every driver in tasks 7.1–7.5 uses. It implements
 *    `requiresAntiBan()` as `mode()->isWebProtocol()` and routes `supports()` through the
 *    matrix, exposing a `refineSupport()` hook for per-provider resolution that
 *    *structurally* cannot widen a refusal (see the trait).
 * 2. **`ModeGuardedChannelDriver`** — a decorator that re-derives both answers from `mode()`
 *    regardless of what the driver it wraps says, in the way `TenantScopedBridgeClient` is
 *    the outermost decorator so no path reaches the wire without ownership resolution
 *    (`BridgeClient`, *"The bridge never learns about tenants"*). A driver returned wrapped
 *    in it cannot answer `false` for `BAILEYS`/`ON_PREMISE` and cannot widen a `❌` cell,
 *    whether it uses the trait or not.
 *
 * ## No secret leaves a driver
 *
 * `register()` and `healthCheck()` are handed `ChannelCredentials`, whose decrypted values
 * come from `ChannelCredential::secrets()` and must go no further than the wire. Neither
 * `RegistrationResult` nor `ChannelHealth` has a public constructor, precisely so their
 * `detail` cannot be set without the credentials it is scrubbed against — a provider `401`
 * that quotes an access-token fragment cannot come back out. The same rule binds exception
 * messages: `WebhookVerificationException` fingerprints identifiers and never quotes a
 * signature, a token, or a body.
 *
 * ## Implementations
 *
 * | Class | Mode | Task |
 * |---|---|---|
 * | `BaileysChannelDriver` | `BAILEYS` — wraps the existing `HttpBridgeClient`, unchanged | 7.1 |
 * | `CloudApiChannelDriver` | `CLOUD_API` | 7.2 |
 * | `OnPremiseChannelDriver` | `ON_PREMISE` (deprecated) | 7.3 |
 * | `BspGatewayChannelDriver` | `BSP_GATEWAY`, one per-provider adapter per `BspProvider` | 7.4 |
 * | `FakeChannelDriver` | test-only, bound only in the testing container | 7.5 |
 *
 * Adding a future backend is a new class, a new `ChannelMode` case, and a credential shape —
 * never a rewrite (NFR4). Resolving a session to its driver is `ChannelRouter::driverFor()`
 * (task 6.3), which is the only place the mapping exists (Property 22).
 */
interface ChannelDriver extends BridgeClient
{
    /*
    |--------------------------------------------------------------------------
    | What this backend is
    |--------------------------------------------------------------------------
    */

    /**
     * Which backend this is.
     *
     * A constant per driver, and the single fact everything else about the driver's *policy*
     * is derived from: `requiresAntiBan()`, `supports()`, the credential shape, the webhook
     * slug. It must equal the `channel_mode` of every session routed to it — a message is
     * dispatched to **exactly one** driver, the one for its session's mode (Property 22) —
     * and `ModeGuardedChannelDriver` enforces that for `send()`, `sendTemplate()` and
     * `register()` rather than trusting the router to have got it right.
     *
     * `BSP_GATEWAY` is one mode with eight partners, so this is not enough to identify the
     * wire: the partner comes from `ChannelCredentials::$provider`, and one driver instance
     * serves all of them (task 7.4).
     */
    public function mode(): ChannelMode;

    /**
     * Whether `$capability` may be attempted on this backend at all.
     *
     * The pre-dispatch gate of Req 8.3 / Property 21: consulted **before** any driver call,
     * with a `false` short-circuiting to a typed `ModeCapabilityException` (task 6.3's
     * `ChannelRouter::assertSupported()`) and **never** a crash, a silent no-op, or a
     * provider error mid-send.
     *
     * ## What an implementation must guarantee
     *
     * - **No I/O.** This is a declaration, not a probe. It is called on every send, and task
     *   8.2 calls it once per capability at session create / mode change to persist the
     *   authoritative set — neither can afford a network round-trip, and a probe that failed
     *   would have to answer "unsupported" and permanently disable a working feature.
     * - **Bounded by the matrix.** `ChannelCapability::supportOn($this->mode())` is the
     *   ceiling. A driver may *narrow* it — that is exactly what task 7.4 does when it
     *   resolves `⚠️ per provider` from the partner's live config — and may never widen it.
     *   `ChannelCapabilitySupport::refinedBy()` is where that floor is enforced, once, so no
     *   refinement layer has to re-argue it.
     * - **Stable within a request.** Task 8.2 persists the answers and gates on the stored
     *   set afterwards; an answer that changed between two calls in one request would make
     *   the persisted set a snapshot of nothing.
     *
     * The matrix distinguishes three states and this returns a `bool` because the gate has
     * two outcomes. `Conditional` reads as `true` — *attempt it, subject to a further rule*
     * — and the rule it implies (the 24-hour window, an approved template, a provider tier)
     * is enforced later by tasks 8.1 and 8.4, which read the three-valued
     * `ChannelCapabilitySupport` rather than this boolean.
     */
    public function supports(ChannelCapability $capability): bool;

    /**
     * Whether the anti-ban warm-up / rate / quiet-hours gate applies to this backend
     * (Req 8.8 / A8; Correctness Property 24).
     *
     * True for the WhatsApp-web-protocol modes — `BAILEYS` and `ON_PREMISE` — and false for
     * the official Meta-hosted and partner routes, which follow the provider's own messaging
     * limits and template rules instead (Algorithm 9's branch).
     *
     * ## What an implementation must guarantee
     *
     * Exactly `$this->mode()->isWebProtocol()`, and nothing else. Not a config lookup, not a
     * per-tenant setting, not a constructor flag — Req 8.8 requires the gate be
     * non-disableable, and any of those would be a way to disable it. Use
     * `DerivesChannelPolicy`, which writes that one expression once, and expect to be routed
     * through `ModeGuardedChannelDriver`, which re-derives the answer from `mode()` and
     * ignores whatever the wrapped driver returned.
     *
     * The inverse is not `isOfficial()`: `ON_PREMISE` is an official route that the anti-ban
     * gate *does* apply to, so a caller reading "official" must not conclude "no anti-ban"
     * (`ChannelMode::isOfficial()` documents the same trap).
     */
    public function requiresAntiBan(): bool;

    /*
    |--------------------------------------------------------------------------
    | Outbound
    |--------------------------------------------------------------------------
    */

    /**
     * Send one outbound message through this backend.
     *
     * The driver-level send, one layer above `sendText()` / `sendMedia()` — see the table in
     * this interface's docblock for the boundary. Called by `channelSendGate` (Algorithm 9,
     * task 8.1) after the capability gate and the anti-ban-or-provider-rules branch have
     * allowed the message.
     *
     * ## What an implementation must guarantee
     *
     * - **Idempotent on `$content->idempotencyKey()`.** design § 2.4 states it, and it is
     *   what makes the send pipeline's retry safe: a second `send()` with a key already
     *   accepted must return the *original* receipt and put nothing new on the wire. Failing
     *   closed is not an option here — a retry that duplicates is a message a customer reads
     *   twice.
     * - **Never bypass a gate.** No plan check, quota decrement, opt-out check or anti-ban
     *   pacing happens here; that is the pipeline's, and this method is not a way around it.
     * - **Degrade rather than refuse.** When the mode cannot render the content's rich shape,
     *   flatten it to `$content->plainText()` and set `SendReceipt::$degraded` — design.md's
     *   degradation table (reply buttons → *"Reply 1/2/3"*, list → numbered menu, product →
     *   text with name/price/link) is a promise that flows work everywhere, and the `menu`
     *   flow node parses numeric choices so the degraded message stays functional. A
     *   capability the mode refuses outright (`❌`) never reaches here: the router refused it
     *   before dispatch.
     * - **`$session->channel_mode` must be `$this->mode()`.** Sending a session's message
     *   through another mode's driver would use the wrong credentials, the wrong rate rules,
     *   and the wrong ban-risk profile. `ModeGuardedChannelDriver` refuses the mismatch.
     *
     * @throws BridgeRequestFailedException when the backend answered *no*
     * @throws BridgeUnreachableException when the backend never answered, so the outcome is unknown
     */
    public function send(Session $session, OutboundContent $content): SendReceipt;

    /**
     * Send a pre-approved template message.
     *
     * The official modes' way of reaching a customer **outside** the 24-hour
     * customer-service window (Req 8.12 / A8). `BAILEYS` has no approval registry — its
     * `TEMPLATE` cell is `⚠️ text-templated only` — so a real template send on it is refused
     * with `ModeCapabilityException` (task 6.3) rather than silently rendered as text, which
     * would look like a template send whose approval nobody ever obtained.
     *
     * ## What an implementation must guarantee
     *
     * - The template is **re-read** from `cloud_api_templates` and must be sendable
     *   (`CloudApiTemplate::isSendable()`); `TemplateRef` carries only the identity, never a
     *   cached body or status, so a template revoked since the reference was built is caught.
     *   Task 8.4 owns the sync and the approval flow.
     * - Idempotency and the mode-match rule of `send()` apply unchanged, and the returned
     *   receipt names the template (`SendReceipt::$templateName`).
     * - `$vars` fills the template's placeholders. Which order and which naming the provider
     *   expects is the driver's business; what this contract requires is that a missing or
     *   surplus variable is a local failure and not a provider rejection charged to the
     *   tenant's quality rating.
     *
     * @param  array<string, string|int|float>  $vars  placeholder values, keyed as the provider expects
     *
     * @throws BridgeRequestFailedException when the backend refuses the template send
     * @throws BridgeUnreachableException when the backend never answered
     */
    public function sendTemplate(Session $session, TemplateRef $template, array $vars): SendReceipt;

    /*
    |--------------------------------------------------------------------------
    | Inbound
    |--------------------------------------------------------------------------
    */

    /**
     * Verify a provider webhook and normalise it into the platform's canonical inbound event.
     *
     * The inbound direction, and the point where four wire formats converge on one type:
     * bridge HMAC, Meta's `verify_token` handshake plus `X-Hub-Signature-256`, the
     * On-Premise client's callback, and a BSP partner's signature (design § Channel Mode
     * 2.5). Task 8.3 resolves `(tenant, session, driver)` from
     * `channel_webhook_routes.route_key` first and then calls this on that session's driver.
     *
     * ## Verification is part of the contract, not a step beside it
     *
     * **A payload whose origin does not check out must not become an `InboundEvent`.** There
     * are exactly two outcomes — an event, or `WebhookVerificationException` (403) — and in
     * particular there is no verdict a caller could forget to consult. A webhook URL is
     * public by construction, so an unverified payload that parsed would inject a customer
     * message, a reply, an AI credit spend and an audit trail from an anonymous POST.
     *
     * What an implementation must check, in this order, before reading a single content
     * field:
     *
     * 1. **Origin.** The HMAC over the **raw** body (`$request->getContent()`, not the
     *    re-encoded array — a re-encode changes bytes and the signature is over bytes), in
     *    constant time; or the mode's equivalent proof.
     * 2. **Recipient.** That the payload is about a number *these* credentials own — Meta's
     *    `phone_number_id`, a partner's sender id. A signature proves who *sent* the body,
     *    not whose number it is about, and a BSP account fronting several tenants' numbers
     *    can legitimately sign a payload for any of them
     *    (`WebhookVerificationException::wrongRecipient()`; Req 8.4, Property 23).
     * 3. **Shape.** That the body is the documented structure; a verified payload of an
     *    unexpected shape is `malformedPayload()`, never a half-populated event.
     *
     * A verified payload the platform has no use for — a `contacts` update, a template-status
     * change, a kind the provider added after this release — is `InboundEvent::unsupported()`,
     * which is a successful parse that gets a `200` so the provider stops retrying. Collapsing
     * that into a verification failure would turn every provider feature announcement into a
     * 403 storm.
     *
     * Meta's GET subscription handshake is **not** parsed here: it echoes `hub.challenge` and
     * has no body to normalise, so it is answered by the webhook route against
     * `ChannelWebhookRoute::matchesVerifyToken()` (task 8.3). This method sees POSTs.
     *
     * @throws WebhookVerificationException when origin, recipient, or shape does not check out
     */
    public function parseWebhook(Request $request, ChannelCredentials $credentials): InboundEvent;

    /*
    |--------------------------------------------------------------------------
    | Credentials & onboarding
    |--------------------------------------------------------------------------
    */

    /**
     * Register or verify this session's number on this backend, and point the provider at the
     * platform's webhook.
     *
     * Called by `SessionManager::create` (task 9.1) for the official modes **before** the
     * session is marked live: Cloud API registers the number under a WABA by
     * `phone_number_id`, a BSP provisions or claims the sender, On-Premise registers against
     * the self-hosted client. A `BAILEYS` session pairs by QR and needs nothing here.
     *
     * ## What an implementation must guarantee
     *
     * - **The result decides liveness.** `RegistrationResult::live()` means the number can
     *   send now; `pending()` means the provider accepted and has not finished verifying, and
     *   the session must stay out of `SENDABLE`. A registration that *failed* is an exception,
     *   not a `pending()`.
     * - **Idempotent.** Re-registering a number already registered reports its current state
     *   rather than resetting it — the same reason `provisionSession()` is idempotent: a
     *   retried provision must not throw away a working registration.
     * - **The callback URL comes from `UrlBuilder::webhook()`**, built from the mode's slug
     *   (`ChannelMode::webhookSlug()`, or `BspProvider::webhookSlug()` for a partner) and the
     *   unguessable `channel_webhook_routes.route_key`, and is reported back on the result so
     *   the panel can show the tenant exactly what its provider will call.
     * - **No secret in the result.** `providerNumberId` is an identifier, and `detail` is
     *   scrubbed against these credentials by `RegistrationResult`'s named constructors —
     *   which is why it has no public one.
     *
     * @throws BridgeRequestFailedException when the provider refuses the registration
     * @throws BridgeUnreachableException when the provider never answered
     */
    public function register(Session $session, ChannelCredentials $credentials): RegistrationResult;

    /**
     * Probe whether `$credentials` actually work.
     *
     * Called by task 7.6 **before** activating credentials on save or rotate: on an unhealthy
     * answer the previous working set is retained and the candidate is recorded
     * `INVALID` (Req 8.6, 8.13 / A8), so a fat-fingered token can never take a tenant's
     * number offline. Also what the connection screen's red light reads.
     *
     * ## What an implementation must guarantee
     *
     * - **Reports, does not throw, for an unhealthy answer.** The same argument
     *   `BridgeClient::isReachable()` makes: the caller wants the failure as data, to record
     *   and to display. An exception is for the case where the probe could not be *made* at
     *   all (an unreachable endpoint), because "we could not ask" and "the provider said no"
     *   lead to different operator actions.
     * - **Read-only.** A probe that registered a number, rotated a token, or sent a message
     *   would have side effects on credentials that are about to be rejected.
     * - **Session-independent.** It answers about credentials, not about a session — which is
     *   what lets task 7.6 validate a candidate set before any session uses it.
     * - **No secret in the answer.** `ChannelHealth`'s named constructors scrub `detail`
     *   against the very credentials being probed, and a probe's `401` body is the response
     *   most likely to quote what it was sent.
     */
    public function healthCheck(ChannelCredentials $credentials): ChannelHealth;
}
