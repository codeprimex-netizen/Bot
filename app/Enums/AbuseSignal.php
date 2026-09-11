<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Every reason the abuse layer can give — the vocabulary shared by the guardrail
 * classifier, the output validator, the anti-fraud heuristics, and the per-session
 * kill-switch (Req 13.8 / B4; Req 32.7 / NFR3).
 *
 * A signal is the unit of *explainability*: an `abuse_events` row stores the list of
 * signals that produced it, so "why was this refused?" is answered from stored data
 * rather than from a log line somebody has to still have. Each case fixes two things
 * that must never be decided at a call site:
 *
 * - `vector()` — which row of design § "Abuse / anti-fraud" it belongs to;
 * - `action()` — how severe it is, and therefore whether it blocks.
 *
 * ## Why severity lives here rather than in the detectors
 *
 * A detector's job is to answer "is this pattern present?" — a yes/no about text.
 * Whether that pattern is worth refusing a customer's message over is a *policy*,
 * and policy that is spread across detectors drifts: one of them ends up returning
 * "suspicious" for something another treats as fatal, and the combined verdict then
 * depends on evaluation order. With the mapping here, a verdict is the escalation of
 * its signals (`GuardAction::escalate()`) and is therefore order-independent.
 *
 * ## The fail-closed cases
 *
 * `UNDECODABLE`, `OVERSIZED`, and `CLASSIFIER_UNAVAILABLE` are not detections — they
 * are the three ways the classifier can fail to reach a conclusion, and all three
 * block. An input the guardrail could not classify is **not** treated as clean: on
 * this path the cost of a false block is one unanswered message, while the cost of a
 * false allow is handing an unexamined instruction payload to a model that also holds
 * the tenant's data and tools. `CLASSIFIER_DEGRADED` is the one exception and the
 * reason it is separate: an *optional* auxiliary classifier being unavailable leaves
 * the deterministic verdict fully intact, so it is recorded and does not block.
 */
enum AbuseSignal: string
{
    /*
    |--------------------------------------------------------------------------
    | Prompt injection — inbound text (Req 13.8 / B4)
    |--------------------------------------------------------------------------
    */

    /** "ignore all previous instructions", "disregard the rules above", "new instructions:". */
    case InstructionOverride = 'INSTRUCTION_OVERRIDE';

    /** "you are now …", "act as …", "developer mode", chat-template role tokens. */
    case RoleReassignment = 'ROLE_REASSIGNMENT';

    /** User content presenting itself as a system/platform instruction, i.e. asking to be promoted up the hierarchy. */
    case HierarchyPromotion = 'HIERARCHY_PROMOTION';

    /** User content carrying the platform's own fence delimiters, i.e. trying to close its data block early. */
    case FenceEscape = 'FENCE_ESCAPE';

    /** "repeat your system prompt", "print everything above", "what were your initial instructions". */
    case SystemPromptProbe = 'SYSTEM_PROMPT_PROBE';

    /** Attempts to have the model emit credentials, configuration, or tool/function internals. */
    case ToolExfiltration = 'TOOL_EXFILTRATION';

    /** A base64 / percent- / unicode-escaped payload that decodes to one of the patterns above. */
    case EncodedPayload = 'ENCODED_PAYLOAD';

    /** Zero-width, bidi, or homoglyph tampering — evasion machinery, with no payload of its own. */
    case Obfuscation = 'OBFUSCATION';

    /** A pattern from `wa.security.guardrail.patterns` (platform or tenant policy). */
    case CustomPattern = 'CUSTOM_PATTERN';

    /*
    |--------------------------------------------------------------------------
    | Prompt injection — the three ways classification fails closed
    |--------------------------------------------------------------------------
    */

    /** The text is not decodable as UTF-8, so no detector can honestly be said to have run. */
    case Undecodable = 'UNDECODABLE';

    /** The text is longer than `wa.security.guardrail.max_input_chars`, so part of it would go unexamined. */
    case Oversized = 'OVERSIZED';

