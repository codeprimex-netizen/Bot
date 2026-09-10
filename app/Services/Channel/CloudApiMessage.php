<?php

declare(strict_types=1);

namespace App\Services\Channel;

use App\Enums\MediaKind;
use App\Services\Bridge\MediaPayload;
use InvalidArgumentException;

/**
 * One request body for Meta's `POST /{phone-number-id}/messages` — the Cloud API's five
 * message shapes, built and validated in one place (Req 8.1, 8.12 / A8; design § Channel
 * Mode 2.2 mode 2: *"single + bulk send, media …, template messages …, interactive
 * buttons/lists"*).
 *
 * ```php
 * CloudApiMessage::text('919812345678', 'Your order shipped.')->body();
 * // ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual',
 * //  'to' => '919812345678', 'type' => 'text', 'text' => ['preview_url' => false, 'body' => '…']]
 * ```
 *
 * ## Why the payload is a value object and not inline in the driver
 *
 * Three reasons, in order of how much they cost when ignored:
 *
 * 1. **Meta rejects unknown and malformed fields, and charges the rejection.** A template with
 *    the wrong number of parameters, a sticker with a caption, a button id over 256 characters
 *    — each comes back as a 4xx that counts toward the number's quality rating. Validating
 *    here makes those local failures, which is what `ChannelDriver::sendTemplate()` requires
 *    of the *variables* and what is equally true of every other field.
 * 2. **`messaging_product` is not optional and not defaultable elsewhere.** Every Cloud API
 *    message body carries it; a body assembled ad hoc in five methods is five chances to
 *    forget it, and the error Meta returns names something else.
 * 3. **Task 12.4 needs a seam, not a rewrite.** The rich `OutboundContent` family — reply
 *    buttons, list messages, product cards — is that task's, and when it lands the driver
 *    gains one `match` arm that calls `buttons()` or `list()`. Those two builders are complete
 *    and tested here so that arm is the whole change; see `CloudApiChannelDriver::send()` for
 *    why the arm cannot be written before the content type exists.
 *
 * ## The five shapes
 *
 * | Constructor | Meta `type` | Used by |
 * |---|---|---|
 * | `text()` | `text` | `send()` of a `TextContent`, and `sendText()` |
 * | `media()` | `image` / `video` / `audio` / `document` / `sticker` | `sendMedia()` |
 * | `template()` | `template` | `sendTemplate()` (task 8.4 gates *when* it may be used) |
 * | `buttons()` | `interactive` (`button`) | task 12.4's reply-button content |
 * | `list()` | `interactive` (`list`) | task 12.4's list content |
 *
 * ## Recipients are digits, and that is checked here
 *
 * `OutboundContent::recipient()` is documented as *"the fully-qualified WhatsApp identity the
 * transport addresses (`…@s.whatsapp.net`, `…@g.us`) **or** the E.164 number an official API
 * expects"*, so both spellings genuinely arrive. Meta wants the number, so `recipient()`
 * normalises: a JID's user part is taken, separators and a leading `+` are dropped, and the
 * result must be 8–15 digits — `HttpBridgeClient`'s own rule, for its reason (*"sending to a
 * mangled number is how one tenant's typo becomes a message to a stranger"*).
 *
 * A **group** JID (`…@g.us`) is refused rather than reduced to digits: Meta's Business
 * Platform has no group messaging at all (`GROUPS` is `❌` on `CLOUD_API`), and a group id
 * stripped of its domain is a plausible-looking number that belongs to somebody.
 */
