<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Illuminate\Contracts\Cache\Store;

/**
 * A cache store that does **not** implement `LockProvider`.
 *
 * Every store the framework ships supports locks, so this is the only way to exercise
 * the documented fallback in `HashChainAuditService`: with no lock available an audit
 * append still succeeds, relying on `unique(chain_key, sequence)` and the retry loop
 * instead of refusing to record a privileged action.
 */
final class LocklessStore implements Store
{
    /** @var array<string, mixed> */
    private array $items = [];

    public function get($key): mixed
    {
        return $this->items[$key] ?? null;
    }

    /**
     * @param  array<int, string>  $keys
     * @return array<string, mixed>
     */
    public function many(array $keys): array
    {
        $values = [];

        foreach ($keys as $key) {
            $values[$key] = $this->get($key);
        }

        return $values;
    }

    public function put($key, $value, $seconds): bool
    {
        $this->items[$key] = $value;

        return true;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function putMany(array $values, $seconds): bool
    {
        foreach ($values as $key => $value) {
            $this->put($key, $value, $seconds);
        }

        return true;
    }

    public function increment($key, $value = 1): int
    {
        $current = (int) ($this->items[$key] ?? 0);
        $this->items[$key] = $current + (int) $value;

        return $this->items[$key];
    }

    public function decrement($key, $value = 1): int
    {
        return $this->increment($key, -(int) $value);
    }

    public function forever($key, $value): bool
    {
        return $this->put($key, $value, 0);
    }

    public function forget($key): bool
    {
        unset($this->items[$key]);

        return true;
    }

    public function flush(): bool
    {
        $this->items = [];

        return true;
    }

    public function getPrefix(): string
    {
        return '';
    }
}
