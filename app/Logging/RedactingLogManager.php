<?php

declare(strict_types=1);

namespace App\Logging;

use Illuminate\Log\Logger;
use Illuminate\Log\LogManager;
use Monolog\Logger as Monolog;
use Psr\Log\LoggerInterface;

/**
 * The platform's `log` manager: identical to Laravel's, except that **no channel can
 * be created without PII redaction attached** (Req 7.3 / A7; Req 32.2 / NFR3).
 *
 * The alternative wirings were each one omission away from a leak:
 *
 * | Approach | Why not |
 * |---|---|
 * | call the redactor at each log site | one forgotten call is the breach |
 * | `'tap' => [...]` per channel in `config/logging.php` | a channel added later, or built at runtime with `Log::build()`, has no tap |
 * | `'processors' => [...]` | only the `monolog` driver honours it |
 * | listen for `MessageLogged` | fires after the handlers have already written |
 *
 * Overriding channel construction is the only hook that covers *every* channel:
 * the configured ones, the stack, ad-hoc `Log::build()` channels, and the emergency
 * logger Laravel falls back to when channel creation itself fails — which is exactly
 * the moment an exception carrying customer data would otherwise be written raw.
 *
 * Only two things are extended, both of them the framework's own extension points for
 * "decorate a freshly built channel"; every driver, formatter, and level behaviour is
 * inherited untouched.
 */
final class RedactingLogManager extends LogManager
{
    /**
     * Every configured channel passes through here on creation.
     *
     * @param  string  $name
     */
    protected function tap($name, Logger $logger): Logger
    {
        $tapped = parent::tap($name, $logger);

        $this->attachRedaction($tapped);

        return $tapped;
    }

    /**
     * The fallback logger, used when a channel cannot be built at all — the record it
     * writes is a real exception with real context, so it needs redaction most.
     */
    protected function createEmergencyLogger(): LoggerInterface
    {
        $logger = parent::createEmergencyLogger();

        if ($logger instanceof Logger) {
            $this->attachRedaction($logger);
        }

        return $logger;
    }

    /**
     * Push the processor onto the channel's Monolog instance, once.
     *
     * Monolog applies processors in the order they sit in its array and `pushProcessor`
     * prepends, so this runs **first** — before Laravel's context processor and before
     * PSR placeholder interpolation, which means every value those steps go on to copy
     * around has already been scrubbed.
     *
     * The idempotence check matters for stack channels: `createStackDriver` copies its
     * children's processors into the stack's own Monolog, so without it a stacked
     * record would be processed once per channel.
     */
    private function attachRedaction(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        if (! $monolog instanceof Monolog) {
            return;
        }

        foreach ($monolog->getProcessors() as $processor) {
            if ($processor instanceof PiiRedactionProcessor) {
                return;
            }
        }

        $monolog->pushProcessor($this->app->make(PiiRedactionProcessor::class));
    }
}
