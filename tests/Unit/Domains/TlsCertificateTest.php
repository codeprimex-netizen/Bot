<?php

declare(strict_types=1);

use App\Services\Domains\TlsCertificate;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| Certificate name matching and validity (Req 9.7 / A9)
|--------------------------------------------------------------------------
| The rule is RFC 6125's, and its narrowness is the point: accepting a name the
| tenant's TLS terminator will not actually present means verifying a domain that
| then fails for real users.
*/

/**
 * @param  list<string>  $names
 */
function certificate(array $names, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): TlsCertificate
{
    return new TlsCertificate(
        names: $names,
        validFrom: $from ?? CarbonImmutable::now()->subDay(),
        validTo: $to ?? CarbonImmutable::now()->addDays(30),
    );
}

it('matches names exactly, case- and root-dot-insensitively', function (): void {
    $certificate = certificate(['chat.acme.example']);

    expect($certificate->coversHost('chat.acme.example'))->toBeTrue()
        ->and($certificate->coversHost('CHAT.Acme.Example'))->toBeTrue()
        ->and($certificate->coversHost('chat.acme.example.'))->toBeTrue()
        ->and($certificate->coversHost('other.acme.example'))->toBeFalse()
        ->and($certificate->coversHost(''))->toBeFalse();
});

it('matches a wildcard over exactly one label', function (string $host, bool $covered): void {
    expect(certificate(['*.acme.example'])->coversHost($host))->toBe($covered);
})->with([
    'one label below' => ['chat.acme.example', true],
    'another label below' => ['api.acme.example', true],
    'two labels below' => ['deep.chat.acme.example', false],
    'the bare parent' => ['acme.acme.example', true],
    'the wildcard\'s own parent' => ['acme.example', false],
    'a different zone' => ['chat.other.example', false],
]);

it('reads the host from any of the names it carries', function (): void {
    $certificate = certificate(['acme.example', 'www.acme.example', 'chat.acme.example']);

    expect($certificate->coversHost('chat.acme.example'))->toBeTrue();
});

it('is valid only inside its window, edges included', function (): void {
    $from = new CarbonImmutable('2025-01-01 00:00:00');
    $to = new CarbonImmutable('2025-04-01 00:00:00');
    $certificate = certificate(['chat.acme.example'], $from, $to);

    expect($certificate->isValidAt($from))->toBeTrue()
        ->and($certificate->isValidAt($to))->toBeTrue()
        ->and($certificate->isValidAt($from->subSecond()))->toBeFalse()
        ->and($certificate->isValidAt($to->addSecond()))->toBeFalse();
});

it('warns ahead of expiry', function (): void {
    $now = new CarbonImmutable('2025-01-01 00:00:00');
    $certificate = certificate(['chat.acme.example'], $now->subMonth(), $now->addDays(10));

    expect($certificate->expiresWithinDays(14, $now))->toBeTrue()
        ->and($certificate->expiresWithinDays(5, $now))->toBeFalse();
});
