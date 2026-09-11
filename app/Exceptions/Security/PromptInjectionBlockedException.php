<?php

declare(strict_types=1);

namespace App\Exceptions\Security;

use App\Services\Abuse\GuardVerdict;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * The guardrail refused a text — inbound or outbound (design § Error Handling:
 * `PromptInjectionBlockedException → logged to abuse_events; reply suppressed`;
 * Req 13.8 / B4).
 *
 * ## Who throws it, and who does not
 *
 * `Guardrail::inspectInput()`/`inspectOutput()` **return** a verdict; they do not
 * throw. That is deliberate: the LLM stage (task 15.2) has to *act* on a block —
 * suppress the reply, keep the conversation alive, possibly hand off to a human — and
 * an exception at that seam would turn a policy decision into a failed job. This
 * exception is for the callers that have no such branch: `assertInput()`/
 * `assertOutput()`, an API endpoint that must answer 422 rather than fall through, and
 * any future path where "carry on without the reply" is not a coherent option.
 *
 * Either way the `abuse_events` row is written by the guardrail *before* the caller
 * sees the verdict, so recording never depends on how the caller handles the refusal.
 *
 * ## What the message may contain
 *
 * The signals and the content hash — never the text. A blocked message is by
 * definition attacker-controlled and by presumption sensitive: putting it in an
 * exception message would copy it into every log aggregator and bug tracker the
 * platform reports to, which is exactly the disclosure the guardrail exists to
 * prevent (see `SecurityException`).
 */
final class PromptInjectionBlockedException extends SecurityException implements HttpExceptionInterface
{
    /**
     * Unprocessable content: the request was well-formed, its *content* was refused.
     */
    public const int STATUS = 422;

    /**
     * The only sentence a client is ever shown. It names no pattern, so it cannot be
     * used as an oracle for tuning a payload past the classifier.
     */
    public const string PUBLIC_MESSAGE = 'This message could not be processed.';

    /**
     * Stable machine-readable code for API clients and the panel.
     */
    public const string ERROR_CODE = 'content_blocked';

    private function __construct(string $message, public readonly GuardVerdict $verdict)
    {
        parent::__construct($message);
    }

    /**
     * Inbound text was refused, so no model call was made.
     */
    public static function onInput(GuardVerdict $verdict): self
    {
        return new self(sprintf(
            'Inbound text was blocked by the guardrail (%s; content %s). No model call was made.',
            self::describeSignals($verdict),
            self::describeContent($verdict),
        ), $verdict);
    }

    /**
     * A model reply failed output validation, so it was suppressed rather than sent.
     */
    public static function onOutput(GuardVerdict $verdict): self
    {
        return new self(sprintf(
            'A model reply was suppressed by output validation (%s; content %s). Nothing was sent.',
            self::describeSignals($verdict),
            self::describeContent($verdict),
        ), $verdict);
    }

    public function getStatusCode(): int
    {
        return self::STATUS;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return [];
    }

    /**
     * Retrying the same text would be refused for the same reason, so a queue worker
     * must not put it back.
     */
    public function isRetryable(): bool
    {
        return false;
    }

    /**
     * The sentence that may be shown to a client.
     */
    public function publicMessage(): string
    {
        return self::PUBLIC_MESSAGE;
    }

    private static function describeSignals(GuardVerdict $verdict): string
    {
        return 'signals: '.($verdict->signalList() === '' ? 'none' : $verdict->signalList());
    }

    /**
     * Length and hash — the two facts about refused text that are safe anywhere.
     */
    private static function describeContent(GuardVerdict $verdict): string
    {
        return sprintf('%d chars, sha256 %s', $verdict->contentLength, substr($verdict->contentHash, 0, 12));
    }
}
