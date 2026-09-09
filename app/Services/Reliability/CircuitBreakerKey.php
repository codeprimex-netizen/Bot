<?php

declare(strict_types=1);

namespace App\Services\Reliability;

use App\Enums\CircuitScope;
use App\Models\CircuitBreaker as CircuitBreakerRecord;
use InvalidArgumentException;

/**
 * A validated `(scope, name)` breaker identity — the one thing every entry point of
 * `App\Services\Reliability\CircuitBreaker` takes (Req 31.3 / NFR2, Algorithm 7).
 *
 * The service accepts a raw `string` scope as well as a `CircuitScope` (design.md's
 * interface signature says `string`), so *something* has to turn loose input into a
 * checked key exactly once. That is this class, and it also gives the fail-fast
 * exception a redacted rendering of the key to put in a message.
 *
 * ```php
 * $key = CircuitBreakerKey::for(CircuitScope::Provider, CircuitBreaker::compositeName('openai', $tenantId));
 *
 * $key->toString();   // 'provider:openai:01JABCDEF…'
 * $key->redacted();   // 'provider:openai:#4f2a9c1b'
 * $key->cacheKey();   // 'provider:openai:01JABCDEF…'
 * ```
 */
final readonly class CircuitBreakerKey
{
    /**
     * Matches `circuit_breakers.name` (a `string(191)`, sized for InnoDB's utf8mb4
     * key limit). Checked here rather than left to the database, because MySQL's
     * behaviour on an over-long value depends on `sql_mode` — it may truncate, and a
     * truncated key would silently merge two breakers into one.
     */
    public const int MAX_NAME_LENGTH = 191;

    private function __construct(
        public CircuitScope $scope,
        public string $name,
    ) {}

    /**
     * Build a key, accepting the scope as an enum or as its raw value.
     *
     * @throws InvalidArgumentException when the scope is not a known family, or the
     *                                  name is empty or longer than the column
     */
    public static function for(CircuitScope|string $scope, string $name): self
    {
        $resolved = $scope instanceof CircuitScope ? $scope : CircuitScope::tryFrom($scope);

        if (! $resolved instanceof CircuitScope) {
            throw new InvalidArgumentException(sprintf(
                'Unknown circuit breaker scope [%s]. Known families: %s.',
                self::redact(is_string($scope) ? $scope : ''),
                implode(', ', CircuitScope::values()),
            ));
        }

        $trimmed = trim($name);

        if ($trimmed === '') {
            throw new InvalidArgumentException(sprintf(
                'A %s circuit breaker needs a name — the dependency it guards (a provider, '
                .'a gateway, a session), optionally with the tenant appended via '
                .'CircuitBreaker::compositeName().',
                $resolved->value,
            ));
        }

        if (mb_strlen($trimmed) > self::MAX_NAME_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'Circuit breaker name is %d characters, over the %d the column holds: [%s]. '
                .'Two breakers whose names differ only past that point would share one row.',
                mb_strlen($trimmed),
                self::MAX_NAME_LENGTH,
                self::redact($trimmed),
            ));
        }

        return new self($resolved, $trimmed);
    }

    /**
     * The full key, as `CircuitBreaker::key()` renders it on a persisted row.
     */
    public function toString(): string
    {
        return $this->scope->value.CircuitBreakerRecord::NAME_SEPARATOR.$this->name;
    }

    /**
     * Cache key for this breaker's snapshot, within the breaker cache's namespace.
     */
    public function cacheKey(): string
    {
        return $this->toString();
    }

    /**
     * The key as it may appear in an exception message or a log line.
     *
     * The first name segment is the dependency itself (`openai`, `razorpay`, a session
     * id) and is kept verbatim — an operator needs to know *what* is down. Every later
     * segment is a tenant id appended by `CircuitBreaker::compositeName()`, so it is
     * fingerprinted the way `CrossTenantAccessException` fingerprints one: two lines
     * about the same tenant's breaker still correlate, without the id itself travelling
     * into a log or an HTTP error.
     */
    public function redacted(): string
    {
        $segments = explode(CircuitBreakerRecord::NAME_SEPARATOR, $this->name);
        $head = self::redact((string) array_shift($segments));

        $tail = array_map(
            static fn (string $segment): string => self::fingerprint($segment),
            $segments,
        );

        return $this->scope->value.CircuitBreakerRecord::NAME_SEPARATOR
            .implode(CircuitBreakerRecord::NAME_SEPARATOR, [$head, ...$tail]);
    }

    public function equals(self $other): bool
    {
        return $this->scope === $other->scope && $this->name === $other->name;
    }

    /**
     * A short, stable, non-reversible stand-in for an identifier.
     */
    private static function fingerprint(string $id): string
    {
        return $id === '' ? '<none>' : '#'.substr(hash('sha256', $id), 0, 8);
    }

    /**
     * Keep untrusted input out of logs verbatim: control characters escaped, length
     * bounded — the same treatment `CrossTenantAccessException` gives a field name.
     */
    private static function redact(string $value): string
    {
        return mb_strimwidth(addcslashes($value, "\0..\37\177"), 0, 120, '…');
    }
}
