<?php

declare(strict_types=1);

use App\Enums\ErrorClass;
use App\Exceptions\Reliability\OutboxDeliveryException;
use App\Services\Reliability\HttpOutboxTransport;
use App\Services\Reliability\OutboxDelivery;
use App\Services\Reliability\RetryPolicy;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Reliability\Outboxes;

/*
|--------------------------------------------------------------------------
| The default outbox transport (Req 31.4 / NFR2, Algorithm 6)
|--------------------------------------------------------------------------
| A real webhook POST, not a placeholder: the payload as the JSON body, the dedup key as
| a header, and a failure expressed as an exception the retry matrix can classify. The
| relay's whole reaction to a failure is derived from that classification, so the status
| each refusal reports is part of this transport's contract and is asserted here.
*/

it('posts the payload as json with the dedup key header', function (): void {
    Http::fake(['shop.test/*' => Http::response('', 202)]);

    app(HttpOutboxTransport::class)->deliver(Outboxes::delivery([
        'destination' => 'https://shop.test/hooks/orders',
        'dedup_key' => 'order.paid:ord-1',
        'event_type' => 'order.paid',
        'payload' => ['order_id' => 'ord-1'],
    ]));

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://shop.test/hooks/orders'
        && $request->method() === 'POST'
        && $request->hasHeader('X-Dedup-Key', 'order.paid:ord-1')
        && $request->hasHeader('X-Event-Type', 'order.paid')
        && $request->hasHeader(OutboxDelivery::ATTEMPT_HEADER, '1')
        && $request->data() === ['order_id' => 'ord-1']);
});

it('treats any 2xx as an ack', function (): void {
    Http::fake(['shop.test/*' => Http::response('', 204)]);

    app(HttpOutboxTransport::class)->deliver(Outboxes::delivery(['destination' => 'https://shop.test/hooks']));

    Http::assertSentCount(1);
});

it('reports a server error as retryable and a client error as terminal', function (): void {
    $policy = app(RetryPolicy::class);

    Http::fake([
        'broken.test/*' => Http::response('boom', 503),
        'picky.test/*' => Http::response('nope', 400),
    ]);

    $serverError = null;

    try {
        app(HttpOutboxTransport::class)->deliver(Outboxes::delivery(['destination' => 'https://broken.test/hooks']));
    } catch (OutboxDeliveryException $e) {
        $serverError = $e;
    }

    $clientError = null;

    try {
        app(HttpOutboxTransport::class)->deliver(Outboxes::delivery(['destination' => 'https://picky.test/hooks']));
    } catch (OutboxDeliveryException $e) {
        $clientError = $e;
    }

    expect($serverError?->getStatusCode())->toBe(503)
        ->and($policy->classify($serverError ?? new RuntimeException))->toBe(ErrorClass::Network)
        ->and($policy->decide($serverError ?? new RuntimeException, 1)->shouldRetry)->toBeTrue()
        ->and($clientError?->getStatusCode())->toBe(400)
        // The receiver rejected *this body*: it will reject it identically next time, so the
        // relay parks the row instead of spending eleven more attempts on it.
        ->and($policy->classify($clientError ?? new RuntimeException))->toBe(ErrorClass::Validation)
        ->and($policy->decide($clientError ?? new RuntimeException, 1)->mustFailExplicitly())->toBeTrue()
        // Never the response body: `outbox.last_error` is a diagnosis, not a copy of a
        // provider's error page.
        ->and($serverError?->getMessage())->not->toContain('boom');
});

it('carries a numeric Retry-After so a throttled receiver is deferred for exactly that long', function (): void {
    Http::fake(['shop.test/*' => Http::response('', 429, ['Retry-After' => '120'])]);

    $refusal = null;

    try {
        app(HttpOutboxTransport::class)->deliver(Outboxes::delivery(['destination' => 'https://shop.test/hooks']));
    } catch (OutboxDeliveryException $e) {
        $refusal = $e;
    }

    $decision = app(RetryPolicy::class)->decide($refusal ?? new RuntimeException, 1);

    expect($refusal?->getHeaders())->toBe(['Retry-After' => '120'])
        ->and($decision->class)->toBe(ErrorClass::RateLimit)
        ->and($decision->isDeferred())->toBeTrue()
        ->and($decision->delayMs)->toBe(120_000);
});

it('ignores an http-date Retry-After rather than guessing at a clock', function (): void {
    Http::fake(['shop.test/*' => Http::response('', 429, ['Retry-After' => 'Wed, 21 Oct 2026 07:28:00 GMT'])]);

    $refusal = null;

    try {
        app(HttpOutboxTransport::class)->deliver(Outboxes::delivery(['destination' => 'https://shop.test/hooks']));
    } catch (OutboxDeliveryException $e) {
        $refusal = $e;
    }

    expect($refusal?->getHeaders())->toBe([]);
});

it('refuses a row it cannot deliver at all, without making a request', function (): void {
    Http::fake();

    $reasons = [null, '', 'not-a-url', 'ftp://shop.test/hooks'];

    foreach ($reasons as $destination) {
        $thrown = null;

        try {
            app(HttpOutboxTransport::class)->deliver(Outboxes::delivery(['destination' => $destination]));
        } catch (OutboxDeliveryException $e) {
            $thrown = $e;
        }

        expect($thrown?->getStatusCode())->toBe(OutboxDeliveryException::UNDELIVERABLE_STATUS)
            ->and($thrown?->isTerminal())->toBeTrue();
    }

    Http::assertNothingSent();
});
