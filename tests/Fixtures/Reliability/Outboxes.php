<?php

declare(strict_types=1);

namespace Tests\Fixtures\Reliability;

use App\Models\OutboxMessage;
use App\Services\Reliability\Outbox;
use App\Services\Reliability\OutboxDelivery;
use App\Services\Reliability\OutboxTransport;

/**
 * Shared entry points for the transactional-outbox tests.
 *
 * A class rather than Pest helper functions, for the reason `Tests\Fixtures\Audit` gives:
 * Pest loads every test file into one process, so a global `outbox()` helper would be a name
 * the whole suite has to keep free for ever.
 */
final class Outboxes
{
    /**
     * Bind `$transport` and hand back the relay that will use it.
     *
     * The container instance is replaced rather than mocked, so the relay under test is the
     * real one the provider wires — only the one call that leaves the platform is a fake.
     */
    public static function relayingWith(RecordingOutboxTransport $transport): Outbox
    {
        app()->instance(OutboxTransport::class, $transport);
        app()->forgetInstance(Outbox::class);

        /** @var Outbox $outbox */
        $outbox = app(Outbox::class);

        return $outbox;
    }

    /**
     * A relay whose receiver acks everything — the happy path.
     */
    public static function acking(): Outbox
    {
        return self::relayingWith(RecordingOutboxTransport::acking());
    }

    /**
     * One delivery as a transport sees it, built from an unsaved row — for the transport
     * tests, which have no need of the `outbox` table.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function delivery(array $attributes = []): OutboxDelivery
    {
        return OutboxDelivery::for(OutboxMessage::factory()->make($attributes + ['attempts' => 1]));
    }
}
