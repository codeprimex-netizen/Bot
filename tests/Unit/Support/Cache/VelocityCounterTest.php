<?php

declare(strict_types=1);

use App\Support\Cache\VelocityCounter;
use App\Support\Cache\VersionedCache;

function counter(int $ttl = 172_800): VelocityCounter
{
    return new VelocityCounter(new VersionedCache('test:velocity:'.bin2hex(random_bytes(4)), $ttl));
}

it('counts hits within a window', function (): void {
    $counter = counter();

    expect($counter->hit('k', 3600)->hits)->toBe(1)
        ->and($counter->hit('k', 3600)->hits)->toBe(2)
        ->and($counter->peek('k', 3600)->hits)->toBe(2);
});

it('does not count when only peeking', function (): void {
    $counter = counter();

    expect($counter->peek('k', 3600)->hits)->toBe(0)
        ->and($counter->peek('k', 3600)->hits)->toBe(0);
});

it('keeps different keys and different windows apart', function (): void {
    $counter = counter();

    $counter->hit('a', 3600);
    $counter->hit('a', 3600);
    $counter->hit('b', 3600);

    expect($counter->peek('a', 3600)->hits)->toBe(2)
        ->and($counter->peek('b', 3600)->hits)->toBe(1)
        ->and($counter->peek('a', 900)->hits)->toBe(0);
});

it('starts a new count when the window rolls', function (): void {
    $counter = counter();

    $counter->hit('k', 60);
    $counter->hit('k', 60);

    thisTest()->travel(61)->seconds();

    expect($counter->peek('k', 60)->hits)->toBe(0);
});

it('reports a positive, bounded time until the window rolls', function (): void {
    $reading = counter()->hit('k', 900);

    expect($reading->resetsInSeconds)->toBeGreaterThan(0)
        ->and($reading->resetsInSeconds)->toBeLessThanOrEqual(900)
        ->and($reading->windowSeconds)->toBe(900);
});

it('permits exactly the configured number of hits', function (): void {
    $counter = counter();

    // A limit of 3 permits three hits and refuses the fourth.
    expect($counter->hit('k', 3600)->exceeds(3))->toBeFalse()
        ->and($counter->hit('k', 3600)->exceeds(3))->toBeFalse()
        ->and($counter->hit('k', 3600)->exceeds(3))->toBeFalse()
        ->and($counter->hit('k', 3600)->exceeds(3))->toBeTrue();
});

it('treats a limit of zero as switched off rather than as refusing everything', function (): void {
    expect(counter()->hit('k', 3600)->exceeds(0))->toBeFalse();
});

it('can be cleared, so one honest success does not leave a lockout behind', function (): void {
    $counter = counter();

    $counter->hit('k', 900);
    $counter->hit('k', 900);
    $counter->clear('k', 900);

    expect($counter->peek('k', 900)->hits)->toBe(0);
});

it('records what the decision was made from', function (): void {
    $reading = counter()->hit('k', 3600);

    expect($reading->toArray(5))->toMatchArray([
        'hits' => 1,
        'limit' => 5,
        'window_seconds' => 3600,
    ]);

    expect($reading->toArray(5)['resets_in_seconds'])->toBeGreaterThan(0);
});

it('does not lose a hit when two callers count at once', function (): void {
    // Two VelocityCounter instances over one cache store — the shape of two workers.
    $namespace = 'test:velocity:shared:'.bin2hex(random_bytes(4));

    $first = new VelocityCounter(new VersionedCache($namespace, 3600));
    $second = new VelocityCounter(new VersionedCache($namespace, 3600));

    $first->hit('k', 3600);
    $second->hit('k', 3600);

    // A lost hit is a bypass, which is why the increment is atomic rather than a
    // read-modify-write.
    expect($first->peek('k', 3600)->hits)->toBe(2);
});
