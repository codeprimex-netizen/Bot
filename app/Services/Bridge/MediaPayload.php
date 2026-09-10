<?php

declare(strict_types=1);

namespace App\Services\Bridge;

use App\Enums\MediaKind;
use InvalidArgumentException;
use SensitiveParameter;

/**
 * One media attachment, described for the wire: what it is, and where the bridge gets the
 * bytes from.
 *
 * ## Exactly one source, chosen by the caller
 *
 * A payload is built through `fromUrl()` or `fromBytes()`, never through the constructor's
 * two source arguments at once. The constructor enforces it because "both" and "neither" are
 * both bugs with a silent failure mode — the bridge would pick one arbitrarily, or send an
 * empty attachment — and a media send that arrives blank is worse than one that never left.
 *
 * | Source | When | Cost |
 * |---|---|---|
 * | `fromUrl()` | the bytes are already on the tenant's `wa_media` disk and a signed, expiring URL can be issued | the bridge fetches them itself; nothing large crosses the PHP boundary |
 * | `fromBytes()` | the bytes only exist in this process (a generated image, a TTS clip) | base64 on the wire, so the whole attachment is held in memory twice |
 *
 * `fromUrl()` is the normal path for exactly that reason, and it is also the one that keeps
 * per-tenant isolation intact: the URL is signed against the tenant's own storage prefix, so
 * the bridge never receives a path it could walk.
 *
 * ## What this deliberately does not do
 *
 * No size check, no MIME allow-list, no sniffing. `TenantStorage` already refuses an
 * oversized or disallowed upload at the point where the bytes are accepted
 * (`wa.media.max_bytes`, `wa.media.allowed_mimes`, `finfo` sniffing), which is the only place
 * those checks can be made honestly — a URL cannot be sniffed without fetching it, and
 * re-checking here would either duplicate the rule or, worse, disagree with it.
 */
final readonly class MediaPayload
{
    /**
     * @param  MediaKind  $kind  which WhatsApp message type this is sent as
     * @param  string  $mimeType  the sniffed content type, as recorded when the bytes were stored
     * @param  string|null  $url  a URL the bridge fetches the bytes from — mutually exclusive with $base64
     * @param  string|null  $base64  the bytes, base64-encoded — mutually exclusive with $url
     * @param  string|null  $filename  display name, documents only
     * @param  string|null  $caption  caption text, for every kind except stickers
     *
     * @throws InvalidArgumentException when the source is absent, doubled, or the kind refuses a field
     */
    public function __construct(
        public MediaKind $kind,
        public string $mimeType,
        public ?string $url = null,
        #[SensitiveParameter]
        public ?string $base64 = null,
        public ?string $filename = null,
        public ?string $caption = null,
    ) {
        if (($url === null) === ($base64 === null)) {
            throw new InvalidArgumentException(
                'A media payload needs exactly one source: a URL the bridge fetches, or base64 bytes. '
                .'Neither means an empty attachment, and both means the bridge picks one arbitrarily.'
            );
        }

        if (trim($mimeType) === '') {
            throw new InvalidArgumentException(
                'A media payload needs its sniffed MIME type: the bridge selects the protocol message '
                .'shape from it, and guessing from a filename is how an executable arrives as an image.'
            );
        }

        if ($caption !== null && ! $kind->usesCaption()) {
            throw new InvalidArgumentException(sprintf(
                'A [%s] carries no caption — the protocol drops it silently, so it is refused here '
                .'where the caller can see it.',
                $kind->value,
            ));
        }
    }

    /**
     * The bridge fetches the bytes from $url itself — the normal path for stored media.
     */
    public static function fromUrl(
        string $url,
        string $mimeType,
        ?MediaKind $kind = null,
        ?string $filename = null,
        ?string $caption = null,
    ): self {
        return new self(
            kind: $kind ?? MediaKind::fromMimeType($mimeType),
            mimeType: $mimeType,
            url: $url,
            filename: $filename,
            caption: $caption,
        );
    }

    /**
     * The bytes travel inline, base64-encoded — for media that only exists in this process.
     */
    public static function fromBytes(
        #[SensitiveParameter]
        string $bytes,
        string $mimeType,
        ?MediaKind $kind = null,
        ?string $filename = null,
        ?string $caption = null,
    ): self {
        return new self(
            kind: $kind ?? MediaKind::fromMimeType($mimeType),
            mimeType: $mimeType,
            base64: base64_encode($bytes),
            filename: $filename,
            caption: $caption,
        );
    }

    /**
     * The request body fragment for this attachment.
     *
     * A filename is emitted only for the kind that displays one, so a payload cannot carry a
     * field the protocol ignores — which keeps the request body an honest description of what
     * was asked for.
     *
     * @return array<string, string>
     */
    public function toRequest(): array
    {
        $body = [
            'kind' => $this->kind->value,
            'mime_type' => $this->mimeType,
        ];

        if ($this->url !== null) {
            $body['url'] = $this->url;
        }

        if ($this->base64 !== null) {
            $body['base64'] = $this->base64;
        }

        if ($this->filename !== null && $this->kind->usesFilename()) {
            $body['filename'] = $this->filename;
        }

        if ($this->caption !== null) {
            $body['caption'] = $this->caption;
        }

        return $body;
    }

    /**
     * Keep the bytes out of stack traces, `dd()`, and log context.
     *
     * A base64 attachment is both enormous and, for an inbound voice note or an ID document
     * a customer sent, personal data. `Illuminate\Log`'s scrubber cannot help with a value
     * this deep inside an argument, so the object declines to describe itself.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'kind' => $this->kind->value,
            'mimeType' => $this->mimeType,
            'source' => $this->url !== null ? 'url' : 'bytes',
            'bytes' => $this->base64 === null ? null : strlen($this->base64),
        ];
    }
}
