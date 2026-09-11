<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Security\Pii\PiiRedactor;
use App\Services\Tenancy\TenantContext;
use App\Support\Pii\LogPiiScrubber;
use App\Support\Pii\PiiKeyRules;
use Tests\Fixtures\Security\PiiProbe;

/*
|--------------------------------------------------------------------------
| Correctness Property 15 — PII never egresses raw to the LLM
|--------------------------------------------------------------------------
| design.md: *"∀ text t sent to an LLM/embedding provider → t contains no unmasked
| phone number, email, or card-like sequence (all replaced by reversible tokens), and
| rehydration restores the customer-facing reply exactly."*
|
| **Validates: Requirements 32.2 / NFR3, 13.7 / B4**
|
| ## What this adds over the scripted tests
|
| `tests/Unit/Services/Security/PiiRedactorTest` asserts the same two obligations over a
| **fixed nine-entry corpus**, and `tests/Feature/Security/PiiRedactorLifetimeTest`
| asserts the token map's lifetime. Both are necessary and neither is generative: every
| value in them is a value somebody chose, so they can only fail for a reason somebody
| already thought of. This file draws its PII instead — nine phone formats across seven
| digit scripts, six email shapes, Luhn-valid cards at four lengths and three groupings,
| and per-tenant patterns — and plants it at positions the corpus never visits: glued to
| words, next to punctuation, several to a string, inside nested structure, and
| **immediately next to another value with nothing but a separator between them**.
|
| That last shape is not decoration. It found a real leak: two grouped numbers written
| side by side (`"415-555-2671 415-555-2671"`) joined into one digit-group run, the run
| was rejected whole for exceeding E.164's 15 digits, and **both numbers egressed
| completely unredacted**. Formats with a group of more than five digits
| (`"0755-1234567"`) leaked the same way. `PiiScanner` now decomposes a run into the
| numbers inside it; these properties are what hold that closed.
|
| ## Why each clause is here
|
| A property test that only asserted "no PII in the output" would pass against a
| redactor that returned the empty string, so every iteration asserts **six** things:
| the output holds no PII *by detectors written independently of the ones under test*,
| the round trip is byte-exact, the surrounding non-PII text is still there, one token is
| minted per distinct value, redacting the output again changes nothing, and text holding
| no PII is returned untouched.
|
| ## Reproducibility
|
| One seed drives every draw and is printed in every failure message.
| `PII_EGRESS_SEED=<seed> vendor/bin/pest --filter='<test name>'` replays a failure
| exactly. The redactor's own per-instance nonce is *not* seeded, and deliberately so —
| nothing here asserts anything about a nonce's value, only that a token minted under one
| is unresolvable under another, which holds for every pair of distinct nonces.
*/

/**
 * The container's redactor, rebuilt so it picks up config a test has just set.
 */
function egressRedactor(): PiiRedactor
{
    app()->forgetScopedInstances();

    return app(PiiRedactor::class);
}

/**
 * A value as JSON, with escaping switched off on both sides of a round-trip comparison.
 *
 * `\u00e9` and `\/` would otherwise make an exact round trip look inexact: a token
 * rehydrates to the *literal* `josé`, which is not the bytes the original encoded to.
 */
