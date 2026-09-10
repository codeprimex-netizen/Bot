<?php

declare(strict_types=1);

use App\Console\Commands\RelayOutbox;
use App\Enums\OutboxStatus;
use App\Models\OutboxMessage;
use App\Services\Reliability\DatabaseOutbox;
use App\Services\Reliability\HttpOutboxTransport;
use App\Services\Reliability\Outbox;
use App\Services\Reliability\OutboxTransport;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Tests\Fixtures\Reliability\Outboxes;
use Tests\Fixtures\Reliability\RecordingOutboxTransport;

/*
|--------------------------------------------------------------------------
| The relay worker and its wiring (Req 31.4 / NFR2)
|--------------------------------------------------------------------------
| `wa:outbox:relay` is what the single cron entry runs every minute. What is asserted
| here is the operational surface: the pass happens, a backlog can be drained on
| purpose, parked rows are surfaced loudly rather than left for somebody to find, and a
| transport that cannot deliver is a boot failure instead of a silent no-op.
*/

afterEach(function (): void {
    Carbon::setTestNow();
});

it('delivers due rows and reports what happened', function (): void {
    $transport = RecordingOutboxTransport::acking();
    Outboxes::relayingWith($transport);
    OutboxMessage::factory()->count(2)->create();

    thisTest()->artisan(RelayOutbox::class)
        ->expectsOutputToContain('delivered')
        ->assertSuccessful();

    expect($transport->deliveryCount())->toBe(2)
        ->and(OutboxMessage::query()->claimable()->count())->toBe(0);
});

it('says nothing when there is nothing due', function (): void {
    Outboxes::acking();

    thisTest()->artisan(RelayOutbox::class)
        ->doesntExpectOutputToContain('delivered')
        ->assertSuccessful();
});

it('counts what is due without delivering it', function (): void {
    $transport = RecordingOutboxTransport::acking();
    Outboxes::relayingWith($transport);
    OutboxMessage::factory()->count(3)->create();

    thisTest()->artisan(RelayOutbox::class, ['--dry-run' => true])
        ->expectsOutputToContain('due')
        ->assertSuccessful();

    expect($transport->deliveryCount())->toBe(0)
        ->and(OutboxMessage::query()->claimable()->count())->toBe(3);
});

it('drains a backlog over several passes and stops as soon as one is empty', function (): void {
    $transport = RecordingOutboxTransport::acking();
    Outboxes::relayingWith($transport);
    OutboxMessage::factory()->count(5)->create();

    thisTest()->artisan(RelayOutbox::class, ['--batch' => '2', '--passes' => '9'])
        ->assertSuccessful();

    expect($transport->deliveryCount())->toBe(5);
});

it('warns loudly about parked rows and hands one back when asked', function (): void {
    config()->set('wa.reliability.outbox.max_attempts', 1);

    Outboxes::relayingWith(RecordingOutboxTransport::failing(new ConnectionException('down')));
    $row = OutboxMessage::factory()->create();

    thisTest()->artisan(RelayOutbox::class)
        ->expectsOutputToContain('parked')
        ->assertSuccessful();

    expect($row->refresh()->status)->toBe(OutboxStatus::Failed);

    config()->set('wa.reliability.outbox.max_attempts', 12);
    $transport = RecordingOutboxTransport::acking();
    Outboxes::relayingWith($transport);

    thisTest()->artisan(RelayOutbox::class, ['--requeue' => [(string) $row->id]])
        ->assertSuccessful();

    expect($row->refresh()->status)->toBe(OutboxStatus::Sent)
        ->and($transport->deliveryCount())->toBe(1);
});

it('warns when a row was parked for having spent its budget on claims that reported nothing', function (): void {
    config()->set('wa.reliability.outbox.max_attempts', 1);

    Outboxes::acking();
    $row = OutboxMessage::factory()->create();

    // A worker killed between the claim and the write: the attempt is counted and the lease
    // taken, and nothing comes back. The budget is spent, so no pass will ever claim the row
    // again — and until the relay parks it, nothing says so.
    OutboxMessage::query()->whereKey($row->getKey())->increment('attempts', 1, ['next_attempt_at' => now()]);

    thisTest()->artisan(RelayOutbox::class)
        ->expectsOutputToContain('abandoned')
        ->assertSuccessful();

    expect($row->refresh()->status)->toBe(OutboxStatus::Failed)
        ->and($row->last_error)->toContain('Parked');
});

it('reports a row it could not requeue instead of pretending it did', function (): void {
    Outboxes::acking();
    $sent = OutboxMessage::factory()->sent()->create();

    thisTest()->artisan(RelayOutbox::class, ['--requeue' => [(string) $sent->id]])
        ->expectsOutputToContain('Could not requeue')
        ->assertSuccessful();
});

it('refuses nonsensical options rather than guessing', function (): void {
    Outboxes::acking();

    thisTest()->artisan(RelayOutbox::class, ['--batch' => '0'])->assertExitCode(2);
    thisTest()->artisan(RelayOutbox::class, ['--passes' => 'lots'])->assertExitCode(2);
    thisTest()->artisan(RelayOutbox::class, ['--requeue' => ['not-an-id']])->assertFailed();
});

it('is scheduled every minute, guarded against overlap and pinned to one server', function (): void {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'wa:outbox:relay'));

    expect($events)->toHaveCount(1);

    $event = $events->first();

    expect($event?->expression)->toBe('* * * * *')
        ->and($event?->withoutOverlapping)->toBeTrue()
        ->and($event?->onOneServer)->toBeTrue();
});

it('wires one outbox and one transport per process, and refuses a transport that cannot deliver', function (): void {
    expect(app(Outbox::class))->toBeInstanceOf(DatabaseOutbox::class)
        ->and(app(Outbox::class))->toBe(app(Outbox::class))
        ->and(app(OutboxTransport::class))->toBeInstanceOf(HttpOutboxTransport::class)
        ->and(app(OutboxTransport::class))->toBe(app(OutboxTransport::class));

    // A transport that silently did nothing would mark the whole queue SENT with nothing
    // sent — undetectable afterwards, so this fails closed rather than falling back.
    app()->forgetInstance(OutboxTransport::class);
    config()->set('wa.reliability.outbox.transport', OutboxStatus::class);

    expect(fn (): OutboxTransport => app(OutboxTransport::class))->toThrow(InvalidArgumentException::class);
});
