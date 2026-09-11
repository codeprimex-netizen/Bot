<?php

declare(strict_types=1);

namespace App\Services\Channel;

use App\Models\ChannelCredential;

/**
 * What one `ChannelCredentialStore::put()` did — the facts the audit entry is built from,
 * carried out of the transaction without any secret material.
 *
 * It exists so the transaction closure can report five things at once (which row, whether it
 * was new, which secret fields are now sealed, which were removed, and whether the write
 * invalidated a previous driver verification) instead of returning a loose array the audit
 * builder would have to trust. Every field is either a name or a flag: `sealedFields` and
 * `removedFields` are **key names**, never values, which is what lets the payload be handed
 * to the append-only chain without a second thought.
 *
 * @see DatabaseChannelCredentialStore for why a secret value can never reach the audit trail
 */
final readonly class ChannelCredentialWrite
{
    /**
     * @param  ChannelCredential  $credential  the stored row — secret attribute already ciphertext
     * @param  bool  $created  true for an insert, false for an update in place
     * @param  list<string>  $sealedFields  secret field names now stored, sorted
     * @param  list<string>  $removedFields  secret field names this write removed, sorted
     * @param  array<string, mixed>  $config  the non-secret config now stored
     * @param  bool  $changed  whether config or secrets changed, and so `verified_at` was cleared
     */
    public function __construct(
        public ChannelCredential $credential,
        public bool $created,
        public array $sealedFields,
        public array $removedFields,
        public array $config,
        public bool $changed,
    ) {}
}
