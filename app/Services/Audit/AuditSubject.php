<?php

declare(strict_types=1);

namespace App\Services\Audit;

use Illuminate\Database\Eloquent\Model;

/**
 * What an audit entry is *about* (Req 24.2 / D1).
 *
 * A polymorphic pair rather than a relation: the subject may live in any table, and
 * an audit row must outlive the row it describes — "tenant 01H… was offboarded" has
 * to stay readable after that tenant is gone, so there is no foreign key.
 *
 * `AuditService::write()` accepts a model directly and calls `of()` for you, so the
 * common case reads `write('tenant.suspended', subject: $tenant)`. Building one by
 * hand is for subjects that are not Eloquent rows at all (a plan key, a queue lane, a
 * WhatsApp session id owned by the bridge).
 */
final readonly class AuditSubject
{
    public function __construct(
        public string $type,
        public ?string $id = null,
    ) {}

    /**
     * The subject of an Eloquent row: its class and primary key.
     *
     * The class name is stored, not the table: it survives a table rename and is what
     * the Admin audit viewer needs to resolve a link back to the record.
     */
    public static function of(Model $model): self
    {
        $key = $model->getKey();

        return new self($model::class, is_int($key) || is_string($key) ? (string) $key : null);
    }

    /**
     * Normalize whatever a caller passed as `subject`.
     */
    public static function from(Model|self|string|null $subject): ?self
    {
        return match (true) {
            $subject === null => null,
            $subject instanceof self => $subject,
            $subject instanceof Model => self::of($subject),
            default => new self($subject),
        };
    }

    /**
     * The tenant this subject belongs to, when it is a tenant-owned row — the hint
     * that lets `write()` pick the right chain without the caller naming a tenant.
     */
    public static function tenantIdOf(Model|self|string|null $subject): ?string
    {
        if (! $subject instanceof Model) {
            return null;
        }

        $tenantId = $subject->getAttribute('tenant_id');

        return is_string($tenantId) && $tenantId !== '' ? $tenantId : null;
    }
}
