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

    /**
     * Every slug that is $base or starts with "$base-" (deleted rows count: a slug stays reserved after a soft delete).
     *
     * @return list<string>
     */
    public function slugsLike(string $base): array;

    /**
     * Which of these upload paths a post that is not deleted still uses, as its cover or inside its body. One query for all paths.
     *
     * @param list<string> $paths
     * @return list<string> the subset still in use
     */
    public function uploadsInUse(array $paths): array;

    /**
     * Inserts the post and its vote-counter row in one transaction.
     *
     * @param array{author_id:string,title:string,slug:string,excerpt:string,body_md:string,cover_image:?string,status:string,published_at:?string} $data
     * @return string the new post UUID
     */
    public function create(array $data): string;

    /** @param array{title:string,excerpt:string,body_md:string,cover_image:?string,status:string,published_at:?string} $data */
    public function update(string $id, array $data): void;

    /** Soft delete; $deletedBy is the acting user (audit). @return bool true when a row was soft-deleted */
    public function softDelete(string $id, string $deletedBy): bool;
}
