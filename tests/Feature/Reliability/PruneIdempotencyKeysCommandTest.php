<?php

declare(strict_types=1);

use App\Console\Commands\PruneIdempotencyKeys;
use App\Enums\IdempotencyState;
use App\Models\IdempotencyKey;

/*
|--------------------------------------------------------------------------
| wa:idempotency:prune — retention pass over the dedup ledger (Req 31.2 / NFR2)
|--------------------------------------------------------------------------
| The command's whole risk profile is deleting one row too many: a completed key that
| goes early makes the next retry of that work look like a first attempt, and the side
| effect happens twice. So the assertions below are mostly about what *survives*.
*/

it('deletes expired keys and leaves everything else alone', function (): void {
    IdempotencyKey::factory()->completed()->expired()->create(['key' => 'k-expired']);
    IdempotencyKey::factory()->completed()->create(['key' => 'k-live', 'expires_at' => now()->addDay()]);
    IdempotencyKey::factory()->completed()->create(['key' => 'k-forever', 'expires_at' => null]);
    IdempotencyKey::factory()->create(['key' => 'k-in-flight', 'expires_at' => null]);

    thisTest()->artisan(PruneIdempotencyKeys::class)
        ->expectsOutputToContain('deleted')
        ->assertExitCode(0);

    expect(IdempotencyKey::query()->pluck('key')->sort()->values()->all())
        ->toBe(['k-forever', 'k-in-flight', 'k-live']);
});

it('says nothing and deletes nothing when the ledger is clean', function (): void {
    IdempotencyKey::factory()->completed()->create(['key' => 'k-live', 'expires_at' => now()->addDay()]);

    thisTest()->artisan(PruneIdempotencyKeys::class)->assertExitCode(0);

    expect(IdempotencyKey::query()->count())->toBe(1);
});

it('counts without deleting on a dry run', function (): void {
    IdempotencyKey::factory()->completed()->expired()->count(3)->create();

    thisTest()->artisan(PruneIdempotencyKeys::class, ['--dry-run' => true])
        ->expectsOutputToContain('prunable')
        ->assertExitCode(0);

    expect(IdempotencyKey::query()->count())->toBe(3);
});

it('reports abandoned leases without repairing them', function (): void {
    // Reclaiming is `IdempotencyStore::once()`'s job, and that transition must stay
    // conditional on a single writer — so the command only tells the operator.
    $stale = IdempotencyKey::factory()->stale()->create(['key' => 'k-stale']);

    thisTest()->artisan(PruneIdempotencyKeys::class)
        ->expectsOutputToContain('presumed abandoned')
        ->assertExitCode(0);

    expect($stale->refresh()->state)->toBe(IdempotencyState::InFlight)
        ->and($stale->locked_at)->not->toBeNull();
});

it('refuses a nonsense batch size', function (): void {
    thisTest()->artisan(PruneIdempotencyKeys::class, ['--batch' => '0'])->assertExitCode(2);
    thisTest()->artisan(PruneIdempotencyKeys::class, ['--batch' => 'nope'])->assertExitCode(2);
});

it('is registered on the schedule', function (): void {
    $events = collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())
        ->filter(fn (Illuminate\Console\Scheduling\Event $event): bool => str_contains((string) $event->command, 'wa:idempotency:prune'));

    expect($events)->toHaveCount(1)
        ->and($events->first()?->expression)->toBe('0 * * * *');
});
