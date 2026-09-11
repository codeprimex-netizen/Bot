<?php

declare(strict_types=1);

use App\Enums\AuditChainDefect;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Services\Audit\AuditChainFinding;
use App\Support\Database\AppendOnlyTable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Audit;
use Tests\Fixtures\AuditChainProbe;
use Tests\Fixtures\AuditTamper;

/*
|--------------------------------------------------------------------------
| Correctness Property 17 — audit-log hash-chain integrity
|--------------------------------------------------------------------------
| design.md: *"∀ audit row i>0 → `row_hash_i = H(row_hash_{i-1} || canonical(payload_i))`;
| any mutation of a past row breaks the chain and is detectable by a verifier."*
|
| **Validates: Requirements 24.2, 24.5 / D1**
|
| `AuditChainIntegrityTest` proves the property by example: one scripted chain, one
| scripted tamper, one asserted finding per case. This file states it as a property, and
| the difference is not "more randomness" — it is three claims the scripted cases cannot
| make:
|
|  1. **Localization, not just detection.** A verifier that returns "broken" for any
|     tamper anywhere is worthless for finding the tamper. So every iteration asserts the
|     *kind* of defect and the *position* it is reported at, derived from the drawn
|     mutation by an oracle written out below as a table — and asserts the finding *count*
|     wherever the tamper has a determinate blast radius, which is what stops a verifier
|     from passing by shouting.
|  2. **Chain isolation.** Chains are per tenant plus one platform chain, so a tamper in
|     tenant A must be reported in tenant A and *nowhere else*. Every iteration re-verifies
|     every chain that has never been touched — including all of the previous iterations'
|     and the platform chain — and requires them intact. A verifier that failed everything
|     on any tamper would pass claim 1 and fail this one.
|  3. **It is genuinely a chain.** The `recompute_tail_link` kind rewrites a row *and
|     repairs its own hash*, the way an attacker who has read the service would. That row
|     is then internally perfect, and the tamper is still caught — at the *next* row, whose
|     `prev_hash` still commits to the old value. If `prev_hash` were not a prefix of the
|     hash this kind would verify clean, and Property 17 would be false.
|
| The control matters as much as the failures: a property test that only ever asserts
| "verification fails" passes against a verifier hardcoded to return "invalid". So every
| chain is verified **clean** before it is tampered with, and lengths from 0 upward are
| included so the empty and single-row chains are covered by the control too.
|
| **Reproducibility.** One seed fixes every draw in a test — chain count, lengths, payload
| shapes, actors, subjects, tamper kind, position. It is printed in every failure message,
| and `AUDIT_CHAIN_SEED=<seed> vendor/bin/pest --filter='<test name>'` replays it. See
| `Tests\Fixtures\AuditChainProbe` for why the house `fake()`/`random_int()` generators
| could not be used here.
|
| **Tampering at all** requires getting under four layers of append-only enforcement
| (model, builder, database triggers, production grants). `Tests\Fixtures\AuditTamper`
| does that by dropping the trigger guards around raw SQL and putting them back in a
| `finally`; both tests below finish by asserting that a raw `UPDATE`/`DELETE` is *still*
| refused, so a helper that leaked the guards away could not pass here and quietly void
| `AuditLogAppendOnlyTest` for the rest of the suite. Every tamper kind is plain DML and
| reproducible on both engines — none is gated on the driver.
*/

