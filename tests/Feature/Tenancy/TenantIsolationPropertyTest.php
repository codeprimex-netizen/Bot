<?php

declare(strict_types=1);

use App\Exceptions\Audit\AppendOnlyViolationException;
use App\Exceptions\Tenancy\CrossTenantAccessException;
use App\Exceptions\Tenancy\MissingTenantContextException;
use App\Models\AbuseEvent;
use App\Models\AuditLog;
use App\Models\EncryptionKey;
use App\Models\IdempotencyKey;
use App\Models\OutboxMessage;
use App\Models\Plan;
use App\Models\QuotaHold;
use App\Models\Saga;
use App\Models\SigningSecret;
use App\Models\Tenant;
use App\Models\TenantApiToken;
use App\Models\TenantTierAssignment;
use App\Models\TenantUsage;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantOwnershipGuard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Fixtures\Tenancy\TenantIsolationProbe;
use Tests\Fixtures\Tenancy\TenantOwnedSubject;

/*
|--------------------------------------------------------------------------
| Correctness Property 1 — tenant isolation
|--------------------------------------------------------------------------
| design.md: *"∀ tenant t, ∀ query q issued in t's context → q returns only rows where
| `tenant_id = t.id`. No API/panel path returns another tenant's row."*
|
| **Validates: Requirements 1.1, 1.2, 1.3 / A1**
|
| This is the load-bearing property of the design: a failure here is a cross-tenant data
| breach, not a bug. `BelongsToTenantTest`, `CrossTenantAccessTest` and
| `TenantOwnedModelsGuardTest` already state it by example — two scripted tenants, two
| scripted models, one assertion per seam. What this file adds is the three things a
| scripted pair cannot say:
|
|  1. **Every tenant-owned table, including the ones that do not exist yet.** The
|     subjects come from a walk of the live schema (`TenantIsolationProbe::subjects()`),
|     not from a list in this file, and a tenant-scoped model with no generator fails the
|     first test below. The scripted tests cover `TenantUsage` and `TenantApiToken`; this
|     one covers every model whose table carries `tenant_id` and whose class uses
|     `BelongsToTenant` — eight today, and whatever a later phase adds tomorrow.
|  2. **Every read path, not the three a reviewer thought of.** `count()`, `exists()`,
|     `sum()`, `max()` and `min()` never hydrate a model, so the retrieval guard cannot
|     see them and only the scope stands between them and another tenant's data — a leak
|     through `count()` is still a leak. `paginate()`, `chunk()`, `cursor()` and `lazy()`
|     build their own queries. `whereIn()` over a mixed set of ids is the shape an API
|     filter has. All of them are asserted, for every model, with row counts drawn at
|     random including **zero**.
|  3. **The negative and the positive together.** "No foreign row came back" is satisfied
|     by a repository that returns nothing at all — and by a tenant that owns nothing,
|     which this test deliberately generates. So every path is also asserted to return the
|     acting tenant's own rows, by id and in the expected number, and every iteration
|     first asserts that the other tenants' rows really are in the table (read through the
|     sanctioned `withoutTenantScope()` hatch). A scope that returned an empty set for
|     everybody would fail on the first iteration.
|
| **Reproducibility.** One seed fixes every draw: how many tenants, how many rows each,
| which tenant is left empty, which model and row a seam targets, which relation a foreign
| parent is reached through, every column value. It is printed in every failure message,
| and `TENANT_ISOLATION_SEED=<seed> vendor/bin/pest --filter='<test name>'` replays it.
|
| **Non-vacuity was verified by mutation**, not assumed. Three mutants were introduced,
| run, and reverted:
|
|  - `TenantScope::apply()` with its `where tenant_id = ?` replaced by
|    `whereNotNull(tenant_id)` — tests 2, 3 and 5 fail;
|  - `TenantOwnershipGuard::assertKeyNotOwnedByAnotherTenant()` with its throw replaced by
|    a silent return, so a foreign id becomes a 404 — tests 2 and 3 fail;
|  - `TenantScope::apply()` returning instead of throwing when no tenant is bound, so an
|    unresolved context reads every tenant — tests 4 and 5 fail.
|
| Each mutant is caught by a *different* group of assertions, which is why the "own rows
| are still returned, in the expected number" half of every claim is mandatory rather than
| decorative.
*/

/**
 * The keys of a set of rows, as sorted strings, so a read path can be compared to the
 * acting tenant's rows without caring about order or key type (ULID or auto-increment).
 *
 * @param  iterable<array-key, Model>  $rows
 * @return list<string>
 */