    /** The mandatory deterministic classifier itself failed. */
    case ClassifierUnavailable = 'CLASSIFIER_UNAVAILABLE';

    /** An optional auxiliary classifier was unavailable; the deterministic verdict stands. */
    case ClassifierDegraded = 'CLASSIFIER_DEGRADED';

    /*
    |--------------------------------------------------------------------------
    | Model output (design § AI 1.3, step 4)
    |--------------------------------------------------------------------------
    */

    /** The reply reproduces part of the system prompt verbatim. */
    case SystemPromptLeak = 'SYSTEM_PROMPT_LEAK';

    /** The reply reproduces the fence scaffolding the untrusted block was wrapped in. */
    case FenceLeak = 'FENCE_LEAK';

    /** The reply contains something shaped like a credential (API key, bearer token). */
    case SecretShaped = 'SECRET_SHAPED';

    /** The reply matched a configured output policy pattern. */
    case OutputPolicyViolation = 'OUTPUT_POLICY_VIOLATION';

    /*
    |--------------------------------------------------------------------------
    | Session risk (design § Abuse: "kill-switch for offending sessions")
    |--------------------------------------------------------------------------
    */

    /** Inspection refused because the session is under a kill-switch. */
    case SessionKilled = 'SESSION_KILLED';

    /** The kill-switch was engaged for a session. */
    case KillSwitchEngaged = 'KILL_SWITCH_ENGAGED';

    /** The kill-switch was released for a session. */
    case KillSwitchReleased = 'KILL_SWITCH_RELEASED';

    /** Repeated blocks in one conversation inside the configured window — the per-conversation rate limit of design § AI 1.3. */
    case ConversationBlockBurst = 'CONVERSATION_BLOCK_BURST';

    /*
    |--------------------------------------------------------------------------
    | Signup abuse (free-trial farming)
    |--------------------------------------------------------------------------
    */

    case SignupIpVelocity = 'SIGNUP_IP_VELOCITY';
    case SignupSubnetVelocity = 'SIGNUP_SUBNET_VELOCITY';
    case SignupDeviceVelocity = 'SIGNUP_DEVICE_VELOCITY';
    case SignupIdentityVelocity = 'SIGNUP_IDENTITY_VELOCITY';
    case DisposableEmailDomain = 'DISPOSABLE_EMAIL_DOMAIN';

    /** Neither an IP nor a device fingerprint was supplied, so nothing could be counted. */
    case UncountableSignup = 'UNCOUNTABLE_SIGNUP';

    /*
    |--------------------------------------------------------------------------
    | OTP abuse
    |--------------------------------------------------------------------------
    */

    case OtpRequestVelocity = 'OTP_REQUEST_VELOCITY';
    case OtpFailureVelocity = 'OTP_FAILURE_VELOCITY';

    /**
     * Which abuse vector — and therefore which `abuse_events` filter — this signal
     * belongs to.
     */
    public function vector(): AbuseVector
    {
        return match ($this) {
            self::InstructionOverride,
            self::RoleReassignment,
            self::HierarchyPromotion,
            self::FenceEscape,
            self::SystemPromptProbe,
            self::ToolExfiltration,
            self::EncodedPayload,
            self::Obfuscation,
            self::CustomPattern,
            self::Undecodable,
            self::Oversized,
            self::ClassifierUnavailable,
            self::ClassifierDegraded => AbuseVector::PromptInjection,

            self::SystemPromptLeak,
            self::FenceLeak,
            self::SecretShaped,
            self::OutputPolicyViolation => AbuseVector::OutputPolicy,

            self::SessionKilled,
            self::KillSwitchEngaged,
            self::KillSwitchReleased,
            self::ConversationBlockBurst => AbuseVector::SessionRisk,

            self::SignupIpVelocity,
            self::SignupSubnetVelocity,
            self::SignupDeviceVelocity,
            self::SignupIdentityVelocity,
            self::DisposableEmailDomain,
            self::UncountableSignup => AbuseVector::SignupAbuse,

            self::OtpRequestVelocity,
            self::OtpFailureVelocity => AbuseVector::OtpAbuse,
        };
    }

