<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Reliability\DatabaseOutbox;
use App\Services\Reliability\HttpOutboxTransport;
use App\Services\Reliability\Outbox;
use App\Services\Reliability\OutboxTransport;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

/**
 * Wires the transactional outbox and its transport (Req 31.4 / NFR2; task 3.3).
 *
 * Both singletons, because neither holds state between calls: the outbox's entire memory is
 * the `outbox` table, and the HTTP transport's is the client factory it is given. One
 * instance per worker process keeps `enqueue()` off the container's critical path — it is
 * called inside other people's transactions, so it should cost an `INSERT` and nothing else.
 *
 * ## The transport is configurable, and a broken choice is fatal
 *
 * `wa.reliability.outbox.transport` is the seam Phase 5+ channel drivers arrive through
 * (NFR4.2). It is validated at resolution time and an unusable value **throws**, rather
 * than falling back to the default. That is the opposite of how, say, a missing error
 * classifier is treated, and for a specific reason: the relay marks a row `SENT` when
 * `deliver()` returns, so a transport that silently did nothing would mark the whole queue
 * delivered without a single effect leaving the platform — undetectable afterwards, and the
 * exact failure Req 31.4 exists to prevent. Failing to boot is the cheap outcome.
 *
 * Deliberately not folded into a shared `ReliabilityServiceProvider`, for the reason
 * `CircuitBreakerServiceProvider` and `IdempotencyServiceProvider` both state: one provider
 * per primitive is cheaper to reason about than one provider with four branches.
 */
class OutboxServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(OutboxTransport::class, function (): OutboxTransport {
            $configured = config('wa.reliability.outbox.transport', HttpOutboxTransport::class);

            if (! is_string($configured) || ! is_a($configured, OutboxTransport::class, allow_string: true)) {
                throw new InvalidArgumentException(sprintf(
                    'wa.reliability.outbox.transport must name a class implementing %s; got %s. Refusing to relay '
                    .'with a transport that cannot deliver — rows would be marked SENT with nothing sent.',
                    OutboxTransport::class,
                    is_string($configured) ? $configured : get_debug_type($configured),
                ));
            }

            /** @var OutboxTransport $transport */
            $transport = $this->app->make($configured);

            return $transport;
        });

        $this->app->singleton(Outbox::class, DatabaseOutbox::class);
    }
}
