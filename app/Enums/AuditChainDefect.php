<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a chain verifier found wrong at one position of an audit chain
 * (Req 24.5 / D1, Correctness Property 17).
 *
 * Each case names a *distinguishable* class of tampering, because "the chain is
 * broken" is not an actionable finding for whoever has to investigate it. The
 * mapping from tampering to defect is what the property test asserts:
 *
 * | Tampering                                  | Defect reported                       |
 * |--------------------------------------------|---------------------------------------|
 * | any hashed field of a row was changed      | `RowHashMismatch`                     |
 * | a row was removed from the middle          | `SequenceGap` (then hashes still link)|
 * | a row was inserted, or a link re-pointed   | `PrevHashMismatch`                    |
 * | two rows were swapped                      | `RowHashMismatch` at the first of them|
 * | the first row was replaced or removed      | `GenesisMismatch` / `SequenceGap`     |
 * | rows were removed from the end             | `TailTruncated` (needs an anchor)     |
 * | an exported anchor disagrees with the row   | `AnchorMismatch`                      |
 * | a row's chain_key ≠ its tenant attribution | `ChainKeyMismatch`                    |
 */
enum AuditChainDefect: string
{
    /** The row's stored `row_hash` is not the hash of its own contents. */
    case RowHashMismatch = 'ROW_HASH_MISMATCH';

    /** The row does not link to the hash of the row before it. */
    case PrevHashMismatch = 'PREV_HASH_MISMATCH';

    /** Sequence numbers are not 1..N without gaps — a row is missing or moved. */
    case SequenceGap = 'SEQUENCE_GAP';

    /** The first row of the chain does not start from the genesis hash. */
    case GenesisMismatch = 'GENESIS_MISMATCH';

    /** Entries after a previously exported anchor are gone. */
    case TailTruncated = 'TAIL_TRUNCATED';

    /** An exported anchor names a different hash than the stored row. */
    case AnchorMismatch = 'ANCHOR_MISMATCH';

    /** The row's chain does not match its tenant attribution. */
    case ChainKeyMismatch = 'CHAIN_KEY_MISMATCH';

    /**
     * Whether this defect proves rows were *removed* rather than altered — the
     * cases that need an external anchor or a gap to be visible at all.
     */
    public function isLoss(): bool
    {
        return match ($this) {
            self::SequenceGap, self::TailTruncated => true,
            self::RowHashMismatch, self::PrevHashMismatch, self::GenesisMismatch,
            self::AnchorMismatch, self::ChainKeyMismatch => false,
        };
    }
}
