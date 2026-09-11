<?php

declare(strict_types=1);

namespace App\Services\Channel\Bsp;

/**
 * A partner's refusal, read out of its own error envelope and reduced to the evidence a retry
 * decision needs (Req 8.1 / A8; Req 31.1 / NFR2).
 *
 * The adapter's half of `ChannelRequestFailedException::refused()`: eight partners spell an
 * error eight ways, and the five fields that decide the message's fate are the same in all of
 * them. So each adapter reads its own envelope into this, and
 * `BspGatewayChannelDriver::refusalFor()` turns it into the platform's typed exception with the
 * mode and the provider attached — which is what lets `BspGatewayErrorClassifier` key a code
 * vocabulary on the partner that produced it.
 *
 * | Partner | Envelope | `$code` is |
 * |---|---|---|
 * | Twilio | `{"code": 21211, "message": …, "status": 400}` | `code` |
 * | 360dialog | `{"error": {"code": 131047, "type": …}}` (Meta's, passed through) | `error.code` |
 * | Gupshup | `{"status": "error", "message": …}` | absent — the status carries it |
 * | Vonage | `{"type": "…/messages-olympus#1010", "title": …}` (RFC 7807) | the `#` fragment of `type` |
 * | MessageBird | `{"errors": [{"code": 2, "description": …}]}` | `errors[0].code` |
 * | Infobip | `{"requestError": {"serviceException": {"messageId": "UNAUTHORIZED", …}}}` | `messageId` — a **token**, not a number |
 * | WATI | `{"result": false, "info": …}` — **on a `200`** | absent; the shape is the refusal |
 * | Kaleyra | `{"error": {"code": "E101", "message": …}}` | `error.code` |
 *
 * Two rows are the reason this type exists at all rather than a status code being read
 * directly. Infobip's code is an identifier (`TOO_MANY_REQUESTS`), which is why
 * `ChannelRequestFailedException::hasErrorCode()` takes strings as well as ints. And WATI
 * answers **HTTP 200** with `result: false`, so a driver that only built a refusal from
 * `$response->failed()` would record a message as sent that WATI never accepted — the one
 * outcome that is definitely wrong.
 *
 * ## No prose
 *
 * `$detail` is deliberately absent. `ChannelRequestFailedException`'s docblock is explicit that
 * a provider's own message *"quotes the request that produced it — including, on a `401`, a
 * fragment of the `Authorization` header"*, and several of these partners do exactly that. A
 * driver that wants to show a tenant why a probe failed renders its own sentence from
 * `$code` and passes it through `ChannelCredentials::redact()`.
 */
final readonly class BspRefusal
{
    /**
     * @param  string|null  $code  the partner's machine-readable code, normalised by the exception
     * @param  string|null  $type  the partner's error family, where it names one
     * @param  int|null  $retryAfterSeconds  a numeric `Retry-After`, or a partner-specific equivalent
     * @param  string|null  $traceId  an opaque correlation handle the partner's support asks for
     */
    public function __construct(
        public ?string $code = null,
        public ?string $type = null,
        public ?int $retryAfterSeconds = null,
        public ?string $traceId = null,
    ) {}
}