function tenantIsolationKeysOf(iterable $rows): array
{
    $keys = [];

    foreach ($rows as $row) {
        $keys[] = $row->getKey();
    }

    return tenantIsolationSorted($keys);
}

/**
 * @param  array<array-key, mixed>  $keys
 * @return list<string>
 */
function tenantIsolationSorted(array $keys): array
{
    $strings = array_map(static fn (mixed $key): string => is_scalar($key) ? (string) $key : '', $keys);

    // Sorted so a read path can be compared to the expected set without either side
    // having to promise an order; `sort()` reindexes, which is what makes this a list.
    sort($strings, SORT_STRING);

    return $strings;
}

/**
 * The keys `chunk()` walks — a read path that builds and re-builds its own query, so the
 * scope has to be re-applied on every page rather than once.
 *
 * @param  class-string<Model>  $class
 * @return list<string>
 */
function tenantIsolationChunkedKeys(string $class, string $key, int $size): array
{
    $keys = [];

    /**
     * @param  EloquentCollection<int, Model>  $rows
     */
    $collect = static function (EloquentCollection $rows) use (&$keys): void {
        foreach ($rows as $row) {
            $keys[] = $row->getKey();
        }
    };

    $class::query()->orderBy($key)->chunk($size, $collect);

    /** @var array<array-key, mixed> $keys */
    return tenantIsolationSorted($keys);
}

/**
 * The keys `paginate()` returns, across every page — the panel and API read path.
 *
 * @param  class-string<Model>  $class
 * @return list<string>
 */
function tenantIsolationPaginatedKeys(string $class, string $key, int $perPage): array
{
    $keys = [];
    $page = 1;

    do {
        $paginator = $class::query()->orderBy($key)->paginate($perPage, ['*'], 'page', $page);

        foreach ($paginator->items() as $row) {
            $keys[] = $row->getKey();
        }

        $page++;
    } while ($paginator->hasMorePages());

    /** @var array<array-key, mixed> $keys */
    return tenantIsolationSorted($keys);
}

/**
 * Every read path the property covers, as `label => the ids that path returns`.
 *
 * Each closure is a *complete* read of the model in the acting tenant's context, so the
 * assertion is always the same one — "exactly the acting tenant's rows" — and adding a
 * path is one line rather than another block of expectations.
 *
 * @param  list<string>  $ownIds
 * @param  list<string>  $foreignIds
 * @return array<string, Closure(): list<string>>
 */
function tenantIsolationReadPaths(
    TenantOwnedSubject $subject,
    array $ownIds,
    array $foreignIds,
    int $pageSize,
): array {
    $class = $subject->model;
    $key = $subject->keyName();

    return [
        'all()' => static fn (): array => tenantIsolationKeysOf($class::all()),
        'get()' => static fn (): array => tenantIsolationKeysOf($class::query()->get()),
        'pluck(key)' => static fn (): array => tenantIsolationSorted($class::query()->pluck($key)->all()),
        'cursor()' => static fn (): array => tenantIsolationKeysOf($class::query()->cursor()),
        'lazy()' => static fn (): array => tenantIsolationKeysOf($class::query()->lazy($pageSize)),
        'chunk()' => static fn (): array => tenantIsolationChunkedKeys($class, $key, $pageSize),
        'paginate()' => static fn (): array => tenantIsolationPaginatedKeys($class, $key, $pageSize),
        // The shape an API filter has: a caller-supplied set of ids, some of them
        // somebody else's. The scope has to drop the foreign ones silently.
        'whereIn(key, own + foreign)' => static fn (): array => tenantIsolationKeysOf(
            $class::query()->whereIn($key, [...$ownIds, ...$foreignIds])->get()
        ),
        'whereIn(key, foreign only)' => static fn (): array => tenantIsolationKeysOf(
            $class::query()->whereIn($key, $foreignIds)->get()
        ),
        // Relation reads: the eager load (which never calls the relation method on the
        // parent) and the `has()` join, both of which build their own inner queries.
        'with(tenant)' => static fn (): array => tenantIsolationKeysOf($class::query()->with('tenant')->get()),
        'has(tenant)' => static fn (): array => tenantIsolationKeysOf($class::query()->has('tenant')->get()),
    ];
}

