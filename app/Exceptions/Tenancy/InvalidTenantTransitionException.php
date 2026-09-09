<?php

declare(strict_types=1);

namespace App\Exceptions\Tenancy;

use App\Enums\TenantStatus;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * A lifecycle transition that the tenant state machine does not allow — **409**
 * (Req 1.1 / A1; design.md §"Tenant lifecycle").
 *
 * `TenantStatus::allowedNext()` is the whole rule set; this exception is what happens
 * when a caller asks for an edge that is not in it — resurrecting a `CANCELLED`
 * tenant, or "reactivating" one that was never suspended.
 *
 * ## Why this is an exception and not a `false`
 *
 * An illegal transition is a bug in the caller, not a business outcome: the admin
 * screen, the dunning job, and the self-service flow each know which edge they mean
 * before they ask. Returning `false` would let the mistake pass as "nothing happened",
 * and the states involved (suspension, cancellation) are precisely the ones where
 * silently doing nothing is dangerous — a dunning job that believed it suspended a
 * tenant would keep letting it send. A same-state call is the one exception: it is a
 * legitimate retry, so `TenantLifecycle` treats it as an idempotent success and never
 * reaches this class.
 *
 * ## What ends up in a message
 *
 * Statuses and the allowed edges — all of them public, operator-facing vocabulary —
 * plus the tenant's own id. Nothing here is another tenant's data, so unlike
 * `CrossTenantAccessException` there is nothing to fingerprint; the id is what makes
 * the log line actionable. Clients still only ever see `PUBLIC_MESSAGE`.
 */
final class InvalidTenantTransitionException extends RuntimeException implements HttpExceptionInterface
{
    /**
     * HTTP status: the request is well-formed, it conflicts with the tenant's state.
     */
    public const int STATUS = 409;

    /**
     * The only sentence a client is ever shown.
     */
    public const string PUBLIC_MESSAGE = 'That account status change is not allowed from the current status.';

    /**
     * Stable machine-readable code for API clients and the panel.
     */
    public const string ERROR_CODE = 'invalid_tenant_transition';

    private function __construct(
        public readonly TenantStatus $from,
        public readonly TenantStatus $to,
        public readonly string $tenantId,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * An edge that is simply not in the state machine.
     */
    public static function between(TenantStatus $from, TenantStatus $to, string $tenantId): self
    {
        if ($from->isTerminal()) {
            return self::fromTerminal($from, $to, $tenantId);
        }

        return new self($from, $to, $tenantId, sprintf(
            'Tenant %s cannot move from %s to %s. Allowed from %s: %s.',
            $tenantId,
            $from->value,
            $to->value,
            $from->value,
            self::describe($from->allowedNext()),
        ));
    }

    /**
     * A transition out of a terminal state — `CANCELLED`, whose only remaining exit is
     * the verified hard-delete after the retention window (task 34.3), which is not a
     * status change.
     */
    public static function fromTerminal(TenantStatus $from, TenantStatus $to, string $tenantId): self
    {
        return new self($from, $to, $tenantId, sprintf(
            'Tenant %s is %s, which is terminal: no transition to %s (or anything else) is possible. '
            .'A cancelled tenant is offboarded, not revived — provision a new tenant instead.',
            $tenantId,
            $from->value,
            $to->value,
        ));
    }

    public function getStatusCode(): int
    {
        return self::STATUS;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return [];
    }

    /**
     * The sentence that may be shown to a client.
     */
    public function publicMessage(): string
    {
        return self::PUBLIC_MESSAGE;
    }

    /**
     * The edges that *were* available — what a panel offers as the next action.
     *
     * @return array<int, TenantStatus>
     */
    public function allowedNext(): array
    {
        return $this->from->allowedNext();
    }

    /**
     * @param  array<int, TenantStatus>  $statuses
     */
    private static function describe(array $statuses): string
    {
        if ($statuses === []) {
            return 'nothing';
        }

        return implode(', ', array_map(static fn (TenantStatus $status): string => $status->value, $statuses));
    }
}
