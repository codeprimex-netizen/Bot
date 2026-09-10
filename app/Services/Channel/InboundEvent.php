<?php

declare(strict_types=1);

namespace App\Services\Channel;

use App\Enums\ChannelMode;
use App\Enums\InboundEventKind;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * One **verified** inbound webhook payload, in the single shape the platform acts on — what
 * `ChannelDriver::parseWebhook()` returns and the only thing downstream of a webhook ever
 * sees (Req 8.4 / A8; Req 10.1 / B1; design § Channel Mode 2.5).
 *
 * ```php
 * // task 8.3's controller: the route establishes (tenant, session, driver); the driver verifies and normalises
 * $route = ChannelWebhookRoute::resolve($routeKey) ?? abort(404);
 * $event = $router->driverFor($route->session)->parseWebhook($request, $credentials);
 *
 * if ($event->kind->isMessage()) { … }
 * ```
 *
 * ## Existing this type is a claim about verification
 *
 * Four wire formats converge here — the bridge's HMAC callback, Meta's Cloud API webhook
 * with its `verify_token` handshake and `X-Hub-Signature-256`, the On-Premise client's
 * callback, and a BSP partner's signed POST. They agree on nothing: not the envelope, not
 * the field names, not the timestamp format, not even how many logical events one HTTP
 * request carries.
 *
 * What they do have in common is that **none of them may be believed unverified**. A
 * webhook URL is public by construction, so an unauthenticated POST that became an
 * `InboundEvent` would inject a customer message — with a reply, an AI credit spend, and an
 * audit trail — into a tenant's conversation history. So the contract is stated as a
 * property of this type rather than as a step callers must remember:
 *
 * > **An `InboundEvent` exists only if the payload it came from proved its origin.**
 *
 * `parseWebhook()` therefore has exactly two outcomes — this object, or
 * `App\Exceptions\Channel\WebhookVerificationException` (403). There is no third one, and in
 * particular there is no boolean beside the parse for a caller to forget. What "proved its
 * origin" means per mode, and the reason a signature alone is not enough for a shared BSP
 * account, is written out on that exception.
 *
 * ## Deliberately no `sessionId`
 *
 * The `(tenant, session, driver)` tuple is established **before** parsing, by
 * `channel_webhook_routes.route_key` (task 8.3). Carrying the session id inside the event as
 * well would create a second source for the same fact, and the interesting case is when the
 * two disagree: a payload that claims a session other than the route's would be either an
 * attack or a bug, and a consumer reading the event's copy would act on the claim. So the
 * route's answer is the only answer, and the event carries only what the *payload* said.
 *
 * `tenantId` **is** here, because it comes from the credentials the driver was handed rather
 * than from the payload, and every consumer needs it to scope its writes. `channelIdentity`
 * is the number the payload says it is about — the field a driver compares against its
 * credentials before believing a shared-account payload
 * (`WebhookVerificationException::wrongRecipient()`).
 *
 * ## The body is here, and it is treated as such
 *
 * `text` is customer content: Req 7.3 / A7 forbids writing it to a log and permits a digest
 * instead, so `contentHash()` is provided and `__debugInfo()` replaces the body with it.
 * This type implements neither `Arrayable` nor `JsonSerializable`, so it cannot reach a log
 * context or an API response by being passed to something that serialises its argument.
 *
 * The verified raw payload is kept — privately, behind `payload()` — because later phases
 * read fields this type does not model: inbound media descriptors and transcription (§ B7),
 * interactive reply ids and product enquiries (§ 12.4), reactions and location. They read
 * them from here rather than by re-parsing the request, which by then no longer exists. That
 * is the extension seam: a new inbound feature is a new reader of `payload()`, not a new
 * field on the canonical type and not a fifth wire format to converge.
 */
final class InboundEvent
{
    /**
     * @param  string|null  $providerMessageId  the backend's id — the inbound message's, or the outbound one a receipt is about
     * @param  string|null  $from  who sent it, for a message; null for a receipt
     * @param  string|null  $channelIdentity  the tenant number the payload is addressed to, when it states one
     * @param  string|null  $text  the message body — content, never logged
     * @param  string|null  $failureReason  a provider-supplied, secret-scrubbed phrase; `SEND_FAILURE` only
     * @param  array<array-key, mixed>  $payload  the verified body, for the fields this type does not model
     *
     * @throws InvalidArgumentException when the event could not be acted on or correlated
     */
    public function __construct(
        public readonly InboundEventKind $kind,
        public readonly ChannelMode $mode,
        public readonly string $tenantId,
        public readonly ?string $providerMessageId = null,
        public readonly ?string $from = null,
        public readonly ?string $channelIdentity = null,
        public readonly ?string $text = null,
        public readonly ?CarbonImmutable $occurredAt = null,
        public readonly ?string $failureReason = null,
        private readonly array $payload = [],
    ) {
        if (trim($this->tenantId) === '') {
            throw new InvalidArgumentException(
                'An inbound event must name the tenant it belongs to: it comes from the credentials the '
                .'driver was handed, and every consumer scopes its writes by it.'
            );
        }

        if ($this->kind->requiresProviderMessageId()
            && ($this->providerMessageId === null || trim($this->providerMessageId) === '')) {
            throw new InvalidArgumentException(sprintf(
                'A [%s] inbound event needs a provider message id: for a message it is what makes '
                .'processing idempotent, and for a receipt it is the only thing that says which '
                .'outbound message the receipt is about.',
                $this->kind->value,
            ));
        }

        if ($this->kind->requiresSender() && ($this->from === null || trim($this->from) === '')) {
            throw new InvalidArgumentException(sprintf(
                'A [%s] inbound event must name who sent it.',
                $this->kind->value,
            ));
        }

        if ($this->failureReason !== null && $this->kind !== InboundEventKind::SendFailure) {
            throw new InvalidArgumentException(sprintf(
                'Only a SEND_FAILURE event carries a failure reason; got one on a [%s]. A reason on a '
                .'successful receipt would be read as a partial failure.',
                $this->kind->value,
            ));
        }
    }

