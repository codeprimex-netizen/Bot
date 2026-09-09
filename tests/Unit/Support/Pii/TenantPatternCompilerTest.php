<?php

declare(strict_types=1);

use App\Support\Pii\PiiScanner;
use App\Support\Pii\TenantPatternCompiler;

/*
|--------------------------------------------------------------------------
| Operator-supplied patterns (Req 32.2 / NFR3)
|--------------------------------------------------------------------------
| A tenant pattern is a regex written outside the codebase and then run against
| every message that tenant handles, which makes it three things at once: a
| feature, an availability risk, and a way to null out all content. This file pins
| the three answers:
|
|   1. a pattern that cannot be trusted is **skipped with a reason**, never
|      applied and never fatal — a settings typo must not stop redaction;
|   2. a pattern that would swallow an ordinary sentence is refused up front;
|   3. a pattern that backtracks catastrophically is **bounded at match time**, so
|      the worst case is "this pattern found nothing here", not a wedged worker.
*/

it('accepts a well-formed pattern and applies it', function (): void {
    $compilation = (new TenantPatternCompiler)->compile(['\bPOL-\d{6}\b']);

    expect($compilation->rejections)->toBe([])
        ->and($compilation->patterns)->toHaveCount(1);

    $spans = (new PiiScanner)->scan('claim POL-778812 filed', $compilation->patterns);

    expect($spans)->toHaveCount(1)
        ->and($spans[0]->text)->toBe('POL-778812');
});

it('chooses a delimiter the pattern does not contain', function (): void {
    // A body containing `/` must not have to escape the delimiter the platform
    // happens to prefer.
    $compilation = (new TenantPatternCompiler)->compile(['REF/[A-Z]{2}/\d{4}']);

    expect($compilation->rejections)->toBe([]);

    $spans = (new PiiScanner)->scan('see REF/AB/1234 attached', $compilation->patterns);

    expect($spans)->toHaveCount(1)
        ->and($spans[0]->text)->toBe('REF/AB/1234');
});

it('refuses a pattern it cannot safely run, with a reason', function (string $source, string $reason): void {
    expect((new TenantPatternCompiler)->validate($source))->toBe($reason);
})->with([
    'empty' => ['', 'empty'],
    'blank' => ['    ', 'empty'],
    'uncompilable' => ['(unclosed', 'invalid'],
    'matches the empty string' => ['.*', 'matches_everything'],
    'optional everything' => ['\d*', 'matches_everything'],
    'matches every character' => ['.', 'over_broad'],
    'nested unbounded quantifier' => ['(a+)+b', 'nested_quantifier'],
    'recursion' => ['(a)(?1)+', 'unsupported_construct'],
    'over length' => [str_repeat('a', 201), 'too_long'],
]);

it('keeps the good patterns when one of them is bad', function (): void {
    $compilation = (new TenantPatternCompiler)->compile(['.*', '\bPOL-\d{6}\b', '(unclosed']);

    expect($compilation->patterns)->toHaveCount(1)
        ->and($compilation->patterns[0]->source)->toBe('\bPOL-\d{6}\b')
        ->and($compilation->rejections)->toBe([
            '.*' => 'matches_everything',
            '(unclosed' => 'invalid',
        ]);
});

it('caps how many patterns one tenant can install', function (): void {
    $compiler = new TenantPatternCompiler(maxPatterns: 2);

    $compilation = $compiler->compile(['A\d{4}', 'B\d{4}', 'C\d{4}', 'D\d{4}']);

    expect($compilation->patterns)->toHaveCount(2)
        ->and($compilation->rejections)->toBe(['C\d{4}' => 'too_many_patterns', 'D\d{4}' => 'too_many_patterns']);
});

it('bounds a pattern that backtracks catastrophically instead of hanging on it', function (): void {
    // `(a|a)+$` compiles, does not match the empty string, and matches nothing in the
    // validator's canary — so it passes every static check, which is exactly why the
    // runtime bound has to exist. Against a subject that cannot match, the number of
    // ways to split the input grows exponentially.
    $compilation = (new TenantPatternCompiler)->compile(['(a|a)+$']);

    expect($compilation->patterns)->toHaveCount(1);

    $subject = str_repeat('a', 40).'!';

    $started = microtime(true);
    $spans = (new PiiScanner)->scan($subject, $compilation->patterns);
    $elapsed = microtime(true) - $started;

    // PCRE exhausts its (lowered) backtrack budget and gives up: the pattern
    // contributes nothing and the scan returns promptly.
    expect($spans)->toBe([])
        ->and($elapsed)->toBeLessThan(1.0);
});

it('reports the same reason for a pattern whether it is validated or compiled', function (): void {
    $compiler = new TenantPatternCompiler;

    $compilation = $compiler->compile(['(a+)+b']);

    expect($compilation->patterns)->toBe([])
        ->and($compilation->rejections)->toBe(['(a+)+b' => $compiler->validate('(a+)+b')]);
});
