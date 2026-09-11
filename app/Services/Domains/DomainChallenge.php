<?php

declare(strict_types=1);

namespace App\Services\Domains;

use App\Enums\DomainChallengeMethod;
use Illuminate\Support\Carbon;

/**
 * The instructions a tenant is given to prove it controls a host, and the exact thing
 * the platform will look for (Req 9.7 / A9).
 *
 * One object rather than a bag of strings because the two halves must not drift: the
 * record the tenant is *shown* and the record the verifier *reads* are the same two
 * fields of the same value, derived once at issuance from the stored
 * `challenge_method` / `challenge_token`. A panel that formatted its own record name
 * would eventually format a different one.
 *
 * ```php
 * $challenge = $verifier->issueChallenge($domain, DomainChallengeMethod::DnsTxt);
 *
 * $challenge->recordName;    // '_wa-challenge.chat.acme.example'
 * $challenge->recordValue;   // 'sX7…' — publish this as a TXT value
 * ```
 *
 * The token is a live credential for exactly one transition, so this object is built for
 * the response that issues it and is not stored anywhere beyond the row's columns; a
 * successful verification clears them.
 *
 * @immutable
 */
final readonly class DomainChallenge
{
    /**
     * @param  string  $host  the claimed host, normalised
     * @param  string  $recordName  DNS name to publish the TXT record at (`dns_txt` only)
     * @param  string  $recordValue  the TXT value / the file body — the token itself
     * @param  string  $url  the URL the platform will fetch (`http_file` only)
     */
    public function __construct(
        public DomainChallengeMethod $method,
        public string $host,
        public string $token,
        public string $recordName,
        public string $recordValue,
        public string $url,
        public Carbon $expiresAt,
    ) {}

    public function isExpiredAt(Carbon $moment): bool
    {
        return $moment->greaterThan($this->expiresAt);
    }

    /**
     * Everything the tenant-facing verification screen (Phase 17+) needs to render the
     * instructions, and nothing else.
     *
     * Shaped for a panel and an API response alike. It contains the token, which is the
     * point — the tenant has to publish it — but it names no other tenant, no internal
     * host, and no platform detail.
     *
     * @return array<string, string>
     */
    public function instructions(): array
    {
        $common = [
            'method' => $this->method->value,
            'host' => $this->host,
            'instruction' => $this->method->instruction(),
            'expires_at' => $this->expiresAt->toIso8601String(),
        ];

        return match ($this->method) {
            DomainChallengeMethod::DnsTxt => $common + [
                'record_type' => 'TXT',
                'record_name' => $this->recordName,
                'record_value' => $this->recordValue,
            ],
            DomainChallengeMethod::HttpFile => $common + [
                'url' => $this->url,
                'body' => $this->recordValue,
            ],
        };
    }
}
