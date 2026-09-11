<?php

declare(strict_types=1);

use App\Exceptions\Tenancy\CrossTenantAccessException;
use App\Models\TenantUsage;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/*
|--------------------------------------------------------------------------
| The typed denial itself (Req 1.3 / A1)
|--------------------------------------------------------------------------
| Two guarantees: it *is* a 403 wherever it surfaces, and it never carries another
| tenant's identifiers — not in the message that reaches the log, and not in the
| sentence that reaches a client.
*/

it('is a 403 on every surface', function (): void {
    $exception = CrossTenantAccessException::forRetrieval(TenantUsage::class, '01hzzz', '01haaa');

    expect($exception)->toBeInstanceOf(HttpExceptionInterface::class)
        ->and($exception->getStatusCode())->toBe(403)
        ->and($exception->getStatusCode())->toBe(CrossTenantAccessException::STATUS)
        ->and($exception->getHeaders())->toBe([])
        ->and($exception->publicMessage())->toBe(CrossTenantAccessException::PUBLIC_MESSAGE);
});

it('fingerprints identifiers instead of echoing them', function (CrossTenantAccessException $exception): void {
    $message = $exception->getMessage();

    expect($message)->not->toContain('01HZZZTENANTGLOBEXXXXXXXXX')
        ->and($message)->not->toContain('01HAAATENANTACMEXXXXXXXXXX')
        // ...but is still correlatable: the same id always fingerprints the same way.
        ->and($message)->toContain('#'.substr(hash('sha256', '01HZZZTENANTGLOBEXXXXXXXXX'), 0, 8))
        ->and($message)->toContain('#'.substr(hash('sha256', '01HAAATENANTACMEXXXXXXXXXX'), 0, 8));
})->with([
    'retrieval' => fn () => CrossTenantAccessException::forRetrieval(
        TenantUsage::class, '01HZZZTENANTGLOBEXXXXXXXXX', '01HAAATENANTACMEXXXXXXXXXX'
    ),
    'write' => fn () => CrossTenantAccessException::forWrite(
        TenantUsage::class, 'delete', '01HZZZTENANTGLOBEXXXXXXXXX', '01HAAATENANTACMEXXXXXXXXXX'
    ),
    'attribution' => fn () => CrossTenantAccessException::forAttribution(
        TenantUsage::class, '01HZZZTENANTGLOBEXXXXXXXXX', '01HAAATENANTACMEXXXXXXXXXX'
    ),
    'relation' => fn () => CrossTenantAccessException::forRelation(
        TenantUsage::class, 'usage', '01HZZZTENANTGLOBEXXXXXXXXX', '01HAAATENANTACMEXXXXXXXXXX'
    ),
]);

it('fingerprints the record key a find-by-id was denied for', function (): void {
    $exception = CrossTenantAccessException::forKey(
        TenantUsage::class,
        'id',
        '01HRECORDIDXXXXXXXXXXXXXXX',
        '01HAAATENANTACMEXXXXXXXXXX',
    );

    $message = $exception->getMessage();

    expect($message)->not->toContain('01HRECORDIDXXXXXXXXXXXXXXX')
        ->and($message)->toContain('#'.substr(hash('sha256', '01HRECORDIDXXXXXXXXXXXXXXX'), 0, 8))
        ->and($message)->toContain(' id = ');
});

it('escapes and truncates untrusted text before it reaches a message', function (): void {
    $exception = CrossTenantAccessException::forKey(
        TenantUsage::class,
        "slug\n\0".str_repeat('x', 200),
        '01HRECORDIDXXXXXXXXXXXXXXX',
        '01HAAATENANTACMEXXXXXXXXXX',
    );

    $message = $exception->getMessage();

    expect($message)->not->toContain("\n")
        ->and($message)->not->toContain("\0")
        ->and($message)->toContain('…')
        ->and($message)->toContain('slug');
});

it('reports no tenant when the owner is unknown', function (): void {
    $exception = CrossTenantAccessException::forRetrieval(TenantUsage::class, null, '01HAAATENANTACMEXXXXXXXXXX');

    expect($exception->getMessage())->toContain('<none>');
});