final readonly class CloudApiMessage
{
    /**
     * The field every Cloud API request body carries, and its only legal value.
     */
    public const string MESSAGING_PRODUCT = 'whatsapp';

    /**
     * The longest body text Meta accepts in a `text` message.
     *
     * `TextContent::MAX_LENGTH` is the same number for the same reason, and both refuse rather
     * than truncate — the protocol truncates silently, and a half-delivered message reads as a
     * successful one.
     */
    public const int MAX_TEXT_LENGTH = 4096;

    /**
     * Meta's caps on the interactive shapes. Enforced locally because each is a 4xx that
     * would count against the number's quality rating.
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
     * @param  array<string, mixed>  $body  the complete request body, ready to POST
     */
    private function __construct(
        public string $type,
        private array $body,
    ) {}

    /**
     * A plain text message.
     *
     * `preview_url` defaults to `false`: link previews make Meta fetch the URL, which turns a
     * transactional message into an outbound request from Meta's infrastructure to whatever the
     * tenant linked, and the default that surprises nobody is the one that does not.
     *
     * @throws InvalidArgumentException when the recipient or body is unusable
     */
    public static function text(string $recipient, string $text, bool $previewUrl = false, ?string $replyTo = null): self
    {
        $trimmed = trim($text);

        if ($trimmed === '') {
            throw new InvalidArgumentException(
                'A Cloud API text message needs a body: Meta accepts an empty one on some paths, '
                .'delivers nothing, and reports success — so it is refused here instead.'
            );
        }

        if (mb_strlen($trimmed) > self::MAX_TEXT_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'A Cloud API text message is limited to %d characters; got %d.',
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
     * A media message: image, video, audio, document, or sticker.
     *
     * `MediaKind`'s backed values are Meta's own message types (`image`, `video`, `audio`,
     * `document`, `sticker`), which is why the kind is used directly as the `type` rather than
     * mapped — a mapping table would be a second place for the same five strings.
     *
     * Bytes are **not** sent inline. Meta accepts either a `link` it fetches or an `id` from a
     * prior upload to `/{phone-number-id}/media`; there is no base64 field, so a
     * `MediaPayload::fromBytes()` has to be uploaded first and passed here as
     * `$uploadedMediaId` (`CloudApiChannelDriver::uploadMedia()` does that).
     *
     * @param  string|null  $uploadedMediaId  Meta's media id, for a payload whose bytes were uploaded
     *
     * @throws InvalidArgumentException when neither a link nor an uploaded id is available
     */
    public static function media(string $recipient, MediaPayload $media, ?string $uploadedMediaId = null): self
    {
        $content = [];

        if ($uploadedMediaId !== null && trim($uploadedMediaId) !== '') {
            $content['id'] = trim($uploadedMediaId);
        } elseif ($media->url !== null && trim($media->url) !== '') {
            $content['link'] = trim($media->url);
        } else {
            throw new InvalidArgumentException(
                'A Cloud API media message needs either a link Meta can fetch or the id of an '
                .'already-uploaded attachment: the Graph API has no inline-bytes field, so base64 '
                .'media must be uploaded to /{phone-number-id}/media first.'
            );
        }

        // Only where the kind actually displays them, so the body stays an honest description
        // of what was asked for — `MediaPayload::toRequest()` makes the same choice.
        if ($media->caption !== null && $media->kind->usesCaption()) {
            $content['caption'] = $media->caption;
        }

        if ($media->filename !== null && $media->kind->usesFilename()) {
            $content['filename'] = $media->filename;
        }

        return new self($media->kind->value, self::envelope($recipient, $media->kind->value, $content));
    }

    /**
     * An approved-template message.
     *
     * `language.code` is WhatsApp's underscore-separated locale (`en_US`), which is what
     * `TemplateRef::LANGUAGE_PATTERN` already validates — a hyphenated BCP-47 tag is the most
     * common way a template send fails at Meta with an unhelpful error.
     *
     * `$components` is passed through as built by the caller
     * (`CloudApiChannelDriver::templateComponents()`), because the component list is where the
     * template's own placeholder arity lives and that is checked against the stored template
     * rather than guessed at here. An empty list is **omitted**: Meta rejects
     * `"components": []` on some template shapes, and a template with no placeholders genuinely
     * has no components.
     *
     * @param  list<array<string, mixed>>  $components
     *
     * @throws InvalidArgumentException when the recipient, name, or language is unusable
     */
    public static function template(string $recipient, string $name, string $language, array $components = []): self
    {
        if (preg_match(TemplateRef::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'A template name must match %s; got [%s].',
                TemplateRef::NAME_PATTERN,
                mb_strimwidth(addcslashes($name, "\0..\37\177"), 0, 80, '…'),
            ));
        }

        if (preg_match(TemplateRef::LANGUAGE_PATTERN, $language) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'A template language must match %s — WhatsApp writes locales with an underscore '
                .'(`en_US`), not a hyphen; got [%s].',
                TemplateRef::LANGUAGE_PATTERN,
                mb_strimwidth(addcslashes($language, "\0..\37\177"), 0, 80, '…'),
            ));
        }

        $template = [
            'name' => $name,
            'language' => ['code' => $language],
        ];

        if ($components !== []) {
            $template['components'] = array_values($components);
        }

        return new self('template', self::envelope($recipient, 'template', $template));
    }

    /**
     * An interactive **reply-button** message: up to three buttons under a body.
     *
     * `$buttons` is `id => title`. The id is what comes back in the inbound webhook
     * (`interactive.button_reply.id`) and is the tenant's own correlation handle — a flow node
     * id, an order action — so it is the array key rather than a positional index, which would
     * make a caller renumber its own flow when a button is removed.
     *
     * @param  array<string, string>  $buttons  reply id => button title
     *
     * @throws InvalidArgumentException when there are no buttons, too many, or one is unusable
     */
    public static function buttons(
        string $recipient,
        string $body,
        array $buttons,
        ?string $header = null,
        ?string $footer = null,
    ): self {
        $trimmedBody = self::interactiveBody($body);

        if ($buttons === []) {
            throw new InvalidArgumentException(
                'An interactive button message needs at least one button; with none, Meta delivers '
                .'a body with nothing to press and the flow it belongs to cannot advance.'
            );
        }

        if (count($buttons) > self::MAX_BUTTONS) {
            throw new InvalidArgumentException(sprintf(
                'Meta accepts at most %d reply buttons; got %d. Use a list message for more — a '
                .'fourth button is dropped silently, so the choice it offered simply disappears.',
                self::MAX_BUTTONS,
                count($buttons),
            ));
        }

        $rendered = [];

        foreach ($buttons as $id => $title) {
            $rendered[] = [
                'type' => 'reply',
                'reply' => [
                    'id' => self::interactiveId((string) $id, 'button'),
                    'title' => self::interactiveTitle($title, self::MAX_BUTTON_TITLE_LENGTH, 'button'),
                ],
            ];
        }

        return new self('interactive', self::envelope($recipient, 'interactive', self::interactive(
            type: 'button',
            body: $trimmedBody,
            action: ['buttons' => $rendered],
            header: $header,
            footer: $footer,
        )));
    }

    /**
     * An interactive **list** message: one button that opens up to ten rows.
     *
     * `$rows` is `id => title`, for the reason `buttons()` gives. Meta allows the rows to be
     * grouped into sections; one section is used, because a caller that needs several is
     * describing a menu that should be a flow rather than a message, and a sectioned API here
     * would have to invent a shape for section titles that nothing in this platform holds yet.
     *
     * @param  array<string, string>  $rows  row id => row title
     *
     * @throws InvalidArgumentException when there are no rows, too many, or one is unusable
     */
    public static function list(
        string $recipient,
        string $body,
        string $buttonLabel,
        array $rows,
        ?string $header = null,
        ?string $footer = null,
    ): self {
        $trimmedBody = self::interactiveBody($body);

        if ($rows === []) {
            throw new InvalidArgumentException(
                'An interactive list message needs at least one row: Meta refuses an empty section, '
                .'and an empty menu is a dead end in the flow that sent it.'
            );
        }

        if (count($rows) > self::MAX_LIST_ROWS) {
            throw new InvalidArgumentException(sprintf(
                'Meta accepts at most %d rows in a single-section list; got %d.',
                self::MAX_LIST_ROWS,
                count($rows),
            ));
        }

        $rendered = [];

        foreach ($rows as $id => $title) {
            $rendered[] = [
                'id' => self::interactiveId((string) $id, 'row'),
                'title' => self::interactiveTitle($title, self::MAX_BUTTON_TITLE_LENGTH, 'row'),
            ];
        }

        return new self('interactive', self::envelope($recipient, 'interactive', self::interactive(
            type: 'list',
            body: $trimmedBody,
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
     * The recipient this body addresses, as Meta will see it.
     *
     * What `SendReceipt::$recipient` records — *"the identity the backend actually addressed"* —
     * when the provider's response does not echo a `wa_id` of its own.
     */
    public function recipient(): string
    {
        $to = $this->body['to'] ?? '';

        return is_string($to) ? $to : '';
    }

    /**
     * A recipient reduced to the E.164 digits Meta addresses.
     *
     * @throws InvalidArgumentException when the value is a group, or is not a usable number
     */
    public static function recipientDigits(string $recipient): string
    {
        $trimmed = trim($recipient);

        if (str_ends_with($trimmed, self::GROUP_JID_SUFFIX)) {
            // Refused, not stripped: Meta has no group messaging (`GROUPS` is `❌` on
            // CLOUD_API), and a group id with its domain removed is a plausible-looking number
            // that belongs to a real person somewhere.
            throw new InvalidArgumentException(
                'A Cloud API message cannot be addressed to a group JID: Meta\'s Business Platform '
                .'has no group messaging, and stripping the suffix would send the message to '
                .'whatever number the group id happens to look like.'
            );
        }

        // A JID's user part is everything before the `@`; an E.164 number has no `@` at all.
        $user = str_contains($trimmed, '@') ? strstr($trimmed, '@', true) : $trimmed;
        $digits = (string) preg_replace('/\D+/', '', (string) $user);

        if (preg_match('/^\d{8,15}$/', $digits) !== 1) {
            throw new InvalidArgumentException(
                'A Cloud API recipient must be an E.164 number of 8-15 digits, or a WhatsApp user '
                .'JID carrying one. A mangled number is not sent "best effort": it would deliver to '
                .'a stranger.'
            );
        }

        return $digits;
    }

    /**
     * The envelope every shape shares.
     *
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private static function envelope(string $recipient, string $type, array $content, ?string $replyTo = null): array
    {
        $body = [
            'messaging_product' => self::MESSAGING_PRODUCT,
            'recipient_type' => 'individual',
            'to' => self::recipientDigits($recipient),
            'type' => $type,
        ];

        if ($replyTo !== null && trim($replyTo) !== '') {
            // Meta threads a reply by quoting the inbound message id. Only ever an id the
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
        ?string $header,
        ?string $footer,
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

        // Key order matters for nobody but a reader: `type`, `header`, `body`, `footer`,
        // `action` is the order Meta's own documentation uses, so a captured request body reads
        // like the reference.
        $ordered = ['type' => $interactive['type']];

        foreach (['header', 'body', 'footer', 'action'] as $key) {
            if (array_key_exists($key, $interactive)) {
                $ordered[$key] = $interactive[$key];
            }
        }

        return $ordered;
    }

    /**
     * @throws InvalidArgumentException when the interactive body is empty or over Meta's cap
     */
    private static function interactiveBody(string $body): string
    {
        $trimmed = trim($body);

        if ($trimmed === '') {
            throw new InvalidArgumentException(
                'An interactive message needs body text: the buttons or rows alone give the '
                .'recipient nothing to answer.'
            );
        }

        if (mb_strlen($trimmed) > self::MAX_INTERACTIVE_BODY_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'An interactive message body is limited to %d characters; got %d.',
                self::MAX_INTERACTIVE_BODY_LENGTH,
                mb_strlen($trimmed),
            ));
        }

        return $trimmed;
    }

    /**
     * @throws InvalidArgumentException when the reply id is empty or over Meta's 256-char cap
     */
    private static function interactiveId(string $id, string $what): string
    {
        $trimmed = trim($id);

        if ($trimmed === '' || mb_strlen($trimmed) > 256) {
            throw new InvalidArgumentException(sprintf(
                'An interactive %s needs a reply id of 1-256 characters: it is what the inbound '
                .'webhook echoes back, so the flow cannot correlate the answer without it.',
                $what,
            ));
        }

        return $trimmed;
    }

    /**
     * @throws InvalidArgumentException when the title is empty or over Meta's cap
     */
    private static function interactiveTitle(string $title, int $max, string $what): string
    {
        $trimmed = trim($title);

        if ($trimmed === '') {
            throw new InvalidArgumentException(sprintf('An interactive %s needs a title.', $what));
        }

        if (mb_strlen($trimmed) > $max) {
            throw new InvalidArgumentException(sprintf(
                'An interactive %s title is limited to %d characters; got %d. Meta truncates it, so '
                .'the choice the recipient reads is not the one that was written.',
                $what,
                $max,
                mb_strlen($trimmed),
            ));
        }

        return $trimmed;
    }
}