it('localizes any single-row tamper to the row and the chain it happened in', function (): void {
    $probe = AuditChainProbe::seeded();
    $service = Audit::service();

    // Chains that have never been tampered with, as chain key => expected length. Every
    // iteration re-verifies all of them: this is the cross-chain isolation claim, and it
    // grows stricter as the test runs.
    $untouched = [];

    // A platform chain, built once and never targeted here — the bystander that proves a
    // tenant's tamper is not reported against the one chain that has no tenant. It is
    // targeted directly in the next test.
    $platformLength = $probe->int(2, 8);
    $probe->chain($platformLength);
    $untouched[AuditLog::PLATFORM_CHAIN] = $platformLength;

    // Two passes over every tamper kind, in a seed-shuffled order.
    $kinds = [
        ...$probe->shuffled(AuditChainProbe::TAMPER_KINDS),
        ...$probe->shuffled(AuditChainProbe::TAMPER_KINDS),
    ];

    foreach ($kinds as $iteration => $kind) {
        /*
        |----------------------------------------------------------------------
        | Draw the shape: several tenants, one chain each
        |----------------------------------------------------------------------
        | A degenerate chain (0 or 1 entries) every iteration, so the empty and
        | single-row cases are inside the control and the isolation assertions rather
        | than in a scripted test of their own — and it doubles as the second tenant
        | `tenant_move` re-attributes a row to, which has to be a *real* tenant other
        | than the one whose chain the row sits in.
        */
        $outsider = Tenant::factory()->create();
        $outsiderLength = $probe->int(0, 1);
        $probe->chain($outsiderLength, $outsider);

        $keys = [$outsider->id => $outsiderLength];

        // At least one chain long enough for every tamper kind, then a random number more.
        $first = Tenant::factory()->create();
        $firstLength = $probe->int(2, 8);
        $probe->chain($firstLength, $first);

        $keys[$first->id] = $firstLength;
        $targets = [$first->id];

        for ($extra = $probe->int(0, 2); $extra > 0; $extra--) {
            $tenant = Tenant::factory()->create();
            $tenantLength = $probe->int(2, 8);
            $probe->chain($tenantLength, $tenant);

            $keys[$tenant->id] = $tenantLength;
            $targets[] = $tenant->id;
        }

        // ---- the control: every chain, old and new, verifies clean -----------------
        foreach ([...$untouched, ...$keys] as $key => $expectedLength) {
            $clean = $service->verifyChain((string) $key);

            expect($clean->isIntact())->toBeTrue(sprintf(
                'seed %d, iteration %d: an untampered chain [%s] failed verification — %s',
                $probe->seed,
                $iteration,
                $key,
                $clean->summary(),
            ))
                ->and($clean->entriesChecked)->toBe($expectedLength)
                ->and($clean->tipSequence)->toBe($expectedLength);
        }

        // ---- draw the target and the tamper ---------------------------------------
        $chainKey = $probe->pick($targets);
        $length = $keys[$chainKey];

        // Kinds whose break is not at the row they touch need a row after it; the
        // appended row sits one past the tip.
        $position = match ($kind) {
            'delete_middle', 'recompute_tail_link', 'reorder_swap' => $probe->int(1, $length - 1),
            'append_forged_tail' => $length + 1,
            default => $probe->int(1, $length),
        };
        $swapWith = $probe->int(min($position + 1, $length), $length);

        $where = sprintf(
            'seed %d, iteration %d, kind [%s] at position %d of the %d-entry chain [%s]',
            $probe->seed,
            $iteration,
            $kind,
            $position,
            $length,
            $chainKey,
        );

        if ($kind === 'reorder_swap') {
            $where .= sprintf(' (swapped with position %d)', $swapWith);
        }

        match ($kind) {
            'payload' => AuditTamper::mutate($chainKey, $position, [
                'payload' => $probe->forgedPayloadJson($position),
            ]),
            'row_hash' => AuditTamper::mutate($chainKey, $position, ['row_hash' => $probe->forgedHash()]),
            'prev_hash' => AuditTamper::mutate($chainKey, $position, ['prev_hash' => $probe->forgedHash()]),
            'actor' => AuditTamper::mutate($chainKey, $position, [
                'actor_id' => 'forged-'.$probe->int(1, 999),
                'actor_label' => 'someone.else@example.test',
            ]),
            'subject' => AuditTamper::mutate($chainKey, $position, [
                'subject_type' => 'App\\Models\\Forged',
                'subject_id' => 'forged-subject-'.$probe->int(1, 999),
            ]),
            'timestamp' => AuditTamper::mutate($chainKey, $position, [
                'created_at' => '2019-03-04 05:06:07.008009',
            ]),
            // Re-attributed to a real other tenant, not a made-up id, so `chain_key` and
            // `tenant_id` disagree the way they would if a row had been quietly moved.
            'tenant_move' => AuditTamper::mutate($chainKey, $position, ['tenant_id' => $outsider->id]),
            'delete_middle' => AuditTamper::remove($chainKey, $position),
            'reorder_swap' => AuditTamper::swap($chainKey, $position, $swapWith),
            'append_forged_tail' => AuditTamper::insertAtTail($chainKey),
            'recompute_tail_link' => AuditTamper::mutateAndRehash($chainKey, $position, [
                'payload' => $probe->forgedPayloadJson($position),
            ]),
            default => throw new LogicException('Unhandled tamper kind ['.$kind.'].'),
        };

        /*
        |----------------------------------------------------------------------
        | The oracle
        |----------------------------------------------------------------------
        | What the verifier must report, derived from the drawn tamper rather than
        | read off the result. Every row of this table is a claim about *where* the
        | break surfaces:
        |
        | kind                 | first defect            | at position | findings
        | ---------------------|-------------------------|-------------|---------
        | payload              | ROW_HASH_MISMATCH       | p           | 1
        | actor                | ROW_HASH_MISMATCH       | p           | 1
        | subject              | ROW_HASH_MISMATCH       | p           | 1
        | timestamp            | ROW_HASH_MISMATCH       | p           | 1
        | tenant_move          | ROW_HASH_MISMATCH       | p           | 2 (+CHAIN_KEY)
        | row_hash             | ROW_HASH_MISMATCH       | p           | 2 (+PREV_HASH at p+1), 1 at the tip
        | prev_hash            | PREV_HASH / GENESIS     | p           | 2 (+ROW_HASH at p)
        | delete_middle        | SEQUENCE_GAP            | p           | 1
        | reorder_swap         | PREV_HASH / GENESIS     | p           | ≥2, cascading
        | append_forged_tail   | PREV_HASH_MISMATCH      | L+1         | 2 (+ROW_HASH)
        | recompute_tail_link  | PREV_HASH_MISMATCH      | p+1         | 1
        |
        | Two entries are worth reading twice. `prev_hash` and `reorder_swap` report
        | GENESIS_MISMATCH rather than PREV_HASH_MISMATCH at position 1, because
        | "row 1 does not start from genesis" is the specific finding for "the first
        | row was replaced". And `recompute_tail_link` is reported at **p+1**, not p:
        | the rewritten row is internally consistent, and it is its successor's link
        | that refuses to move.
        */
        $expectedSequence = match ($kind) {
            'recompute_tail_link' => $position + 1,
            default => $position,
        };

        $expectedDefect = match ($kind) {
            'payload', 'actor', 'subject', 'timestamp', 'tenant_move', 'row_hash' => AuditChainDefect::RowHashMismatch,
            'delete_middle' => AuditChainDefect::SequenceGap,
            'prev_hash', 'reorder_swap' => $position === 1
                ? AuditChainDefect::GenesisMismatch
                : AuditChainDefect::PrevHashMismatch,
            default => AuditChainDefect::PrevHashMismatch,
        };

        $alsoExpected = match ($kind) {
            'tenant_move' => [AuditChainDefect::ChainKeyMismatch],
            'prev_hash', 'reorder_swap', 'append_forged_tail' => [AuditChainDefect::RowHashMismatch],
            'row_hash' => $position < $length ? [AuditChainDefect::PrevHashMismatch] : [],
            default => [],
        };

        // `null` means "cascades, so the count is not part of the claim" — true only of
        // a reorder, where every row between the swapped pair links to the wrong hash.
        $expectedFindings = match ($kind) {
            'payload', 'actor', 'subject', 'timestamp', 'delete_middle', 'recompute_tail_link' => 1,
            'prev_hash', 'tenant_move', 'append_forged_tail' => 2,
            'row_hash' => $position < $length ? 2 : 1,
            default => null,
        };

        $expectedChecked = match ($kind) {
            'delete_middle' => $length - 1,
            'append_forged_tail' => $length + 1,
            default => $length,
        };

        // ---- 1. detection, localized ----------------------------------------------
        $result = $service->verifyChain($chainKey);
        $defects = array_map(
            static fn (AuditChainFinding $finding): string => $finding->defect->value,
            $result->findings,
        );

        expect($result->isIntact())->toBeFalse($where.' went undetected entirely.')
            ->and($result->firstFinding()?->defect)->toBe($expectedDefect, sprintf(
                '%s: expected first defect %s, got %s.',
                $where,
                $expectedDefect->value,
                implode('+', $defects) === '' ? '(none)' : implode('+', $defects),
            ))
            ->and($result->firstBrokenSequence())->toBe($expectedSequence, sprintf(
                '%s: expected the first break at position %d, reported at %s.',
                $where,
                $expectedSequence,
                $result->firstBrokenSequence() === null ? '(none)' : (string) $result->firstBrokenSequence(),
            ))
            ->and($result->firstFinding()?->chainKey)->toBe($chainKey)
            ->and($result->entriesChecked)->toBe($expectedChecked, $where.': wrong number of rows walked.')
            ->and($result->summary())->toContain('FAILED');

        foreach ($alsoExpected as $defect) {
            // `in_array` rather than `toContain`, which is variadic and would read a
            // failure message as a second expected needle.
            expect(in_array($defect->value, $defects, true))->toBeTrue(sprintf(
                '%s: expected %s among the findings, got %s.',
                $where,
                $defect->value,
                implode('+', $defects),
            ));
        }

        if ($expectedFindings === null) {
            expect(count($result->findings))->toBeGreaterThanOrEqual(2, $where.': a reorder breaks at least two links.');
        } else {
            expect($result->findings)->toHaveCount($expectedFindings, sprintf(
                '%s: expected exactly %d finding(s), got %d (%s).',
                $where,
                $expectedFindings,
                count($result->findings),
                implode('+', $defects),
            ));
        }

        // Losing a row is a different claim from altering one, and the report says which.
        expect($result->firstFinding()?->defect->isLoss())->toBe($kind === 'delete_middle', $where);

        // ---- 2. isolation: no other chain is implicated ---------------------------
        foreach ($untouched as $key => $expectedLength) {
            $elsewhere = $service->verifyChain((string) $key);

            expect($elsewhere->isIntact())->toBeTrue(sprintf(
                '%s: the untouched chain [%s] was implicated too — %s',
                $where,
                $key,
                $elsewhere->summary(),
            ))
                ->and($elsewhere->entriesChecked)->toBe($expectedLength);
        }

        foreach ($keys as $key => $expectedLength) {
            if ((string) $key === $chainKey) {
                continue;
            }

            $sibling = $service->verifyChain((string) $key);

            expect($sibling->isIntact())->toBeTrue(sprintf(
                '%s: the sibling chain [%s] was implicated too — %s',
                $where,
                $key,
                $sibling->summary(),
            ));

            // From here on it is one of the chains every later iteration must leave alone.
            $untouched[(string) $key] = $expectedLength;
        }
    }

    // Twenty-two tampers, and the guards the helper had to lift are all back.
    expect(fn () => DB::table('audit_logs')->update(['action' => 'rewritten']))
        ->toThrow(QueryException::class, AppendOnlyTable::MESSAGE)
        ->and(fn () => DB::table('audit_logs')->delete())
        ->toThrow(QueryException::class, AppendOnlyTable::MESSAGE);
});

