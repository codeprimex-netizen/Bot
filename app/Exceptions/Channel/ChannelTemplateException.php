<?php

declare(strict_types=1);

namespace App\Exceptions\Channel;

use App\Enums\ChannelMode;
use App\Enums\ChannelTemplateStatus;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * A template send was asked for that the **local** approved-template registry refuses — so
 * nothing reaches the provider, and the tenant's quality rating is not charged for a request
 * that was always going to fail (Req 8.12 / A8; design § Channel Mode 2.3, data model
 * `cloud_api_templates`).
 *
 * ```php
 * // CloudApiChannelDriver::sendTemplate(), before any Graph API call
 * $row = CloudApiTemplate::sendable($credentialId, $ref->name, $ref->language)
 *     ?? throw ChannelTemplateException::notApproved(ChannelMode::CloudApi, $ref, $status);
 * ```
 *
 * ## Not `TemplateRequiredException` — the two answer opposite questions
 *
 * Req 8.12 and design § 2.5 name a `TemplateRequiredException` for the 24-hour-window rule:
 * *a free-form message was sent outside the customer-service window and no approved template
 * was attached*. That exception belongs to **task 8.4**, which owns the window, and its
 * remedy is *"pick a template"*.
 *
 * This one is the other side: a template **was** named, and the registry says it cannot be
 * sent. The remedy is *"fix or await approval of this template"*, and a panel that could not
 * tell the two apart would tell a tenant to choose a template it had just chosen.
 *
 * | | `TemplateRequiredException` (8.4) | this (7.2) |
 * |---|---|---|
 * | Trigger | free-form content, window closed, no template | a named template that is not sendable |
 * | Owner | the send gate | the driver's `sendTemplate()` |
 * | Remedy shown | select an approved template | resubmit / wait for review / re-sync |
 *
 * ## Why the check is local, and why it is re-read per send
 *
 * `cloud_api_templates` is a **mirror** of the provider's state (that model's own docblock),
 * so this check costs one indexed read instead of a Graph API round trip on every send —
 * which matters inside a campaign loop, where a remote check would be slower than the send it
 * guards. `TemplateRef` deliberately carries no status for the same reason: a reference built
 * five minutes ago may name a template Meta has since paused, and only a re-read catches it.
 *
 * `PAUSED` is refused as firmly as `PENDING`: the provider has stopped accepting the
 * template, so attempting the send would convert a local refusal into a remote failure
 * mid-campaign, on the tenant's quality rating.
 *
 * ## 422, and never retried
 *
 * **422 Unprocessable Content**, the status `ModeCapabilityException` and
 * `ChannelCredentialException` already use for the same shape of failure: the request was
 * understood and addressed to the right tenant, and the platform's own stored state cannot
 * carry it out. `PlatformErrorClassifier`'s HTTP fallback maps it to `ErrorClass::Validation`
 * — zero retry attempts — which is right: a template is approved when a reviewer approves it,
 * not after a backoff. A tenant waiting on `PENDING` re-sends when the sync (task 8.4) says
 * so.
 *
 * ## What may be said out loud
 *
 * The template's `(name, language)` pair, its mirrored status, and the mode. `TemplateRef`
 * already argues that the pair is *"neither a secret nor an identity"* — it is the tenant's
 * own copy deck, named by the tenant, and it is the only thing that makes the message
 * actionable. The variable **keys** of a mismatch are named too (`1`, `2`, `customer_name`);
 * their **values** never are, because those are per-send data and can be a customer's name or
 * order number (Req 7.3 / A7). No credential, no phone number, no tenant id.
 */
final class ChannelTemplateException extends RuntimeException implements HttpExceptionInterface
{
    /**
     * See the class docblock for why this is not a 403 and not a 503.
     */
    public const int STATUS = 422;

    public const string ERROR_CODE = 'channel_template_unusable';

    /**
     * The longest a rendered key list may be, so a template with two hundred placeholders
     * cannot write a paragraph into a log line.
     */
    public const int MAX_KEYS_LISTED = 10;

    /**
     * @param  string  $templateKey  the `name:language` pair — `TemplateRef::key()`
     * @param  ChannelTemplateStatus|null  $status  the mirrored status, when a row exists
     */
    private function __construct(
        public readonly ChannelMode $mode,
        public readonly string $templateKey,
        public readonly ?ChannelTemplateStatus $status,
        string $message,
        private readonly string $publicMessage,
    ) {
        parent::__construct($message);
    }

    /**
     * No row for `(account, name, language)` at all.
     *
     * Distinct from `notApproved()` because the remedies differ and neither is obvious from
     * the other: this is *"this account has no such template — create and submit it, or
     * re-sync"*, and a common cause is a template approved under the tenant's **other**
     * credential set (approval is per provider account, which is why
     * `CloudApiTemplate::sendable()` is keyed on `credential_id`).
     */
    public static function notRegistered(ChannelMode $mode, string $templateKey): self
    {
        return new self($mode, self::sanitise($templateKey), null, sprintf(
            'No template [%s] is registered for this %s account, so there is nothing to send. '
            .'Approval is per provider account: a template approved under another credential set '
            .'of the same tenant is not sendable through this one.',
            self::sanitise($templateKey),
            $mode->value,
        ), sprintf(
            'The template "%s" is not in this connection\'s approved-template list. Submit it, or '
            .'re-sync templates if it was approved elsewhere.',
            self::sanitise($templateKey),
        ));
    }

