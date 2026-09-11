<?php

declare(strict_types=1);

namespace App\Services\Abuse;

use Illuminate\Support\Str;

/**
 * Turns an identity — email, phone, device fingerprint, IP address — into a keyed
 * digest, so the abuse layer can *count* identities without *holding* them
 * (Req 32.7 / NFR3; design § Observability: phone numbers redacted, bodies hashed).
 *
 * ```php
 * $digest->email('Ada.Lovelace+trial7@Gmail.com');   // same digest as ada.lovelace@gmail.com
 * $digest->phone('+91 98765 43210');                 // same digest as 919876543210
 * $digest->subnet('203.0.113.42');                   // the /24, so rotating a host bit does not reset
 * ```
 *
 * ## Keyed, not plain
 *
 * `hash_hmac` under a platform key rather than a bare `sha256`, because the input space
 * of an email address or a phone number is small enough to enumerate: a plain digest of
 * a phone number is reversible with a laptop, which would make the counters and the
 * `abuse_events.subject_hash` column a phone-number database with extra steps. The key
 * defaults to `APP_KEY` and can be set separately
 * (`wa.security.abuse.hash_key`) so that rotating the app key does not have to
 * invalidate the abuse trail's correlations.
 *
 * ## Normalization is where bypass resistance lives
 *
 * A digest is only as good as the normalization in front of it — `a+1@gmail.com` and
 * `a+2@gmail.com` are the same mailbox, and a free-trial farmer knows it. So:
 *
 * - **email** — lower-cased; a `+tag` suffix is dropped from the local part (every major
 *   provider treats it as the same mailbox); dots are dropped from the local part **only**
 *   for the providers that are documented to ignore them (Gmail and its aliases), because
 *   for other hosts `a.b@` and `ab@` really can be two different people.
 * - **phone** — reduced to digits, so `+91 98765-43210`, `919876543210`, and
 *   `0091 9876543210` (leading zeros trimmed) collapse to one identity.
 * - **device** — the caller's fingerprint string, trimmed and lower-cased. A rotating
 *   fingerprint defeats *this* counter by design; it does not defeat the IP and subnet
 *   counters, which is why the heuristics never rely on one signal alone.
 * - **subnet** — IPv4 `/24` and IPv6 `/64`, so an attacker with a range must burn a
 *   whole range rather than one address per attempt.
 */
final readonly class IdentityDigest
{
    /**
     * Providers documented to ignore dots in the local part.
     *
     * @var list<string>
     */
    private const array DOT_INSENSITIVE_DOMAINS = ['gmail.com', 'googlemail.com'];

    public function __construct(private string $key) {}

    public static function fromConfig(): self
    {
        $configured = config('wa.security.abuse.hash_key');
        $appKey = config('app.key');

        $key = is_string($configured) && $configured !== ''
            ? $configured
            : (is_string($appKey) ? $appKey : '');

        // An empty key would silently turn every digest into an unkeyed hash — i.e. into a
        // reversible one — so a fixed, obviously-non-secret fallback is used instead and
        // the platform still functions in a bare test environment.
        return new self($key === '' ? 'wa-abuse-digest' : $key);
    }

    /**
     * Digest of a normalized email address.
     */
    public function email(string $email): string
    {
        return $this->of('email', $this->normalizeEmail($email));
    }

    /**
     * Digest of a normalized phone number.
     */
    public function phone(string $phone): string
    {
        $digits = ltrim((string) preg_replace('/\D+/', '', $phone), '0');

        return $this->of('phone', $digits);
    }

    /**
     * Digest of a client-supplied device fingerprint.
     */
    public function device(string $fingerprint): string
    {
        return $this->of('device', Str::lower(trim($fingerprint)));
    }

    /**
     * Digest of an exact IP address.
     */
    public function ip(string $ip): string
    {
        return $this->of('ip', Str::lower(trim($ip)));
    }

    /**
     * Digest of the address's network — IPv4 `/24`, IPv6 `/64`.
     */
    public function subnet(string $ip): string
    {
        return $this->of('subnet', $this->network($ip));
    }

    /**
     * Digest of any value, under a namespace so an email digest can never collide with a
     * device digest.
     */
    public function of(string $kind, string $value): string
    {
        return hash_hmac('sha256', $kind.':'.$value, $this->key);
    }

    /**
     * The network part of an address, or the address itself when it cannot be parsed
     * (which is safe: an unparseable value simply counts as its own network).
     */
    public function network(string $ip): string
    {
        $trimmed = trim($ip);

        if (filter_var($trimmed, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $parts = explode('.', $trimmed);

            return implode('.', array_slice($parts, 0, 3)).'.0/24';
        }

        if (filter_var($trimmed, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $packed = inet_pton($trimmed);

            if ($packed !== false) {
                // First 8 bytes = /64, the smallest block a residential IPv6 customer is
                // normally given, so it is the honest unit of "one subscriber".
                return bin2hex(substr($packed, 0, 8)).'::/64';
            }
        }

        return Str::lower($trimmed);
    }

    /**
     * Lower-case, drop the `+tag`, and drop dots for the dot-insensitive providers.
     */
    private function normalizeEmail(string $email): string
    {
        $lowered = Str::lower(trim($email));
        $at = strrpos($lowered, '@');

        if ($at === false) {
            return $lowered;
        }

        $local = substr($lowered, 0, $at);
        $domain = substr($lowered, $at + 1);

        $plus = strpos($local, '+');

        if ($plus !== false) {
            $local = substr($local, 0, $plus);
        }

        if (in_array($domain, self::DOT_INSENSITIVE_DOMAINS, true)) {
            $local = str_replace('.', '', $local);
        }

        return $local.'@'.$domain;
    }

    /**
     * The domain of an email address, lower-cased — what the disposable-domain list is
     * checked against. Empty when the value is not an address.
     */
    public function domainOf(string $email): string
    {
        $lowered = Str::lower(trim($email));
        $at = strrpos($lowered, '@');

        return $at === false ? '' : substr($lowered, $at + 1);
    }
}