/**
 * Refuse a cross-tenant *write*: with the typed 403 everywhere it can be reached, and
 * with either typed refusal on the append-only tables.
 *
 * `AuditLog` and `AbuseEvent` are append-only, and `AppendOnly` registers its `deleting`
 * hook before `BelongsToTenant` registers the ownership hooks — so a delete aimed at one
 * of their rows is refused for being a delete before it can be refused for being somebody
 * else's. The claim that matters, *the write does not happen*, holds either way, and the
 * caller asserts the stored row afterwards. Every other seam and every other model must
 * fail with `CrossTenantAccessException` specifically.
 *
 * @param  Closure(): mixed  $write
 */
function tenantIsolationExpectRefused(Closure $write, TenantOwnedSubject $subject, string $where): void
{
    if (! $subject->appendOnly) {
        expect($write)->toThrow(CrossTenantAccessException::class, null, $where);

        return;
    }

    try {
        $write();
    } catch (CrossTenantAccessException|AppendOnlyViolationException) {
        return;
    }

    expect(false)->toBeTrue($where.': the cross-tenant write was not refused at all.');
}

/*
|--------------------------------------------------------------------------
| 1. The control: discovery, and a generator for everything it discovers
|--------------------------------------------------------------------------
*/

it('discovers every tenant-owned model from the live schema and can seed each one', function (): void {
    // A discovery bug would make every other test in this file vacuously pass, so the
    // models that exist today are named explicitly — the guard
    // `TenantOwnedModelsGuardTest` puts on its own walk, for the same reason.
    $scoped = TenantIsolationProbe::scopedModels();

    expect($scoped)->toContain(
        AbuseEvent::class,
        AuditLog::class,
        EncryptionKey::class,
        QuotaHold::class,
        Saga::class,
        TenantApiToken::class,
        TenantTierAssignment::class,
        TenantUsage::class,
    )
        // The tenant root and the platform-owned catalogue are not tenant-scoped, and a
        // walk that thought they were would be finding the column somewhere it is not.
        ->and($scoped)->not->toContain(Tenant::class, User::class, Plan::class)
        // The reviewed exemptions of `TenantOwnedModelsGuardTest`: tables that carry
        // `tenant_id` without the trait, because they are read before a tenant exists and
        // therefore cannot make Property 1's claim. That test owns the argument and keeps
        // the list honest; this one covers everything the trait is actually on.
        ->and($scoped)->not->toContain(
            TenantUser::class,
            OutboxMessage::class,
            IdempotencyKey::class,
            SigningSecret::class,
        );

    $unseedable = TenantIsolationProbe::modelsWithoutWriter();

    expect($unseedable)->toBe([], sprintf(
        'These tenant-scoped models are in the live schema but this property cannot seed them, so '
        .'Property 1 is not being asserted for them: %s. Add a writer to '
        .'TenantIsolationProbe::writers().',
        implode(', ', $unseedable),
    ));

    // ...and the generator really does write rows for the tenant it names, which is the
    // assumption every later assertion rests on.
    $probe = TenantIsolationProbe::seeded();
    $tenants = $probe->tenants(2);

    foreach (TenantIsolationProbe::subjects() as $subject) {
        $rows = $probe->seed($subject, $tenants[0], 1);

        expect($rows)->toHaveCount(1, sprintf('seed %d: could not seed %s.', $probe->seed, $subject->label()))
            ->and($rows[0]->getAttribute('tenant_id'))->toBe($tenants[0]->id, sprintf(
                'seed %d: a seeded %s row is not owned by the tenant it was written for.',
                $probe->seed,
                $subject->label(),
            ))
            ->and($subject->rowsOf($tenants[1]))->toBe([], sprintf(
                'seed %d: seeding %s for one tenant gave rows to another.',
                $probe->seed,
                $subject->label(),
            ));
    }
});

/*
|--------------------------------------------------------------------------
| 2. The property: every read path, every model, every tenant
|--------------------------------------------------------------------------
*/

