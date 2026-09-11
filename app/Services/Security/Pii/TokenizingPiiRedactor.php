<?php

declare(strict_types=1);

namespace App\Services\Security\Pii;

use App\Exceptions\Security\PiiRedactionException;
use App\Services\Tenancy\TenantContext;
use App\Support\Pii\PiiScanner;
use App\Support\Pii\PiiSpan;

/**
 * The reversible redactor of Correctness Property 15: PII out, tokens in, and the
 * exact text back again for the customer.
 *
 * ## Token shape
 *
 * `[[PII:PHONE:kfhdambp:3]]` — a fenced literal, the kind of value it replaced, a
 * per-instance nonce, and an index. Each part earns its place:
 *
 * - **the kind** keeps the prompt meaningful: a model can write "I'll text you on
 *   `[[PII:PHONE:kfhdambp:3]]`" and the rehydrated reply reads naturally, which an
 *   opaque `[[REDACTED]]` makes impossible;
 * - **the nonce** is fresh random per unit of work, and it is what makes token
 *   lifetime *structural*. A token minted in an earlier request cannot be resolved by
 *   this one — not because a check refuses it, but because this instance has never
 *   heard of that nonce. A caller who kept masked text across a request boundary gets
 *   the token left intact, never somebody else's value;
 * - **the index** is per instance and reused for repeat values, so the same phone
 *   number mentioned three times reads as one entity to the model.
 *
 * The alphabet is letters `a`–`p` (hex nibbles mapped to letters) precisely so a
 * token contains no long digit run: a token must not itself look like PII to the
 * scanner, to a log processor, or to Property 15's own assertions.
 *
 * ## Lifetime
 *
 * Bound `scoped()` in the container — one instance per request and per queued job,
 * discarded at the boundary, exactly like `FieldCipher`'s unwrapped DEK cache. On top
 * of that:
 *
 * - `forget()` re-keys the nonce and drops the map, so a long-running worker can
 *   scope a map to one *message* instead of one job;
 * - a change of bound tenant does `forget()` automatically, so a worker that moves
 *   from tenant A to tenant B cannot rehydrate B's reply with A's data.
 *
 * The map is never written anywhere: `TokenMap` refuses to serialize, and nothing in
 * this class caches, queues, or logs it.
 */
final class TokenizingPiiRedactor implements PiiRedactor
{
    /**
     * Fits `PiiScanner::TOKEN_PATTERN`'s six-digit index. Unreachable for a message;
     * a caller that gets here is feeding the redactor something else entirely.
     */
    private const int MAX_TOKENS = 999_999;

    /**
     * How deep `redactStructure()` will walk before refusing.
     */
    private const int MAX_DEPTH = 16;

    private string $nonce;

    private TokenMap $map;

    /**
     * @var array<string, string> "KIND\0value" => token, so a repeated value reuses
     *                            its token
     */
    private array $tokenByValue = [];

    private int $issued = 0;

    private bool $tenantBound = false;

    private ?string $boundTenantId = null;

    public function __construct(
        private readonly PiiScanner $scanner,
        private readonly TenantPiiPatternSource $patterns,
        private readonly TenantContext $tenants,
    ) {
        $this->nonce = self::mintNonce();
        $this->map = TokenMap::empty();
    }

    public function redact(string $text): RedactionResult
    {
        $this->syncTenant();

        if ($text === '') {
            return new RedactionResult($text, TokenMap::empty());
        }

        $spans = $this->scanner->scan($text, $this->patterns->patternsFor($this->tenants->current()));
        $call = TokenMap::empty();
        $masked = $text;

        // Replace from the end: every earlier offset stays valid, so no arithmetic on
        // shifting positions and no chance of corrupting multi-byte text.
        foreach (array_reverse($spans) as $span) {
            $token = $this->tokenFor($span);
            $masked = substr_replace($masked, $token, $span->start, $span->length());
            $call->put($token, $span->text);
        }

        return new RedactionResult($masked, $call);
    }

    public function rehydrate(string $text, TokenMap $map): string
    {
        if ($text === '' || $map->isEmpty()) {
            return $text;
        }

        $restored = preg_replace_callback(
            PiiScanner::TOKEN_PATTERN,
            static function (array $match) use ($map): string {
                $token = (string) $match[0];

                // An unknown token is left verbatim. It is either stale (another
                // request's nonce), forged, or literal text the customer typed — and
                // none of those may be answered with a value from this map.
                return $map->plaintextFor($token) ?? $token;
            },
            $text,
        );

        if ($restored === null) {
            throw PiiRedactionException::rehydrationFailed(preg_last_error());
        }

        return $restored;
    }

    public function redactStructure(array $payload): array
    {
        $this->syncTenant();

        /** @var array<array-key, mixed> $walked */
        $walked = $this->walk($payload, 0);

        return $walked;
    }

    public function currentMap(): TokenMap
    {
        return $this->map;
    }

    public function forget(): void
    {
        $this->nonce = self::mintNonce();
        $this->map = TokenMap::empty();
        $this->tokenByValue = [];
        $this->issued = 0;
        $this->tenantBound = false;
        $this->boundTenantId = null;
    }

    /**
     * @throws PiiRedactionException
     */
    private function walk(mixed $value, int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            throw PiiRedactionException::tooDeep(self::MAX_DEPTH);
        }

        if (is_array($value)) {
            $result = [];

            foreach ($value as $key => $item) {
                $result[$key] = $this->walk($item, $depth + 1);
            }

            return $result;
        }

        if (is_string($value)) {
            return $this->redact($value)->masked;
        }

        return $value;
    }

    /**
     * The token for one span — reusing the existing one when this unit of work has
     * already seen the same value in the same role.
     *
     * @throws PiiRedactionException
     */
    private function tokenFor(PiiSpan $span): string
    {
        $key = $span->kind->value."\0".$span->text;
        $token = $this->tokenByValue[$key] ?? null;

        if ($token === null) {
            if ($this->issued >= self::MAX_TOKENS) {
                throw PiiRedactionException::tokenBudgetExhausted(self::MAX_TOKENS);
            }

            $this->issued++;
            $token = sprintf('[[PII:%s:%s:%d]]', $span->kind->value, $this->nonce, $this->issued);
            $this->tokenByValue[$key] = $token;
        }

        $this->map->put($token, $span->text);

        return $token;
    }

    /**
     * Forget everything if the unit of work has changed tenant.
     *
     * A queue worker keeps a scoped instance for the length of one job, but a job that
     * legitimately spans tenants (a platform sweep) would otherwise carry tenant A's
     * token map into tenant B's message. Re-keying on the boundary makes that
     * impossible instead of merely unlikely.
     */
    private function syncTenant(): void
    {
        $current = $this->tenants->currentId();

        if ($this->tenantBound && $current !== $this->boundTenantId) {
            $this->forget();
        }

        $this->tenantBound = true;
        $this->boundTenantId = $current;
    }

    /**
     * Eight letters of fresh randomness: hex, mapped onto `a`–`p` so the nonce can
     * never contribute a digit to a token.
     */
    private static function mintNonce(): string
    {
        return strtr(bin2hex(random_bytes(4)), '0123456789abcdef', 'abcdefghijklmnop');
    }
}
