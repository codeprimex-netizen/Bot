<?php

declare(strict_types=1);

namespace App\Services\Channel;

use App\Enums\ChannelTemplateCategory;
use App\Models\CloudApiTemplate;
use InvalidArgumentException;

/**
 * Which approved template to send, named the way every official backend names one — the
 * argument to `ChannelDriver::sendTemplate()` (Req 8.12 / A8; design § Channel Mode 2.4).
 *
 * ```php
 * $ref = TemplateRef::fromModel($template);          // from the cloud_api_templates registry
 * $receipt = $driver->sendTemplate($session, $ref, ['1' => 'ACME', '2' => '#4417']);
 * ```
 *
 * ## `(name, language)` is the identity, and it is a pair on purpose
 *
 * Meta, the On-Premise client, and every BSP that syncs templates address a template by its
 * name *plus* its language — `order_update` in `en_US` and `order_update` in `pt_BR` are two
 * separately-approved objects, and a send that names only the name is ambiguous the moment a
 * tenant adds a second locale. `CloudApiTemplate::sendable()` looks a row up by exactly
 * `(credential_id, name, language)` for the same reason.
 *
 * `credentialId` is carried because approval is **per provider account**: the same template
 * name approved under one WABA says nothing about another, so a driver holding two credential
 * sets must know which registry the reference came from. It is nullable only for the caller
 * that has not resolved a row — a `BAILEYS` text-templated send, where there is no approval
 * registry at all.
 *
 * ## What this deliberately does not carry
 *
 * The body, the components, and the approval status. Those live on `CloudApiTemplate` and
 * change independently of any send: a reference that cached the body would send yesterday's
 * approved text after a re-sync, and one that cached `status` would let a template revoked
 * five minutes ago still look sendable. `sendTemplate()` implementations re-read the row —
 * `CloudApiTemplate::isSendable()` is the check, task 8.4 owns enforcing it.
 *
 * Variables are not here either: they are per-send data, so they are `sendTemplate()`'s
 * second argument rather than part of the template's identity.
 */
final readonly class TemplateRef
{
    /**
     * The shape a template name may have.
     *
     * Meta's own constraint — lowercase letters, digits and underscores, up to 512
     * characters — validated here because a name outside it cannot exist at the provider,
     * so a send using one is a request that will always fail, and failing it locally keeps
     * the tenant's error out of the provider's quality rating.
     */
    public const string NAME_PATTERN = '/^[a-z0-9_]{1,512}$/';

    /**
     * The shape a template language tag may have: `en`, `en_US`, `zh_CN`, `pt_BR`.
     *
     * Underscore-separated, as WhatsApp writes them — not the BCP-47 hyphen. A hyphenated
     * tag is the most common way a template send fails at Meta with an unhelpful error, so
     * it is refused with a helpful one.
     */
    public const string LANGUAGE_PATTERN = '/^[a-z]{2,3}(_[A-Za-z0-9]{2,8})?$/';

    /**
     * @throws InvalidArgumentException when the name or language could not exist at a provider
     */
    public function __construct(
        public string $name,
        public string $language,
        public ?string $credentialId = null,
        public ?ChannelTemplateCategory $category = null,
    ) {
        if (preg_match(self::NAME_PATTERN, $this->name) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'A template name must match %s (lowercase letters, digits, underscores); got [%s]. '
                .'No provider can hold a name outside that shape, so the send would always fail.',
                self::NAME_PATTERN,
                mb_strimwidth(addcslashes($this->name, "\0..\37\177"), 0, 80, '…'),
            ));
        }

        if (preg_match(self::LANGUAGE_PATTERN, $this->language) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'A template language must match %s — WhatsApp writes locales with an underscore '
                .'(`en_US`), not a hyphen; got [%s].',
                self::LANGUAGE_PATTERN,
                mb_strimwidth(addcslashes($this->language, "\0..\37\177"), 0, 80, '…'),
            ));
        }
    }

    /**
     * A reference to one row of the approved-template registry.
     */
    public static function fromModel(CloudApiTemplate $template): self
    {
        return new self(
            name: $template->name,
            language: $template->language,
            credentialId: $template->credential_id,
            category: $template->category,
        );
    }

    /**
     * The `(name, language)` pair as one token, for a log line or a cache key.
     *
     * Neither half is a secret or an identity, so this is safe to write down — which is what
     * makes it usable as the correlation handle for a template send.
     */
    public function key(): string
    {
        return $this->name.':'.$this->language;
    }
}
