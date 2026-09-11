<?php

declare(strict_types=1);

namespace App\Exceptions\Tenancy;

use App\Services\Tenancy\QuotaResumer;
use RuntimeException;

/**
 * A hold names a resumer that cannot be resolved — so its parked work cannot be handed
 * back (Req 20.3 / C3; Req 31.1 / NFR2).
 *
 * Raised by `QuotaResumerRegistry` and caught by `QuotaParkingLot`, which records it on
 * the hold and leaves the work **parked**. That is the whole point of it being an
 * exception rather than a boolean: "no handler" must never be mistaken for "handed back",
 * because the second closes the hold and drops the campaign.
 *
 * Every instance is a deployment mistake, and the message says which: a resumer key that
 * was removed from `wa.tenancy.quota.holds.resumers` while holds still named it, a class
 * that no longer exists, or a class that does not implement `QuotaResumer`.
 */
final class UnknownQuotaResumerException extends RuntimeException
{
    private function __construct(
        public readonly string $resumer,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * The key is not in `wa.tenancy.quota.holds.resumers` at all.
     */
    public static function notRegistered(string $resumer): self
    {
        return new self($resumer, sprintf(
            'No quota resumer is registered under "%s": parked work naming it stays paused until '
            .'wa.tenancy.quota.holds.resumers maps it to a %s implementation.',
            $resumer,
            QuotaResumer::class,
        ));
    }

    /**
     * The key is registered, but what it names is not usable.
     */
    public static function notAResumer(string $resumer, string $class): self
    {
        return new self($resumer, sprintf(
            'The quota resumer registered under "%s" (%s) is not a %s, so parked work naming it cannot be resumed.',
            $resumer,
            $class,
            QuotaResumer::class,
        ));
    }
}
