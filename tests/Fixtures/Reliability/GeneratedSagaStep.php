<?php

declare(strict_types=1);

namespace Tests\Fixtures\Reliability;

use App\Services\Reliability\SagaContext;
use App\Services\Reliability\SagaStepDefinition;
use App\Services\Reliability\SagaStepResult;
use RuntimeException;
use stdClass;

/**
 * A saga step whose *shape* is drawn rather than written: it either creates a real,
 * observable effect in `SagaWorld` or is read-only, contributes a drawn slice of saga
 * state, and honours every fault `SagaWorld` has been told to inject at its name.
 *
 * `SpySagaStep` is the same idea with a fixed shape; this one exists because
 * Correctness Property 18 has to hold for sagas of *any* length and *any* mix of
 * compensating and read-only steps, and a fixture with three hard-coded compensating
 * steps cannot state that. The two are deliberately not merged: the scripted suite
 * reads better against a fixed cast, and a shared class would have to grow a
 * configuration surface that no scripted test uses.
 *
 * The effects are the point, as in `SpySagaStep`: a compensation that is never entered
 * — because it was replayed off the forward action's idempotency key — leaves its
 * reservation standing, and `SagaWorld::liveEffects()` says so however tidy the status
 * columns look.
 */
final class GeneratedSagaStep implements SagaStepDefinition
{
    /**
     * @param  bool  $compensates  whether the forward action leaves an effect that must be undone; `false` is a read-only step returning `SagaStepResult::none()`
     * @param  array<string, mixed>  $contributes  this step's contribution to `sagas.state`
     */
    public function __construct(
        private readonly string $name,
        private readonly bool $compensates,
        private readonly array $contributes,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function forward(SagaContext $context): SagaStepResult
    {
        if (SagaWorld::forwardShouldFail($this->name)) {
            // Nothing has been reserved, so this step leaves nothing to undo — the
            // contract `FAILED` steps rely on.
            throw new RuntimeException(sprintf('forward action of [%s] failed on purpose', $this->name));
        }

        if (! $this->compensates) {
            SagaWorld::performed($this->name);

            // A read-only step can have an unstorable result too — a contribution the
            // ledger cannot hold. It leaves no effect, so the interesting half is only
            // that the orchestrator still settles it DONE and unwinds.
            return SagaStepResult::none()->contributing(
                SagaWorld::recordingShouldFail($this->name)
                    ? [...$this->contributes, 'unstorable' => new stdClass]
                    : $this->contributes
            );
        }

        $handle = ['reservation' => $this->name.'-handle'];

        // The effect exists from here on. Everything below is about what the *record* of
        // it does or does not manage to say.
        SagaWorld::reserve($this->name, $handle);

        if (SagaWorld::shouldOrphan($this->name)) {
            throw new RuntimeException(sprintf(
                'forward action of [%s] failed after its effect landed', $this->name
            ));
        }

        if (SagaWorld::recordingShouldFail($this->name)) {
            // An object among the contributed state: the effect happened and its result
            // cannot go in the ledger, so the step must end up DONE and be compensated
            // without a recorded handle.
            return SagaStepResult::compensateWith($handle, ref: 'release:'.$this->name)
                ->contributing([...$this->contributes, 'unstorable' => new stdClass]);
        }

        // Everything the compensation needs travels through the ledger, so a step whose
        // bookkeeping was lost to a crash can still be undone after a replay.
        return SagaStepResult::compensateWith($handle, ref: 'release:'.$this->name)
            ->contributing($this->contributes);
    }

    public function compensate(SagaContext $context): void
    {
        if (SagaWorld::compensationShouldFail($this->name)) {
            throw new RuntimeException(sprintf('compensation of [%s] failed on purpose', $this->name));
        }

        if (! $this->compensates) {
            // Read-only, and still invoked — the orchestrator never consults the nullable
            // handle columns to decide. Recorded so the reverse-order claim covers it.
            SagaWorld::release($this->name);

            return;
        }

        // Driven by what the forward action recorded, not by live state: a compensation
        // that recomputed its handle would pass this suite while being wrong in
        // production. The one legitimate absence is a step whose result the ledger could
        // not store — its effect landed and must still be released, by the name the world
        // knows it by.
        $reservation = $context->compensationPayload()['reservation'] ?? null;

        if (! is_string($reservation) && ! SagaWorld::recordingShouldFail($this->name)) {
            throw new RuntimeException(sprintf(
                'compensation of [%s] has no recorded handle to act on', $this->name
            ));
        }

        SagaWorld::release($this->name);
    }
}
