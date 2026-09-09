<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The abuse vector an `abuse_events` row belongs to — one case per row of
 * design § "Abuse / anti-fraud" (Req 32.7 / NFR3; Req 13.8 / B4).
 *
 * | Vector | Written by | Defence |
 * |---|---|---|
 * | `PROMPT_INJECTION` | `Guardrail::inspectInput` | classifier + delimiter fencing + instruction hierarchy |
 * | `OUTPUT_POLICY` | `Guardrail::inspectOutput` | system-prompt-leak and policy validation, reply suppressed |
 * | `SIGNUP_ABUSE` | `AntiFraudGuard::inspectSignup` | velocity + device/IP heuristics, trial-farming defence |
 * | `OTP_ABUSE` | `AntiFraudGuard::inspectOtpRequest`/`recordOtpFailure` | OTP send and verification velocity |
 * | `SESSION_RISK` | `SessionKillSwitch` | per-session kill-switch (ToS/ban risk) |
 *
 * The vector is what an operator filters the abuse feed by, so it is deliberately
 * coarse: the precise reason lives in `AbuseSignal`, which maps onto exactly one
 * vector.
 */
enum AbuseVector: string
{
    case PromptInjection = 'PROMPT_INJECTION';
    case OutputPolicy = 'OUTPUT_POLICY';
    case SignupAbuse = 'SIGNUP_ABUSE';
    case OtpAbuse = 'OTP_ABUSE';
    case SessionRisk = 'SESSION_RISK';

    /**
     * Whether rows of this vector are written **before** a tenant exists, and so
     * legitimately carry `tenant_id = NULL`.
     *
     * Signup and OTP abuse happen on the registration path (design § User Panel row 1:
     * "pre-tenant; provisions tenant+wallet+... on verify"), where there is no tenant
     * to attribute the event to and asking for one would raise
     * `MissingTenantContextException` on the one path that must stay reachable to
     * anonymous callers.
     */
    public function isPreTenant(): bool
    {
        return match ($this) {
            self::SignupAbuse, self::OtpAbuse => true,
            self::PromptInjection, self::OutputPolicy, self::SessionRisk => false,
        };
    }

    /**
     * Human-readable label for the admin abuse feed.
     */
    public function label(): string
    {
        return match ($this) {
            self::PromptInjection => 'Prompt injection',
            self::OutputPolicy => 'Model output policy',
            self::SignupAbuse => 'Signup abuse',
            self::OtpAbuse => 'OTP abuse',
            self::SessionRisk => 'Session risk',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
