<?php

declare(strict_types=1);

namespace Tests\Fixtures\Url;

use Illuminate\Support\Str;

/**
 * The generator and the independent judge for Correctness Property 27 — canonical-host
 * URL generation, no host-header injection (Req 9.1, 9.4 / A9; Req 32.2 / NFR3;
 * design § Base URL §U.5).
 *
 * Two jobs, and they are deliberately in one class because they must not share code with
 * the platform:
 *
 * 1. **Hostile hosts are drawn, not listed.** `AcceptedHostTest`, `BaseUrlTest`,
 *    `UrlBuilderTest` and `SignedUrlTest` each carry a tidy `->with([...])` list of hosts
 *    somebody thought of. This class enumerates *classes* of host — an attacker origin, our
 *    host with an attacker suffix, our host as somebody's prefix, a punycode homograph, an
 *    IDN form, an explicit port, userinfo, a CRLF payload, a NUL, an absolute URL, IP
 *    literals, a 300-character host, an over-long label, two and three labels under our
 *    apex, an underscore label, empty, whitespace, and our own host in random case with a
 *    random number of trailing dots — and draws a fresh member of each class per iteration,
 *    against the *drawn* canonical host and apex rather than a hardcoded one.
 * 2. **The verdict comes from somewhere else.** `accepted()` re-derives Req 9.4's rule
 *    from scratch, by counting labels, where `HostAllowlist` derives it from suffix
 *    matching. The two implementations agree only if the rule is right — which is the whole
 *    point of an oracle, and is why the "widen the pattern to `.*\.apex$`" mutant is caught
 *    here rather than being asserted into existence.
 *
 * ## Reproducibility
 *
 * `random_int()` cannot be replayed and seeding `mt_srand()` for `fake()` would also seed
 * the model factories (where `fake()->unique()->company()` starts colliding across
 * iterations and throws before the property is ever evaluated). So every value comes from
 * `sha256(seed:draw)`, one seed fixes the whole test, the seed is printed in every failure
 * message, and it replays:
 *
 * ```
 * CANONICAL_HOST_SEED=140737488355328 vendor/bin/pest --filter='<test name>'
 * ```
 *
 * @see AuditChainProbe the same construction, for Property 17
 */
final class CanonicalHostProbe
{
    /**
     * Set this to replay a failed run.
     */
    public const string SEED_ENV = 'CANONICAL_HOST_SEED';

    /**
     * Every class of hostile or confusable `Host` value, enumerated.
     *
     * The *class* is the one dimension small enough to cover exhaustively, so every
     * iteration walks all of them (in a seed-shuffled order) while the concrete value
     * inside each class is drawn. A randomly sampled class would leave a third of them
     * unexercised per run, and a coverage assertion over sampled classes is a flake
     * waiting for a slow afternoon.
     *
     * Six of these are hosts the platform **must accept** (`apex_itself`, `apex_label`,
     * `operator_listed`, `canonical_respelled`, and — when the apexes are derived from the
     * platform host — `canonical_prefixed`). They are in the same list on purpose: a
     * generator that only produced rejects would let an allowlist that refuses everything
     * pass, and the oracle has to decide the verdict rather than the test author.
     *
     * @var non-empty-list<string>
     */
    public const array HOST_CLASSES = [
        'attacker_origin',          // a host that is simply not ours
        'attacker_origin_with_port', // ...carrying a port, so a naive split sees a hostname
        'canonical_suffixed',       // ours with an attacker suffix: bot.example.test.evil.net
        'canonical_prefixed',       // a label in front of ours — a tenant subdomain, or not
        'punycode_homograph',       // an A-label that reads like ours
        'internationalised',        // a U-label, which DNS never sees and TLS never names
        'explicit_port',            // ours with a port glued on
        'userinfo',                 // attacker.example.net@ours — the URL-parser confusion
        'crlf',                     // response splitting / header injection
        'nul',                      // C-string truncation in whatever reads it next
        'absolute_url',             // a whole URL where a host belongs
        'ipv4',                     // an address literal
        'ipv6',                     // a bracketed address literal
        'over_long_host',           // past the 253-character DNS limit
        'over_long_label',          // past the 63-character label limit, under our apex
        'deep_under_apex',          // two labels under our apex — not a tenant subdomain
        'triple_under_apex',        // three labels under our apex
        'bad_label_under_apex',     // an underscore label under our apex
        'empty',                    // no Host at all
        'whitespace',               // a Host of nothing but spaces
        'canonical_respelled',      // ours, random case, random trailing root dots
        'apex_itself',              // the apex, respelled
        'apex_label',               // exactly one label under the apex
        'operator_listed',          // wa.url.hosts.additional, respelled
    ];

