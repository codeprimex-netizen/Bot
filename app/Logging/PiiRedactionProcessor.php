<?php

declare(strict_types=1);

namespace App\Logging;

use App\Support\Pii\LogPiiScrubber;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Throwable;

/**
 * Scrubs PII out of **every** log record, before any handler sees it
 * (Req 7.3 / A7; Req 32.2 / NFR3).
 *
 * This is a processor rather than a helper on purpose. "Call the redactor before you
 * log a phone number" is a rule that holds until the first person who has not read
 * it, and that one forgotten call is the breach — the platform would be one
 * `Log::info($message)` away from writing customer phone numbers to disk forever.
 * A processor moves the guarantee from discipline to plumbing: `RedactingLogManager`
 * attaches this class to every channel the application ever builds, including
 * channels configured after this code was written, so there is no log call anywhere
 * in `app/` that can bypass it.
 *
 * Three record parts are covered:
 *
 * - **message** — masked. `Log::info('called +14155552671')` never reaches a handler
 *   with those digits in it.
 * - **context** — walked with the key rules: secrets dropped, message bodies replaced
 *   by their hash, everything else masked. PSR placeholder interpolation happens in a
 *   later processor, so `Log::info('call {phone}', [...])` is interpolated from the
 *   *masked* context.
 * - **extra** — walked identically, since processors upstream (Laravel's own context
 *   processor, tracing) put data there.
 *
 * Two properties this class must have, and how each is achieved:
 *
 * 1. **It cannot break logging.** A redaction failure — a pathological string, an
 *    exhausted PCRE limit — is caught, and the record is replaced with a marker
 *    instead of being emitted raw or dropped. Failing closed on *content* while
 *    keeping the log line is the only combination that neither leaks nor blinds an
 *    operator.
 * 2. **It is idempotent.** A stack channel copies its children's processors, so a
 *    record can pass this class twice; the `pii_redacted` marker in `extra` makes the
 *    second pass a no-op, which matters because re-hashing an already-hashed body
 *    would produce a different digest per channel.
 */
final class PiiRedactionProcessor implements ProcessorInterface
{
    /**
     * Marker written into `extra`, both as the idempotence guard and as a visible
     * assertion in the log that the line went through redaction.
     */
    public const string MARKER = 'pii_redacted';

    public function __construct(private readonly LogPiiScrubber $scrubber) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        if (($record->extra[self::MARKER] ?? null) === true) {
            return $record;
        }

        try {
            return $record->with(
                message: $this->scrubber->scrub($record->message),
                context: $this->scrubber->scrubPayload($record->context),
                extra: array_merge($this->scrubber->scrubPayload($record->extra), [self::MARKER => true]),
            );
        } catch (Throwable $failure) {
            // The line survives, its content does not: an operator still sees that
            // something was logged at this level, at this time, on this channel.
            return $record->with(
                message: '[log record withheld: PII redaction failed]',
                context: ['redaction_error' => $failure::class],
                extra: [self::MARKER => true],
            );
        }
    }
}
