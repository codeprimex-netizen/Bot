<?php

declare(strict_types=1);

namespace App\Services\Channel;

use App\Enums\MediaKind;
use App\Services\Bridge\MediaPayload;
use InvalidArgumentException;

/**
 * One request body for the On-Premise client's `POST /v1/messages` — the legacy Business API's
 * message shapes, built and validated in one place (Req 8.1, 8.12 / A8; design § Channel Mode
 * 2.2 mode 3: *"capability set is close to Cloud API (templates, session window, media,
 * interactive) but self-hosted"*).
 *
 * ```php
 * OnPremiseMessage::text('919812345678', 'Your order shipped.')->body();
 * // ['recipient_type' => 'individual', 'to' => '919812345678',
 * //  'type' => 'text', 'text' => ['preview_url' => false, 'body' => '…']]
 * ```
 *
 * ## It is `CloudApiMessage`'s sibling, and the four differences are the whole class
 *
 * The two backends are close enough that a reader should be told precisely where they part, so
 * that nobody "simplifies" one into the other:
 *
 * | | `CloudApiMessage` | this |
 * |---|---|---|
 * | `messaging_product` | **required** on every body | **absent** — the field does not exist on the On-Premise API, and sending it is an unknown-parameter refusal (`1010`) |
 * | media by URL | `{"link": "…"}`, fetched by Meta | `{"link": "…", "provider": {"name": "…"}}` — the On-Premise client fetches a link only through a **configured media provider** |
 * | template language | `{"code": "en_US"}` | `{"policy": "deterministic", "code": "en_US"}`, plus an optional `namespace` — the legacy client's own spelling |
 * | who hosts it | `graph.facebook.com` | a container the tenant runs, at a base URL the tenant supplies |
 *
 * Everything else — the `interactive` button and list shapes, `context.message_id` threading,
 * the 4096-character text cap, the three-button and ten-row caps — is genuinely identical,
 * because the On-Premise client is the same product one release behind.
 *
 * ## Why the recipient rule is restated rather than borrowed
 *
 * `CloudApiMessage::recipientDigits()` implements the same rule, and this class deliberately
 * does not call it. Its refusal messages name *"a Cloud API message"* and *"Meta's Business
 * Platform"*, and an On-Premise tenant shown that sentence would go looking at a Meta console
 * they do not use — for a mistake in a payload that never went near Meta. A wrong diagnosis on
 * a working platform costs more than a duplicated regex.
 *
 * The duplication is *guarded* rather than accepted, the way `DedupesChannelSends` guards its
 * scope literal: `OnPremiseChannelDriverTest` asserts that both classes reduce the same inputs
 * to the same digits and refuse the same values, so the day one of them changes the suite says
 * so.
 *
 * ## What is validated here, and why locally
 *
 * Every cap below is a 4xx from the client if it is exceeded — and on the On-Premise API a 4xx
 * on a message is still a message the tenant's own container processed and rejected, which
 * counts toward the number's quality signals exactly as Meta's does. Refusing locally keeps a
 * caller's typo out of that record, which is the same argument
 * `ChannelDriver::sendTemplate()` makes for template variables and which is equally true of
 * every other field.
 */