it('returns only the acting tenants rows on every read path, for every tenant-owned model', function (): void {
    $probe = TenantIsolationProbe::seeded();
    $context = app(TenantContext::class);

    foreach ($probe->shuffled(TenantIsolationProbe::subjects()) as $subject) {
        $class = $subject->model;
        $key = $subject->keyName();

        // Two to four tenants, random row counts, at least one tenant left empty.
        $tenants = $probe->tenants($probe->int(2, 4));
        $counts = $probe->rowCounts(count($tenants), $subject->maxRowsPerTenant);

        /** @var array<string, list<string>> $seeded  tenant id => the keys it owns */
        $seeded = [];

        foreach ($tenants as $index => $tenant) {
            $seeded[$tenant->id] = tenantIsolationKeysOf($probe->seed($subject, $tenant, $counts[$index]));
        }

        $total = array_sum($counts);

        foreach ($tenants as $index => $acting) {
            $context->forget();
            $context->set($acting);

            $ownIds = $seeded[$acting->id];
            $foreignIds = [];

            foreach ($seeded as $ownerId => $keys) {
                if ($ownerId !== $acting->id) {
                    $foreignIds = [...$foreignIds, ...$keys];
                }
            }

            $where = sprintf(
                'seed %d: %s, tenant %d of %d [%s] owning %d of %d rows',
                $probe->seed,
                $subject->label(),
                $index + 1,
                count($tenants),
                $acting->id,
                count($ownIds),
                $total,
            );

            /*
            |------------------------------------------------------------------
            | The anti-vacuity control
            |------------------------------------------------------------------
            | Read through the sanctioned `withoutTenantScope()` hatch, so every
            | assertion below is known to be about a table that *has* the rows it
            | must not return — including the iterations where the acting tenant
            | owns none. Without this, an implementation that returned nothing at
            | all (or a generator that seeded nothing) would satisfy every "no
            | foreign rows" claim in this file.
            */
            $storedPerTenant = [];

            foreach ($tenants as $owner) {
                $storedPerTenant[] = count($subject->rowsOf($owner));
            }

            expect(array_sum($storedPerTenant))->toBe($total, $where.': the fixture did not seed the rows this iteration is about.')
                ->and(count($ownIds))->toBe($counts[$index], $where.': wrong number of own rows seeded.')
                ->and(count($foreignIds))->toBe($total - $counts[$index]);

            // ---- every read path returns exactly the acting tenant's rows ----------
            foreach (tenantIsolationReadPaths($subject, $ownIds, $foreignIds, $probe->int(1, 3)) as $label => $read) {
                // A foreign-only `whereIn` must come back empty; every other path must
                // come back with all of the tenant's own rows and nothing else.
                $expected = $label === 'whereIn(key, foreign only)' ? [] : $ownIds;
                $returned = $read();

                expect($returned)->toBe($expected, sprintf(
                    '%s: read path %s returned [%s], expected [%s].',
                    $where,
                    $label,
                    implode(',', $returned),
                    implode(',', $expected),
                ));
            }

            // ---- the paths that never hydrate a model ------------------------------
            // A leak here is invisible to the retrieval guard: only the scope constrains
            // an aggregate, and leaking a rival's row count is still a leak.
            $max = $class::query()->max($key);
            $min = $class::query()->min($key);

            expect($class::query()->count())->toBe(count($ownIds), $where.': count() counted more than the acting tenant.')
                ->and($class::query()->exists())->toBe($ownIds !== [], $where.': exists() disagreed with the tenant own rows.')
                ->and($class::query()->doesntExist())->toBe($ownIds === [], $where)
                ->and($class::query()->pluck('tenant_id')->unique()->values()->all())
                ->toBe($ownIds === [] ? [] : [$acting->id], $where.': a read returned a row owned by another tenant.')
                ->and($class::query()->paginate(2)->total())->toBe(count($ownIds), $where.': paginate() reported a foreign total.')
                // max()/min() are asserted by membership rather than by value: a foreign
                // key is not in `$ownIds`, which is the leak, while comparing the value
                // itself would only assert PHP's sort against the database's.
                ->and($max === null ? null : in_array((string) $max, $ownIds, true))
                ->toBe($ownIds === [] ? null : true, $where.': max(key) came from outside the tenant.')
                ->and($min === null ? null : in_array((string) $min, $ownIds, true))
                ->toBe($ownIds === [] ? null : true, $where.': min(key) came from outside the tenant.');

            if ($subject->sumColumn !== null) {
                $expectedSum = 0;

                foreach ($subject->rowsOf($acting) as $row) {
                    $expectedSum += (int) $row->getAttribute($subject->sumColumn);
                }

                $sum = (int) $class::query()->sum($subject->sumColumn);

                expect($sum)->toBe($expectedSum, sprintf(
                    '%s: sum(%s) was %d, expected %d — an aggregate that crosses tenants leaks '
                    .'their volume even though it hydrates nothing.',
                    $where,
                    $subject->sumColumn,
                    $sum,
                    $expectedSum,
                ));
            }

            // ---- find-by-id: own rows found, foreign rows denied (Req 1.3) ---------
            if ($ownIds !== []) {
                $ownId = $probe->pick($ownIds);

                expect((string) $class::query()->findOrFail($ownId)->getKey())->toBe($ownId, $where.': the tenant own row was not found by id.')
                    ->and((string) $class::query()->find($ownId)?->getKey())->toBe($ownId, $where);

                // The lazy relation load, from a row the tenant is allowed to hold.
                $owner = $class::query()->firstOrFail()->getRelationValue('tenant');

                expect($owner)->toBeInstanceOf(Tenant::class, $where)
                    ->and($owner instanceof Tenant ? $owner->id : null)
                    ->toBe($acting->id, $where.': a row lazy-loaded another tenant as its owner.');
            }

            if ($foreignIds !== []) {
                $foreignId = $probe->pick($foreignIds);

                // Naming another tenant's row is a typed 403, not a silent miss.
                expect(fn () => $class::query()->find($foreignId))->toThrow(CrossTenantAccessException::class, null, $where)
                    ->and(fn () => $class::query()->findOrFail($foreignId))->toThrow(CrossTenantAccessException::class, null, $where);
            }

            // ...while an id that exists nowhere stays an ordinary miss: "no such row" is
            // not a cross-tenant access attempt.
            $absent = $class::query()->getModel()->getKeyType() === 'int' ? '9999999' : '01hzzzzzzzzzzzzzzzzzzzzzzz';

            expect($class::query()->find($absent))->toBeNull($where.': a nonexistent id was reported as a cross-tenant access.')
                ->and(fn () => $class::query()->findOrFail($absent))->toThrow(ModelNotFoundException::class);

            // ---- relation filters, in both directions ------------------------------
            expect($class::query()->whereHas('tenant', fn (Builder $query) => $query->whereKey($acting->id))->count())
                ->toBe(count($ownIds), $where.': whereHas(tenant) lost the acting tenant own rows.');

            foreach ($tenants as $other) {
                if ($other->id === $acting->id) {
                    continue;
                }

                expect($class::query()->whereHas('tenant', fn (Builder $query) => $query->whereKey($other->id))->count())
                    ->toBe(0, $where.': whereHas(tenant) reached rows owned by ['.$other->id.'].');
            }
        }
    }
});

