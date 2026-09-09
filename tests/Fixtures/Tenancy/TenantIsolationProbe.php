<?php

declare(strict_types=1);

namespace Tests\Fixtures\Tenancy;

use App\Enums\AbuseSignal;
use App\Enums\AbuseVector;
use App\Enums\GuardAction;
use App\Enums\KeyPurpose;
use App\Enums\QuotaHoldStatus;
use App\Enums\QuotaKind;
use App\Enums\QuotaReason;
use App\Enums\TenantTier;
use App\Models\AbuseEvent;
use App\Models\AuditLog;
use App\Models\Concerns\BelongsToTenant;
use App\Models\EncryptionKey;
use App\Models\QuotaHold;
use App\Models\Saga;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\TenantApiToken;
use App\Models\TenantTierAssignment;
use App\Models\TenantUsage;
use App\Services\Abuse\AbuseEventDraft;
use App\Services\Abuse\AbuseRecorder;
use App\Services\Audit\AuditService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Finder\Finder;

/**
 * The generator for Correctness Property 1 (tenant isolation): random tenants, random
 * numbers of rows in **every** tenant-owned table the live schema has, and a
 * reproducible source of every draw that shaped them.
 *
 * ## Why the subject list is derived, not written
 *
 * Property 1 says *every* query in a tenant's context returns only that tenant's rows.
 * A property test that names the tables it checks stops testing that claim the moment
 * somebody adds a table — and it stops silently, still green, still named "tenant
 * isolation". So `subjects()` walks `app/Models`, keeps the classes whose table carries
 * `tenant_id` and whose class uses `BelongsToTenant`, and looks each one up in
 * `writers()`. A model with no writer is reported by `modelsWithoutWriter()` and the
 * property test **fails** on it: adding a tenant-owned model in a later phase either
 * gets covered here or breaks this task's test, which is the point.
 *
 * Discovery deliberately mirrors `TenantOwnedModelsGuardTest` (the schema walk that
 * already enforces "column ⇒ trait"), so the two cannot disagree about what a
 * tenant-owned model is.
 *
 * ## Reproducibility
 *
 * Every draw comes from one seeded engine — tenant counts, row counts, which tenant is
 * left empty, which row is targeted, which seam is exercised, every column value. The
 * seed is printed in every failure message and replays the run:
 *
 * ```
 * TENANT_ISOLATION_SEED=<seed> vendor/bin/pest --filter='<test name>'
 * ```
 *
 * Tenant names still come from the unseeded factory faker (`fake()->unique()` cannot be
 * seeded without also seeding the factories into collisions — see `AuditChainProbe`),
 * so a replay is identical in every respect the property depends on.
 *
 * ## Rows are written through the production path
 *
 * `abuse_events` goes through `AbuseRecorder`, `audit_logs` through `AuditService`, and
 * everything else through its factory or `create()`. Each write happens inside
 * `TenantContext::runFor($tenant)`, which is how a scheduler or a queued job writes for
 * a tenant — so the rows this property reads back are the rows production makes.
 */
final class TenantIsolationProbe
{
    /**
     * Set this to replay a failed run.
     */
    public const string SEED_ENV = 'TENANT_ISOLATION_SEED';

    /**
     * The seams a query scope structurally cannot cover, which
     * `App\Services\Tenancy\TenantOwnershipGuard` documents and covers instead.
     *
     * Enumerated rather than sampled, for the reason `AuditChainProbe` gives about
     * tamper kinds: the seam is the one dimension small enough to cover exhaustively,
     * and a randomly sampled seam would leave some of them unexercised on any given
     * run. Which *model* and which *row* each seam is exercised against is drawn.
     *
     * @var non-empty-list<string>
     */
    public const array OWNERSHIP_SEAMS = [
        'instance save',                 // save() on a foreign instance — built without scopes
        'instance update',               // update() on a foreign instance
        'instance delete',               // delete() on a foreign instance
        'steal to my tenant',            // re-attributing a foreign row to the acting tenant
        'give away my row',              // re-attributing an owned row to another tenant
        'forged tenant_id on create',    // a create that names another tenant
        'find by foreign id',            // find()/findOrFail() naming a foreign row
        'route binding',                 // route-model binding naming a foreign row
        'unscoped rehydration',          // fresh()/refresh(), which drop the scope by construction
        'explicit ownership assertion',  // the check a service makes by hand
        'relation through foreign parent', // a child reached through a tenant you may not hold
    ];

    private readonly Randomizer $rng;

    /**
     * Bumped for every row written, so a dedup/correlation key is unique across the
     * whole run without being random.
     */
    private int $rows = 0;

