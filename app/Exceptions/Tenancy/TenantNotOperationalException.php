<?php

declare(strict_types=1);

namespace App\Exceptions\Tenancy;

use App\Enums\TenantStatus;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * A tenant that is not operational (`SUSPENDED` or `CANCELLED`) tried to do something
 * only an operational tenant may do — **403** (Req 1.1 / A1; Req 10.3 / B1;
 * design.md §"Tenant lifecycle": *suspension blocks all outbound, keeps inbound
 * logging, panels read-only*).
 *
 * Two call sites raise it, and the distinction is worth keeping in the message
 * because the operator response differs:
 *
 * - **outbound** — the send gate refused a message. Nothing leaves the platform for a
 *   suspended tenant, ever; the job is blocked rather than deferred, because the
 *   condition is not going to clear on its own like a rate limit would.
 * - **mutation** — a panel write was refused. The tenant can still *read* everything;
 *   this is the read-only half of suspension.
 *
 * Inbound recording is deliberately **not** among them: a suspended tenant keeps its
 * inbound log (Req 10.3), so the inbound path asks
 * `TenantLifecycle::canRecordInbound()` and `canAutoReply()` and never sees this
 * exception for a suspended tenant.
 *
 * The tenant id in the message is the caller's own, so — unlike
 * `CrossTenantAccessException` — there is nothing to fingerprint. Clients still only
 * ever see `PUBLIC_MESSAGE`.
 */
final class TenantNotOperationalException extends RuntimeException implements HttpExceptionInterface
{
    public const int STATUS = 403;

    /**
     * The only sentence a client is ever shown.
     */
    public const string PUBLIC_MESSAGE = 'This account is suspended: it can be viewed but not used to send or change anything.';

    /**
     * Stable machine-readable code for API clients and the panel.
     */
    public const string ERROR_CODE = 'tenant_not_operational';

    private function __construct(
        public readonly string $tenantId,
        public readonly ?TenantStatus $status,
        public readonly string $intent,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * The send gate's refusal — the single choke point of Algorithm 3 / task 9.3.
     */
    public static function outboundBlocked(string $tenantId, TenantStatus $status): self
    {
        return new self($tenantId, $status, 'send outbound messages', sprintf(
            'Tenant %s is %s: outbound sending is blocked. Inbound messages are still recorded; '
            .'reactivate the tenant to resume sending.',
            $tenantId,
            $status->value,
        ));
    }

    /**
     * A panel write refused while the tenant is read-only.
     */
    public static function mutationBlocked(string $tenantId, TenantStatus $status, string $intent): self
    {
        return new self($tenantId, $status, $intent, sprintf(
            'Tenant %s is %s: the panel is read-only, so [%s] is refused.',
            $tenantId,
            $status->value,
            self::redact($intent),
        ));
    }

    /**
     * A guard was asked about a tenant id that resolves to no tenant at all.
     *
     * Fails closed for the same reason `TenantScope` does: an unresolvable tenant is
     * the one case where guessing is unrecoverable. A deleted tenant whose queued jobs
     * are still draining lands here, and must not send.
     */
    public static function unknownTenant(string $tenantId, string $intent): self
    {
        return new self($tenantId, null, $intent, sprintf(
            'No tenant %s exists, so [%s] is refused: a lifecycle guard has no status to trust.',
            $tenantId,
            self::redact($intent),
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
     * Keep caller-supplied text out of logs verbatim, exactly as the other tenancy
     * exceptions do it.
     */
    private static function redact(string $value): string
    {
        return mb_strimwidth(addcslashes($value, "\0..\37\177"), 0, 120, '…');
    }
}
