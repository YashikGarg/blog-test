<?php

declare(strict_types=1);

namespace App\Repository;

use App\Model\Post;

interface PostRepositoryInterface
{
    /**
     * Keyset page of published posts, newest first. Bodies are not loaded (bodyMd is '').
     *
     * @param array{publishedAt:string,id:string}|null $before cursor of the last row already shown
     * @return list<Post>
     */
    public function findPublished(int $limit, ?string $search, ?array $before): array;

    /** Published posts only, with body. */
    public function findBySlug(string $slug): ?Post;

    /** Draft or published (never deleted), with body. */
    public function findById(string $id): ?Post;

    /**
     * Admin list: draft and published, newest first, keyset on the primary key.
     *
     * @return list<Post>
     */
    public function findForAdmin(?string $beforeId, int $limit): array;

    /** Slugs stay reserved after a soft delete, so deleted rows count too. */
    public function slugExists(string $slug, ?string $exceptId): bool;

    /**
     * Inserts the post and its vote-counter row in one transaction.
     *
     * @param array{author_id:string,title:string,slug:string,excerpt:string,body_md:string,cover_image:?string,status:string,published_at:?string} $data
     * @return string the new post UUID
     */
    public function create(array $data): string;

    /** @param array{title:string,excerpt:string,body_md:string,cover_image:?string,status:string,published_at:?string} $data */
    public function update(string $id, array $data): void;

    /** @return bool true when a row was soft-deleted */
    public function softDelete(string $id): bool;
}
