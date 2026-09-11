<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Enums\AuditActorType;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;

/**
 * Who an audit entry is attributed to (Req 24.2 / D1).
 *
 * Callers rarely build one by hand: `AuditService::write()` resolves the actor from
 * the authenticated request when none is passed, which is what keeps the audit call
 * at a business call site down to one line. The named constructors exist for the
 * cases where the actor is *not* whoever is logged in — impersonation (the admin is
 * the actor, the impersonated user is the subject), queued work continuing a human's
 * action, and the platform-mode listener.
 */
final readonly class AuditActor
{
    /**
     * @param  string|null  $id  the actor's primary key, `null` for `System`
     * @param  string|null  $label  a display identity captured at write time (email/name),
     *                              so the entry stays readable after the account changes
     */
    public function __construct(
        public AuditActorType $type,
        public ?string $id = null,
        public ?string $label = null,
    ) {}

    /**
     * Work nobody requested: schedulers, queue workers, webhook intake, console.
     */
    public static function system(?string $label = null): self
    {
        return new self(AuditActorType::System, null, $label);
    }

    public static function user(User|string $user, ?string $label = null): self
    {
        return $user instanceof User
            ? new self(AuditActorType::User, (string) $user->getKey(), $label ?? $user->email)
            : new self(AuditActorType::User, $user, $label);
    }

    /**
     * A platform super-admin. Same identity table as a user today; a distinct actor
     * type because the *authority* is different, and the audit trail is a record of
     * authority exercised (Req 24.1 / D1).
     */
    public static function admin(User|string $admin, ?string $label = null): self
    {
        return $admin instanceof User
            ? new self(AuditActorType::Admin, (string) $admin->getKey(), $label ?? $admin->email)
            : new self(AuditActorType::Admin, $admin, $label);
    }

    /**
     * The actor behind the current request, or `System` when there is nobody.
     *
     * Guards are consulted in descending authority and **only if configured**: the
     * `platform-admin` guard arrives with task 30.1, and this must not break before
     * then. `$platformMode` biases an otherwise ordinary session towards `Admin`,
     * because opening the audited scope bypass is something only a super-admin can do.
     */
    public static function fromAuth(bool $platformMode = false): self
    {
        foreach (['platform-admin', 'admin'] as $guard) {
            if (! self::guardExists($guard)) {
                continue;
            }

            $user = Auth::guard($guard)->user();

            if ($user !== null) {
                return new self(AuditActorType::Admin, self::identifierOf($user), self::labelOf($user));
            }
        }

        $user = Auth::hasUser() ? Auth::user() : null;

        if ($user === null) {
            return self::system();
        }

        return new self(
            $platformMode ? AuditActorType::Admin : AuditActorType::User,
            self::identifierOf($user),
            self::labelOf($user),
        );
    }

    private static function guardExists(string $guard): bool
    {
        $guards = config('auth.guards');

        return is_array($guards) && array_key_exists($guard, $guards);
    }

    private static function identifierOf(Authenticatable $user): ?string
    {
        $id = $user->getAuthIdentifier();

        return is_int($id) || is_string($id) ? (string) $id : null;
    }

    private static function labelOf(Authenticatable $user): ?string
    {
        if ($user instanceof User) {
            return $user->email;
        }

        return null;
    }
}