    /**
     * Every channel a proxy, a web server or the framework could carry a host through.
     *
     * `Host` is the header itself; `X-Forwarded-Host` is what Symfony reads instead once a
     * proxy is trusted; `X-Original-Host` is what several ingress controllers and IIS
     * rewrites add; `HTTP_HOST` and `SERVER_NAME` are the CGI variables Symfony falls back
     * to; and `X-Forwarded-Proto`/`X-Forwarded-Port` move the *scheme* and *port* of the
     * effective origin, which are as much a part of an authority as the host is.
     *
     * @var non-empty-list<string>
     */
    public const array INJECTION_CHANNELS = [
        'Host',
        'X-Forwarded-Host',
        'X-Original-Host',
        'HTTP_HOST',
        'SERVER_NAME',
        'X-Forwarded-Proto',
        'X-Forwarded-Port',
    ];

    /**
     * One RFC-1035 label. Written out here rather than imported from `PlatformHosts` so
     * the oracle and the implementation cannot be wrong together.
     */
    private const string LABEL_PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/';

    /**
     * Hosts the clause-2 transplant draws origins from — real deployment shapes and real
     * attacker shapes, all of them syntactically valid so `CanonicalBase::parse()` accepts
     * them and a signature can actually be issued against them.
     *
     * @var non-empty-list<string>
     */
    private const array ORIGIN_HOSTS = [
        'bot.example.test',
        'panel.example.test',
        'acme.app.example.test',
        'chat.acme.example',
        'staging.bot.example.test',
        'bot.example.test.attacker.example.net',
        'attacker.example.net',
        'example.test',
        'exports.bot.example.test',
    ];

    private int $draws = 0;

    public function __construct(public readonly int $seed) {}

    /**
     * A fresh seed, or the one named by `CANONICAL_HOST_SEED` for a replay.
     */
    public static function seeded(): self
    {
        $override = getenv(self::SEED_ENV);

        if (is_string($override) && ctype_digit($override)) {
            return new self((int) $override);
        }

        return new self(random_int(1, 2 ** 48));
    }

    /*
    |--------------------------------------------------------------------------
    | Draws
    |--------------------------------------------------------------------------
    */

    /**
     * An integer in `[$min, $max]`.
     */
    public function int(int $min, int $max): int
    {
        if ($max <= $min) {
            return $min;
        }

        return $min + $this->draw() % (($max - $min) + 1);
    }

    public function bool(int $percent = 50): bool
    {
        return $this->int(1, 100) <= $percent;
    }

    /**
     * @template TValue
     *
     * @param  non-empty-list<TValue>  $values
     * @return TValue
     */
    public function pick(array $values): mixed
    {
        return $values[$this->int(0, count($values) - 1)];
    }

    /**
     * A Fisher-Yates shuffle from the seeded stream, so even the order the classes and
     * configuration cells are exercised in varies between runs and is still replayable.
     *
     * @template TValue
     *
     * @param  list<TValue>  $values
     * @return list<TValue>
     */
    public function shuffled(array $values): array
    {
        for ($index = count($values) - 1; $index > 0; $index--) {
            $swap = $this->int(0, $index);
            [$values[$index], $values[$swap]] = [$values[$swap], $values[$index]];
        }

        return $values;
    }

    /*
    |--------------------------------------------------------------------------
    | Hosts
    |--------------------------------------------------------------------------
    */

