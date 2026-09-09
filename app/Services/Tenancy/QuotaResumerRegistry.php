<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Exceptions\Tenancy\UnknownQuotaResumerException;
use Illuminate\Contracts\Container\Container;

/**
 * Resolves the short key on a hold (`campaign`, `sequence`, `import`) to the
 * `QuotaResumer` that hands that kind of work back (Req 20.3 / C3).
 *
 * The map is config (`wa.tenancy.quota.holds.resumers`), so a subsystem adds itself by
 * appending one line and implementing one method — the sweep, the table, and this class do
 * not change. That is the seam task 26.2 (campaigns), 26.5 (imports) and 27.x (drip
 * sequences) each use.
 *
 * ## Unresolvable is fatal *for that hold*, never global
 *
 * A key that names nothing usable raises `UnknownQuotaResumerException`, which
 * `QuotaParkingLot` catches per hold: that hold stays `QUOTA_PAUSED` with the error
 * recorded, and every other hold in the sweep is still processed. The alternative — a
 * registry that returned null and let the caller treat "no handler" as "nothing to do" —
 * would close the hold and lose the campaign, which is exactly what Req 31.1 forbids.
 *
 * (This is the opposite trade-off to `DispatchServiceProvider`, which refuses to boot on a
 * bad gate class. There, a missing gate silently removes a platform-wide cap; here, a
 * missing resumer can only delay one tenant's parked work, and refusing to run the sweep
 * at all would delay *everyone's*.)
 */
final readonly class QuotaResumerRegistry
{
    public function __construct(private Container $container) {}

    /**
     * Whether $key names a resolvable resumer.
     */
    public function has(string $key): bool
    {
        return $this->classFor($key) !== null;
    }

    /**
     * The resumer registered under $key.
     *
     * @throws UnknownQuotaResumerException when nothing usable is registered under it
     */
    public function resolve(string $key): QuotaResumer
    {
        $class = $this->classFor($key);

        if ($class === null) {
            throw UnknownQuotaResumerException::notRegistered($key);
        }

        $resumer = $this->container->make($class);

        if (! $resumer instanceof QuotaResumer) {
            throw UnknownQuotaResumerException::notAResumer($key, $class);
        }

        return $resumer;
    }

    /**
     * The registered keys, for diagnostics and the operator-facing command output.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_values(array_filter(
            array_map(
                static fn (mixed $key): string => is_string($key) ? $key : '',
                array_keys($this->map()),
            ),
            static fn (string $key): bool => $key !== '',
        ));
    }

    /**
     * The class registered under $key, or null when there is none that could be a resumer.
     *
     * @return class-string|null
     */
    private function classFor(string $key): ?string
    {
        $entry = $this->map()[trim($key)] ?? null;

        if (! is_string($entry)) {
            return null;
        }

        $class = trim($entry);

        return $class !== '' && class_exists($class) ? $class : null;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function map(): array
    {
        $configured = config('wa.tenancy.quota.holds.resumers');

        return is_array($configured) ? $configured : [];
    }
}