function egressJson(mixed $value): string
{
    return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/*
| Substring assertions are written as `expect(str_contains(...))` throughout rather than
| with `toContain()`, because that matcher is variadic: a failure message passed to it
| becomes a second needle, and the assertion silently stops saying what it looks like it
| says. Every clause below needs to name the seed and the drawn value, so none of them
| can use it.
*/

/*
|--------------------------------------------------------------------------
| The property
|--------------------------------------------------------------------------
*/

it('never lets PII egress raw and always restores it exactly', function (): void {
    $probe = PiiProbe::seeded();

    // Coverage counters: a property test that never planted more than one value, or
    // never wrote two numbers side by side, would pass without exercising what it is for.
    $planted = 0;
    $adjacentPairs = 0;

    foreach (range(1, 14) as $iteration) {
        $tenant = Tenant::factory()->create(['slug' => 'egress-'.$iteration.'-'.$probe->letters(6)]);
        $custom = $probe->customPattern();

        config()->set('wa.security.pii.tenant_patterns', [$tenant->slug => [$custom['pattern']]]);
        app(TenantContext::class)->forget();
        app(TenantContext::class)->set($tenant);

        $redactor = egressRedactor();

        // One to four drawn values, sometimes including the tenant's own configured
        // shape, and sometimes the same value twice — a repeat must reuse its token
        // rather than mint a second one.
        $values = [];

        foreach (range(1, $probe->int(1, 4)) as $ignored) {
            $values[] = $probe->value();
        }

        if ($probe->chance(40)) {
            $values[] = $custom['value'];
        }

        if ($probe->chance(30)) {
            $values[] = $values[0];
        }

        $values = $probe->shuffleValues($values);
        ['text' => $text, 'anchors' => $anchors] = $probe->plant($values);

        $redactor->forget();
        $result = $redactor->redact($text);
        $planted += count($values);

        $where = sprintf('seed %d, iteration %d', $probe->seed, $iteration);
        $drawn = implode(', ', array_map(
            static fn (array $value): string => $value['kind'].' ('.$value['format'].') '.$value['text'],
            $values,
        ));
        $shown = sprintf('%sdrawn:  %s%sinput:  %s%smasked: %s', PHP_EOL, $drawn, PHP_EOL, $text, PHP_EOL, $result->masked);

        // ---- 1. no value egresses, in any of its formats ------------------------
        foreach ($values as $value) {
            expect(str_contains($result->masked, $value['text']))->toBeFalse(sprintf(
                '%s: %s %s survived redaction.%s',
                $where,
                $value['kind'],
                $value['format'],
                $shown,
            ));
        }

        // ---- 2. and nothing PII-shaped is left, by an independent judge ----------
        expect(PiiProbe::leaks($result->masked))->toBe([], sprintf(
            '%s: independent detectors still find PII.%s', $where, $shown,
        ));

        // ---- 3. the round trip is byte-exact ------------------------------------
        expect($redactor->rehydrate($result->masked, $result->map))->toBe($text, sprintf(
            '%s: rehydration did not restore the message.%s', $where, $shown,
        ));

        // ---- 4. the output is still a message, not a hole -----------------------
        // Without this clause the whole property is satisfied by returning ''.
        foreach ($anchors as $anchor) {
            expect(str_contains($result->masked, $anchor))->toBeTrue(sprintf(
                '%s: the surrounding non-PII text was destroyed.%s', $where, $shown,
            ));
        }

        // ---- 5. one token per distinct value ------------------------------------
        $distinct = count(array_unique(array_column($values, 'text')));

        expect($result->map)->toHaveCount($distinct, sprintf(
            '%s: %d distinct value(s) planted, %d token(s) minted.%s',
            $where,
            $distinct,
            count($result->map),
            $shown,
        ));

        // ---- 6. idempotence -----------------------------------------------------
        $again = $redactor->redact($result->masked);

        expect($again->masked)->toBe($result->masked, sprintf('%s: redacting redacted text changed it.', $where))
            ->and($again->map)->toHaveCount(0, sprintf('%s: redacting redacted text minted new tokens.', $where));

        // ---- 7. the adversarial shape: two values, separator only between them ---
        $first = $probe->phone();
        $second = $probe->phone();
        $adjacent = $probe->adjacent($first['text'], $second['text']);

        $redactor->forget();
        $pair = $redactor->redact($adjacent);
        $adjacentPairs++;

        $pairShown = sprintf(
            '%sfirst:  %s (%s)%ssecond: %s (%s)%sinput:  %s%smasked: %s',
            PHP_EOL, $first['text'], $first['format'],
            PHP_EOL, $second['text'], $second['format'],
            PHP_EOL, $adjacent,
            PHP_EOL, $pair->masked,
        );

        // Where the boundary between two abutting numbers falls is the redactor's
        // business, so the token count is deliberately not asserted here: a run that
        // legitimately reads as one number may mint one token covering both. What may
        // never happen is a value surviving, or a digit run being left behind.
        foreach ([$first, $second] as $value) {
            expect(str_contains($pair->masked, $value['text']))->toBeFalse(sprintf(
                '%s: %s %s survived when written next to another number.%s',
                $where,
                $value['kind'],
                $value['format'],
                $pairShown,
            ));
        }

        expect(PiiProbe::leaks($pair->masked))->toBe([], sprintf(
            '%s: two abutting numbers left PII behind.%s', $where, $pairShown,
        ))
            ->and($redactor->rehydrate($pair->masked, $pair->map))->toBe($adjacent, sprintf(
                '%s: rehydration lost an abutting pair.%s', $where, $pairShown,
            ));
    }

    expect($planted)->toBeGreaterThan(14, 'no iteration planted more than one value.')
        ->and($adjacentPairs)->toBe(14, 'the adjacency shape was not exercised every iteration.');
});

/*
|--------------------------------------------------------------------------
| The control: nothing that is not PII may be touched
|--------------------------------------------------------------------------
*/

it('returns text that holds no PII completely unchanged', function (): void {
    $probe = PiiProbe::seeded();
    $redactor = egressRedactor();

    foreach (range(1, 30) as $iteration) {
        $text = $probe->words($probe->int(3, 12));

        $result = $redactor->redact($text);

        expect($result->masked)->toBe($text, sprintf(
            'seed %d, iteration %d: text with no PII was altered.%sinput:  %s%smasked: %s',
            $probe->seed,
            $iteration,
            PHP_EOL,
            $text,
            PHP_EOL,
            $result->masked,
        ))
            ->and($result->map)->toHaveCount(0, sprintf(
                'seed %d, iteration %d: text with no PII minted %d token(s): %s',
                $probe->seed,
                $iteration,
                count($result->map),
                $text,
            ))
            ->and($result->isRedacted())->toBeFalse();
    }
});

/*
|--------------------------------------------------------------------------
| The carve-outs, asserted positively
|--------------------------------------------------------------------------
*/

it('leaves every documented carve-out exactly as it found it', function (): void {
    $probe = PiiProbe::seeded();
    $redactor = egressRedactor();

    foreach (range(1, 20) as $iteration) {
        foreach ($probe->carveOuts() as $label => $value) {
            // In a sentence, the way it would really arrive — a carve-out surrounded by
            // words has to survive the same scan a phone number in that position fails.
            $text = $probe->words(2).' '.$value.' '.$probe->words(2);
            $result = $redactor->redact($text);

            expect($result->masked)->toBe($text, sprintf(
                'seed %d, iteration %d: a %s (%s) was masked. This is a documented '
                .'carve-out, not a leak: widening it changes what an LLM can reason '
                .'about, so the fix is never to relax the assertion.%smasked: %s',
                $probe->seed,
                $iteration,
                $label,
                $value,
                PHP_EOL,
                $result->masked,
            ))
                ->and($result->map)->toHaveCount(0);
        }
    }
});

/*
|--------------------------------------------------------------------------
| Structured egress
|--------------------------------------------------------------------------
*/

it('masks every string in a nested payload and keeps its shape', function (): void {
    $probe = PiiProbe::seeded();

    foreach (range(1, 12) as $iteration) {
        $redactor = egressRedactor();

        $values = [];

        foreach (range(1, $probe->int(1, 3)) as $ignored) {
            $values[] = $probe->value();
        }

        $payload = $probe->structure($values);
        $masked = $redactor->redactStructure($payload);
        $encoded = egressJson($masked);
        $where = sprintf('seed %d, iteration %d', $probe->seed, $iteration);

        foreach ($values as $value) {
            expect(str_contains($encoded, $value['text']))->toBeFalse(sprintf(
                '%s: %s %s survived inside a structure — a text-only redactor is exactly '
                .'how structured egress leaks.%sencoded: %s',
                $where,
                $value['kind'],
                $value['format'],
                PHP_EOL,
                $encoded,
            ));
        }

        expect(PiiProbe::leaks($encoded))->toBe([], sprintf(
            '%s: structure held PII.%sencoded: %s', $where, PHP_EOL, $encoded,
        ))
            // Shape and non-string scalars survive untouched: a walker that coerces on
            // the way through returns `"3"` where `3` was, and the caller's schema stops
            // matching.
            ->and($masked['count'])->toBe($payload['count'])
            ->and($masked['flag'])->toBe($payload['flag'])
            ->and($masked['nothing'])->toBeNull()
            ->and($masked['note'])->toBe($payload['note'])
            ->and(array_keys((array) $masked['contact']))->toBe(array_keys((array) $payload['contact']));

        // Every token accumulated into one map, so one call restores any of them.
        foreach ($values as $index => $value) {
            $leaf = ((array) $masked['contact'])['field_'.$index];
            $original = ((array) $payload['contact'])['field_'.$index];

            expect($redactor->rehydrate(egressJson($leaf), $redactor->currentMap()))
                ->toBe(egressJson($original), sprintf(
                    '%s: leaf %d (%s) did not round-trip.', $where, $index, $value['format'],
                ));
        }
    }
});

/*
|--------------------------------------------------------------------------
| Tenant isolation
|--------------------------------------------------------------------------
*/

it('applies only the bound tenant’s patterns and never resolves another tenant’s token', function (): void {
    $probe = PiiProbe::seeded();

    foreach (range(1, 10) as $iteration) {
        $suffix = $probe->letters(6);
        $first = Tenant::factory()->create(['slug' => 'iso-a-'.$iteration.'-'.$suffix]);
        $second = Tenant::factory()->create(['slug' => 'iso-b-'.$iteration.'-'.$suffix]);

        $forFirst = $probe->customPattern();
        $forSecond = $probe->customPattern();

        // Two tenants that drew the same prefix would share a shape, which makes
        // "A's pattern must not redact for B" untestable rather than false. Redrawn
        // rather than skipped, and rather than the assertion being softened to tolerate
        // it — the seeded engine makes the redraw deterministic.
        while ($forSecond['pattern'] === $forFirst['pattern']) {
            $forSecond = $probe->customPattern();
        }

        config()->set('wa.security.pii.tenant_patterns', [
            $first->slug => [$forFirst['pattern']],
            $second->slug => [$forSecond['pattern']],
        ]);

        $text = $probe->words(2).' '.$forFirst['value']['text'].' and '.$forSecond['value']['text'].' '.$probe->words(2);
        $where = sprintf('seed %d, iteration %d', $probe->seed, $iteration);
        $tenants = app(TenantContext::class);

        $tenants->forget();
        $tenants->set($first);
        $redactor = egressRedactor();
        $forA = $redactor->redact($text);

        expect(str_contains($forA->masked, $forFirst['value']['text']))->toBeFalse(sprintf(
            '%s: a tenant’s own configured shape (%s) was not redacted for it.%smasked: %s',
            $where,
            $forFirst['pattern'],
            PHP_EOL,
            $forA->masked,
        ))
            // The other tenant's identifier shape is none of this tenant's business.
            ->and(str_contains($forA->masked, $forSecond['value']['text']))->toBeTrue(sprintf(
                '%s: tenant B’s pattern (%s) redacted for tenant A.%smasked: %s',
                $where,
                $forSecond['pattern'],
                PHP_EOL,
                $forA->masked,
            ))
            ->and($redactor->rehydrate($forA->masked, $forA->map))->toBe($text);

        // A change of bound tenant re-keys the tokeniser and drops the map, so the token
        // minted a moment ago is not a token any more — not refused, simply unknown.
        $tenants->forget();
        $tenants->set($second);
        $forB = $redactor->redact($text);

        expect(str_contains($forB->masked, $forSecond['value']['text']))->toBeFalse(sprintf(
            '%s: tenant B’s own shape (%s) was not redacted after the tenant changed.%smasked: %s',
            $where,
            $forSecond['pattern'],
            PHP_EOL,
            $forB->masked,
        ))
            ->and(str_contains($forB->masked, $forFirst['value']['text']))->toBeTrue(sprintf(
                '%s: tenant A’s pattern (%s) still applied after the tenant changed.%smasked: %s',
                $where,
                $forFirst['pattern'],
                PHP_EOL,
                $forB->masked,
            ))
            ->and($redactor->rehydrate($forA->masked, $redactor->currentMap()))
            ->toBe($forA->masked, sprintf(
                '%s: a token minted for tenant A resolved while tenant B was bound.', $where,
            ));
    }
});

/*
|--------------------------------------------------------------------------
| The opaque-identifier exemption is not a hole (task 4.6, PiiKeyRules)
|--------------------------------------------------------------------------
| The exemption exists because the bare-phone detector was eating digit runs out of
| SHA-256 digests and ULIDs, which destroyed the correlation handle a log line is
| written for. It needs *both* halves — an identifier key **and** an opaque-looking
| value — so the property has two directions, and asserting only the first would let the
| exemption become a way to write a phone number to a log under `id`.
*/

it('records an opaque identifier verbatim but still masks PII under the same key', function (): void {
    $probe = PiiProbe::seeded();
    $scrubber = app(LogPiiScrubber::class);

    foreach (range(1, 40) as $iteration) {
        $key = $probe->identifierKey();
        $where = sprintf('seed %d, iteration %d, key [%s]', $probe->seed, $iteration, $key);

        // Direction one: the evidence survives intact.
        $identifier = $probe->opaqueIdentifier();
        $recorded = (string) $scrubber->scrubPayload([$key => $identifier])[$key];

        expect(PiiKeyRules::isOpaqueIdentifier($key, $identifier))->toBeTrue(sprintf(
            '%s: the exemption did not apply to %s.', $where, $identifier,
        ))
            ->and($recorded)->toBe($identifier, sprintf(
                '%s: the identifier was mangled — %s became %s.', $where, $identifier, $recorded,
            ));

        // Direction two: the exemption is not a channel. A value with no letter in it, or
        // one carrying an `@`, is not an opaque machine token whatever its key says.
        $phone = $probe->phone();
        $email = $probe->email();

        foreach ([$phone, $email] as $value) {
            $written = (string) $scrubber->scrubPayload([$key => $value['text']])[$key];

            expect(PiiKeyRules::isOpaqueIdentifier($key, $value['text']))->toBeFalse(sprintf(
                '%s: %s (%s) was accepted as an opaque identifier.',
                $where,
                $value['kind'],
                $value['format'],
            ))
                ->and(str_contains($written, $value['text']))->toBeFalse(sprintf(
                    '%s: %s (%s) was written verbatim under an identifier key.%swritten: %s',
                    $where,
                    $value['kind'],
                    $value['format'],
                    PHP_EOL,
                    $written,
                ))
                // Judged on digits: the log mask deliberately keeps an email's domain, so
                // the full judge would flag correct output here (see `digitLeaks()`).
                ->and(PiiProbe::digitLeaks($written))->toBe([], sprintf(
                    '%s: %s (%s) left a digit run behind.%swritten: %s',
                    $where,
                    $value['kind'],
                    $value['format'],
                    PHP_EOL,
                    $written,
                ));
        }

        // The local part is the identity in an address; the domain is the operational
        // signal the mask is allowed to keep. Only its first character may survive, so a
        // JID's msisdn and a person's name are both gone.
        $written = (string) $scrubber->scrubPayload([$key => $email['text']])[$key];
        $at = strrpos($email['text'], '@');

        expect(str_contains($written, substr($email['text'], 0, $at === false ? 0 : $at)))->toBeFalse(sprintf(
            '%s: the local part of an address survived under an identifier key.%swritten: %s',
            $where,
            PHP_EOL,
            $written,
        ));
    }
});