    /**
     * A fresh member of $class, built against the canonical host and apex of the
     * configuration cell being exercised.
     *
     * Built against the *drawn* configuration rather than a constant, because the
     * interesting cases are relative to it: "our host with a suffix" is only a homograph
     * of the host this cell actually emits, and "one label under the apex" is only
     * legitimate for the apex this cell actually hangs subdomains off.
     */
    public function hostileHost(string $class, string $canonicalHost, string $apex): string
    {
        return match ($class) {
            'attacker_origin' => $this->pick([
                'attacker.example.net',
                'evil'.$this->int(1, 99).'.example.net',
                'localhost',
            ]),
            'attacker_origin_with_port' => 'attacker.example.net:'.$this->pick([80, 443, 8443, 65535]),
            'canonical_suffixed' => $canonicalHost.'.'.$this->pick(['evil.net', 'attacker.example.net', 'co']),
            'canonical_prefixed' => 'evil'.$this->int(1, 999).'.'.$canonicalHost,
            'punycode_homograph' => 'xn--'.$this->pick(['bt-exmple-4ve', 'bo-example-9kb', 'pnel-k1a']).'.test',
            'internationalised' => $this->pick(['пример.example', 'ドメイン.test', 'παράδειγμα.example']),
            'explicit_port' => $canonicalHost.':'.$this->pick([80, 443, 8443]),
            'userinfo' => $this->pick(['attacker.example.net@', 'user:pass@', 'admin@']).$canonicalHost,
            'crlf' => $canonicalHost."\r\nX-Injected: ".$this->int(1, 9),
            'nul' => $canonicalHost."\0.".$this->pick(['evil.net', 'attacker.example.net']),
            'absolute_url' => 'https://attacker.example.net/'.$this->pick(['', 'panel', 'exports/1']),
            'ipv4' => '192.0.2.'.$this->int(1, 254),
            'ipv6' => '[2001:db8::'.$this->int(1, 9).']',
            // Ten 29-character labels: 299 characters, past the 253-character DNS limit
            // while every individual label is legal, so only the total length is wrong.
            'over_long_host' => implode('.', array_fill(0, 10, str_repeat('h', 29))),
            'over_long_label' => str_repeat('a', 64).'.'.$apex,
            'deep_under_apex' => 'a'.$this->int(1, 9).'.b'.$this->int(1, 9).'.'.$apex,
            'triple_under_apex' => 'a.b.c'.$this->int(1, 9).'.'.$apex,
            'bad_label_under_apex' => '_probe'.$this->int(1, 9).'.'.$apex,
            'empty' => '',
            'whitespace' => str_repeat(' ', $this->int(1, 4)),
            'canonical_respelled' => $this->respelled($canonicalHost).str_repeat('.', $this->int(1, 3)),
            'apex_itself' => $this->respelled($apex).($this->bool(30) ? '.' : ''),
            'apex_label' => 'probe'.$this->int(100, 999).'.'.$apex,
            'operator_listed' => $this->respelled(UrlConfiguration::ADDITIONAL_HOST),
            default => throw new \LogicException('Unhandled host class ['.$class.'].'),
        };
    }

    /**
     * $host with each ASCII letter independently upper- or lower-cased.
     *
     * A proxy, a client library or a hand-typed URL can arrive in any case, and a host is
     * case-insensitive — so a *legitimate* host in a random case has to stay legitimate,
     * and a signature over one spelling has to verify against the others. Only applied to
     * ASCII hosts, where a byte is a character.
     */
    public function respelled(string $host): string
    {
        $spelled = '';

        foreach (str_split($host) as $character) {
            $spelled .= $this->bool() ? strtoupper($character) : strtolower($character);
        }

        return $spelled;
    }

    /**
     * A tenant subdomain label that is a valid single DNS label, is not reserved, and is
     * unique within a run — so `tenantSubdomain()` is exercised on its happy path rather
     * than on its refusals (which `UrlBuilderTest` already covers).
     */
    public function tenantLabel(int $index): string
    {
        return 't'.$index.'p'.$this->int(100, 999);
    }

    /*
    |--------------------------------------------------------------------------
    | The oracle for Req 9.4 (Property 27's third clause)
    |--------------------------------------------------------------------------
    */