it('detects a tamper in the platform chain without implicating a single tenant chain', function (): void {
    // The platform chain is the one chain with no tenant, and the one a tenant-scoped
    // reader can never see (`tenant_id IS NULL`). It is verified by the same walk, so it
    // must be tamper-evident on the same terms — and a forged platform row must not make
    // any tenant's history look forged.
    $probe = AuditChainProbe::seeded();
    $service = Audit::service();

    $platformLength = $probe->int(3, 9);
    $probe->chain($platformLength);

    $tenants = [];

    foreach (range(1, $probe->int(2, 4)) as $ignored) {
        $tenant = Tenant::factory()->create();
        $probe->chain($probe->int(1, 6), $tenant);
        $tenants[] = $tenant;
    }

    // The control, through the tenant-facing entry point rather than by chain key.
    expect($service->verify()->isIntact())->toBeTrue();

    foreach ($tenants as $tenant) {
        expect($service->verify($tenant)->isIntact())->toBeTrue();
    }

    $position = $probe->int(1, $platformLength);
    $kind = $probe->pick(['payload', 'actor', 'timestamp']);

    AuditTamper::mutate(AuditLog::PLATFORM_CHAIN, $position, match ($kind) {
        'payload' => ['payload' => $probe->forgedPayloadJson($position)],
        'actor' => ['actor_label' => 'super.admin.impostor@example.test'],
        default => ['created_at' => '2018-07-06 05:04:03.002001'],
    });

    $where = sprintf(
        'seed %d: platform-chain %s tamper at position %d of %d',
        $probe->seed,
        $kind,
        $position,
        $platformLength,
    );

    $result = $service->verify();

    expect($result->isIntact())->toBeFalse($where.' went undetected.')
        ->and($result->chainKey)->toBe(AuditLog::PLATFORM_CHAIN)
        ->and($result->firstBrokenSequence())->toBe($position, $where)
        ->and($result->firstFinding()?->defect)->toBe(AuditChainDefect::RowHashMismatch, $where)
        ->and($result->findings)->toHaveCount(1, $where);

    foreach ($tenants as $tenant) {
        $bystander = $service->verify($tenant);

        expect($bystander->isIntact())->toBeTrue(sprintf(
            '%s: tenant chain [%s] was implicated — %s',
            $where,
            $tenant->id,
            $bystander->summary(),
        ));
    }

    expect(fn () => DB::table('audit_logs')->update(['action' => 'rewritten']))
        ->toThrow(QueryException::class, AppendOnlyTable::MESSAGE);
});