/*
|--------------------------------------------------------------------------
| 3. The seams a query scope structurally cannot cover (Req 1.3)
|--------------------------------------------------------------------------
*/

it('denies every ownership seam a query scope cannot reach, on a randomly drawn model and row', function (): void {
    $probe = TenantIsolationProbe::seeded();
    $context = app(TenantContext::class);
    $subjects = TenantIsolationProbe::subjects();

    foreach ($probe->shuffled(TenantIsolationProbe::OWNERSHIP_SEAMS) as $iteration => $seam) {
        $subject = $probe->pick($subjects);
        $class = $subject->model;
        $column = $subject->mutableColumn;
        $value = $probe->int(600, 999);

        [$acting, $other] = $probe->tenants(2);
        $rows = max(1, min(2, $subject->maxRowsPerTenant));
        $probe->seed($subject, $acting, $rows);
        $foreignKeys = tenantIsolationKeysOf($probe->seed($subject, $other, $rows));

        $context->forget();
        $context->set($acting);

        // The realistic way a foreign instance ends up in a caller's hands: the
        // sanctioned `forTenant()` read hatch, which is allowed to *hold* the row —
        // holding one is a declared bypass, persisting one is not.
        $foreignId = $probe->pick($foreignKeys);
        $foreign = $subject->storedRow($other, $foreignId);
        $own = $class::query()->firstOrFail();

        expect($foreign)->toBeInstanceOf(Model::class);

        $foreign = $foreign instanceof Model ? $foreign : $own;
        $before = $foreign->getAttribute($column);

        $where = sprintf(
            'seed %d, iteration %d: seam [%s] on the %s row [%s] owned by [%s] while acting as [%s]',
            $probe->seed,
            $iteration,
            $seam,
            $subject->label(),
            $foreignId,
            $other->id,
            $acting->id,
        );

        $exercise = match ($seam) {
            // Eloquent builds an instance write through `newModelQuery()`, without global
            // scopes: the ownership hooks are all that stand between it and the row.
            'instance save' => function () use ($foreign, $column, $value, $subject, $where): void {
                tenantIsolationExpectRefused(function () use ($foreign, $column, $value): void {
                    $foreign->setAttribute($column, $value);
                    $foreign->save();
                }, $subject, $where);
            },
            'instance update' => function () use ($foreign, $column, $value, $subject, $where): void {
                tenantIsolationExpectRefused(fn () => $foreign->update([$column => $value]), $subject, $where);
            },
            'instance delete' => function () use ($foreign, $subject, $where): void {
                tenantIsolationExpectRefused(fn () => $foreign->delete(), $subject, $where);
            },
            // Stealing a row: its *stored* owner is the other tenant, so the write is
            // refused even though the attribute now names the acting one.
            'steal to my tenant' => function () use ($foreign, $acting, $subject, $where): void {
                tenantIsolationExpectRefused(function () use ($foreign, $acting): void {
                    $foreign->setAttribute('tenant_id', $acting->id);
                    $foreign->save();
                }, $subject, $where);
            },
            // ...and the mirror image: moving an owned row out to another tenant.
            'give away my row' => function () use ($own, $other, $subject, $where): void {
                tenantIsolationExpectRefused(function () use ($own, $other): void {
                    $own->setAttribute('tenant_id', $other->id);
                    $own->save();
                }, $subject, $where);
            },
            // A create that names a tenant is legitimate (provisioning, imports, platform
            // writes); one that names a tenant other than the acting one is forgery.
            'forged tenant_id on create' => function () use ($class, $other, $subject, $where): void {
                tenantIsolationExpectRefused(
                    fn () => (new $class)->forceFill(['tenant_id' => $other->id])->save(),
                    $subject,
                    $where,
                );
            },
            'find by foreign id' => function () use ($class, $foreignId, $where): void {
                expect(fn () => $class::query()->find($foreignId))->toThrow(CrossTenantAccessException::class, null, $where)
                    ->and(fn () => $class::query()->findOrFail($foreignId))->toThrow(CrossTenantAccessException::class, null, $where);
            },
            // Route-model binding is the most common find-by-id in the panels and the API,
            // and a scope-induced miss there would surface as a 404 rather than a denial.
            'route binding' => function () use ($class, $foreignId, $where): void {
                expect(fn () => (new $class)->resolveRouteBinding($foreignId))
                    ->toThrow(CrossTenantAccessException::class, null, $where);
            },
            // fresh()/refresh() are built without scopes by construction, so the retrieval
            // guard is the only thing that stops them handing over a foreign row.
            'unscoped rehydration' => function () use ($foreign, $where): void {
                expect(fn () => $foreign->fresh())->toThrow(CrossTenantAccessException::class, null, $where)
                    ->and(fn () => $foreign->refresh())->toThrow(CrossTenantAccessException::class, null, $where);
            },
            // The check a service makes by hand when a model arrives from a cache, a job
            // payload, or a caller that took a `Model` argument — and which must *not*
            // fire for a row the acting tenant does own.
            'explicit ownership assertion' => function () use ($foreign, $own, $where): void {
                $guard = app(TenantOwnershipGuard::class);

                $guard->assertOwned($own, 'access');

                expect(fn () => $guard->assertOwned($foreign, 'access'))
                    ->toThrow(CrossTenantAccessException::class, null, $where);
            },
            // A child reached through a parent the acting tenant may not hold — including
            // the relations that drop the tenant scope on purpose, and the eager load,
            // which never calls the relation method on the parent at all.
            'relation through foreign parent' => function () use ($probe, $other, $acting, $where): void {
                $relation = $probe->pick(['usage', 'apiTokens', 'tenantUsers', 'users']);

                $load = match ($relation) {
                    'usage' => fn () => $other->usage()->get(),
                    'apiTokens' => fn () => $other->apiTokens()->count(),
                    'tenantUsers' => fn () => $other->tenantUsers()->get(),
                    default => fn () => $other->users()->get(),
                };

                // The relation *method* is refused before a single row is read, which is
                // what makes a relation that drops the tenant scope on purpose safe.
                expect($load)->toThrow(CrossTenantAccessException::class, null, $where.' via '.$relation);

                /*
                | An eager load never calls the relation method on the parent, so it is
                | the retrieval check that has to stop it — and it can only stop rows
                | that exist. A bystander tenant with a row in a relation `Tenant`
                | declares is seeded for exactly that reason: asserting this against a
                | tenant with an empty relation would pass whether the check existed or
                | not. (An empty relation leaks nothing, which is why it is not the
                | interesting case.)
                */
                $outsider = $probe->tenants(2)[0];
                $probe->seed(TenantIsolationProbe::subjectFor(TenantUsage::class), $outsider, 1);

                expect(fn () => Tenant::query()->whereKey($outsider->id)->with('usage')->first())
                    ->toThrow(CrossTenantAccessException::class, null, $where.' via an eager load')
                    // ...and the acting tenant's own parent still eager-loads.
                    ->and(Tenant::query()->whereKey($acting->id)->with('usage')->first()?->id)
                    ->toBe($acting->id, $where.': the guard broke a legitimate eager load.');
            },
            default => throw new LogicException('Unhandled ownership seam ['.$seam.'].'),
        };

        $exercise();

        // Whatever the seam, the row is exactly as it was and both tenants still own what
        // they owned: a refusal that had already written is not a refusal.
        $after = $subject->storedRow($other, $foreignId);

        expect($after)->toBeInstanceOf(Model::class, $where.': the refused operation removed the row.')
            ->and($after?->getAttribute($column))->toEqual($before, $where.': the refused write reached the database anyway.')
            ->and($after?->getAttribute('tenant_id'))->toBe($other->id, $where.': the row changed hands.')
            ->and($subject->rowsOf($other))->toHaveCount($rows, $where.': the other tenant lost or gained rows.')
            ->and($class::query()->count())->toBe($rows, $where.': the acting tenant lost or gained rows.');
    }
});

