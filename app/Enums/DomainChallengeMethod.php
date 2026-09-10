<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a tenant proves it controls a custom domain (Req 9.7 / A9).
 *
 * Two methods, because the two things a tenant may be able to change are different:
 * the DNS zone, and what the host serves. A tenant whose DNS is managed by an agency
 * can still drop a file on the web server, and a tenant whose web server is ours (the
 * domain already points at the platform) can only realistically edit DNS. Neither is
 * weaker than the other — both prove control of the name — so the tenant picks one at
 * issuance and that choice is stored, so the check that runs later is the check the
 * tenant was given instructions for.
 *
 * ## Why the method is pinned rather than "whichever passes"
 *
 * A verifier that tried both would verify a domain on evidence the tenant never
 * published, which sounds harmless until the same token is reused: an attacker who
 * learns a token from a DNS zone (public) could satisfy the HTTP half on a host it
 * happens to serve. One issuance, one method, one token.
 */
enum DomainChallengeMethod: string
{
    /**
     * A TXT record at `{prefix}.{host}` (default `_wa-challenge.chat.acme.example`)
     * whose value is the challenge token. Proves control of the DNS zone.
     */
    case DnsTxt = 'dns_txt';

    /**
     * The token served as the body of `http://{host}/{path}/{token}` — ACME's http-01
     * shape. Proves control of what the host answers with on port 80, which for a
     * domain already pointed at the platform is served by
     * `App\Http\Controllers\DomainChallengeController` and therefore proves the DNS
     * record points *here*.
     *
     * Plain HTTP on purpose: TLS for the custom domain may not exist yet — confirming
     * it is the separate half of Req 9.7, and requiring it here would make the
     * challenge unsatisfiable until after the certificate it is a precondition for.
     */
    case HttpFile = 'http_file';

    /**
     * The method a tenant gets when it expresses no preference.
     *
     * DNS, because it is the only one that works before the domain points at the
     * platform — which is the state every claim starts in.
     */
    public static function default(): self
    {
        return self::DnsTxt;
    }

    public static function tryFromLoose(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom(strtolower(trim($value)));
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * One sentence for the tenant-facing verification screen (Phase 17+).
     */
    public function instruction(): string
    {
        return match ($this) {
            self::DnsTxt => 'Publish the TXT record below in your DNS zone, then check again.',
            self::HttpFile => 'Point the domain at the platform, or serve the token below at the '
                .'given path over plain HTTP, then check again.',
        };
    }
}