it('cannot see a truncated tail on its own, and needs a witnessed anchor to', function (): void {
    /*
    | The honest limit of the construction, asserted rather than glossed over.
    |
    | A hash chain proves nothing was altered, moved, duplicated, or removed from the
    | *middle*. It cannot prove nothing was cut off the *end*: dropping the last N rows
    | leaves a chain that is internally perfect and merely shorter. Every hash-chained
    | log has this property, and the same is true of the strongest single-row tamper
    | there is — rewriting the **tip** and repairing its hash, since no later row's
    | `prev_hash` exists to contradict it.
    |
    | `AuditChainAnchor` is what closes both gaps, and it works by being stored where the
    | attacker is not: exported to a monitoring system, a compliance mailbox, or
    | append-only object storage. So this test asserts, for random chain lengths and
    | random truncation depths, that verification is *clean* without an anchor and
    | reports TAIL_TRUNCATED / ANCHOR_MISMATCH with one.
    */
    $probe = AuditChainProbe::seeded();
    $service = Audit::service();

    foreach (range(1, 8) as $iteration) {
        $length = $probe->int(3, 9);
        // Including `$length` itself: the whole chain erased is still a truncation.
        $dropped = $probe->int(1, $length);
        $tenant = Tenant::factory()->create();

        $probe->chain($length, $tenant);

        $anchor = $service->anchor($tenant);
        $where = sprintf(
            'seed %d, iteration %d: %d of %d entries dropped from the tail of [%s]',
            $probe->seed,
            $iteration,
            $dropped,
            $length,
            $tenant->id,
        );

        expect($anchor->sequence)->toBe($length, $where)
            ->and($anchor->isEmpty())->toBeFalse();

        AuditTamper::truncateTail($tenant->id, $dropped);

        // Unanchored: nothing to see. This is the claim the design makes, stated as an
        // assertion so it cannot quietly stop being true.
        $blind = $service->verify($tenant);

        expect($blind->isIntact())->toBeTrue($where.': a truncated chain is internally consistent — '.$blind->summary())
            ->and($blind->entriesChecked)->toBe($length - $dropped)
            ->and($blind->tipSequence)->toBe($length - $dropped);

        // Anchored: the loss is named, at the position the anchor witnessed.
        $anchored = $service->verify($tenant, $anchor);

        expect($anchored->isIntact())->toBeFalse($where.': the anchor did not notice the truncation.')
            ->and($anchored->firstFinding()?->defect)->toBe(AuditChainDefect::TailTruncated, $where)
            ->and($anchored->firstFinding()?->defect->isLoss())->toBeTrue()
            ->and($anchored->firstBrokenSequence())->toBe($length, $where)
            ->and($anchored->tipSequence)->toBe($length - $dropped)
            ->and($anchored->findings)->toHaveCount(1, $where);
    }

    foreach (range(1, 8) as $iteration) {
        // The tip rewritten *and* its hash repaired: the one alteration the chain alone
        // cannot catch, for the same reason truncation is invisible.
        $length = $probe->int(2, 9);
        $tenant = Tenant::factory()->create();

        $probe->chain($length, $tenant);

        $anchor = $service->anchor($tenant);
        $where = sprintf(
            'seed %d, iteration %d: tip of the %d-entry chain [%s] rewritten and rehashed',
            $probe->seed,
            $iteration,
            $length,
            $tenant->id,
        );

        AuditTamper::mutateAndRehash($tenant->id, $length, [
            'payload' => $probe->forgedPayloadJson($length),
        ]);

        expect($service->verify($tenant)->isIntact())->toBeTrue($where.': the chain alone cannot see this.');

        $anchored = $service->verify($tenant, $anchor);

        expect($anchored->isIntact())->toBeFalse($where.': the anchor did not notice the rewrite.')
            ->and($anchored->firstFinding()?->defect)->toBe(AuditChainDefect::AnchorMismatch, $where)
            ->and($anchored->firstBrokenSequence())->toBe($length, $where)
            ->and($anchored->firstFinding()?->expected)->toBe($anchor->rowHash);
    }

    expect(fn () => DB::table('audit_logs')->update(['action' => 'rewritten']))
        ->toThrow(QueryException::class, AppendOnlyTable::MESSAGE);
});
