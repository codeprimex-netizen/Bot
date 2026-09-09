<?php

declare(strict_types=1);

namespace Tests\Fixtures\Reliability;

use RuntimeException;

/**
 * Stands in for a provider SDK's own exception — the thing a later phase (a Channel Mode
 * driver, an LLM client, a payment gateway) has to classify without the platform knowing
 * anything about it.
 */
final class FakeProviderException extends RuntimeException
{
    public function __construct(public readonly int $providerCode = 0)
    {
        parent::__construct(sprintf('provider said %d', $providerCode));
    }
}