    /**
     * A row exists and its mirrored status does not permit a send.
     */
    public static function notApproved(ChannelMode $mode, string $templateKey, ChannelTemplateStatus $status): self
    {
        return new self($mode, self::sanitise($templateKey), $status, sprintf(
            'Template [%s] is %s on this %s account and only APPROVED templates may be sent. The '
            .'send is refused locally rather than attempted: a provider rejection would count '
            .'against this number\'s quality rating.',
            self::sanitise($templateKey),
            $status->value,
            $mode->value,
        ), sprintf(
            'The template "%s" is %s and cannot be sent yet.',
            self::sanitise($templateKey),
            strtolower($status->value),
        ));
    }

    /**
     * The reference names a provider account other than the one the send would authenticate
     * as.
     *
     * A `TemplateRef` carries the `credentialId` it was resolved from precisely so this is
     * detectable: sending it through a different account would be refused by the provider,
     * because the approval does not exist there.
     */
    public static function wrongAccount(ChannelMode $mode, string $templateKey): self
    {
        return new self($mode, self::sanitise($templateKey), null, sprintf(
            'Template [%s] was resolved against a different %s credential set than the one this '
            .'session sends with. Template approval is per provider account, so the reference '
            .'cannot be carried across.',
            self::sanitise($templateKey),
            $mode->value,
        ), sprintf(
            'The template "%s" belongs to a different connection on this account.',
            self::sanitise($templateKey),
        ));
    }

    /**
     * The supplied variables do not fill the template's placeholders exactly.
     *
     * A **local** failure on purpose: `ChannelDriver::sendTemplate()` requires that *"a
     * missing or surplus variable is a local failure and not a provider rejection charged to
     * the tenant's quality rating"*. Surplus is refused as firmly as missing, because a
     * surplus key is nearly always a renamed placeholder — the send would go out with a stale
     * value silently substituted into the wrong slot.
     *
     * @param  list<string>  $missing  placeholder keys the template needs and `$vars` lacks
     * @param  list<string>  $surplus  keys in `$vars` the template has no placeholder for
     */
    public static function variableMismatch(
        ChannelMode $mode,
        string $templateKey,
        array $missing,
        array $surplus,
    ): self {
        return new self($mode, self::sanitise($templateKey), null, sprintf(
            'Template [%s] was given the wrong variables%s%s. Only the placeholder names are '
            .'listed — their values are per-send content. Refused locally, because the provider '
            .'would reject it and charge the rejection to this number\'s quality rating.',
            self::sanitise($templateKey),
            $missing === [] ? '' : sprintf(': missing %s', self::keyList($missing)),
            $surplus === [] ? '' : sprintf('%s unexpected %s', $missing === [] ? ':' : ',', self::keyList($surplus)),
        ), sprintf(
            'The template "%s" needs a different set of values%s.',
            self::sanitise($templateKey),
            $missing === [] ? '' : sprintf(' (missing %s)', self::keyList($missing)),
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
     * The sentence a tenant may be shown: what to do, with no identifiers in it.
     */
    public function publicMessage(): string
    {
        return $this->publicMessage;
    }

    public function errorCode(): string
    {
        return self::ERROR_CODE;
    }

    /**
     * Whether the template exists and could become sendable on its own.
     *
     * What a panel needs in order to choose between *"waiting for review"* and *"do
     * something"*: `PENDING` and `PAUSED` can change without the tenant acting, `REJECTED`
     * cannot, and a missing row certainly cannot.
     */
    public function mayBecomeSendable(): bool
    {
        return $this->status !== null && $this->status->isSyncable();
    }

    /**
     * A bounded, comma-separated rendering of placeholder **keys**.
     *
     * @param  list<string>  $keys
     */
    private static function keyList(array $keys): string
    {
        $shown = array_map(static fn (string $key): string => self::sanitise($key), array_slice($keys, 0, self::MAX_KEYS_LISTED));
        $rendered = implode(', ', $shown);

        return count($keys) > self::MAX_KEYS_LISTED
            ? $rendered.sprintf(' (+%d more)', count($keys) - self::MAX_KEYS_LISTED)
            : $rendered;
    }

    /**
     * Keep a value out of a message verbatim: control characters escaped, length bounded —
     * the treatment `WebhookVerificationException::sanitise()` gives a header name.
     *
     * A template name is validated by `TemplateRef::NAME_PATTERN` before it can reach here in
     * the normal path, but a `notRegistered()` may be raised for a name that never passed
     * through one.
     */
    private static function sanitise(string $value): string
    {
        return mb_strimwidth(addcslashes($value, "\0..\37\177"), 0, 120, '…');
    }
}