    private function __construct(public readonly int $seed)
    {
        $this->rng = new Randomizer(new Mt19937($seed));
    }

    /**
     * A fresh seed, or the one named by `TENANT_ISOLATION_SEED` for a replay.
     */
    public static function seeded(): self
    {
        $configured = getenv(self::SEED_ENV);

        return new self(
            is_string($configured) && $configured !== '' && ctype_digit($configured)
                ? (int) $configured
                : random_int(1, 2 ** 48),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Draws
    |--------------------------------------------------------------------------
    */

    public function int(int $min, int $max): int
    {
        return $max <= $min ? $min : $this->rng->getInt($min, $max);
    }

    public function chance(int $percent): bool
    {
        return $this->rng->getInt(1, 100) <= $percent;
    }

    /**
     * @template TValue
     *
     * @param  non-empty-list<TValue>  $items
     * @return TValue
     */
    public function pick(array $items): mixed
    {
        return $items[$this->rng->getInt(0, count($items) - 1)];
    }

    /**
     * @template TValue
     *
     * @param  list<TValue>  $items
     * @return list<TValue>
     */
    public function shuffled(array $items): array
    {
        /** @var list<TValue> $shuffled */
        $shuffled = $this->rng->shuffleArray($items);

        return $shuffled;
    }

    /**
     * How many rows each of `$tenants` tenants gets, with **at least one tenant left
     * empty** and at least one tenant given rows.
     *
     * The empty tenant is the case a scope bug hides behind: "no foreign rows came
     * back" is trivially true of a tenant that owns nothing, so the property has to
     * hold there too — and the tenant next to it must still see all of its own.
     *
     * @return non-empty-list<int>
     */
    public function rowCounts(int $tenants, int $maxRowsPerTenant): array
    {
        $cap = max(1, min(3, $maxRowsPerTenant));
        $counts = [];

        for ($index = 0; $index < $tenants; $index++) {
            $counts[] = $this->int(0, $cap);
        }

        $empty = $this->int(0, $tenants - 1);
        $counts[$empty] = 0;

        $filled = $this->int(0, $tenants - 2);
        $counts[$filled >= $empty ? $filled + 1 : $filled] = $this->int(1, $cap);

        /** @var non-empty-list<int> $counts */
        return $counts;
    }

    /*
    |--------------------------------------------------------------------------
    | Tenants and rows
    |--------------------------------------------------------------------------
    */

    /**
     * `$count` fresh tenants, created with no tenant bound — the provisioning state.
     *
     * @return non-empty-list<Tenant>
     */
    public function tenants(int $count): array
    {
        $tenants = [];

        for ($index = 0; $index < max(2, $count); $index++) {
            $tenants[] = Tenant::factory()->create();
        }

        /** @var non-empty-list<Tenant> $tenants */
        return $tenants;
    }

    /**
     * Write `$count` rows of `$subject` owned by `$tenant`, acting as that tenant.
     *
     * @return list<Model>
     */
    public function seed(TenantOwnedSubject $subject, Tenant $tenant, int $count): array
    {
        if ($count < 1) {
            return [];
        }

        return app(TenantContext::class)->runFor($tenant, function (Tenant $bound) use ($subject, $count): array {
            $rows = [];

            for ($index = 0; $index < $count; $index++) {
                $this->rows++;
                $rows[] = $subject->write($bound, $index, $this);
            }

            return $rows;
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Discovery
    |--------------------------------------------------------------------------
    */

    /**
     * Every concrete Eloquent model under `app/Models`.
     *
     * @return list<class-string<Model>>
     */
    public static function eloquentModels(): array
    {
        $models = [];

        foreach (Finder::create()->files()->in(app_path('Models'))->name('*.php') as $file) {
            /** @var class-string<Model> $class */
            $class = 'App\\Models\\'.str_replace('/', '\\', Str::before($file->getRelativePathname(), '.php'));

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
                continue;
            }

            $models[] = $class;
        }

        sort($models);

        return $models;
    }

    /**
     * Models whose table carries `tenant_id` **and** which are tenant-scoped by
     * `BelongsToTenant`.
     *
     * The second half is not a convenience: the tables that carry the column without
     * the trait are the reviewed exemptions of `TenantOwnedModelsGuardTest`
     * (`tenant_users`, `outbox`, `idempotency_keys`, `signing_secrets`), each of which
     * is read *before* a tenant exists and therefore cannot make Property 1's claim.
     * That test owns the argument and keeps the exemption list honest; this one covers
     * everything the trait is on.
     *
     * @return list<class-string<Model>>
     */
    public static function scopedModels(): array
    {
        return array_values(array_filter(self::eloquentModels(), static function (string $class): bool {
            $table = (new $class)->getTable();

            return Schema::hasTable($table)
                && Schema::hasColumn($table, TenantScope::COLUMN)
                && in_array(BelongsToTenant::class, class_uses_recursive($class), true);
        }));
    }

    /**
     * The discovered models, paired with the writer that can seed them.
     *
     * @return non-empty-list<TenantOwnedSubject>
     */
    public static function subjects(): array
    {
        $writers = self::writers();
        $subjects = [];

        foreach (self::scopedModels() as $class) {
            if (array_key_exists($class, $writers)) {
                $subjects[] = $writers[$class];
            }
        }

        if ($subjects === []) {
            throw new RuntimeException('Discovered no tenant-owned models at all: the schema walk is broken.');
        }

        return $subjects;
    }

    /**
     * One named subject, for the rare assertion that is *about* a particular table
     * rather than about all of them — the eager-load seam, which needs rows in a
     * relation `Tenant` actually declares.
     *
     * @param  class-string<Model>  $class
     */
    public static function subjectFor(string $class): TenantOwnedSubject
    {
        $subject = self::writers()[$class] ?? null;

        if (! $subject instanceof TenantOwnedSubject) {
            throw new RuntimeException('No writer is registered for ['.$class.'].');
        }

        return $subject;
    }

    /**
     * Tenant-scoped models the schema walk found and this generator cannot seed.
     *
     * Always empty; asserted by the property test, so a model added in a later phase
     * fails *this* task's test rather than quietly falling out of its coverage.
     *
     * @return list<class-string<Model>>
     */
    public static function modelsWithoutWriter(): array
    {
        $writers = self::writers();

        return array_values(array_filter(
            self::scopedModels(),
            static fn (string $class): bool => ! array_key_exists($class, $writers),
        ));
    }

    /**
     * How to write one row of each tenant-owned table, keyed by model.
     *
     * @return array<class-string<Model>, TenantOwnedSubject>
     */
    private static function writers(): array
    {
        $subjects = [
            new TenantOwnedSubject(
                model: TenantUsage::class,
                // uniq(tenant_id, kind, period_key): one counter per quota kind.
                maxRowsPerTenant: count(QuotaKind::cases()),
                appendOnly: false,
                sumColumn: 'used',
                mutableColumn: 'used',
                writer: static fn (Tenant $tenant, int $index, self $probe): Model => TenantUsage::factory()
                    ->ofKind(QuotaKind::cases()[$index])
                    ->create([
                        'tenant_id' => $tenant->id,
                        'used' => $probe->int(0, 500),
                        'limit' => 1_000,
                    ]),
                reader: static fn (?string $tenantId): array => (
                    $tenantId === null
                        ? TenantUsage::withoutTenantScope()
                        : TenantUsage::forTenant($tenantId)
                )->get()->all(),
            ),
            new TenantOwnedSubject(
                model: TenantApiToken::class,
                maxRowsPerTenant: 8,
                appendOnly: false,
                sumColumn: null,
                mutableColumn: 'name',
                writer: static fn (Tenant $tenant, int $index, self $probe): Model => TenantApiToken::factory()
                    ->create([
                        'tenant_id' => $tenant->id,
                        'name' => 'probe key '.$index.'/'.$probe->int(1_000, 9_999),
                    ]),
                reader: static fn (?string $tenantId): array => (
                    $tenantId === null
                        ? TenantApiToken::withoutTenantScope()
                        : TenantApiToken::forTenant($tenantId)
                )->get()->all(),
            ),
            new TenantOwnedSubject(
                model: TenantTierAssignment::class,
                // uniq(tenant_id): a tenant has exactly one tier row, or none at all.
                maxRowsPerTenant: 1,
                appendOnly: false,
                sumColumn: 'lane_weight',
                mutableColumn: 'lane_weight',
                writer: static fn (Tenant $tenant, int $index, self $probe): Model => TenantTierAssignment::factory()
                    ->onTier($probe->pick(TenantTier::cases()))
                    ->withLaneWeight($probe->int(1, 50))
                    ->create(['tenant_id' => $tenant->id]),
                reader: static fn (?string $tenantId): array => (
                    $tenantId === null
                        ? TenantTierAssignment::withoutTenantScope()
                        : TenantTierAssignment::forTenant($tenantId)
                )->get()->all(),
            ),
            new TenantOwnedSubject(
                model: EncryptionKey::class,
                maxRowsPerTenant: 4,
                appendOnly: false,
                sumColumn: 'version',
                mutableColumn: 'kms_key_id',
                // uniq(tenant_id, purpose, version) plus uniq(tenant_id, purpose,
                // active_flag): one lineage, one ACTIVE version, the rest retiring.
                writer: static function (Tenant $tenant, int $index, self $probe): Model {
                    $factory = EncryptionKey::factory()->ofPurpose(KeyPurpose::Field)->version($index + 1);

                    return ($index === 0 ? $factory : $factory->retiring())
                        ->create(['tenant_id' => $tenant->id]);
                },
                reader: static fn (?string $tenantId): array => (
                    $tenantId === null
                        ? EncryptionKey::withoutTenantScope()
                        : EncryptionKey::forTenant($tenantId)
                )->get()->all(),
            ),
            new TenantOwnedSubject(
                model: Saga::class,
                maxRowsPerTenant: 6,
                appendOnly: false,
                sumColumn: 'current_step',
                mutableColumn: 'last_error',
                writer: static fn (Tenant $tenant, int $index, self $probe): Model => Saga::factory()->create([
                    'tenant_id' => $tenant->id,
                    // uniq(tenant_id, type, correlation_id).
                    'correlation_id' => (string) Str::ulid(),
                    'current_step' => $probe->int(0, 3),
                ]),
                reader: static fn (?string $tenantId): array => (
                    $tenantId === null
                        ? Saga::withoutTenantScope()
                        : Saga::forTenant($tenantId)
                )->get()->all(),
            ),
            new TenantOwnedSubject(
                model: QuotaHold::class,
                maxRowsPerTenant: 6,
                appendOnly: false,
                sumColumn: 'units',
                mutableColumn: 'last_error',
                writer: static function (Tenant $tenant, int $index, self $probe): Model {
                    $kind = $probe->pick(QuotaKind::cases());

                    return QuotaHold::create([
                        'tenant_id' => $tenant->id,
                        'quota_kind' => $kind,
                        'period_key' => $kind->periodKey(),
                        'reason' => QuotaReason::PeriodExhausted,
                        'units' => $probe->int(1, 40),
                        'status' => QuotaHoldStatus::QuotaPaused,
                        // uniq(tenant_id, dedup_key).
                        'dedup_key' => 'probe:'.$probe->seed.':'.$probe->rows.':'.$index,
                        'resume_at' => now()->addDay(),
                        'paused_at' => now(),
                    ]);
                },
                reader: static fn (?string $tenantId): array => (
                    $tenantId === null
                        ? QuotaHold::withoutTenantScope()
                        : QuotaHold::forTenant($tenantId)
                )->get()->all(),
            ),
            new TenantOwnedSubject(
                model: AuditLog::class,
                maxRowsPerTenant: 6,
                appendOnly: true,
                // `sequence` is 1..n within the tenant's own chain.
                sumColumn: 'sequence',
                mutableColumn: 'action',
                writer: static fn (Tenant $tenant, int $index, self $probe): Model => app(AuditService::class)->write(
                    'probe.row.written',
                    ['index' => $index, 'nonce' => $probe->int(1, 1_000_000)],
                    tenant: $tenant,
                ),
                reader: static fn (?string $tenantId): array => (
                    $tenantId === null
                        ? AuditLog::withoutTenantScope()
                        : AuditLog::forTenant($tenantId)
                )->get()->all(),
            ),
            new TenantOwnedSubject(
                model: AbuseEvent::class,
                maxRowsPerTenant: 6,
                appendOnly: true,
                sumColumn: 'content_length',
                mutableColumn: 'surface',
                writer: static function (Tenant $tenant, int $index, self $probe): Model {
                    $event = app(AbuseRecorder::class)->record(new AbuseEventDraft(
                        vector: $probe->pick(AbuseVector::cases()),
                        action: $probe->pick([GuardAction::Flag, GuardAction::Block]),
                        signals: [AbuseSignal::CustomPattern],
                        evidence: ['rule' => 'probe', 'index' => $index],
                        surface: 'probe',
                        contentLength: $probe->int(1, 400),
                    ));

                    if (! $event instanceof AbuseEvent) {
                        // record() never throws; it returns null when the insert failed.
                        // A generator that shrugged that off would silently seed fewer
                        // rows than the property believes it did.
                        throw new RuntimeException('AbuseRecorder could not write a probe row.');
                    }

                    return $event;
                },
                reader: static fn (?string $tenantId): array => (
                    $tenantId === null
                        ? AbuseEvent::withoutTenantScope()
                        : AbuseEvent::forTenant($tenantId)
                )->get()->all(),
            ),
        ];

        $keyed = [];

        foreach ($subjects as $subject) {
            $keyed[$subject->model] = $subject;
        }

        return $keyed;
    }
}
