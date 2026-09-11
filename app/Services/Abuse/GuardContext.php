<?php

declare(strict_types=1);

namespace App\Services\Abuse;

/**
 * Who and what an inspection is about — the attribution an `abuse_events` row needs
 * and the key the per-session kill-switch is checked against (Req 13.8 / B4).
 *
 * ```php
 * $verdict = $guardrail->inspectInput($message->body, GuardContext::forSession($sessionId, $conversationId));
 * ```
 *
 * Optional by design: `inspectInput($text)` on its own is the signature design § AI 1.3
 * specifies and it works, recording an event with no session attribution. Supplying a
 * context is what activates the two features that need identity — the kill-switch check
 * and the per-conversation block burst — so a caller that has a session should always
 * pass one.
 *
 * **Keys, not foreign keys.** `sessions_wa` and `conversations` arrive in later phases
 * (tasks 6.x, 12.x); the abuse layer only ever compares these strings, so it neither
 * waits for those tables nor breaks when a session is deleted. The tenant is *not* a
 * field: it comes from `TenantContext`, which is the platform's single answer to "which
 * tenant is this?" and cannot be overridden by a caller here.
 */
final readonly class GuardContext
{
    public function __construct(
        public ?string $sessionKey = null,
        public ?string $conversationKey = null,
        public ?string $surface = null,
    ) {}

    public static function none(): self
    {
        return new self;
    }

    public static function forSession(?string $sessionKey, ?string $conversationKey = null): self
    {
        return new self($sessionKey, $conversationKey);
    }

    /**
     * The same context with a surface label, used by the guardrail to distinguish
     * `guardrail.input` from `guardrail.output` on otherwise identical attribution.
     */
    public function on(string $surface): self
    {
        return new self($this->sessionKey, $this->conversationKey, $surface);
    }

    /**
     * The key the per-conversation block counter is kept under — the conversation when
     * there is one, else the session. Null when neither is known, in which case there
     * is nothing to rate-limit and the counter is skipped rather than shared between
     * unrelated callers.
     */
    public function burstKey(): ?string
    {
        return $this->conversationKey ?? $this->sessionKey;
    }
}
