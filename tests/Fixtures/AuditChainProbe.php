<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Enums\AuditActorType;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Services\Audit\AuditActor;
use App\Services\Audit\AuditSubject;

/**
 * The input generator for the Correctness Property 17 property test: random audit
 * chains, and a reproducible source of the draws that shaped them.
 *
 * ## Why not `fake()` / `random_int()`
 *
 * A property test is only useful if a failure can be replayed, and the house
 * generators cannot be replayed: `random_int()` is a CSPRNG with no seed, and seeding
 * `mt_srand()` for `fake()` would also seed the model factories — where
 * `fake()->unique()->company()` would start colliding across iterations and throw
 * before the property was ever evaluated.
 *
 * So this class carries its own draw counter and derives every value from
 * `sha256(seed:draw)`. One seed therefore fixes the whole test: chain count, chain
 * lengths, payload shapes, actors, subjects, the mutation kind, and the position it is
 * applied at. The seed is printed in every failure message, and setting
 * `AUDIT_CHAIN_SEED` replays it:
 *
 * ```
 * AUDIT_CHAIN_SEED=140737488355328 vendor/bin/pest --filter='localizes any single-row tamper'
 * ```
 *
 * Tenant *names* still come from the unseeded factory faker, so a replay is identical
 * in every respect the property depends on and not byte-identical in the ones it does
 * not.
 */
final class AuditChainProbe
{
    /**
     * Set this to replay a failed run.
     */
    public const string SEED_ENV = 'AUDIT_CHAIN_SEED';

    /**
     * Every way one row of a chain can be tampered with, as an attacker who has got
     * past the append-only grants and triggers would do it.
     *
     * Enumerated rather than sampled: the *kind* is the one dimension small enough to
     * cover exhaustively, so the test walks all of them (in a seed-shuffled order)
     * while every other dimension is drawn at random. A randomly sampled kind would
     * leave roughly one kind in three unexercised on any given run — and a coverage
     * assertion over sampled kinds is just a flake waiting for a slow afternoon.
     *
     * @var non-empty-list<string>
     */
    public const array TAMPER_KINDS = [
        'payload',              // the hashed payload is rewritten
        'row_hash',             // the row's own hash is replaced
        'prev_hash',            // the link to the previous row is re-pointed
        'actor',                // "who did it" is rewritten
        'subject',              // "what it was done to" is rewritten
        'timestamp',            // "when" is rewritten
        'tenant_move',          // the row is re-attributed to another tenant
        'delete_middle',        // a row is removed from the middle, leaving a gap
        'reorder_swap',         // two rows are swapped into each other's positions
        'append_forged_tail',   // a plausible row with a wrong hash is appended
        'recompute_tail_link',  // a row is rewritten *and* its own hash repaired
    ];

    private int $draws = 0;

    public function __construct(public readonly int $seed) {}

    /**
     * A fresh seed, or the one named by `AUDIT_CHAIN_SEED` for a replay.
     */
    public static function seeded(): self
    {
        $override = getenv(self::SEED_ENV);

        if (is_string($override) && ctype_digit($override)) {
            return new self((int) $override);
        }

        return new self(random_int(1, 2 ** 48));
    }

    /*
    |--------------------------------------------------------------------------
    | Draws
    |--------------------------------------------------------------------------
    */

    /**
     * An integer in `[$min, $max]`.
     */
    public function int(int $min, int $max): int
    {
        if ($max <= $min) {
            return $min;
        }

        return $min + $this->draw() % (($max - $min) + 1);
    }

    public function bool(int $percent = 50): bool
    {
        return $this->int(1, 100) <= $percent;
    }

    /**
     * @template TValue
     *
     * @param  non-empty-list<TValue>  $values
     * @return TValue
     */
    public function pick(array $values): mixed
    {
        return $values[$this->int(0, count($values) - 1)];
    }

    /**
     * A Fisher-Yates shuffle from the seeded stream, so even the order the tamper
     * kinds are exercised in varies between runs and is still replayable.
     *
     * @template TValue
     *
     * @param  list<TValue>  $values
     * @return list<TValue>
     */
    public function shuffled(array $values): array
    {
        for ($index = count($values) - 1; $index > 0; $index--) {
            $swap = $this->int(0, $index);
            [$values[$index], $values[$swap]] = [$values[$swap], $values[$index]];
        }

        return $values;
    }