    /*
    |--------------------------------------------------------------------------
    | The four wire formats, converged
    |--------------------------------------------------------------------------
    */

    /**
     * A customer message.
     *
     * @param  array<array-key, mixed>  $payload
     *
     * @throws InvalidArgumentException when the sender or provider message id is missing
     */
    public static function message(
        ChannelMode $mode,
        string $tenantId,
        string $providerMessageId,
        string $from,
        ?string $text = null,
        ?string $channelIdentity = null,
        ?CarbonImmutable $occurredAt = null,
        array $payload = [],
    ): self {
        return new self(
            kind: InboundEventKind::Message,
            mode: $mode,
            tenantId: $tenantId,
            providerMessageId: $providerMessageId,
            from: $from,
            channelIdentity: $channelIdentity,
            text: $text,
            occurredAt: $occurredAt,
            payload: $payload,
        );
    }

    /**
     * A receipt about an outbound message: delivered, read, or failed.
     *
     * One constructor for the three, because they differ only in which they are — and taking
     * the kind as an argument keeps a driver from inventing a fourth.
     *
     * @param  array<array-key, mixed>  $payload
     * @param  string|null  $failureReason  `SEND_FAILURE` only, and already passed through
     *                                      `ChannelCredentials::redact()`
     *
     * @throws InvalidArgumentException when `$kind` is not a receipt, or the correlation id is missing
     */
    public static function receipt(
        InboundEventKind $kind,
        ChannelMode $mode,
        string $tenantId,
        string $providerMessageId,
        ?CarbonImmutable $occurredAt = null,
        ?string $failureReason = null,
        ?string $channelIdentity = null,
        array $payload = [],
    ): self {
        if (! $kind->isReceipt()) {
            throw new InvalidArgumentException(sprintf(
                'A receipt event must be one of %s; got [%s].',
                implode(', ', array_map(
                    static fn (InboundEventKind $candidate): string => $candidate->value,
                    array_filter(
                        InboundEventKind::cases(),
                        static fn (InboundEventKind $candidate): bool => $candidate->isReceipt(),
                    ),
                )),
                $kind->value,
            ));
        }

        return new self(
            kind: $kind,
            mode: $mode,
            tenantId: $tenantId,
            providerMessageId: $providerMessageId,
            channelIdentity: $channelIdentity,
            occurredAt: $occurredAt,
            failureReason: $failureReason,
            payload: $payload,
        );
    }

    /**
     * A payload that verified, and that this platform does not act on — a `contacts` update,
     * a template-status change, an event kind added by the provider after this release.
     *
     * The honest answer, and the reason `parseWebhook()` never returns null: this is a
     * successful parse whose consumer is nobody, which must be acknowledged with a `200` so
     * the provider stops retrying it. Collapsing it into a verification failure would turn
     * every provider feature announcement into a 403 storm and an alert.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public static function unsupported(
        ChannelMode $mode,
        string $tenantId,
        ?string $channelIdentity = null,
        array $payload = [],
    ): self {
        return new self(
            kind: InboundEventKind::Unsupported,
            mode: $mode,
            tenantId: $tenantId,
            channelIdentity: $channelIdentity,
            payload: $payload,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Reading it
    |--------------------------------------------------------------------------
    */

    /**
     * The verified payload, for the fields this type does not model.
     *
     * Verified, but still *provider* data: read defensively. It is the seam later phases
     * extend through — inbound media, interactive reply ids, reactions, location.
     *
     * @return array<array-key, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }

    /**
     * One key of the verified payload, or `$default`.
     */
    public function payloadValue(string $key, mixed $default = null): mixed
    {
        return $this->payload[$key] ?? $default;
    }

    /**
     * A digest of the body — what a log line, an audit payload, and a metric may carry
     * instead of the text (Req 7.3 / A7: *"only content hashes"*).
     *
     * `null` when there is no body, so a caller cannot accidentally log the digest of the
     * empty string and correlate every bodyless event with every other one.
     */
    public function contentHash(): ?string
    {
        if ($this->text === null || $this->text === '') {
            return null;
        }

        return hash('sha256', $this->text);
    }

    /**
     * Whether anything acts on this event.
     */
    public function isActionable(): bool
    {
        return $this->kind->isActionable();
    }

    /**
     * What `dd()`, `var_dump()`, and a test-failure diff are allowed to see: no body, no raw
     * payload, and the sender fingerprinted the way `WebhookVerificationException` does it.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'kind' => $this->kind->value,
            'mode' => $this->mode->value,
            'tenantId' => $this->tenantId,
            'providerMessageId' => $this->providerMessageId,
            'from' => $this->from === null ? null : '#'.substr(hash('sha256', $this->from), 0, 8),
            'channelIdentity' => $this->channelIdentity === null
                ? null
                : '#'.substr(hash('sha256', $this->channelIdentity), 0, 8),
            'contentHash' => $this->contentHash(),
            'occurredAt' => $this->occurredAt?->toIso8601String(),
            'failureReason' => $this->failureReason,
            'payloadKeys' => array_map(
                static fn (int|string $key): string => (string) $key,
                array_keys($this->payload),
            ),
        ];
    }
}