/*
|--------------------------------------------------------------------------
| 4. Fail-closed: an unbound context is a bug, not a licence
|--------------------------------------------------------------------------
*/

it('fails closed on every read path when no tenant is bound, for every tenant-owned model', function (): void {
    $probe = TenantIsolationProbe::seeded();
    $context = app(TenantContext::class);

    foreach ($probe->shuffled(TenantIsolationProbe::subjects()) as $subject) {
        $class = $subject->model;
        $key = $subject->keyName();
        $tenants = $probe->tenants(2);
        $total = 0;

        foreach ($tenants as $tenant) {
            $count = $probe->int(1, max(1, min(2, $subject->maxRowsPerTenant)));
            $probe->seed($subject, $tenant, $count);
            $total += $count;
        }

        $context->forget();

        $where = sprintf(
            'seed %d: %s with %d rows and no tenant bound',
            $probe->seed,
            $subject->label(),
            $total,
        );

        // An unresolved context is a bug. It must not become a cross-tenant read — and it
        // must not become a silently empty one either, which is the failure mode that
        // hides for months and then loses a tenant its data.
        $reads = [
            'count()' => fn () => $class::query()->count(),
            'get()' => fn () => $class::query()->get(),
            'all()' => fn () => $class::all(),
            'first()' => fn () => $class::query()->first(),
            'exists()' => fn () => $class::query()->exists(),
            'pluck()' => fn () => $class::query()->pluck($key),
            'find()' => fn () => $class::query()->find('1'),
            'paginate()' => fn () => $class::query()->paginate(2),
            'max()' => fn () => $class::query()->max($key),
            'cursor()' => fn () => $class::query()->cursor()->all(),
            'chunk()' => fn () => tenantIsolationChunkedKeys($class, $key, 2),
            'whereIn()' => fn () => $class::query()->whereIn($key, ['1'])->get(),
            'whereHas(tenant)' => fn () => $class::query()->whereHas('tenant')->count(),
            // A create with nothing bound cannot attribute the row to anybody, so it is
            // refused rather than written unattributed.
            'create()' => fn () => (new $class)->forceFill([$subject->mutableColumn => 1])->save(),
        ];

        foreach ($reads as $label => $read) {
            expect($read)->toThrow(MissingTenantContextException::class, null, sprintf(
                '%s: %s did not fail closed — an unbound context must never resolve to '
                .'"every tenant", and must never resolve to "no rows" either.',
                $where,
                $label,
            ));
        }

        // Nothing was read, and nothing was destroyed on the way: the rows are all there.
        $stored = 0;

        foreach ($tenants as $tenant) {
            $stored += count($subject->rowsOf($tenant));
        }

        expect($stored)->toBe($total, $where.': the fail-closed reads changed the table.');
    }
});

