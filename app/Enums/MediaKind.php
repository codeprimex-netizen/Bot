<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which WhatsApp message type a media attachment is sent as.
 *
 * ## Why this is not just the MIME type
 *
 * WhatsApp does not send "a file with a content type" — it sends an *image message*, a
 * *document message*, a *voice note*, and they render and behave differently. The mapping
 * from MIME to kind is therefore lossy in exactly one place, and it is the place that
 * matters: `audio/ogg` is a **voice note** when it is a recording and a **document** when it
 * is a music file the tenant wants downloadable, and no amount of sniffing can tell those
 * apart. So the kind is chosen by the caller, with `fromMimeType()` offering the sensible
 * default rather than the only answer.
 *
 * Capability differences between channel modes (a sticker on Cloud API, media size caps per
 * BSP) are **not** modelled here: they belong to `ChannelCapability` and the per-driver
 * capability matrix (task 6.1). This enum only says what the attachment *is*.
 */
enum MediaKind: string
{
    case Image = 'image';
    case Video = 'video';
    case Audio = 'audio';
    case Document = 'document';
    case Sticker = 'sticker';

    /**
     * The kind a MIME type most plausibly is, or `Document` as the universal fallback.
     *
     * `Document` rather than null because every file can be sent as a document: an unknown
     * type still reaches the recipient, which is a better outcome than refusing a send over a
     * classification we did not need to make. `audio/ogg` maps to `Audio`; a caller that means
     * "a downloadable music file" says `Document` explicitly.
     */
    public static function fromMimeType(string $mimeType): self
    {
        $type = strtolower(trim($mimeType));

        return match (true) {
            $type === 'image/webp' => self::Sticker,
            str_starts_with($type, 'image/') => self::Image,
            str_starts_with($type, 'video/') => self::Video,
            str_starts_with($type, 'audio/') => self::Audio,
            default => self::Document,
        };
    }

    /**
     * Whether a filename is meaningful for this kind.
     *
     * Only documents display one. An image or a voice note carries a caption instead, and a
     * filename on those is dead weight on the wire.
     */
    public function usesFilename(): bool
    {
        return $this === self::Document;
    }

    /**
     * Whether WhatsApp renders a caption alongside this kind.
     *
     * Stickers do not, and a caption sent with one is silently dropped by the protocol — so
     * `MediaPayload` refuses it up front instead, where the caller can see it.
     */
    public function usesCaption(): bool
    {
        return $this !== self::Sticker;
    }

    public function label(): string
    {
        return match ($this) {
            self::Image => 'Image',
            self::Video => 'Video',
            self::Audio => 'Audio',
            self::Document => 'Document',
            self::Sticker => 'Sticker',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
