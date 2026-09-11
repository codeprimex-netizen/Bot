<?php

declare(strict_types=1);

use App\Support\Cache\VersionedCache;
use Illuminate\Support\Facades\Cache;

/*
|--------------------------------------------------------------------------
| Versioned cache invalidation (Req 30.4 / NFR1)
|--------------------------------------------------------------------------
| The point of the pattern is a negative guarantee: after a bump there is no key a
| reader could compose that still reaches a pre-bump entry. The tests below assert
| that, not just that the version number moved.
*/

beforeEach(function (): void {
    Cache::flush();
});

function versionedCache(string $namespace = 'plans', int $ttl = 300): VersionedCache
{
    return new VersionedCache($namespace, $ttl);
}

it('composes every key from the namespace and the current version', function (): void {
    $cache = versionedCache();

    expect($cache->key('slug:growth'))->toBe(sprintf('plans:v%d:slug:growth', $cache->version()))
        ->and($cache->namespace())->toBe('plans');
});

it('computes a value once and serves it from the cache afterwards', function (): void {
    $cache = versionedCache();
    $calls = 0;

    $compute = function () use (&$calls): string {
        $calls++;

        return 'growth';
    };

    expect($cache->remember('slug:growth', $compute))->toBe('growth')
        ->and($cache->remember('slug:growth', $compute))->toBe('growth')
        ->and($calls)->toBe(1);
});

it('makes every pre-bump entry unreachable with one bump', function (): void {
    $cache = versionedCache();

    $cache->put('id:1', 'stale');
    $cache->put('slug:growth', 'stale');
    $cache->put('active', ['stale']);

    $before = $cache->version();
    $after = $cache->bump();

    expect($after)->toBeGreaterThan($before)
        // Not one forgotten key — the whole namespace, including entries the writer
        // never named.
        ->and($cache->get('id:1'))->toBeNull()
        ->and($cache->get('slug:growth'))->toBeNull()
        ->and($cache->get('active'))->toBeNull()
        ->and($cache->has('id:1'))->toBeFalse();
});

it('recomputes after a bump instead of serving the superseded value', function (): void {
    $cache = versionedCache();
    $value = 'first';

    expect($cache->remember('slug:growth', fn (): string => $value))->toBe('first');

    $value = 'second';
    $cache->bump();

    expect($cache->remember('slug:growth', fn (): string => $value))->toBe('second');
});

it('does not pin a null result as a cache hit', function (): void {
    $cache = versionedCache();
    $calls = 0;

    $miss = function () use (&$calls): ?string {
        $calls++;

        return null;
    };

    expect($cache->remember('id:missing', $miss))->toBeNull()
        ->and($cache->remember('id:missing', $miss))->toBeNull()
        ->and($calls)->toBe(2);
});

it('forgets a single entry without disturbing the rest of the namespace', function (): void {
    $cache = versionedCache();

    $cache->put('id:1', 'one');
    $cache->put('id:2', 'two');
    $cache->forget('id:1');

    expect($cache->get('id:1'))->toBeNull()
        ->and($cache->get('id:2'))->toBe('two');
});

it('keeps namespaces independent so one bump cannot invalidate another layer', function (): void {
    $plans = versionedCache('plans');
    $flows = versionedCache('flows');

    $plans->put('shared-key', 'plan');
    $flows->put('shared-key', 'flow');

    $plans->bump();

    expect($plans->get('shared-key'))->toBeNull()
        ->and($flows->get('shared-key'))->toBe('flow');
});

it('keeps the version stable across instances of the same namespace', function (): void {
    $writer = versionedCache();
    $writer->put('id:1', 'one');

    expect(versionedCache()->get('id:1'))->toBe('one')
        ->and(versionedCache()->version())->toBe($writer->version());
});

it('re-seeds a lost version above every version it has already used', function (): void {
    $cache = versionedCache();

    $cache->put('id:1', 'stale');
    $bumped = $cache->bump();

    // The version key itself evicted while data entries survive — the one case in
    // which a counter restarting from 1 would resurrect stale entries.
    Cache::forget('plans:version');

    expect($cache->version())->toBeGreaterThan($bumped)
        ->and($cache->get('id:1'))->toBeNull();
});

it('refuses a namespace or TTL that would leak entries', function (): void {
    expect(fn (): VersionedCache => new VersionedCache('  ', 300))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn (): VersionedCache => new VersionedCache('plans', 0))
        ->toThrow(InvalidArgumentException::class);
});

it('reads its store and TTL from config when built with for()', function (): void {
    config(['wa.cache.ttl.default' => 60]);

    $cache = VersionedCache::for('tenant:01J:settings');

    $cache->put('timezone', 'UTC');

    expect($cache->get('timezone'))->toBe('UTC')
        ->and($cache->key('timezone'))->toStartWith('tenant:01J:settings:v');
});