/*
|--------------------------------------------------------------------------
| 5. The one bypass: audited, and no wider than its frame (Req 1.5)
|--------------------------------------------------------------------------
*/

it('bypasses the scope only inside the audited platform frame, and never past it', function (): void {
    $probe = TenantIsolationProbe::seeded();
    $context = app(TenantContext::class);
    $subjects = TenantIsolationProbe::subjects();
    $tenants = $probe->tenants($probe->int(2, 3));

    /** @var array<string, array<string, int>> $seeded  model => tenant id => rows this test asked for */
    $seeded = [];

    foreach ($subjects as $subject) {
        $counts = $probe->rowCounts(count($tenants), $subject->maxRowsPerTenant);

        foreach ($tenants as $index => $tenant) {
            $probe->seed($subject, $tenant, $counts[$index]);
            $seeded[$subject->model][$tenant->id] = $counts[$index];
        }
    }

    $acting = $probe->pick($tenants);
    $reason = 'admin: cross-tenant sweep '.$probe->int(1_000, 9_999);

    $context->forget();
    $context->set($acting);

    foreach ($subjects as $subject) {
        $class = $subject->model;
        $where = sprintf('seed %d: %s, acting tenant [%s]', $probe->seed, $subject->label(), $acting->id);

        /*
        | What the acting tenant owns, read through the sanctioned `forTenant()` hatch
        | rather than taken from the seed plan — the same preference the frame comparison
        | below already states, and here it is load-bearing: a row of one table can require
        | a parent row in another *subject's* table (a `channel_send_log` row needs a
        | session; a `cloud_api_templates` row needs the credential set it is approved
        | against), so seeding one subject legitimately adds rows to another's table. The
        | seed plan is kept as a floor, so a writer that silently wrote nothing still fails
        | rather than making this iteration vacuous.
        */
        $own = count($subject->rowsOf($acting));

        expect($own)->toBeGreaterThanOrEqual(
            $seeded[$subject->model][$acting->id],
            $where.': the fixture did not seed the rows this iteration is about.',
        );

        // Scoped before the frame...
        expect($class::query()->count())->toBe($own, $where);

        /*
        | ...unconstrained inside the audited frame, which is the only bypass Req 1.5
        | allows. Both counts are taken *inside* the same frame and compared to each
        | other, so the claim is "platform mode sees the whole table" measured against
        | the sanctioned hatch rather than against a number this test computed — and
        | `audit_logs` (which the frame itself appends to) cannot skew it.
        */
        $inside = $context->asPlatform($reason, fn (): array => [
            $class::query()->count(),
            count($subject->rowsOf()),
        ]);

        // ...and scoped again the moment the frame closes, with no residue.
        expect($inside[0])->toBe($inside[1], $where.': platform mode did not see the whole table.')
            ->and($inside[0])->toBeGreaterThanOrEqual($own)
            ->and($class::query()->count())->toBe($own, $where.': the bypass outlived its frame.')
            ->and($context->actingAsPlatform())->toBeFalse($where)
            // Inside the frame, `runFor()` re-applies the scope for one named tenant —
            // the platform-admin drill-down, which must not become a second bypass.
            ->and($context->asPlatform($reason, fn (): int => $context->runFor(
                $acting,
                fn (): int => $class::query()->count(),
            )))->toBe($own, $where.': runFor() inside platform mode did not re-apply the scope.');
    }

    // The bypass is audited (Req 1.5): every frame is on the platform chain with its
    // reason, and no tenant's own chain records it.
    $entries = AuditLog::withoutTenantScope()
        ->where('chain_key', AuditLog::PLATFORM_CHAIN)
        ->orderBy('sequence')
        ->get();
    $reasons = $entries
        ->map(fn (AuditLog $entry): mixed => is_array($entry->payload) ? ($entry->payload['reason'] ?? null) : null)
        ->unique()
        ->values()
        ->all();

    expect($entries)->toHaveCount(count($subjects) * 4, sprintf(
        'seed %d: %d platform frames were opened and %d audit entries were written.',
        $probe->seed,
        count($subjects) * 2,
        $entries->count(),
    ))
        ->and($entries->pluck('action')->unique()->values()->all())
        ->toBe(['platform_mode.entered', 'platform_mode.exited'])
        ->and($reasons)->toBe([$reason])
        ->and($entries->pluck('tenant_id')->unique()->values()->all())->toBe([null]);

    // ...and the bypass does not survive a unit-of-work boundary. The worker isolates the
    // context around every queued job, so the next job starts from nothing bound — which
    // fails closed rather than inheriting "still platform".
    $subject = $probe->pick($subjects);
    $class = $subject->model;
    $job = 'job-'.$probe->int(1, 999);

    $context->enterPlatformMode('admin: still open when the job boundary arrives');
    $context->isolate($job);

    expect($context->actingAsPlatform())->toBeFalse()
        ->and(fn () => $class::query()->count())->toThrow(MissingTenantContextException::class, null, sprintf(
            'seed %d: platform mode leaked across a job boundary — the next unit of work would '
            .'read every tenant.',
            $probe->seed,
        ));

    // The frame the boundary suspended is put back by `release()`, not lost: a suspension
    // is not an exit.
    $context->release($job);

    expect($context->actingAsPlatform())->toBeTrue();

    $context->forget();
});