final readonly class OnPremiseMessage
{
    /**
     * The longest body text the client accepts in a `text` message.
     *
     * `TextContent::MAX_LENGTH` and `CloudApiMessage::MAX_TEXT_LENGTH` are the same number for
     * the same reason: the protocol truncates silently past it, and a half-delivered message
     * reads as a successful one.
     */
    public const int MAX_TEXT_LENGTH = 4096;

    /**
     * The interactive caps, identical to Cloud API's because it is the same feature.
     */
    public const int MAX_BUTTONS = 3;

    public const int MAX_BUTTON_TITLE_LENGTH = 20;

    public const int MAX_LIST_ROWS = 10;

    public const int MAX_INTERACTIVE_BODY_LENGTH = 1024;

    /**
     * The suffix of a one-to-one WhatsApp identity, and of a group's.
     */
    public const string USER_JID_SUFFIX = '@s.whatsapp.net';

    public const string GROUP_JID_SUFFIX = '@g.us';

    /**
     * The `language.policy` the legacy client requires on a template send.
     *
     * `deterministic` means *"use exactly this language, do not fall back"*. The alternative the
     * client once accepted (`fallback`) is what makes a tenant's Hindi campaign silently go out
     * in English when the Hindi template is not approved yet — a wrong-language message a
     * customer reads, which is worse than a refusal the tenant sees.
     */
    public const string LANGUAGE_POLICY = 'deterministic';

    /**
     * @param  array<string, mixed>  $body  the complete request body, ready to POST
     */
    private function __construct(
        public string $type,
        private array $body,
    ) {}

    /**
     * A plain text message.
     *
     * `preview_url` defaults to `false` for `CloudApiMessage::text()`'s reason: a preview makes
     * the *client container* fetch whatever the tenant linked, turning a transactional message
     * into an outbound request from the tenant's own network.
     *
     * @throws InvalidArgumentException when the recipient or body is unusable
     */
    public static function text(string $recipient, string $text, bool $previewUrl = false, ?string $replyTo = null): self
    {
        $trimmed = trim($text);

        if ($trimmed === '') {
            throw new InvalidArgumentException(
                'An On-Premise text message needs a body: the protocol accepts an empty one, delivers '
                .'nothing, and reports success — so it is refused here instead.'
            );
        }

        if (mb_strlen($trimmed) > self::MAX_TEXT_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'An On-Premise text message is limited to %d characters; got %d. The protocol truncates '
                .'silently, so the caller is told instead.',
                self::MAX_TEXT_LENGTH,
                mb_strlen($trimmed),
            ));
        }

        return new self('text', self::envelope($recipient, 'text', [
            'preview_url' => $previewUrl,
            'body' => $trimmed,
        ], $replyTo));
    }

    /**
     * A media message — by uploaded id, or by link through a configured media provider.
     *
     * The one shape that genuinely differs from Cloud API's. The On-Premise client has **no
     * inline-bytes field and no unqualified `link`**: it either sends media it already holds
     * (`POST /v1/media` returned an id) or it fetches a URL through a *media provider* the
     * operator configured on the container (`POST /v1/settings/application/media/providers`),
     * naming that provider in the message.
     *
     * So the driver resolves it this way, and `OnPremiseChannelDriver::sendMedia()` documents the
     * consequence:
     *
     * | Payload | `$uploadedMediaId` | Body |
     * |---|---|---|
     * | `MediaPayload::fromBytes()` | the id from `POST /v1/media` | `{"id": "…"}` |
     * | `MediaPayload::fromUrl()`, provider configured | `null` | `{"link": "…", "provider": {"name": "…"}}` |
     * | `MediaPayload::fromUrl()`, no provider | `null` | `{"link": "…"}` — which the client refuses with `1009`, surfaced as a typed refusal naming the missing setting rather than as a silent non-delivery |
     *
     * @param  string|null  $uploadedMediaId  the id `POST /v1/media` returned, for an inline payload
     * @param  string|null  $mediaProvider  the container's configured media-provider name, for a link
     *
     * @throws InvalidArgumentException when neither an id nor a link is available
     */
    public static function media(
        string $recipient,
        MediaPayload $media,
        ?string $uploadedMediaId = null,
        ?string $mediaProvider = null,
    ): self {
        $type = $media->kind->value;
        $content = [];

        if ($uploadedMediaId !== null && trim($uploadedMediaId) !== '') {
            $content['id'] = trim($uploadedMediaId);
        } elseif ($media->url !== null && trim($media->url) !== '') {
            $content['link'] = trim($media->url);

            if ($mediaProvider !== null && trim($mediaProvider) !== '') {
                $content['provider'] = ['name' => trim($mediaProvider)];
            }
        } else {
            throw new InvalidArgumentException(
                'An On-Premise media message needs either the id of an uploaded object or a link the '
                .'client can fetch. Neither would post a media message with an empty payload, which the '
                .'client accepts and the recipient receives as a broken attachment.'
            );
        }

        if ($media->caption !== null && $media->kind->usesCaption()) {
            $content['caption'] = $media->caption;
        }

        if ($media->filename !== null && $media->kind === MediaKind::Document) {
            // Only the kind that displays one, so the body stays an honest description of what
            // was asked for — `MediaPayload::toArray()`'s own rule.
            $content['filename'] = $media->filename;
        }

        return new self($type, self::envelope($recipient, $type, $content));
    }

    /**
     * An approved template message.
     *
     * `namespace` is the legacy client's Message Template Namespace and is **omitted when the
     * tenant has not configured one**: newer client builds resolve the namespace themselves,
     * older ones require it, and sending an empty string is refused by both. A build that needs
     * it and did not get it answers `2001`/`2003`, which the classifier maps to `VALIDATION` and
     * the panel shows — a configuration gap reported as one.
     *
     * @param  list<array<string, mixed>>  $components  the provider-shaped component list
     *
     * @throws InvalidArgumentException when the recipient, name, or language is unusable
     */
    public static function template(
        string $recipient,
        string $name,
        string $language,
        array $components = [],
        ?string $namespace = null,
    ): self {
        if (trim($name) === '' || trim($language) === '') {
            throw new InvalidArgumentException(
                'An On-Premise template send needs both a name and a language: approval is per '
                .'(name, language) pair, so a send naming only one of them is ambiguous the moment a '
                .'tenant adds a second locale.'
            );
        }

        $template = [
            'name' => trim($name),
            'language' => ['policy' => self::LANGUAGE_POLICY, 'code' => trim($language)],
        ];

        if ($namespace !== null && trim($namespace) !== '') {
            $template['namespace'] = trim($namespace);
        }

        if ($components !== []) {
            // Omitted when empty for `CloudApiMessage::template()`'s reason: the client refuses
            // `"components": []` on some builds, and a template with no placeholders has none.
            $template['components'] = $components;
        }

        return new self('template', self::envelope($recipient, 'template', $template));
    }

    /**
     * Reply buttons — up to three, as the client renders them.
     *
     * @param  list<array{id: string, title: string}>  $buttons
     *
     * @throws InvalidArgumentException when the button set is unusable
     */
    public static function buttons(
        string $recipient,
        string $body,
        array $buttons,
        ?string $header = null,
        ?string $footer = null,
    ): self {
        if ($buttons === [] || count($buttons) > self::MAX_BUTTONS) {
            throw new InvalidArgumentException(sprintf(
                'An On-Premise reply-button message carries 1 to %d buttons; got %d. Beyond the cap the '
                .'client refuses the whole message, so the degraded numbered-text rendering '
                .'(OutboundContent::plainText()) is the answer for a longer set.',
                self::MAX_BUTTONS,
                count($buttons),
            ));
        }

        $rendered = [];

        foreach ($buttons as $button) {
            $rendered[] = ['type' => 'reply', 'reply' => [
                'id' => self::interactiveId($button['id'] ?? '', 'button'),
                'title' => self::interactiveTitle($button['title'] ?? '', self::MAX_BUTTON_TITLE_LENGTH, 'button'),
            ]];
        }

        return new self('interactive', self::envelope($recipient, 'interactive', self::interactive(
            type: 'button',
            body: self::interactiveBody($body),
            action: ['buttons' => $rendered],
            header: $header,
            footer: $footer,
        )));
    }

    /**
     * A single-section list message — up to ten rows.
     *
     * @param  list<array{id: string, title: string, description?: string}>  $rows
     *
     * @throws InvalidArgumentException when the row set is unusable
     */
    public static function list(
        string $recipient,
        string $body,
        string $buttonLabel,
        array $rows,
        ?string $header = null,
        ?string $footer = null,
    ): self {
        if ($rows === [] || count($rows) > self::MAX_LIST_ROWS) {
            throw new InvalidArgumentException(sprintf(
                'An On-Premise list message carries 1 to %d rows; got %d.',
                self::MAX_LIST_ROWS,
                count($rows),
            ));
        }

        $rendered = [];

        foreach ($rows as $row) {
            $entry = [
                'id' => self::interactiveId($row['id'] ?? '', 'list row'),
                'title' => self::interactiveTitle($row['title'] ?? '', self::MAX_BUTTON_TITLE_LENGTH, 'list row'),
            ];

            if (($row['description'] ?? '') !== '') {
                $entry['description'] = trim((string) $row['description']);
            }

            $rendered[] = $entry;
        }

        return new self('interactive', self::envelope($recipient, 'interactive', self::interactive(
            type: 'list',
            body: self::interactiveBody($body),
            action: [
                'button' => self::interactiveTitle($buttonLabel, self::MAX_BUTTON_TITLE_LENGTH, 'list button'),
                'sections' => [['rows' => $rendered]],
            ],
            header: $header,
            footer: $footer,
        )));
    }

    /**
     * The request body, ready to POST.
     *
     * @return array<string, mixed>
     */
    public function body(): array
    {
        return $this->body;
    }

    /**
     * The recipient this body addresses, as the client will see it.
     *
     * What `SendReceipt::$recipient` records when the client's response does not echo an identity
     * of its own — which, unlike Cloud API, it never does: the On-Premise `POST /v1/messages`
     * answer carries a message id and nothing about the contact.
     */
    public function recipient(): string
    {
        $to = $this->body['to'] ?? '';

        return is_string($to) ? $to : '';
    }

    /**
     * A recipient reduced to the E.164 digits the client addresses.
     *
     * Deliberately not `CloudApiMessage::recipientDigits()` — see the class docblock for why the
     * rule is restated rather than borrowed, and for the test that keeps the two in agreement.
     *
     * @throws InvalidArgumentException when the value is a group, or is not a usable number
     */
    public static function recipientDigits(string $recipient): string
    {
        $trimmed = trim($recipient);

        if (str_ends_with($trimmed, self::GROUP_JID_SUFFIX)) {
            // Refused, not stripped: the On-Premise API has no group messaging either (`GROUPS`
            // is `❌` on ON_PREMISE, because it is an official Business API client rather than a
            // WhatsApp Web session), and a group id with its domain removed is a
            // plausible-looking number that belongs to a real person somewhere.
            throw new InvalidArgumentException(
                'An On-Premise message cannot be addressed to a group JID: the WhatsApp Business API '
                .'client has no group messaging, and stripping the suffix would send the message to '
                .'whatever number the group id happens to look like.'
            );
        }

        // A JID's user part is everything before the `@`; an E.164 number has no `@` at all.
        $user = str_contains($trimmed, '@') ? strstr($trimmed, '@', true) : $trimmed;
        $digits = (string) preg_replace('/\D+/', '', (string) $user);

        if (preg_match('/^\d{8,15}$/', $digits) !== 1) {
            throw new InvalidArgumentException(
                'An On-Premise recipient must be an E.164 number of 8-15 digits, or a WhatsApp user JID '
                .'carrying one. A mangled number is not sent "best effort": it would deliver to a '
                .'stranger.'
            );
        }

        return $digits;
    }

    /**
     * The envelope every shape shares — and the one field it deliberately does not carry.
     *
     * There is **no `messaging_product`**. That field is Cloud API's, and the On-Premise client
     * treats an unrecognised top-level key as `1010` *"parameter is not required"* — so copying
     * Cloud API's envelope would fail every send on some builds and be ignored on others, which
     * is the worse of the two because it works until it does not.
     *
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private static function envelope(string $recipient, string $type, array $content, ?string $replyTo = null): array
    {
        $body = [
            'recipient_type' => 'individual',
            'to' => self::recipientDigits($recipient),
            'type' => $type,
        ];

        if ($replyTo !== null && trim($replyTo) !== '') {
            // The client threads a reply by quoting an inbound message id. Only ever an id the
            // platform received, so it needs no shape validation beyond being non-empty.
            $body['context'] = ['message_id' => trim($replyTo)];
        }

        $body[$type] = $content;

        return $body;
    }

    /**
     * The `interactive` object shared by the button and list shapes.
     *
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    private static function interactive(
        string $type,
        string $body,
        array $action,
        ?string $header = null,
        ?string $footer = null,
    ): array {
        $interactive = [
            'type' => $type,
            'body' => ['text' => $body],
            'action' => $action,
        ];

        if ($header !== null && trim($header) !== '') {
            $interactive['header'] = ['type' => 'text', 'text' => trim($header)];
        }

        if ($footer !== null && trim($footer) !== '') {
            $interactive['footer'] = ['text' => trim($footer)];
        }

        return $interactive;
    }

    /**
     * @throws InvalidArgumentException when the body is empty or over the cap
     */
    private static function interactiveBody(string $body): string
    {
        $trimmed = trim($body);

        if ($trimmed === '' || mb_strlen($trimmed) > self::MAX_INTERACTIVE_BODY_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'An On-Premise interactive message needs a body of 1 to %d characters; got %d.',
                self::MAX_INTERACTIVE_BODY_LENGTH,
                mb_strlen($trimmed),
            ));
        }

        return $trimmed;
    }

    /**
     * A reply id: what comes back on the inbound webhook when the customer picks this option, so
     * it is the field a flow correlates on and an empty one makes the choice unreadable.
     *
     * @throws InvalidArgumentException when the id is empty
     */
    private static function interactiveId(string $id, string $what): string
    {
        $trimmed = trim($id);

        if ($trimmed === '') {
            throw new InvalidArgumentException(sprintf(
                'An On-Premise interactive %s needs an id: it is what the inbound reply carries, and '
                .'without it the customer\'s choice cannot be told from any other.',
                $what,
            ));
        }

        return $trimmed;
    }

    /**
     * A visible label, refused rather than truncated: a clipped button reads as a bug in the bot.
     *
     * @throws InvalidArgumentException when the title is empty or over the cap
     */
    private static function interactiveTitle(string $title, int $max, string $what): string
    {
        $trimmed = trim($title);

        if ($trimmed === '' || mb_strlen($trimmed) > $max) {
            throw new InvalidArgumentException(sprintf(
                'An On-Premise interactive %s title must be 1 to %d characters; got %d.',
                $what,
                $max,
                mb_strlen($trimmed),
            ));
        }

        return $trimmed;
    }
}
