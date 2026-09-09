<?php

declare(strict_types=1);

namespace App\Services\Abuse;

use App\Enums\AbuseSignal;
use App\Enums\AbuseVector;
use App\Exceptions\Security\AntiFraudBlockedException;
use App\Support\Cache\VelocityCounter;
use Illuminate\Support\Str;

/**
 * The signup-velocity / OTP / device-IP heuristics (design § Abuse / anti-fraud, row 1;
 * Req 32.7 / NFR3).
 *
 * ## The four counters, and why there are four
 *
 * | Counter | Keyed on | Defeated by | Covered by |
 * |---|---|---|---|
 * | identity | keyed digest of the normalized email/phone | a new mailbox | ip, subnet, device |
 * | device | the panel's fingerprint | clearing storage / a fresh browser | ip, subnet |
 * | ip | the exact address | a proxy or a mobile-data reconnect | subnet |
 * | subnet | `/24` (IPv4) or `/64` (IPv6) | a botnet across many ranges | — |
 *
 * No single counter is hard to evade; the point is that each one is evaded by a *different
 * effort*, so evading all four costs a farmer a new mailbox, a new browser profile, and a
 * new network per trial. The email normalization matters as much as the counters
 * themselves — `a+1@gmail.com` and `a.1@gmail.com` are the same mailbox, and
 * `IdentityDigest` treats them that way.
 *
 * ## Legitimate shared addresses are never locked out permanently
 *
 * Three mechanisms, all of them required together:
 *
 * 1. **windows, not bans.** Every counter is a fixed window and every refusal expires with
 *    it; `FraudVerdict::retryAfterSeconds()` is the remainder, and it becomes a
 *    `Retry-After` header. There is no persistent "blocked" flag anywhere in this class —
 *    a block cannot outlive its window even if the platform wanted it to.
 * 2. **the address counters are the loosest.** An office or a campus shares one address, so
 *    the IP and subnet limits are set well above the identity and device limits, and the
 *    subnet window is the one an operator widens if a customer segment sits behind carrier
 *    NAT.
 * 3. **an allowlist.** `wa.security.anti_fraud.trusted_ips` skips the address counters for
 *    known egress (a customer's office range, the platform's own test runners) while
 *    leaving identity and device counting fully in force — so an allowlisted address is not
 *    an unlimited signup source.
 *
 * ## No tenant, and no leak
 *
 * Everything here runs before a tenant exists. Counter keys are
 * `signup:ip:{digest}`-shaped: they contain a keyed digest of an address or an identity and
 * *nothing tenant-derived*, so no counter can be shared between tenants and none can be
 * probed for another tenant's data. The `abuse_events` rows are written with
 * `tenant_id = NULL` by `DatabaseAbuseRecorder` (which reads `TenantContext` rather than
 * demanding it), so this path never raises `MissingTenantContextException` — the failure
 * that would otherwise make registration impossible.
 */
final class HeuristicAntiFraudGuard implements AntiFraudGuard
{
    public const string SURFACE_SIGNUP = 'signup';

    public const string SURFACE_OTP_REQUEST = 'otp.request';

    public const string SURFACE_OTP_VERIFY = 'otp.verify';