    /**
     * Whether $host is genuinely one of the four things Req 9.4 admits: a configured apex,
     * **exactly one** DNS label under one, a verified custom domain, or an
     * operator-configured additional host (the last two arrive as $exactHosts).
     *
     * Written by counting labels, where `HostAllowlist` decides by suffix matching and a
     * `preg_quote`d pattern. Two implementations of one rule: they agree only if the rule
     * holds, so an allowlist that widened its pattern to any depth, or narrowed it to
     * nothing, disagrees with this rather than with itself.
     *
     * @param  list<string>  $apexes  normalised configured apexes
     * @param  list<string>  $exactHosts  normalised hosts accepted by exact match
     */
    public static function accepted(string $host, array $apexes, array $exactHosts): bool
    {
        $host = self::fold($host);

        if ($host === '') {
            return false;
        }

        foreach ($exactHosts as $exact) {
            if ($host === self::fold($exact)) {
                return true;
            }
        }

        $labels = explode('.', $host);

        foreach ($apexes as $apex) {
            $apexLabels = explode('.', self::fold($apex));

            if ($labels === $apexLabels) {
                return true;
            }

            if (count($labels) !== count($apexLabels) + 1) {
                continue;
            }

            if (array_slice($labels, 1) !== $apexLabels) {
                continue;
            }

            if (preg_match(self::LABEL_PATTERN, $labels[0]) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lowercase, no surrounding whitespace, no root dot — the fold both host layers apply
     * before comparing. Not part of the property (a host is case- and root-dot-insensitive
     * by definition), so sharing it with the implementation costs nothing.
     */
    public static function fold(string $host): string
    {
        return Str::lower(rtrim(trim($host), '.'));
    }

    /*
    |--------------------------------------------------------------------------
    | Origins and links (Property 27's second clause)
    |--------------------------------------------------------------------------
    */

    /**
     * $count canonical base strings with **pairwise-distinct authorities**, so a link
     * signed under one and presented under another differs in exactly the field the
     * signature is supposed to bind.
     *
     * Ports and mount points vary independently of the host: a deployment reachable on
     * another port is a different origin, and a mount point is inside the signed path, so
     * both have to be in the draw.
     *
     * @return non-empty-list<string>
     */
    public function origins(int $count): array
    {
        $hosts = array_slice($this->shuffled(self::ORIGIN_HOSTS), 0, max(2, $count));
        $origins = [];

        foreach ($hosts as $host) {
            $port = $this->pick([null, null, 8443, 8080, 9443]);
            $mount = $this->pick(['', '', '/app', '/panel/v2']);

            $origins[] = 'https://'.$host.($port === null ? '' : ':'.$port).$mount;
        }

        if ($origins === []) {
            // Unreachable: `ORIGIN_HOSTS` is non-empty and `$count` is floored at two. The
            // guard is here so the non-emptiness the callers rely on is proven rather than
            // annotated.
            throw new \LogicException('The origin pool produced no origins.');
        }

        return $origins;
    }

    /**
     * A path that survives `UrlPath::normalize()` — the property is about the host, so the
     * path is drawn from the shapes real signed links use (an export, a payment link, a
     * media download) rather than from the refusals `UrlPath` already has tests for.
     */
    public function path(): string
    {
        return '/'.$this->pick(['exports', 'payments', 'media', 'invoices'])
            .'/'.strtoupper(Str::random($this->int(6, 16)))
            .'/'.$this->pick(['download', 'pay', 'view', 'render.pdf']);
    }

    /**
     * One hex digit of $signature replaced with a different one — a forged signature that
     * is still the right shape, so it is refused on its value rather than on its syntax.
     */
    public function flipSignature(string $signature): string
    {
        if ($signature === '') {
            return 'deadbeef';
        }

        $at = $this->int(0, strlen($signature) - 1);
        $digits = str_split('0123456789abcdef');
        $replacement = $this->pick($digits);

        while ($replacement === $signature[$at]) {
            $replacement = $this->pick($digits);
        }

        return substr($signature, 0, $at).$replacement.substr($signature, $at + 1);
    }

    /**
     * One alphanumeric byte of $path replaced with a different one — the smallest possible
     * change to *which resource* a link names, and one that leaves the URL syntactically
     * intact so it reaches the signature check rather than being rejected as malformed.
     */
    public function flipPathByte(string $path): string
    {
        $positions = [];

        foreach (str_split($path) as $index => $character) {
            if (ctype_alnum($character)) {
                $positions[] = $index;
            }
        }

        if ($positions === []) {
            return $path.'x';
        }

        $at = $this->pick($positions);
        $alphabet = str_split('abcdefghijkmnpqrstuvwxyz23456789');
        $replacement = $this->pick($alphabet);

        while (strtolower($path[$at]) === $replacement) {
            $replacement = $this->pick($alphabet);
        }

        return substr($path, 0, $at).$replacement.substr($path, $at + 1);
    }

    /**
     * A 48-bit draw derived from the seed and the draw index.
     */
    private function draw(): int
    {
        $this->draws++;

        return (int) hexdec(substr(hash('sha256', $this->seed.':'.$this->draws), 0, 12));
    }
}
