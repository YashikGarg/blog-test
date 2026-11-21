<?php

declare(strict_types=1);

namespace App\Model;

final readonly class Comment
{
    public function __construct(
        public string $id,
        public string $postId,
        public string $postSlug,
        public string $userId,
        public string $userName,
        public string $body,
        public string $createdAt,
        public string $updatedAt,
    ) {
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (string) $row['id'],
            (string) $row['post_id'],
            (string) $row['post_slug'],
            (string) $row['user_id'],
            (string) $row['user_name'],
            (string) $row['body'],
            (string) $row['created_at'],
            (string) $row['updated_at'],
        );
    }

    public function isEdited(): bool
    {
        return $this->updatedAt > $this->createdAt;
    }
}
