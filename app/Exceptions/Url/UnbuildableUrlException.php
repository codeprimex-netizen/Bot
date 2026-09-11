<?php

declare(strict_types=1);

namespace App\Exceptions\Url;

use RuntimeException;

/**
 * A URL the platform was asked to emit cannot be built (Req 9.1, 9.2, 9.6 / A9;
 * design § Base URL §U.3, §U.5).
 *
 * The generation-side sibling of `InvalidBaseUrlException`: that one refuses a bad
 * *origin*, this one refuses a bad *request for a URL* — a path that would move the
 * origin, an expiry window past the 3600-second cap, a tenant subdomain that would
 * resolve to somebody else.
 *
 * ## Why every one of these is a throw and not a repair
 *
 * Each case below is a caller bug or a deployment error, and each has a plausible
 * "helpful" repair that is worse than the failure:
 *
 * | Asked for | Tempting repair | What the repair actually ships |
 * |---|---|---|
 * | `absolute('//attacker.example/x')` | treat it as a path | a link to another origin, emitted by us |
 * | `signed($path, +30 days)` | clamp to 3600s | a link the caller believes is valid for a month, dead in an hour |
 * | `signed($path, yesterday)` | extend it | a link that authorises access the caller meant to have already ended |
 * | `tenantSubdomain($t)` for a reserved label | emit it anyway | `api.apex` / `admin.apex` handed out as a tenant's home |
 * | `tenantSubdomain($t)` for a label another tenant claims | emit it anyway | a link that resolves to the **other** tenant |
 *
 * The last two are the sharpest: a URL that resolves to the wrong tenant is not a broken
 * link, it is a cross-tenant one, and it fails silently — the recipient lands somewhere
 * plausible. So a subdomain that cannot be proven to resolve back to the tenant it was
 * asked for is never emitted.
 *
 * Unhandled this surfaces as a 500. That is correct: no tenant input reaches these
 * arguments (paths and providers are literals in our own code, expiries come from our own
 * policy), so every occurrence is ours to fix.
 */
final class UnbuildableUrlException extends RuntimeException
{
    /**
     * Longest raw value echoed back in a message.
     */
    private const int MAX_ECHOED_LENGTH = 120;

    /**
     * A path argument cannot be appended to a base URL.
     *
     * @param  string  $context  which builder method was called, for the message
     */
    public static function path(string $context, string $path, string $reason): self
    {
        return new self(sprintf(
            'The path [%s] cannot be used to build a URL (%s): %s. Pass a plain, '
            .'server-side path such as /exports/01J.../download — a query string, a '
            .'fragment, a relative segment or an encoded separator either moves the URL '
            .'somewhere the caller did not mean or makes a signature ambiguous.',
            self::echoable($path),
            $context,
            $reason,
        ));
    }

    /**
     * `signed()` / `webhook()` were given nothing to point at.
     */
    public static function emptyPath(string $context): self
    {
        return new self(sprintf(
            'A %s URL needs a path: it points at one resource, and the bare origin is not '
            .'one. Use BaseUrl::platform()/forTenant() if the origin itself is what you want.',
            $context,
        ));
    }

    /**
     * The expiry has already passed, or is the instant of signing.
     */
    public static function expiryNotInFuture(int $expiresAt, int $now): self
    {
        return new self(sprintf(
            'A signed URL cannot expire at or before the moment it is signed (expiry %d, '
            .'now %d). A link that is born expired is refused here rather than emitted and '
            .'rejected later, where it looks like a platform fault to whoever received it.',
            $expiresAt,
            $now,
        ));
    }

    /**
     * The requested validity window is longer than Req 9.6 allows.
     */
    public static function windowTooLong(int $seconds, int $maximum): self
    {
        return new self(sprintf(
            'A signed URL may be valid for at most %d seconds (Req 9.6), but %d were '
            .'requested. It is refused at generation, not clamped: a caller that asked for '
            .'a long-lived link would otherwise hand out a URL it believes in and the '
            .'platform does not. Long-lived access needs an authenticated route, not a '
            .'bearer link in an email.',
            $maximum,
            $seconds,
        ));
    }