    /**
     * @param  array<string, VelocityLimit>  $limits  keyed by counter name (see `limit()`)
     * @param  list<string>  $trustedIps  addresses and `CIDR`-less prefixes whose address counters are skipped
     * @param  list<string>  $disposableDomains  refused email domains
     */
    public function __construct(
        private readonly VelocityCounter $counters,
        private readonly IdentityDigest $digest,
        private readonly AbuseRecorder $recorder,
        private readonly array $limits = [],
        private readonly array $trustedIps = [],
        private readonly array $disposableDomains = [],
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Signup
    |--------------------------------------------------------------------------
    */

    public function inspectSignup(SignupAttempt $attempt): FraudVerdict
    {
        if (! $attempt->isCountable()) {
            // Nothing to key a counter on: console provisioning, an import, a test. Recorded
            // as a flag so the operator can see it happened, and allowed — refusing here
            // would break tenant creation while stopping nobody (an attacker always has an
            // address).
            return $this->finish(FraudVerdict::make(
                [AbuseSignal::UncountableSignup],
                ['countable' => false],
                AbuseVector::SignupAbuse,
                self::SURFACE_SIGNUP,
            ));
        }

        $signals = [];
        $evidence = [];
        $retryAfter = 0;

        if ($attempt->email !== null && $this->isDisposable($attempt->email)) {
            $signals[] = AbuseSignal::DisposableEmailDomain;
            $evidence['email_domain'] = ['disposable' => true];
        }

        $identity = $attempt->primaryIdentity();

        if ($identity !== null) {
            $this->check(
                'signup.identity',
                'signup:identity:'.$this->identityDigest($attempt),
                AbuseSignal::SignupIdentityVelocity,
                $signals,
                $evidence,
                $retryAfter,
            );
        }

        if ($attempt->deviceFingerprint !== null) {
            $this->check(
                'signup.device',
                'signup:device:'.$this->digest->device($attempt->deviceFingerprint),
                AbuseSignal::SignupDeviceVelocity,
                $signals,
                $evidence,
                $retryAfter,
            );
        }

        if ($attempt->ip !== null) {
            if ($this->isTrustedIp($attempt->ip)) {
                $evidence['ip'] = ['trusted' => true];
            } else {
                $this->check(
                    'signup.ip',
                    'signup:ip:'.$this->digest->ip($attempt->ip),
                    AbuseSignal::SignupIpVelocity,
                    $signals,
                    $evidence,
                    $retryAfter,
                );

                $this->check(
                    'signup.subnet',
                    'signup:subnet:'.$this->digest->subnet($attempt->ip),
                    AbuseSignal::SignupSubnetVelocity,
                    $signals,
                    $evidence,
                    $retryAfter,
                );
            }
        }

        return $this->finish(FraudVerdict::make(
            $signals,
            $evidence,
            AbuseVector::SignupAbuse,
            self::SURFACE_SIGNUP,
            $this->subjectHash($attempt),
            $retryAfter,
        ));
    }

    public function assertSignup(SignupAttempt $attempt): FraudVerdict
    {
        return $this->assert($this->inspectSignup($attempt));
    }

    /*
    |--------------------------------------------------------------------------
    | OTP
    |--------------------------------------------------------------------------
    */

    public function inspectOtpRequest(SignupAttempt $attempt): FraudVerdict
    {
        $signals = [];
        $evidence = [];
        $retryAfter = 0;

        if ($attempt->hasIdentity()) {
            $this->check(
                'otp.request_identity',
                'otp:request:identity:'.$this->identityDigest($attempt),
                AbuseSignal::OtpRequestVelocity,
                $signals,
                $evidence,
                $retryAfter,
            );
        }

        if ($attempt->ip !== null && ! $this->isTrustedIp($attempt->ip)) {
            $this->check(
                'otp.request_ip',
                'otp:request:ip:'.$this->digest->ip($attempt->ip),
                AbuseSignal::OtpRequestVelocity,
                $signals,
                $evidence,
                $retryAfter,
            );
        }

        return $this->finish(FraudVerdict::make(
            $signals,
            $evidence,
            AbuseVector::OtpAbuse,
            self::SURFACE_OTP_REQUEST,
            $this->subjectHash($attempt),
            $retryAfter,
        ));
    }

    public function assertOtpRequest(SignupAttempt $attempt): FraudVerdict
    {
        return $this->assert($this->inspectOtpRequest($attempt));
    }

    public function recordOtpFailure(SignupAttempt $attempt): FraudVerdict
    {
        $signals = [];
        $evidence = [];
        $retryAfter = 0;

        $this->check(
            'otp.failure',
            $this->otpFailureKey($attempt),
            AbuseSignal::OtpFailureVelocity,
            $signals,
            $evidence,
            $retryAfter,
        );

        return $this->finish(FraudVerdict::make(
            $signals,
            $evidence,
            AbuseVector::OtpAbuse,
            self::SURFACE_OTP_VERIFY,
            $this->subjectHash($attempt),
            $retryAfter,
        ));
    }

    public function clearOtpFailures(SignupAttempt $attempt): void
    {
        $limit = $this->limit('otp.failure');

        $this->counters->clear($this->otpFailureKey($attempt), $limit->windowSeconds);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Count one hit on a named counter and, if it has reached its limit, add the signal.
     *
     * The evidence is written whether or not the limit was reached — an allowed attempt's
     * counters are exactly what an operator needs when the *next* one is refused.
     *
     * @param  list<AbuseSignal>  $signals
     * @param  array<string, mixed>  $evidence
     */
    private function check(
        string $name,
        string $key,
        AbuseSignal $signal,
        array &$signals,
        array &$evidence,
        int &$retryAfter,
    ): void {
        $limit = $this->limit($name);

        if ($limit->isDisabled()) {
            $evidence[$name] = ['disabled' => true];

            return;
        }

        $reading = $this->counters->hit($key, $limit->windowSeconds);
        $evidence[$name] = $reading->toArray($limit->max);

        if (! $reading->exceeds($limit->max)) {
            return;
        }

        if (! in_array($signal, $signals, true)) {
            $signals[] = $signal;
        }

        $retryAfter = max($retryAfter, $reading->resetsInSeconds);
    }

    /**
     * Record the event (for a flag or a block) and hand the verdict back.
     *
     * Recording happens here rather than at the call site for the same reason the guardrail
     * records its own verdicts: Req 32.7's trail must not depend on the panel screen
     * remembering to write it.
     */
    private function finish(FraudVerdict $verdict): FraudVerdict
    {
        if ($verdict->action->isRecordable()) {
            $this->recorder->record($verdict->toDraft());
        }

        return $verdict;
    }

    private function assert(FraudVerdict $verdict): FraudVerdict
    {
        if ($verdict->blocks()) {
            throw AntiFraudBlockedException::from($verdict);
        }

        return $verdict;
    }

    /**
     * The counter for wrong one-time codes: keyed on the identity when there is one, on the
     * address otherwise, so a guesser who omits the identity field is still counted.
     */
    private function otpFailureKey(SignupAttempt $attempt): string
    {
        if ($attempt->hasIdentity()) {
            return 'otp:failure:identity:'.$this->identityDigest($attempt);
        }

        return 'otp:failure:ip:'.$this->digest->ip($attempt->ip ?? 'unknown');
    }

    /**
     * The digest an identity-keyed counter uses: the phone number when present (harder to
     * farm, and the OTP target), the email otherwise.
     */
    private function identityDigest(SignupAttempt $attempt): string
    {
        if ($attempt->phone !== null) {
            return $this->digest->phone($attempt->phone);
        }

        return $this->digest->email($attempt->email ?? '');
    }

    /**
     * What the stored row is *about* — the identity if there is one, else the device, else
     * the address. Always a keyed digest.
     */
    private function subjectHash(SignupAttempt $attempt): ?string
    {
        if ($attempt->hasIdentity()) {
            return $this->identityDigest($attempt);
        }

        if ($attempt->deviceFingerprint !== null) {
            return $this->digest->device($attempt->deviceFingerprint);
        }

        return $attempt->ip === null ? null : $this->digest->ip($attempt->ip);
    }

    private function isDisposable(string $email): bool
    {
        $domain = $this->digest->domainOf($email);

        return $domain !== '' && in_array($domain, $this->disposableDomains, true);
    }

    /**
     * Whether the address counters are skipped for this address.
     *
     * An entry matches exactly, or as a dotted/colon prefix (`203.0.113.` covers the /24),
     * which is enough for the operator break-glass this list is for and avoids shipping a
     * CIDR parser that would have to be right about IPv6 to be safe.
     */
    private function isTrustedIp(string $ip): bool
    {
        $candidate = Str::lower(trim($ip));

        foreach ($this->trustedIps as $trusted) {
            $entry = Str::lower(trim($trusted));

            if ($entry === '') {
                continue;
            }

            if ($candidate === $entry) {
                return true;
            }

            $isPrefix = str_ends_with($entry, '.') || str_ends_with($entry, ':');

            if ($isPrefix && str_starts_with($candidate, $entry)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The configured limit for a counter, with the shipped default when the platform has
     * not overridden it.
     */
    private function limit(string $name): VelocityLimit
    {
        return $this->limits[$name] ?? self::defaultLimit($name);
    }

    /**
     * The defaults, in one place so `HeuristicAntiFraudGuard` behaves identically whether
     * or not `config/wa.php` has been published.
     *
     * Address limits are deliberately looser than identity/device limits — see the class
     * docblock on shared addresses.
     */
    public static function defaultLimit(string $name): VelocityLimit
    {
        return match ($name) {
            'signup.identity' => new VelocityLimit(3, 3600),
            'signup.device' => new VelocityLimit(3, 86400),
            'signup.ip' => new VelocityLimit(8, 3600),
            'signup.subnet' => new VelocityLimit(20, 3600),
            'otp.request_identity' => new VelocityLimit(5, 3600),
            'otp.request_ip' => new VelocityLimit(20, 3600),
            'otp.failure' => new VelocityLimit(5, 900),
            default => new VelocityLimit(0, 3600),
        };
    }

    /**
     * Every counter this class knows about — the list the service provider reads
     * configuration for, and the list a test asserts is fully configured.
     *
     * @return list<string>
     */
    public static function counterNames(): array
    {
        return [
            'signup.identity',
            'signup.device',
            'signup.ip',
            'signup.subnet',
            'otp.request_identity',
            'otp.request_ip',
            'otp.failure',
        ];
    }
}
