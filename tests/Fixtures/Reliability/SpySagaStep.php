<?php

declare(strict_types=1);

namespace Tests\Fixtures\Reliability;

use App\Services\Reliability\SagaContext;
use App\Services\Reliability\SagaStepDefinition;
use App\Services\Reliability\SagaStepResult;
use RuntimeException;

/**
 * A saga step whose forward action creates a real, observable effect in `SagaWorld` and
 * whose compensation removes it — with per-step fault injection in either direction.
 *
 * The effect is the point. Property 18 is about side effects, so a double that only
 * recorded "compensate() was called" would pass even if the compensation had been
 * replayed off the forward action's idempotency key and never run at all. Here the
 * assertion is that the reservation is *gone*.
 */
final class SpySagaStep implements SagaStepDefinition
{
    public function __construct(private readonly string $name) {}

    public function name(): string
    {
        return $this->name;
    }

    public function forward(SagaContext $context): SagaStepResult
    {
        if (SagaWorld::forwardShouldFail($this->name)) {
            throw new RuntimeException(sprintf('forward action of [%s] failed on purpose', $this->name));
        }

        $handle = ['reservation' => $this->name.'-handle'];

        SagaWorld::reserve($this->name, $handle);

        // Everything the compensation needs travels back through the ledger, so a step
        // whose bookkeeping was lost to a crash can still be undone after a replay.
        return SagaStepResult::compensateWith($handle, ref: 'release:'.$this->name)
            ->contributing([$this->name.'_done' => true]);
    }

    public function compensate(SagaContext $context): void
    {
        if (SagaWorld::compensationShouldFail($this->name)) {
            throw new RuntimeException(sprintf('compensation of [%s] failed on purpose', $this->name));
        }

        // Deliberately driven by what the forward action recorded, not by live state: a
        // compensation that recomputed its handle would pass this suite while being wrong
        // in production, where the world has moved on by the time it runs.
        $reservation = $context->compensationPayload()['reservation'] ?? null;

        if (! is_string($reservation)) {
            throw new RuntimeException(sprintf(
                'compensation of [%s] has no recorded handle to act on', $this->name
            ));
        }

        SagaWorld::release($this->name);
    }
}
