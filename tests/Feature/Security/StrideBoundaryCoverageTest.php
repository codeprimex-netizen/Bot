<?php

declare(strict_types=1);

use App\Http\Middleware\EnsurePermission;
use App\Services\Chatbot\Rag\VectorStore;
use App\Services\Rbac\DatabaseRbacService;
use App\Services\Rbac\RbacService;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| The STRIDE table is an executable claim (Req 32.1, 32.6 / NFR3, task 4.6)
|--------------------------------------------------------------------------
| design.md names eight trust boundaries and a mitigation for each. Prose cannot
| stop an attacker, so `docs/security/stride.md` restates the same table with two
| extra columns — *where it is enforced* and *what proves it* — and this file is what
| makes those columns true:
|
|   1. every boundary in design.md appears in the register (a boundary added to the
|      design fails the build until somebody accounts for it);
|   2. every clause marked `enforced` names code that exists and a test file that
|      exists;
|   3. every clause marked `unenforced` names the owning task and claims no test, so
|      an unfinished boundary can never read as coverage;
|   4. the enforcement points this task built are actually wired — a middleware
|      nobody registered and a service nobody bound are documentation.
|
| This is the anti-rot test. It does not re-prove the mitigations (each clause names
| the test that does); it proves the register still describes reality.
*/

const STRIDE_REGISTER = 'docs/security/stride.md';
const STRIDE_DESIGN = '.kiro/specs/whatsapp-chatbot-platform/design.md';

/**
 * Cells of every row of the first markdown table that follows `$heading`.
 *
 * @return list<list<string>>
 */
function strideTable(string $path, string $heading, int $columns): array
{
    $contents = (string) file_get_contents(base_path($path));
    $after = Str::after($contents, $heading);

    expect($after)->not->toBe($contents, sprintf('[%s] no longer contains the heading [%s].', $path, $heading));

    $rows = [];
    $started = false;

    foreach (explode("\n", $after) as $line) {
        $line = trim($line);

        if (! str_starts_with($line, '|')) {
            if ($started) {
                break;
            }

            continue;
        }

        $cells = array_map('trim', explode('|', trim($line, '|')));

        if (count($cells) !== $columns) {
            continue;
        }

        // The header row and the `|---|` separator.
        if (! $started) {
            $started = true;

            continue;
        }

        if (str_starts_with($cells[0], '---')) {
            continue;
        }

        $rows[] = array_values($cells);
    }

    expect($rows)->not->toBeEmpty(sprintf('Parsed no rows under [%s] in [%s].', $heading, $path));

    return $rows;
}

/**
 * The boundaries design.md declares.
 *
 * @return list<string>
 */
function strideDesignBoundaries(): array
{
    $boundaries = array_map(
        static fn (array $row): string => $row[0],
        strideTable(STRIDE_DESIGN, '### STRIDE threat model (main trust boundaries)', 3),
    );

    return array_values(array_unique($boundaries));
}

/**
 * The register's clause rows: `[boundary, clause, enforced at, proven by, status]`.
 *
 * @return list<list<string>>
 */
function strideClauses(): array
{
    return strideTable(STRIDE_REGISTER, '## Clause coverage', 5);
}

/**
 * Backticked tokens in a cell — class names and test paths.
 *
 * @return list<string>
 */
function strideTokens(string $cell): array
{
    preg_match_all('/`([^`]+)`/', $cell, $matches);

    /** @var list<non-empty-string> $tokens */
    $tokens = $matches[1];

    return $tokens;
}

/*
|--------------------------------------------------------------------------
| 1. Coverage of the design's boundaries
|--------------------------------------------------------------------------
*/

it('finds the design table it is meant to guard', function (): void {
    // A parsing bug would make every other test here vacuously pass, so the boundaries
    // that exist today are named explicitly.
    expect(strideDesignBoundaries())->toBe([
        'Bridge → webhook',
        'Gateway → webhook',
        'Panels (User/Admin)',
        'Public API',
        'LLM egress',
        'Vector store',
        'Audit log',
        'Queue/workers',
    ]);
});

it('accounts for every boundary the design declares, and invents none', function (): void {
    $declared = strideDesignBoundaries();
    $registered = array_values(array_unique(array_map(
        static fn (array $row): string => $row[0],
        strideClauses(),
    )));

    sort($declared);
    sort($registered);

    expect($registered)->toBe($declared, sprintf(
        'The STRIDE register and design.md disagree about the trust boundaries. Missing from %s: %s. '
        .'Not in design.md: %s.',
        STRIDE_REGISTER,
        implode(', ', array_diff($declared, $registered)) ?: '—',
        implode(', ', array_diff($registered, $declared)) ?: '—',
    ));
});

/*
|--------------------------------------------------------------------------
| 2 & 3. Every claim is backed; every gap is labelled
|--------------------------------------------------------------------------
*/

it('names code that exists for every clause it calls enforced', function (): void {
    $missing = [];

    foreach (strideClauses() as [$boundary, $clause, $enforcedAt, , $status]) {
        if ($status !== 'enforced') {
            continue;
        }

        $symbols = array_values(array_filter(
            strideTokens($enforcedAt),
            static fn (string $token): bool => str_starts_with($token, 'App\\'),
        ));

        expect($symbols)->not->toBeEmpty(sprintf(
            '[%s] claims an enforced clause with no App\\ symbol: %s',
            $boundary,
            Str::limit($clause, 60),
        ));

        foreach ($symbols as $symbol) {
            if (! class_exists($symbol) && ! interface_exists($symbol) && ! trait_exists($symbol) && ! enum_exists($symbol)) {
                $missing[] = $symbol;
            }
        }
    }

    expect($missing)->toBe([], 'The STRIDE register points at code that does not exist: '.implode(', ', $missing));
});

