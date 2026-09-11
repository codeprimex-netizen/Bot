<?php

declare(strict_types=1);

namespace App\Services\Abuse;

/**
 * The signup / OTP / device-IP heuristics of design § Abuse / anti-fraud, row 1:
 * *"email+phone OTP, device/IP heuristics, velocity limits, trial quota caps"*
 * (Req 32.7 / NFR3).
 *
 * ## A service the registration screens call, not a middleware
 *
 * Tasks 24.1/24.2 own the panel screens and `RegistrationService`/`OtpService`; this is
 * the decision they ask for. It is deliberately not a route middleware: the interesting
 * signals (the email, the phone number, the panel's device fingerprint) are *validated
 * input*, not headers, and a middleware would either run before validation or have to
 * re-parse the body.
 *
 * ## Attempts are counted, not successes
 *
 * `inspectSignup()` and `inspectOtpRequest()` **count** the attempt they are asked about
 * and then judge it. That is the opposite of the "check now, record if it works later"
 * shape, and it is what makes the limits hard to bypass: abandoning the form, failing
 * validation, or crashing the request does not give the caller a free attempt back, and
 * there is no second call a caller can forget to make. The only counter that *is* cleared
 * on success is the OTP failure counter (`clearOtpFailures()`), because that one exists to
 * catch code-guessing and a correct code proves the guesser was the owner.
 *
 * ## No tenant, by design
 *
 * Every method here runs on the anonymous registration path, where no tenant exists.
 * Counters are keyed on keyed digests of the identity/address only — never on a tenant —
 * and the `abuse_events` rows they produce carry `tenant_id = NULL`. Nothing in this
 * interface may take, resolve, or require a tenant: doing so would raise
 * `MissingTenantContextException` on the one path that has to stay open to strangers, and
 * attributing a stranger's attempt to some tenant would be a cross-tenant leak.
 */
interface AntiFraudGuard
{
    /**
     * Judge a registration attempt, counting it.
     */
    public function inspectSignup(SignupAttempt $attempt): FraudVerdict;

    /**
     * `inspectSignup()` for a caller that wants the refusal as an exception (429 with
     * `Retry-After`).
     *
     * @throws \App\Exceptions\Security\AntiFraudBlockedException
     */
    public function assertSignup(SignupAttempt $attempt): FraudVerdict;

    /**
     * Judge a request to *send* a one-time code, counting it. Guards the cost of the SMS
     * as much as the account.
     */
    public function inspectOtpRequest(SignupAttempt $attempt): FraudVerdict;

    /**
     * @throws \App\Exceptions\Security\AntiFraudBlockedException
     */
    public function assertOtpRequest(SignupAttempt $attempt): FraudVerdict;

    /**
     * Count one **incorrect** one-time code and judge whether verification should stop
     * for now. The bounded lockout that makes a 6-digit code unguessable.
     */
    public function recordOtpFailure(SignupAttempt $attempt): FraudVerdict;

    /**
     * Forget the failed-code counter after a correct code.
     */
    public function clearOtpFailures(SignupAttempt $attempt): void;
}