    /**
     * A well-formed SHA-256 that is not any real row's hash — the "plausible but
     * wrong" value a forger would write.
     */
    public function forgedHash(): string
    {
        return hash('sha256', 'forged:'.$this->seed.':'.$this->draw());
    }

    /*
    |--------------------------------------------------------------------------
    | Chains
    |--------------------------------------------------------------------------
    */

    /**
     * Append `$count` honest entries to one chain, each with a randomly shaped
     * payload, actor, subject, and action.
     *
     * @return list<AuditLog>
     */
    public function chain(int $count, Tenant|string|null $tenant = null): array
    {
        $entries = [];

        for ($index = 1; $index <= $count; $index++) {
            $action = $this->action();
            $payload = $this->payload($index);
            $actor = $this->actor();
            $subject = $this->subject();

            $entries[] = $tenant === null
                ? Audit::service()->writeForPlatform($action, $payload, $subject, $actor)
                : Audit::service()->write($action, $payload, $subject, $actor, $tenant);
        }

        return $entries;
    }

    /**
     * A payload whose *shape*, not just its values, varies: mixed scalar types, nested
     * maps and lists, unicode, keys deliberately inserted out of order (so the
     * canonical key sort is exercised by every chain), and keys the audit redactor
     * rewrites on the way in — because the hash must cover the redacted form, and a
     * payload that changed shape between hashing and storing would show up here as a
     * phantom tamper.
     *
     * @return array<string, mixed>
     */
    public function payload(int $index): array
    {
        $payload = [
            'index' => $index,
            'nonce' => $this->int(0, 1_000_000),
            'ratio' => $this->int(1, 9_999) / 7,
            'label' => 'entry-'.$this->int(1_000, 9_999),
            'enabled' => $this->bool(),
            'nothing' => null,
        ];

        if ($this->bool(60)) {
            $payload['nested'] = [
                'depth' => ['value' => $this->int(0, 999), 'items' => [1, 'two', 3.5, null, false]],
            ];
        }

        if ($this->bool(40)) {
            $payload['unicode'] = 'ünïcødé-'.$this->int(1, 99).'-«/»-\\';
        }

        if ($this->bool(35)) {
            // Inserted zeta-before-alpha on purpose: two payloads that differ only in
            // key order must hash identically.
            $payload['zeta'] = $this->int(1, 9);
            $payload['alpha'] = ['10' => 'numeric-ish key', 'x' => [[], ['']]];
        }

        if ($this->bool(30)) {
            $payload['api_key'] = 'sk-live-'.$this->int(100_000, 999_999);
        }

        if ($this->bool(30)) {
            $payload['phone'] = '+91 98765 '.$this->int(10_000, 99_999);
        }

        return $payload;
    }

    /**
     * The forged payload a tamper writes — carrying a key no generated payload has, so
     * a rewrite can never accidentally reproduce the value it replaced.
     */
    public function forgedPayloadJson(int $position): string
    {
        return (string) json_encode([
            '__tampered_at' => $position,
            '__by' => 'seed:'.$this->seed,
            'index' => -$position,
        ]);
    }

    private function action(): string
    {
        return $this->pick([
            'tenant.suspended',
            'user.impersonated',
            'plan.limits.changed',
            'platform_mode.entered',
            'group.settings.changed',
            'session.credentials.rotated',
            'billing.wallet.topped_up',
            'compliance.export.requested',
        ]);
    }

    private function actor(): AuditActor
    {
        $type = $this->pick(AuditActorType::cases());

        if ($type === AuditActorType::System) {
            return AuditActor::system($this->bool() ? 'queue:'.$this->pick(['default', 'dispatch', 'maintenance']) : null);
        }

        return new AuditActor(
            $type,
            (string) $this->int(1, 999_999),
            sprintf('%s%d@example.test', strtolower($type->value), $this->int(1, 99)),
        );
    }

    private function subject(): ?AuditSubject
    {
        if (! $this->bool(75)) {
            return null;
        }

        return new AuditSubject(
            $this->pick([Tenant::class, AuditLog::class, 'wa.session', 'queue.lane', 'plan.key']),
            $this->bool(85) ? 'subject-'.$this->int(1_000, 999_999) : null,
        );
    }

    /**
     * A 48-bit draw derived from the seed and the draw index.
     */
    private function draw(): int
    {
        $this->draws++;

        return (int) hexdec(substr(hash('sha256', $this->seed.':'.$this->draws), 0, 12));
    }
}
