<?php

declare(strict_types=1);

namespace App\Events\Tenancy;

/**
 * The audited tenant-scope bypass was closed (Req 1.5 / A1).
 *
 * Emitted on the matching `exitPlatformMode()`, at the end of `asPlatform()`,
 * and by `forget()` if a frame was still open at a request/job boundary — so
 * every `PlatformModeEntered` is guaranteed a paired exit for the audit trail.
 */
final readonly class PlatformModeExited
{
    /**
     * @param  string  $reason  the reason the closed frame was opened with
     * @param  float  $durationMs  how long the bypass stayed open
     * @param  bool  $forced  true when closed by a boundary `forget()` rather than an explicit exit
     */
    public function __construct(
        public string $reason,
        public float $durationMs,
        public bool $forced = false,
    ) {}
}
