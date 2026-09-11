<?php

declare(strict_types=1);

namespace Tests\Fixtures\Reliability;

use App\Enums\ErrorClass;
use App\Services\Reliability\ErrorClassifier;
use Throwable;

/**
 * What a subsystem registers so its own provider errors get the right retry budget — the
 * extension mechanism exercised for real: it recognises one vendor code and declines
 * everything else with `null`.
 */
final class FakeProviderErrorClassifier implements ErrorClassifier
{
    public function classify(Throwable $e): ?ErrorClass
    {
        if (! $e instanceof FakeProviderException) {
            return null;
        }

        return match ($e->providerCode) {
            429 => ErrorClass::RateLimit,
            131_026 => ErrorClass::NotOnWhatsApp,
            default => null,
        };
    }
}
