<?php

declare(strict_types=1);

namespace App\Services\Chatbot\Rag;

/**
 * The vector-store seam (design.md § AI 1.1; task 13.2 implements it).
 *
 * **Task 4.6 owns exactly one thing about this interface: its shape.** Every method that
 * touches vectors takes a `VectorFilter`, which cannot exist without a tenant — so the
 * design's STRIDE mitigation for this boundary, *"payload filter `tenant_id` on every
 * query"*, is a property of the signature rather than a rule an implementation is trusted
 * to follow. `VectorFilterTest` pins that shape; `StrideBoundaryCoverageTest` fails if a
 * later change loosens it.
 *
 * There is **no implementation here on purpose**. A stub would be a lie in the shape of
 * working code (and task 39.4's completeness scan fails the build on one); the real
 * drivers — `QdrantVectorStore`, `PgvectorVectorStore`, `MysqlVectorStore` — plus the
 * test-only `FakeVectorStore` arrive with task 13.2, and `Retriever` (13.5) is what calls
 * them. Until then this file is a contract and a guarded one, which is the honest state
 * of this boundary: the tenant filter is enforced by construction, and nothing is yet
 * wired behind it.
 *
 * ## Deviations from the interface sketched in design.md, and why
 *
 * ```php
 * // design.md § AI 1.1
 * public function search(int $tenantId, array $queryVector, int $k, array $filter = []): array;
 * ```
 *
 * 1. **`int $tenantId` → carried by `VectorFilter`.** Two problems with the sketch. It is
 *    the wrong type — tenants are ULID strings in this build (`tenants.id`), never
 *    integers — and, more importantly, a tenant id *next to* a free `$filter` array leaves
 *    the implementation responsible for translating one into the other. That translation
 *    is precisely the step that gets forgotten, and forgetting it is silent.
 * 2. **`array $filter = []` removed.** A defaulted filter argument means "no filter" is
 *    both expressible and the path of least resistance. Extra constraints go through
 *    `VectorFilter::with()`, which cannot drop the tenant term.
 *
 * Task 13.2 may add methods (health checks, batch delete by namespace, collection
 * management). It must not add one that takes a tenant id as a bare scalar or a filter as
 * a bare array.
 */
interface VectorStore
{
    /**
     * Insert or replace chunks in the filter's tenant namespace.
     *
     * @param  iterable<array{id: string, vector: list<float>, payload: array<string, mixed>}>  $chunks
     */
    public function upsert(VectorFilter $filter, iterable $chunks): void;

    /**
     * The `$k` nearest neighbours of `$queryVector` **within the filter**, ranked.
     *
     * @param  list<float>  $queryVector
     * @param  positive-int  $k
     * @return list<array{id: string, score: float, payload: array<string, mixed>}>
     */
    public function search(VectorFilter $filter, array $queryVector, int $k): array;

    /**
     * Delete chunks — the right-to-delete path (design.md § Compliance).
     *
     * @param  list<string>  $chunkIds
     */
    public function delete(VectorFilter $filter, array $chunkIds): void;

    /**
     * Which driver this is: `'qdrant'`, `'pgvector'`, or `'mysql'`.
     */
    public function driver(): string;
}