    /**
     * How severe this signal is — the single place severity is decided.
     */
    public function action(): GuardAction
    {
        return match ($this) {
            // Evasion machinery, a degraded auxiliary classifier, and a signup with
            // nothing to count are all worth a recorded flag and none of them is worth
            // refusing a customer over on its own.
            self::Obfuscation,
            self::ClassifierDegraded,
            self::UncountableSignup,
            self::ConversationBlockBurst => GuardAction::Flag,

            // Informational: the release of a kill-switch is recorded for the trail and
            // permits everything by definition.
            self::KillSwitchReleased => GuardAction::Allow,

            default => GuardAction::Block,
        };
    }

    /**
     * Whether this signal is one of the three "could not classify" outcomes, as
     * opposed to a positive detection. Kept as a predicate because an operator
     * triaging the abuse feed needs to tell "we found an attack" apart from "we could
     * not look" — the first tunes policy, the second is an engineering defect.
     */
    public function isFailClosed(): bool
    {
        return match ($this) {
            self::Undecodable, self::Oversized, self::ClassifierUnavailable => true,
            default => false,
        };
    }

    /**
     * One sentence, safe to store and show: it describes the *pattern*, never the
     * text that matched it.
     */
    public function label(): string
    {
        return match ($this) {
            self::InstructionOverride => 'Attempt to override the standing instructions',
            self::RoleReassignment => 'Attempt to reassign the assistant\'s role',
            self::HierarchyPromotion => 'User content presenting itself as a system instruction',
            self::FenceEscape => 'Attempt to break out of the untrusted-content fence',
            self::SystemPromptProbe => 'Attempt to make the system prompt be revealed',
            self::ToolExfiltration => 'Attempt to exfiltrate credentials, configuration, or tool internals',
            self::EncodedPayload => 'Encoded payload that decodes to an injection attempt',
            self::Obfuscation => 'Invisible or look-alike characters used to evade detection',
            self::CustomPattern => 'Matched a configured policy pattern',
            self::Undecodable => 'Text could not be decoded, so it could not be classified',
            self::Oversized => 'Text is longer than the classifier examines in full',
            self::ClassifierUnavailable => 'The injection classifier could not run',
            self::ClassifierDegraded => 'An auxiliary classifier was unavailable; the deterministic verdict stands',
            self::SystemPromptLeak => 'Reply reproduced part of the system prompt',
            self::FenceLeak => 'Reply reproduced the untrusted-content fence scaffolding',
            self::SecretShaped => 'Reply contained a credential-shaped value',
            self::OutputPolicyViolation => 'Reply matched a configured output policy pattern',
            self::SessionKilled => 'The session is under a kill-switch',
            self::KillSwitchEngaged => 'Kill-switch engaged for the session',
            self::KillSwitchReleased => 'Kill-switch released for the session',
            self::ConversationBlockBurst => 'Repeated blocked messages in one conversation',
            self::SignupIpVelocity => 'Too many signups from this address',
            self::SignupSubnetVelocity => 'Too many signups from this address range',
            self::SignupDeviceVelocity => 'Too many signups from this device',
            self::SignupIdentityVelocity => 'Too many signup attempts for this identity',
            self::DisposableEmailDomain => 'Disposable email domain',
            self::UncountableSignup => 'Signup carried neither an address nor a device fingerprint',
            self::OtpRequestVelocity => 'Too many one-time codes requested',
            self::OtpFailureVelocity => 'Too many incorrect one-time codes',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * The strictest action across a list of signals — how a verdict is formed.
     *
     * @param  list<self>  $signals
     */
    public static function actionFor(array $signals): GuardAction
    {
        $action = GuardAction::Allow;

        foreach ($signals as $signal) {
            $action = $action->escalate($signal->action());
        }

        return $action;
    }
}
