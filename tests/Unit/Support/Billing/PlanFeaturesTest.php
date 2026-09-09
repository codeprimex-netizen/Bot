<?php

declare(strict_types=1);

use App\Exceptions\Billing\MalformedPlanException;
use App\Support\Billing\PlanFeatures;

/*
|--------------------------------------------------------------------------
| plans.features — shape validation (Req 25.1 / D2)
|--------------------------------------------------------------------------
| These flags are the only thing between a tenant and a feature it has not paid
| for, so the interesting cases are the *malformed* ones: every one of them must
| raise rather than resolve to "allowed".
*/

it('reads a well-formed feature map', function (): void {
    $features = PlanFeatures::fromRaw(['ai' => true, 'campaigns' => false]);

    expect($features->allows('ai'))->toBeTrue()
        ->and($features->allows('campaigns'))->toBeFalse()
        ->and($features->all())->toBe(['ai' => true, 'campaigns' => false])
        ->and($features->enabled())->toBe(['ai'])
        ->and($features->isEmpty())->toBeFalse();
});

it('treats an absent flag as not granted', function (): void {
    expect(PlanFeatures::fromRaw([])->allows('ai'))->toBeFalse()
        ->and(PlanFeatures::none()->allows('ai'))->toBeFalse()
        ->and(PlanFeatures::none()->isEmpty())->toBeTrue();
});

it('rejects a null or non-object features column', function (mixed $raw): void {
    expect(fn (): PlanFeatures => PlanFeatures::fromRaw($raw, 'plan "growth"'))
        ->toThrow(MalformedPlanException::class);
})->with([
    'null' => [null],
    'string' => ['{"ai":true}'],
    'int' => [1],
    'bool' => [true],
]);

it('rejects a JSON array of feature names rather than reading it as flags', function (): void {
    // ["ai", "campaigns"] would arrive as [0 => 'ai', 1 => 'campaigns'] — gating on
    // the features "0" and "1", i.e. gating on nothing.
    expect(fn (): PlanFeatures => PlanFeatures::fromRaw(['ai', 'campaigns']))
        ->toThrow(MalformedPlanException::class, 'is not a string');
});

it('rejects a truthy value that is not a boolean', function (mixed $value): void {
    expect(fn (): PlanFeatures => PlanFeatures::fromRaw(['ai' => $value]))
        ->toThrow(MalformedPlanException::class, 'only true or false are accepted');
})->with([
    'int 1' => [1],
    'string "true"' => ['true'],
    'string "yes"' => ['yes'],
    'null' => [null],
    'nested array' => [['enabled' => true]],
]);

it('rejects a key that could never match a gate', function (mixed $key): void {
    expect(fn (): PlanFeatures => PlanFeatures::fromRaw([$key => true]))
        ->toThrow(MalformedPlanException::class);
})->with([
    'empty' => [''],
    'leading space' => [' ai'],
    'trailing space' => ['ai '],
]);

it('names the offending plan in the error so an admin can find it', function (): void {
    expect(fn (): PlanFeatures => PlanFeatures::fromRaw(['ai' => 1], 'plan "growth"'))
        ->toThrow(MalformedPlanException::class, 'plan "growth"');
});

it('refuses to answer a question about an empty feature key', function (): void {
    expect(fn (): bool => PlanFeatures::fromRaw(['ai' => true])->allows('  '))
        ->toThrow(InvalidArgumentException::class);
});
