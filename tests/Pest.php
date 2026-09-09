<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Pest\TestSuite;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests boot the application and run against a migrated database.
| Unit tests stay framework-light and only bind the base test case where a
| test file opts in explicitly.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * The running test case, typed so Laravel's HTTP and auth helpers are visible
 * to static analysis inside Pest closures (where `$this` is only known as the
 * PHPUnit base class).
 */
function thisTest(): TestCase
{
    /** @var TestCase $case */
    $case = TestSuite::getInstance()->test;

    return $case;
}
