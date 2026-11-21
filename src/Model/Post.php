<?php

declare(strict_types=1);

namespace App\Model;

final readonly class Post
{
    public function __construct(
        public string $id,
        public string $authorId,
        public string $authorName,
        public string $title,
        public string $slug,
        public string $excerpt,
        public string $bodyMd,
        public ?string $coverImage,
        public PostStatus $status,
        public int $upvotes,
        public ?string $publishedAt,
        public string $updatedAt,
    ) {
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (string) $row['id'],
            (string) $row['author_id'],
            (string) $row['author_name'],
            (string) $row['title'],
            (string) $row['slug'],
            (string) $row['excerpt'],
            (string) $row['body_md'],
            $row['cover_image'] === null ? null : (string) $row['cover_image'],
            PostStatus::from((string) $row['status']),
            (int) $row['upvotes'],
            $row['published_at'] === null ? null : (string) $row['published_at'],
            (string) $row['updated_at'],
        );
    }
}
