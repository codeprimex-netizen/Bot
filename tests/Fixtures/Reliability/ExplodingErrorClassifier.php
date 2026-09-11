<?php

declare(strict_types=1);

namespace Tests\Fixtures\Reliability;

use App\Enums\ErrorClass;
use App\Services\Reliability\ErrorClassifier;
use RuntimeException;
use Throwable;

/**
 * A badly written classifier. The chain must survive it: classification runs while
 * something is already failing, so a broken classifier may not be allowed to turn a
 * retryable send into a crashed worker.
 */
final class ExplodingErrorClassifier implements ErrorClassifier
{
    public function classify(Throwable $e): ?ErrorClass
    {
        throw new RuntimeException('classifier is broken');
    }
}