it('names a test that exists for every clause it calls enforced', function (): void {
    $missing = [];

    foreach (strideClauses() as [$boundary, $clause, , $provenBy, $status]) {
        if ($status !== 'enforced') {
            continue;
        }

        $tests = array_values(array_filter(
            strideTokens($provenBy),
            static fn (string $token): bool => str_starts_with($token, 'tests/'),
        ));

        // A clause with no test is a claim, not a mitigation.
        expect($tests)->not->toBeEmpty(sprintf(
            '[%s] claims an enforced clause with no proving test: %s',
            $boundary,
            Str::limit($clause, 60),
        ));

        foreach ($tests as $test) {
            if (! is_file(base_path($test)) || (int) filesize(base_path($test)) === 0) {
                $missing[] = $test;
            }
        }
    }

    expect($missing)->toBe([], 'The STRIDE register points at tests that do not exist: '.implode(', ', $missing));
});

it('labels every unenforced clause with its owning task and claims no coverage for it', function (): void {
    $unenforced = 0;

    foreach (strideClauses() as [$boundary, $clause, , $provenBy, $status]) {
        if (! str_starts_with($status, 'unenforced')) {
            expect(in_array($status, ['enforced', 'infrastructure'], true))->toBeTrue(sprintf(
                '[%s] uses an unknown status [%s]. See the register\'s "How to read the Status column".',
                $boundary,
                $status,
            ));

            continue;
        }

        $unenforced++;

        // Either a task owns it, or the register says outright that nothing does — which
        // is itself a finding, not a shrug.
        expect($status)->toMatch('/^unenforced — (task \d+\.\d+|no owning task)$/', sprintf(
            '[%s] is unenforced but names no owner: %s',
            $boundary,
            Str::limit($clause, 60),
        ));

        // The whole point of the split: an unfinished boundary must not read as covered.
        expect(strideTokens($provenBy))->toBe([], sprintf(
            '[%s] is unenforced but claims a proving test: %s',
            $boundary,
            Str::limit($clause, 60),
        ));
    }

    // If this ever reaches zero, every boundary is wired and the register's Gaps section
    // should say so rather than being quietly stale.
    expect($unenforced)->toBeGreaterThan(0);
});

it('records every unowned gap in the Gaps section', function (): void {
    $contents = (string) file_get_contents(base_path(STRIDE_REGISTER));
    $gaps = Str::after($contents, '## Gaps');

    foreach (strideClauses() as [$boundary, , , , $status]) {
        if ($status !== 'unenforced — no owning task') {
            continue;
        }

        expect(str_contains($gaps, $boundary))->toBeTrue(sprintf(
            'Boundary [%s] has a clause nobody owns; the Gaps section must explain it.',
            $boundary,
        ));
    }
});

/*
|--------------------------------------------------------------------------
| 4. The enforcement points this task built are wired, not merely present
|--------------------------------------------------------------------------
*/

it('registers the permission middleware as a route alias, so a route can declare it', function (): void {
    $kernel = app(HttpKernelContract::class);

    expect($kernel)->toBeInstanceOf(HttpKernel::class);

    /** @var HttpKernel $kernel */
    $aliases = $kernel->getMiddlewareAliases();

    expect($aliases)->toHaveKey('tenant.permission')
        ->and($aliases['tenant.permission'])->toBe(EnsurePermission::class);
});

it('binds the RBAC service per unit of work, so a revoked role cannot outlive a request', function (): void {
    expect(app(RbacService::class))->toBeInstanceOf(DatabaseRbacService::class)
        // Same instance within the request (the membership cache is worth having)...
        ->and(app(RbacService::class))->toBe(app(RbacService::class));

    $before = app(RbacService::class);
    app()->forgetScopedInstances();

    // ...and gone at the request/job boundary.
    expect(app(RbacService::class))->not->toBe($before);
});

it('publishes the api credential on the request through the tenant middleware only', function (): void {
    // `EnsurePermission` reads the credential from a request attribute, which only
    // `ResolveTenant` writes. If that ever stops being true, an API request could be
    // authorized against a credential nobody verified this request.
    $writers = [];

    foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
        $contents = (string) file_get_contents($file->getRealPath());

        if (str_contains($contents, 'attributes->set(ApiTokenIdentity::REQUEST_ATTRIBUTE')) {
            $writers[] = $file->getRelativePathname();
        }
    }

    expect($writers)->toBe(['Http/Middleware/ResolveTenant.php']);
});

it('keeps the vector-store register row honest about whether a driver exists', function (): void {
    $implementations = [];

    foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
        $contents = (string) file_get_contents($file->getRealPath());

        if (preg_match('/implements\s+[^\n{]*\bVectorStore\b/', $contents) === 1) {
            $implementations[] = $file->getRelativePathname();
        }
    }

    $register = (string) file_get_contents(base_path(STRIDE_REGISTER));
    $claimsNoDriver = str_contains($register, 'unenforced — task 13.2');

    // Two states are allowed: no driver and the row saying so, or a driver and the row
    // updated. What is not allowed is a driver landing while the register still says the
    // boundary is unwired — that is the exact drift this whole file exists to catch.
    expect($implementations === [])->toBe($claimsNoDriver, sprintf(
        'VectorStore implementations found (%s) but %s still marks the boundary unenforced — '
        .'move that clause to `enforced` and name the test that proves the driver filters by tenant.',
        implode(', ', $implementations) ?: 'none',
        STRIDE_REGISTER,
    ));

    expect(interface_exists(VectorStore::class))->toBeTrue();
});
