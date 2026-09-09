<?php

declare(strict_types=1);

namespace App\Services\Security\Pii;

use App\Exceptions\Security\PiiRedactionException;
use Countable;

/**
 * The reversible half of Correctness Property 15: token → the exact text redaction
 * removed.
 *
 * This object is the most dangerous thing in the PII layer, because it is a
 * plaintext PII store. Everything about it is therefore built to make its **lifetime
 * a single unit of work** and to make any attempt to extend that lifetime fail
 * loudly rather than quietly:
 *
 * - `__serialize()` throws. `serialize()`, `Cache::put()`, a queued job payload, a
 *   session, a database column — every route to persistence goes through it, and all
 *   of them now raise `PiiRedactionException` instead of writing a file that maps
 *   tokens back to phone numbers.
 * - It implements no `Arrayable`, `Jsonable`, or `JsonSerializable`, and holds its
 *   values in a private property, so `json_encode()` of a map yields `{}` rather than
 *   its contents — a map that leaks into an API response or a log context carries
 *   nothing.
 * - `__debugInfo()` reports the token *count* only, so `dd()`, `var_dump()`, and any
 *   dumper-based log formatter cannot print the values either.
 * - `tokens()` exposes token names; there is no method that lists values. The only
 *   way to read a value is to already know its token.
 *
 * What is deliberately *not* claimed: PHP strings cannot be reliably zeroed, so a
 * map's values live in the process's memory until it is garbage collected. The
 * mitigation is the same one `FieldCipher` uses for unwrapped DEKs — the holder is
 * request/job-scoped, so the process boundary bounds the exposure.
 */
final class TokenMap implements Countable
{
    /**
     * @param  array<string, string>  $tokens  token => plaintext
     */
    private function __construct(private array $tokens = []) {}

    public static function empty(): self
    {
        return new self;
    }

    /**
     * Bind a token to the text it replaced.
     *
     * Re-binding the same token to the same value is a no-op (the same phone number
     * appearing twice in one message reuses its token). Re-binding it to a *different*
     * value means two units of work are sharing a map, which would rehydrate one
     * conversation's reply with another's data — so that is an error, not a silent
     * overwrite.
     *
     * @throws PiiRedactionException
     */
    public function put(string $token, string $plaintext): void
    {
        $existing = $this->tokens[$token] ?? null;

        if ($existing !== null && $existing !== $plaintext) {
            throw PiiRedactionException::tokenCollision($token);
        }

        $this->tokens[$token] = $plaintext;
    }

    /**
     * The text a token stands for, or `null` when this map never issued it.
     *
     * `null` is the important case: an unknown token is left alone by rehydration
     * rather than guessed at, which is what makes a stale or forged token inert.
     */
    public function plaintextFor(string $token): ?string
    {
        return $this->tokens[$token] ?? null;
    }

    public function has(string $token): bool
    {
        return isset($this->tokens[$token]);
    }

    /**
     * The tokens this map can resolve — names only, never values.
     *
     * @return list<string>
     */
    public function tokens(): array
    {
        return array_keys($this->tokens);
    }

    /**
     * A new map able to resolve both sides' tokens.
     *
     * Used when a caller redacts several strings (a system prompt, a history, a
     * question) and has to rehydrate one reply that may reference any of them.
     *
     * @throws PiiRedactionException on a token bound to two different values
     */
    public function merge(self $other): self
    {
        $merged = new self($this->tokens);

        foreach ($other->tokens as $token => $plaintext) {
            $merged->put($token, $plaintext);
        }

        return $merged;
    }

    public function isEmpty(): bool
    {
        return $this->tokens === [];
    }

    public function count(): int
    {
        return count($this->tokens);
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws PiiRedactionException always
     */
    public function __serialize(): array
    {
        throw PiiRedactionException::notPersistable();
    }

    /**
     * @param  array<array-key, mixed>  $data
     *
     * @throws PiiRedactionException always
     */
    public function __unserialize(array $data): void
    {
        throw PiiRedactionException::notPersistable();
    }

    /**
     * @return array<string, int|string>
     */
    public function __debugInfo(): array
    {
        return ['tokens' => count($this->tokens), 'values' => '[redacted]'];
    }
}