    /**
     * `wa.url.signed.ttl_seconds` is not a usable default lifetime.
     */
    public static function configuredTtl(int $seconds, int $maximum): self
    {
        return new self(sprintf(
            'wa.url.signed.ttl_seconds is %d, which cannot be the default lifetime of a '
            .'signed link: it must be between 1 and %d seconds (Req 9.6 caps the window, '
            .'and config cannot raise a security ceiling). Set WA_URL_SIGNED_TTL to a '
            .'value in that range.',
            $seconds,
            $maximum,
        ));
    }

    /**
     * A webhook provider slug is not a single safe path segment.
     */
    public static function provider(string $provider): self
    {
        return new self(sprintf(
            'The webhook provider [%s] is not a usable path segment. Expected a short '
            .'lowercase slug such as bridge, cloud-api or razorpay: the value becomes part '
            .'of a URL handed to a third party, so it may not carry a separator, a dot or '
            .'an escape.',
            self::echoable($provider),
        ));
    }

    /**
     * A webhook route key is not a single safe path segment.
     */
    public static function routeKey(string $routeKey): self
    {
        return new self(sprintf(
            'The webhook route key [%s] is not a usable path segment. Expected 1-128 '
            .'characters of [A-Za-z0-9_-] — a ULID or a random token — since this is the '
            .'value inbound webhooks are mapped back to one (tenant, session, driver) '
            .'tuple by.',
            self::echoable($routeKey),
        ));
    }

    /**
     * The origin a webhook would be registered against is not HTTPS (Req 9.2).
     */
    public static function insecureWebhookBase(string $base, string $environment): self
    {
        return new self(sprintf(
            'The webhook callback URL would be registered as [%s], but Req 9.2 requires '
            .'HTTPS and this deployment runs in the [%s] environment. A callback URL is '
            .'fetched from the public internet and carries a verify token or an HMAC, so '
            .'over http:// it is a credential on the wire. Unset WA_URL_FORCE_HTTPS (it is '
            .'pinned to false) or set APP_URL to the https origin.',
            self::echoable($base),
            $environment,
        ));
    }

    /**
     * The label a tenant's subdomain would use is not a DNS label.
     */
    public static function unusableSubdomainLabel(string $label, string $reason): self
    {
        return new self(sprintf(
            'The tenant subdomain label [%s] cannot be used: %s. It comes from the '
            .'tenant\'s subdomain column, or from its slug when that is unset, so a tenant '
            .'that cannot have a subdomain has a data problem rather than a URL problem.',
            self::echoable($label),
            $reason,
        ));
    }

    /**
     * The label is one the platform keeps for itself.
     */
    public static function reservedSubdomain(string $label): self
    {
        return new self(sprintf(
            'The tenant subdomain label [%s] is reserved by the platform '
            .'(wa.tenancy.reserved_subdomains), so no request to it would ever resolve to '
            .'a tenant. Emitting the URL anyway would hand a tenant a home page that '
            .'belongs to the platform.',
            self::echoable($label),
        ));
    }

    /**
     * Another tenant already answers on that label.
     */
    public static function subdomainCollision(string $label): self
    {
        return new self(sprintf(
            'The tenant subdomain label [%s] is claimed by another tenant, which set it as '
            .'its explicit subdomain while this tenant reaches it only through its slug. '
            .'Tenant resolution matches the subdomain column first, so the URL would '
            .'resolve to the other tenant — a cross-tenant link, not a broken one. Give '
            .'this tenant an explicit subdomain (Req 9.7 refuses the collision at '
            .'assignment).',
            self::echoable($label),
        ));
    }

    /**
     * The offending value, safe to put in a log line: control characters — the
     * header-injection payloads these checks exist to refuse — are escaped rather than
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
