<?php

declare(strict_types=1);

namespace App\Exceptions\Url;

use RuntimeException;

/**
 * The canonical base URL a deployment is configured with cannot be used
 * (Req 9.1, 9.3 / A9; design § Base URL §U.2).
 *
 * ## Why this is loud instead of defaulted
 *
 * `config('app.url')` has a framework default of `http://localhost`, so an
 * unset `APP_URL` does not look unset — it looks like a working deployment on the
 * loopback interface. Every consequence of that is silent and remote: the webhook
 * callback registered with Meta/BSP/Razorpay points at a host no provider can
 * reach, the export links in emails point at the recipient's own machine, and the
 * OAuth redirect never matches the registered allowlist. Each one fails somewhere
 * the operator is not looking, hours after deploy, and each looks like a provider
 * problem rather than a configuration one.
 *
 * So the base URL is validated at the point it is resolved, and an unusable value
 * raises this exception rather than being coerced into something plausible. It is
 * the same choice `MalformedPlanException` makes for unreadable gating data: the
 * value that everything else is derived from is refused, not guessed.
 *
 * Unhandled it surfaces as a 500 — this is a deployment error with no user-facing
 * remedy, and no tenant can cause it.
 *
 * ## Recovering from a bad stored override
 *
 * `platform_settings['base_url']` is validated on write too (`PlatformSettingObserver`),
 * so an admin's typo is refused at the edit that caused it and never reaches the
 * table. A value that got there anyway — raw SQL, a restore from an older schema —
 * is refused on read as well, which does take the panels down; clearing that one
 * row (`PlatformSettings::forget('base_url')`) falls the platform back to `APP_URL`.
 */
final class InvalidBaseUrlException extends RuntimeException
{
    /**
     * Longest raw value echoed back in a message.
     */
    private const int MAX_ECHOED_LENGTH = 120;

    /**
     * Nothing is configured at all: an empty `APP_URL`, or an override row holding
     * an empty string.
     */
    public static function missing(string $source): self
    {
        return new self(sprintf(
            'The canonical base URL is empty (%s). Every absolute link, webhook callback and '
            .'signed URL is built from it, so it cannot be defaulted: set APP_URL to the '
            .'deployment origin (e.g. https://bot.example.com).',
            $source,
        ));
    }

    /**
     * The value is not a usable absolute origin.
     */
    public static function malformed(string $source, string $raw, string $reason): self
    {
        return new self(sprintf(
            'The canonical base URL [%s] (%s) cannot be used: %s. Expected an absolute origin '
            .'such as https://bot.example.com, optionally with a port and a path prefix.',
            self::echoable($raw),
            $source,
            $reason,
        ));
    }

    /**
     * A hostname on its own — a tenant's custom domain — is not usable.
     */
    public static function malformedHost(string $source, string $raw, string $reason): self
    {
        return new self(sprintf(
            'The host [%s] (%s) cannot be used in a canonical base URL: %s. Expected a DNS '
            .'hostname such as chat.acme.example, optionally an IP literal.',
            self::echoable($raw),
            $source,
            $reason,
        ));
    }

    /**
     * The configured origin is the loopback interface, outside `local`/`testing`.
     *
     * Almost always an `APP_URL` nobody set: the framework default is
     * `http://localhost`, and a deployment that keeps it registers webhook callbacks
     * pointing at the provider's own machine.
     */
    public static function loopback(string $source, string $raw, string $environment): self
    {
        return new self(sprintf(
            'The canonical base URL [%s] (%s) points at the loopback interface, but this '
            .'deployment runs in the [%s] environment. That is the framework default for an '
            .'APP_URL nobody set — every webhook callback and signed link would point at a '
            .'host no provider, and no recipient, can reach. Set APP_URL to the public origin.',
            self::echoable($raw),
            $source,
            $environment,
        ));
    }

    /**
     * The offending value, safe to put in a log line: control characters — the
     * header-injection payloads this class exists to refuse — are escaped rather than
     * written through, and the result is truncated.
     */
    private static function echoable(string $raw): string
    {
        $escaped = (string) preg_replace_callback(
            '/[^\x20-\x7E]/',
            static fn (array $match): string => sprintf('\x%02X', ord($match[0])),
            $raw,
        );

        return mb_strimwidth($escaped, 0, self::MAX_ECHOED_LENGTH, '…');
    }
}
