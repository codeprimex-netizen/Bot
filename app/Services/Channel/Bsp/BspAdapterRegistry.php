<?php

declare(strict_types=1);

namespace App\Services\Channel\Bsp;

use App\Enums\BspProvider;
use App\Services\Channel\Bsp\Adapters\GupshupAdapter;
use App\Services\Channel\Bsp\Adapters\InfobipAdapter;
use App\Services\Channel\Bsp\Adapters\KaleyraAdapter;
use App\Services\Channel\Bsp\Adapters\MessageBirdAdapter;
use App\Services\Channel\Bsp\Adapters\ThreeSixtyDialogAdapter;
use App\Services\Channel\Bsp\Adapters\TwilioAdapter;
use App\Services\Channel\Bsp\Adapters\VonageAdapter;
use App\Services\Channel\Bsp\Adapters\WatiAdapter;
use LogicException;

/**
 * Exactly one `BspAdapter` per `BspProvider`, resolved by enum case — the lookup that replaces the
 * provider `switch` a single-class driver would have needed (Req 8.1, 8.2 / A8; Req 33.1 / NFR4).
 *
 * ```php
 * $adapter = $registry->for(BspProvider::Gupshup);   // never a match, never a null
 * ```
 *
 * ## Why it is a class and not an array in the driver
 *
 * Two readers need the same map for different reasons, and neither should own it:
 * `BspGatewayChannelDriver` needs the request shapes, and `BspGatewayErrorClassifier` needs the
 * error vocabularies. The classifier runs *while something is already failing* — it is reached from
 * `RetryPolicy` inside a failed job — so it must not construct a driver, and the driver must not be
 * the only way to reach an adapter.
 *
 * ## Completeness is checked at construction, not hoped for
 *
 * The constructor refuses a map that is missing a provider or that files an adapter under the wrong
 * one. That turns the failure mode of adding a ninth `BspProvider` case from *"a tenant's sends
 * fail at the provider with a confusing error"* into *"the container cannot build the driver"*,
 * which is a deployment defect reported as one.
 *
 * It is the same posture `DefaultChannelRouter` takes about the mode registry — refuse loudly
 * rather than substitute a default backend — and for a sharper reason here: a *default* adapter
 * would send one partner's body to another partner's endpoint under a third's auth header.
 *
 * ## Adapters are stateless, so one instance each is enough
 *
 * Every adapter is `final readonly`, holds nothing, performs no I/O and is a pure function from
 * credentials to a `BspRequest` (see `BspAdapter`). So the eight are constructed once here rather
 * than resolved per call, and this registry is safe to register as a **singleton** — it can carry no
 * tenant state into another tenant's job, which is precisely the property that forces
 * `ChannelCredentialStore` and `ChannelRouter` to be `scoped()` instead.
 */
final readonly class BspAdapterRegistry
{
    /**
     * @var array<string, BspAdapter> keyed by `BspProvider::value`
     */
    private array $adapters;

    /**
     * @param  list<BspAdapter>|null  $adapters  the eight, or null for the platform's own set
     *
     * @throws LogicException when the set does not cover `BspProvider` exactly once each
     */
    public function __construct(?array $adapters = null)
    {
        $keyed = [];

        foreach ($adapters ?? self::defaults() as $adapter) {
            $key = $adapter->provider()->value;

            if (array_key_exists($key, $keyed)) {
                throw new LogicException(sprintf(
                    'Two BSP adapters claim provider [%s]: %s and %s. One partner is served by exactly '
                    .'one adapter, because which of them a send used would otherwise depend on '
                    .'registration order.',
                    $key,
                    $keyed[$key]::class,
                    $adapter::class,
                ));
            }

            $keyed[$key] = $adapter;
        }

        $missing = [];

        foreach (BspProvider::cases() as $provider) {
            if (! array_key_exists($provider->value, $keyed)) {
                $missing[] = $provider->value;
            }
        }

        if ($missing !== []) {
            throw new LogicException(sprintf(
                'No BSP adapter is registered for %s. BspGatewayChannelDriver fronts every BspProvider '
                .'case, and a tenant can select any of them — so a missing adapter is a deployment '
                .'defect, refused here rather than discovered by a tenant whose sends fail at the '
                .'provider.',
                implode(', ', $missing),
            ));
        }

        $this->adapters = $keyed;
    }

    /**
     * The adapter for `$provider`.
     *
     * Total, by construction: every `BspProvider` case has an entry, checked above, so this needs no
     * null arm and no caller has to handle one.
     */
    public function for(BspProvider $provider): BspAdapter
    {
        return $this->adapters[$provider->value];
    }

    /**
     * Every adapter, in `BspProvider` declaration order.
     *
     * Ordered by the enum rather than by the constructor's argument, so a caller that iterates —
     * `BspGatewayChannelDriver::isReachable()` probing partner hosts, a test walking every partner —
     * sees a stable order whatever order the adapters were handed over in.
     *
     * @return list<BspAdapter>
     */
    public function all(): array
    {
        return array_map(
            fn (BspProvider $provider): BspAdapter => $this->for($provider),
            BspProvider::cases(),
        );
    }

    /**
     * The platform's own eight.
     *
     * A code list rather than a config key, for the reason `ChannelServiceProvider::drivers()` gives
     * about the mode registry: which class speaks a partner's protocol is a property of the platform,
     * not an operator preference, and a mis-keyed entry in an environment-driven map would be a live
     * cross-provider bug — one tenant's API key posted to another partner's endpoint.
     *
     * @return list<BspAdapter>
     */
    public static function defaults(): array
    {
        return [
            new TwilioAdapter,
            new ThreeSixtyDialogAdapter,
            new GupshupAdapter,
            new VonageAdapter,
            new MessageBirdAdapter,
            new InfobipAdapter,
            new WatiAdapter,
            new KaleyraAdapter,
        ];
    }
}
