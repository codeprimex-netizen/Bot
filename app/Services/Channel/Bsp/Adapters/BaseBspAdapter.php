<?php

declare(strict_types=1);

namespace App\Services\Channel\Bsp\Adapters;

use App\Enums\ChannelCapability;
use App\Enums\ChannelCapabilitySupport;
use App\Enums\ChannelMode;
use App\Enums\ErrorClass;
use App\Enums\InboundEventKind;
use App\Exceptions\Channel\ChannelRequestFailedException;
use App\Exceptions\Channel\WebhookVerificationException;
use App\Models\CloudApiTemplate;
use App\Services\Bridge\BridgeWire;
use App\Services\Channel\Bsp\BspAdapter;
use App\Services\Channel\ChannelCredentials;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * What all eight `BspAdapter` implementations do identically — base URL resolution, the shape
 * of the runtime capability sub-matrix, recipient normalisation, and the code-table form of
 * `classify()` (Req 8.1, 8.2 / A8; design § Channel Mode 2.3, 2.7).
 *
 * ```php
 * final readonly class GupshupAdapter extends BaseBspAdapter
 * {
 *     public function provider(): BspProvider { return BspProvider::Gupshup; }
 *     public function defaultBaseUrl(): string { return 'https://api.gupshup.io'; }
 *     protected function authSecretKey(): string { return 'apikey'; }
 *     // baseUrl(), refineSupport(), decodeWebhook(), the digits rule: inherited
 * }
 * ```
 *
 * A base class rather than a trait because there is a genuine *is-a*: everything here is the
 * behaviour of "a BSP adapter", not a mixin an unrelated class might want, and an abstract
 * method (`authSecretKey()`) is how a subclass is *required* to name its own credential rather
 * than reminded to.
 *
 * ## The runtime sub-matrix, and the five credential keys it reads
 *
 * design § 2.3's *"the driver reports each provider's real `supports()` at runtime"* comes down
 * to five booleans and one number, all in the credentials' **non-secret** `config` bag — which
 * is where they belong, because each is a fact about one tenant's contract with one partner
 * rather than about the partner or about this deployment:
 *
 * | Key | Default | Narrows |
 * |---|---|---|
 * | `media_enabled` | `true` | `MEDIA` → `Unsupported` when the account cannot send media |
 * | `media_max_bytes` | — | nothing; it is a *quantity*, read by the send path, and design.md's *"media size/type caps vary"* |
 * | `interactive_enabled` | per partner | `INTERACTIVE` → `Unsupported`, or `Native`/`Conditional` — see `interactiveSupport()` |
 * | `templates_enabled` | `true` | `TEMPLATE` → `Unsupported` for an account with no approved template registry |
 * | `delivery_receipts` | `true` | `DELIVERY_RECEIPTS` → `Unsupported` when the partner account has no status callback configured |
 * | `free_form_window_hours` | — | nothing; the 24-hour rule is task 8.4's |
 *
 * Every arm either leaves the ceiling alone or moves it **down**. The two directions a
 * refinement is allowed to move a cell inside "supported" (`⚠️` → `✅` and `✅` → `⚠️`) are used
 * only for `INTERACTIVE`, which is the one cell design.md distinguishes per partner. Nothing
 * here can produce a verdict wider than the ceiling, and the driver would absorb it if it did.
 *
 * ## Recipient digits
 *
 * Every partner addresses a WhatsApp user by number, so `digits()` is the one normalisation all
 * eight share: separators and a leading `+` stripped, and 8–15 digits required — the rule
 * `HttpBridgeClient::digits()` states, for its reason (*"sending to a mangled number is how one
 * tenant's typo becomes a message to a stranger"*). A partner's own presentation, such as
 * Twilio's `whatsapp:+` prefix, is added on top by the subclass.
 */
abstract readonly class BaseBspAdapter implements BspAdapter
{
    /**
     * The `config` key every partner may override its API host with — §2.7's *"endpoint/base
     * URL"*.
     *
     * Per tenant rather than per deployment because several partners are genuinely per-account:
     * Infobip issues a personal `{id}.api.infobip.com` and WATI a numbered
     * `live-server-{n}.wati.io`, so there is no platform-wide default that would work.
     */
    public const string BASE_URL_CONFIG_KEY = 'base_url';

    /**
     * The `config` key naming the WhatsApp sender these credentials own.
     *
     * Also the expected side of the webhook recipient check for the six partners that identify a
     * sender by number. Gupshup (application name) and MessageBird (channel id) override
     * `senderIdentity()`.
     */
    public const string SENDER_CONFIG_KEY = 'sender';

    /**
     * The webhook signing secret §2.7 gives every `BSP_GATEWAY` credential set.
     */
    public const string WEBHOOK_SECRET_KEY = 'webhook_secret';

    /**
     * The `config` key naming which header the webhook secret is presented in, for the three
     * partners that publish no MAC and leave the header to the operator
     * (`BspWebhookVerifier::assertSharedSecretHeader()`).
     */
    public const string WEBHOOK_HEADER_CONFIG_KEY = 'webhook_secret_header';

    /**
     * The runtime sub-matrix keys — see the table in the class docblock.
     */
    public const string MEDIA_ENABLED_KEY = 'media_enabled';

    public const string MEDIA_MAX_BYTES_KEY = 'media_max_bytes';

    public const string INTERACTIVE_ENABLED_KEY = 'interactive_enabled';

    public const string TEMPLATES_ENABLED_KEY = 'templates_enabled';

    public const string DELIVERY_RECEIPTS_KEY = 'delivery_receipts';

    /*
    |--------------------------------------------------------------------------
    | Credentials
    |--------------------------------------------------------------------------
    */

    /**
     * The `secret_config` key this partner's API credential is stored under.
     *
     * Abstract because the eight names are all different (`auth_token`, `api_key`, `apikey`,
     * `access_key`, `access_token`), and every one of them matches
     * `App\Support\Pii\PiiKeyRules::SECRET_PATTERN`, so a caller who logs a decrypted bag still
     * gets `[redacted]`.
     */
    abstract protected function authSecretKey(): string;

    /**
     * The API host these credentials use, without a trailing slash.
     *
     * Precedence: the tenant's `base_url`, then this deployment's
     * `wa.channel.bsp.{slug}.base_url`, then the compiled-in default. The middle step exists for
     * a sandbox or a regional edge; the last is what makes a deleted config key degrade to a
     * working adapter rather than to a malformed URL (`CloudApiChannelDriver::baseUrl()`'s rule).
     *
     * A candidate that is not an absolute `http(s)` URL is skipped rather than used. This value
     * is where a tenant's API key is about to be sent, so a value that is not a URL must not
     * become the host part of one.
     */
    public function baseUrl(ChannelCredentials $credentials): string
    {
        $candidates = [
            $credentials->config(self::BASE_URL_CONFIG_KEY),
            config('wa.channel.bsp.'.$this->provider()->webhookSlug().'.base_url'),
        ];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $trimmed = rtrim(trim($candidate), '/');

            if (str_starts_with($trimmed, 'https://') || str_starts_with($trimmed, 'http://')) {
                return $trimmed;
            }
        }

        return rtrim($this->defaultBaseUrl(), '/');
    }

    /**
     * `base_url` plus the partner's own required identifiers.
     *
     * `base_url` is required for every partner even though `defaultBaseUrl()` exists, because a
     * tenant who has not entered one has almost certainly not finished onboarding — and for
     * Infobip and WATI the default is only a shape, so a send would go to a host that is not
     * theirs.
     *
     * @return list<string>
     */
    public function requiredConfigKeys(): array
    {
        return [self::BASE_URL_CONFIG_KEY, self::SENDER_CONFIG_KEY];
    }

    /**
     * The partner's API credential, plus the webhook signing secret.
     *
     * The webhook secret is *required*, not optional: without it no inbound message can ever be
     * verified, and a connection that can only talk is not connected — the call
     * `CloudApiChannelDriver::missingCredentialKeys()` makes about Meta's `app_secret`, for the
     * same reason.
     *
     * @return list<string>
     */
    public function requiredSecretKeys(): array
    {
        return [$this->authSecretKey(), self::WEBHOOK_SECRET_KEY];
    }

    /**
     * The sender identity these credentials own, as digits.
     *
     * @throws InvalidArgumentException when the credentials name no sender
     */
    public function senderIdentity(ChannelCredentials $credentials): string
    {
        $sender = self::digits($credentials->requireConfig(self::SENDER_CONFIG_KEY));

        if ($sender === null) {
            throw new InvalidArgumentException(sprintf(
                'The [%s] credentials name a [%s] that is not an E.164 number. The sender identity is '
                .'what every inbound webhook is checked against, so a mangled one would either refuse '
                .'all traffic or match a number this tenant does not own.',
                $this->provider()->value,
                self::SENDER_CONFIG_KEY,
            ));
        }

        return $sender;
    }

    /*
    |--------------------------------------------------------------------------
    | The runtime capability sub-matrix
    |--------------------------------------------------------------------------
    */

    /**
     * This partner account's answer for `$capability` — layer 3 of the sub-matrix.
     *
     * One exhaustive `match`, with no default arm, for `ChannelCapability`'s own reason about
     * the matrix: a capability added in a later phase must be a static-analysis error here
     * rather than silently taking whichever arm happens to be last.
     *
     * Every arm returns `$ceiling` or something narrower. `INTERACTIVE` is the only cell that
     * may also be resolved *upward* inside "supported" (`⚠️` → `✅`), which is exactly the
     * resolution design § 2.3 asks for — and it still cannot escape the ceiling, because the
     * driver combines this through `ChannelCapabilitySupport::refinedBy()`.
     */
    public function refineSupport(
        ChannelCapability $capability,
        ChannelCapabilitySupport $ceiling,
        ChannelCredentials $credentials,
    ): ChannelCapabilitySupport {
        return match ($capability) {
            // Media: the ceiling is `⚠️ per provider`. An account that cannot send media at all
            // is a refusal; the size and type caps that "vary per provider" are a quantity the
            // send path reads from `media_max_bytes`, not a capability.
            ChannelCapability::Media => self::flag($credentials, self::MEDIA_ENABLED_KEY, true)
                ? $ceiling
                : ChannelCapabilitySupport::Unsupported,

            // Interactive: the one cell design.md distinguishes per partner, so the decision is
            // the subclass's. See `interactiveSupport()`.
            ChannelCapability::Interactive => $this->interactiveSupport($ceiling, $credentials),

            // Templates: `✅ (provider template sync)` at the ceiling, but an account with no
            // approved template registry cannot send one — and a `sendTemplate()` on it would be
            // a provider rejection charged to the number's quality rating rather than the typed
            // pre-dispatch refusal Req 8.3 requires.
            ChannelCapability::Template => self::flag($credentials, self::TEMPLATES_ENABLED_KEY, true)
                ? $ceiling
                : ChannelCapabilitySupport::Unsupported,

            // Delivery receipts: `⚠️ per provider`. A partner account whose status callback was
            // never configured delivers none, and reporting the capability would leave every
            // message permanently at "sent" on the delivery board.
            ChannelCapability::DeliveryReceipts => self::flag($credentials, self::DELIVERY_RECEIPTS_KEY, true)
                ? $ceiling
                : ChannelCapabilitySupport::Unsupported,

            /*
             * Everything else is the ceiling unchanged, and each for a stated reason:
             *
             * - `SEND_SINGLE` is `✅` on every backend — the one operation all of them have.
             * - `SEND_BULK` and `FREE_FORM_ANYTIME` are `⚠️` for rules rather than capabilities:
             *   the per-provider tier (task 8.1) and the 24-hour window (task 8.4). A `Conditional`
             *   is precisely "attempt it, subject to a rule the pipeline still enforces".
             * - `INBOUND_WEBHOOK` is `✅`: every partner has a callback, and *which* proof it
             *   carries is `verifyWebhook()`'s business rather than a capability.
             * - the five Baileys-only cells are `❌` at the ceiling. Listed so the `match` stays
             *   exhaustive; the driver never asks about them, and `refinedBy()` would absorb an
             *   answer anyway.
             */
            ChannelCapability::SendSingle,
            ChannelCapability::SendBulk,
            ChannelCapability::FreeFormAnytime,
            ChannelCapability::InboundWebhook,
            ChannelCapability::Groups,
            ChannelCapability::Welcome,
            ChannelCapability::Extraction,
            ChannelCapability::Tagging,
            ChannelCapability::Channels => $ceiling,
        };
    }

    /**
     * How this partner account does buttons and lists.
     *
     * The default is the honest reading of `BspProvider::declaredSupport()`'s own layer, refined
     * by the account: an explicit `interactive_enabled = false` is a refusal, and otherwise the
     * `⚠️`/`✅` the partner layer already decided stands. Twilio and Vonage override it, because
     * for them interactive is reachable *only* through a content template registered with the
     * partner (design § 2.3), which is a condition rather than a capability — and an account with
     * no content service configured cannot do it at all.
     */
    protected function interactiveSupport(
        ChannelCapabilitySupport $ceiling,
        ChannelCredentials $credentials,
    ): ChannelCapabilitySupport {
        return self::flag($credentials, self::INTERACTIVE_ENABLED_KEY, true)
            ? $ceiling
            : ChannelCapabilitySupport::Unsupported;
    }

    /*
    |--------------------------------------------------------------------------
    | Inbound
    |--------------------------------------------------------------------------
    */

    /**
     * The verified body as a JSON object — seven of the eight partners' encoding.
     *
     * `TwilioAdapter` overrides this: its callbacks are `application/x-www-form-urlencoded`.
     *
     * @return array<string, mixed>
     *
     * @throws WebhookVerificationException when the body is not a JSON object
     */
    public function decodeWebhook(Request $request): array
    {
        $decoded = json_decode($request->getContent(), true);

        if (! is_array($decoded)) {
            throw WebhookVerificationException::malformedPayload(
                ChannelMode::BspGateway,
                'its body is not a JSON object',
            );
        }

        return BridgeWire::arrayOrEmpty($decoded);
    }

    /**
     * Every callback from this partner names the number it is about — the safe answer, and the
     * default.
     *
     * `true` here means a payload that names none is refused as malformed rather than accepted
     * unchecked, so weakening the recipient check is something an adapter has to *do*, in an
     * override, next to the argument for it. The four adapters that override it say why.
     */
    public function requiresRecipientClaim(): bool
    {
        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | Small shared readers
    |--------------------------------------------------------------------------
    */

    /**
     * The webhook signing secret, or a refusal naming the missing key.
     *
     * @throws InvalidArgumentException when no webhook secret is stored
     */
    protected function webhookSecret(ChannelCredentials $credentials): string
    {
        return $credentials->requireSecret(self::WEBHOOK_SECRET_KEY);
    }

    /**
     * Which header the webhook secret is presented in, for the partners the operator configures.
     *
     * From the credentials when the tenant said, and `$default` otherwise. Restricted to a
     * header-name shape because the value is used as a header *name*: a value carrying a colon
     * or a newline is a header-injection attempt, and one carrying nothing at all would read
     * every header.
     */
    protected function webhookSecretHeader(ChannelCredentials $credentials, string $default): string
    {
        $configured = $credentials->config(self::WEBHOOK_HEADER_CONFIG_KEY);

        if (is_string($configured) && preg_match('/^[A-Za-z0-9-]{1,64}$/', trim($configured)) === 1) {
            return trim($configured);
        }

        return $default;
    }

    /**
     * The partner's own handle for a template, or its name when the partner addresses templates
     * by name.
     *
     * `provider_template_id` is populated by task 8.4's template sync; a row synced from a
     * partner that issues an id (Twilio's `ContentSid`) carries it, and one from a partner that
     * does not carries `null`.
     */
    protected static function templateHandle(CloudApiTemplate $template): string
    {
        $id = $template->provider_template_id;

        return $id === null || trim($id) === '' ? $template->name : trim($id);
    }

    /**
     * A boolean from the credentials' config, defaulting to `$default`.
     *
     * Tolerant of the shapes a stored JSON column and a panel form actually produce — `true`,
     * `"true"`, `1`, `"1"`, `"no"` — because this bag is written by a tenant-facing form and by
     * task 8.4's sync, and a `"false"` string read as truthy would silently *widen* a capability.
     * An unreadable value falls back to `$default` rather than to `false`: the defaults are the
     * partner's normal configuration, and a typo must not take a working capability away.
     */
    protected static function flag(ChannelCredentials $credentials, string $key, bool $default): bool
    {
        $value = $credentials->config($key);

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        if (is_string($value)) {
            return match (strtolower(trim($value))) {
                'true', '1', 'yes', 'on', 'enabled' => true,
                'false', '0', 'no', 'off', 'disabled' => false,
                default => $default,
            };
        }

        return $default;
    }

    /**
     * A phone number reduced to E.164 digits, or `null` when it is not one.
     *
     * `HttpBridgeClient::digits()`'s rule — 8–15 digits after separators and a leading `+` are
     * stripped, and `null` rather than a best effort. A partner presentation prefix
     * (`whatsapp:+1…`) is stripped by the same pass, since it contributes no digits.
     */
    protected static function digits(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = (string) preg_replace('/\D+/', '', $value);

        return preg_match('/^\d{8,15}$/', $digits) === 1 ? $digits : null;
    }

    /**
     * A partner timestamp as an instant, or `null`.
     *
     * Epoch seconds arrive as a quoted string from several partners, which is the one shape
     * `BridgeWire::timestampOrNull()` cannot read alone — it would parse `1735786800` as a year.
     * Converted to an int first, exactly as `CloudApiChannelDriver::timestampOf()` does, and
     * `null` rather than `now()` for anything unreadable: a fabricated occurrence time is
     * indistinguishable from a real one afterwards.
     */
    protected static function timestamp(mixed $value): ?CarbonImmutable
    {
        if (is_string($value) && preg_match('/^\d{1,14}$/', trim($value)) === 1) {
            $value = (int) trim($value);
        }

        return BridgeWire::timestampOrNull($value);
    }

    /**
     * One of the receipt kinds from a partner's status word, or `null` for a word this release
     * does not act on.
     *
     * The words themselves are partner vocabulary and are supplied by the subclass; the mapping
     * onto `InboundEventKind` is shared because it is the platform's. `sent` and `delivered`
     * collapse into one kind, as that enum prescribes, and the raw word is preserved in the
     * event payload so task 9.5 can tell "accepted by the partner" from "on the device" when it
     * applies the never-downgrades rule (Req 20.6 / C3, Property 9).
     *
     * @param  list<string>  $accepted  partner words meaning "the partner has it"
     * @param  list<string>  $delivered  partner words meaning "the handset has it"
     * @param  list<string>  $read  partner words meaning "the customer opened it"
     * @param  list<string>  $failed  partner words meaning "it will not arrive"
     */
    protected static function receiptKind(
        ?string $status,
        array $accepted,
        array $delivered,
        array $read,
        array $failed,
    ): ?InboundEventKind {
        if ($status === null) {
            return null;
        }

        $lowered = strtolower(trim($status));

        return match (true) {
            in_array($lowered, $accepted, true), in_array($lowered, $delivered, true) => InboundEventKind::DeliveryReceipt,
            in_array($lowered, $read, true) => InboundEventKind::ReadReceipt,
            in_array($lowered, $failed, true) => InboundEventKind::SendFailure,
            default => null,
        };
    }

    /**
     * The first `ErrorClass` whose code table contains this refusal's code, or `null`.
     *
     * The shape every adapter's `classify()` takes: a partner's codes as a table keyed by the
     * class they map to, evaluated in the table's own order so a code listed twice resolves the
     * same way every time. Written once here because the *lookup* is identical for all eight and
     * only the table is the partner's.
     *
     * `ChannelRequestFailedException::hasErrorCode()` takes ints and strings alike, which is what
     * lets Twilio's `20003` and Infobip's `UNAUTHORIZED` share this method.
     *
     * @param  array<string, list<int|string>>  $table  `ErrorClass::value` → the partner's codes
     */
    protected static function classifyByCode(ChannelRequestFailedException $e, array $table): ?ErrorClass
    {
        if ($e->errorCode === null) {
            return null;
        }

        foreach ($table as $class => $codes) {
            if ($codes !== [] && $e->hasErrorCode(...$codes)) {
                return ErrorClass::from($class);
            }
        }

        return null;
    }
}
