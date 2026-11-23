<?php

declare(strict_types=1);

namespace App\Repository;

use App\Model\Post;
use PDO;
use Symfony\Component\Uid\Uuid;

final readonly class PdoPostRepository implements PostRepositoryInterface
{
    // Static SQL pieces. The cursor and search clauses are appended by code; their values are always bound.
    // The post columns are written once: the body column goes between the two halves ('' for lists, the real text for one post).
    private const POST_COLUMNS_1 = 'SELECT BIN_TO_UUID(p.id) AS id, BIN_TO_UUID(p.author_id) AS author_id, u.name AS author_name,
        p.title, p.slug, p.excerpt, ';
    private const POST_COLUMNS_2 = ', p.cover_image, p.status,
        COALESCE(c.upvotes, 0) AS upvotes, p.published_at, p.updated_at
        FROM posts p';
    private const POST_JOINS = ' JOIN users u ON u.id = p.author_id LEFT JOIN post_vote_counts c ON c.post_id = p.id';

    /** List rows carry no body ('' AS body_md): cards do not need it. */
    public const POST_SELECT_LIST = self::POST_COLUMNS_1 . "'' AS body_md" . self::POST_COLUMNS_2 . self::POST_JOINS;
    /** One post with its body. */
    public const POST_SELECT_FULL = self::POST_COLUMNS_1 . 'p.body_md AS body_md' . self::POST_COLUMNS_2 . self::POST_JOINS;

    /** The list used for search: MySQL picks the FULLTEXT index itself. */
    public const LIST = self::POST_SELECT_LIST . " WHERE p.status = 'published'";
    /**
     * The newest-first list (no search). Nearly every post is 'published', so the optimizer judges the index no cheaper
     * than a scan and sorts every row (EXPLAIN: type ALL, Using filesort). With LIMIT the index is far cheaper,
     * so the status/date index is named: backward index scan, no filesort. Not used for search (it would hide the FULLTEXT index).
     */
    public const LIST_BY_DATE = self::POST_COLUMNS_1 . "'' AS body_md" . self::POST_COLUMNS_2
        . ' FORCE INDEX (idx_posts_status_published)' . self::POST_JOINS . " WHERE p.status = 'published'";
    public const CURSOR = ' AND (p.published_at < :pa OR (p.published_at = :pa2 AND p.id < UUID_TO_BIN(:id)))';
    public const SEARCH = ' AND MATCH(p.title, p.body_md) AGAINST (:q IN NATURAL LANGUAGE MODE)';
    public const LIST_TAIL = ' ORDER BY p.published_at DESC, p.id DESC LIMIT :limit';

    public const BY_SLUG = self::POST_SELECT_FULL . " WHERE p.slug = :slug AND p.status = 'published'";
    public const BY_ID = self::POST_SELECT_FULL . " WHERE p.id = UUID_TO_BIN(:id) AND p.status <> 'deleted'";
    public const ADMIN_LIST = self::POST_SELECT_LIST . " WHERE p.status IN ('draft', 'published')";
    public const ADMIN_CURSOR = ' AND p.id < UUID_TO_BIN(:before)';
    public const ADMIN_TAIL = ' ORDER BY p.id DESC LIMIT :limit';
    /** Every slug of one family: the base itself and base-N. One query, however many suffixes are taken. */
    public const SLUG_FAMILY = 'SELECT slug FROM posts WHERE slug = :base OR slug LIKE :prefix';
    /**
     * Which of the given upload paths a live post still uses (as its cover or inside its body): one pass for all paths.
     * The COLLATE is required: JSON_TABLE yields utf8mb4_0900 text, posts use utf8mb4_unicode_ci (error 1267 otherwise).
     */
    public const UPLOADS_IN_USE = "SELECT j.path FROM JSON_TABLE(:paths, '\$[*]' COLUMNS (path VARCHAR(255) COLLATE utf8mb4_unicode_ci PATH '\$')) AS j
        WHERE EXISTS (SELECT 1 FROM posts p WHERE p.status <> 'deleted' AND (p.cover_image = j.path OR LOCATE(j.path, p.body_md) > 0))";

    public function __construct(private PDO $pdo)
    {
    }

    public function findPublished(int $limit, ?string $search, ?array $before): array
    {
        $sql = $search === null ? self::LIST_BY_DATE : self::LIST;
        $sql .= $before !== null ? self::CURSOR : '';
        $sql .= $search !== null ? self::SEARCH : '';
        $sql .= self::LIST_TAIL;

        $stmt = $this->pdo->prepare($sql);
        if ($before !== null) {
            $stmt->bindValue(':pa', $before['publishedAt']);
            $stmt->bindValue(':pa2', $before['publishedAt']);
            $stmt->bindValue(':id', $before['id']);
        }
        if ($search !== null) {
            $stmt->bindValue(':q', $search);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(Post::fromRow(...), $stmt->fetchAll());
    }

    public function findBySlug(string $slug): ?Post
    {
        $stmt = $this->pdo->prepare(self::BY_SLUG);
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch();

        return $row === false ? null : Post::fromRow($row);
    }

    public function findById(string $id): ?Post
    {
        $stmt = $this->pdo->prepare(self::BY_ID);
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : Post::fromRow($row);
    }

    public function findForAdmin(?string $beforeId, int $limit): array
    {
        $sql = self::ADMIN_LIST . ($beforeId !== null ? self::ADMIN_CURSOR : '') . self::ADMIN_TAIL;
        $stmt = $this->pdo->prepare($sql);
        if ($beforeId !== null) {
            $stmt->bindValue(':before', $beforeId);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(Post::fromRow(...), $stmt->fetchAll());
    }

    public function slugsLike(string $base): array
    {
        $stmt = $this->pdo->prepare(self::SLUG_FAMILY);
        $stmt->bindValue(':base', $base);
        // LIKE wildcards in the base are escaped, so "probe_hello" never matches "probe-hello".
        $stmt->bindValue(':prefix', addcslashes($base, '%_\\') . '-%');
        $stmt->execute();

        return array_values($stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function uploadsInUse(array $paths): array
    {
        if ($paths === []) {
            return [];
        }
        $stmt = $this->pdo->prepare(self::UPLOADS_IN_USE);
        $stmt->bindValue(':paths', json_encode(array_values($paths), JSON_THROW_ON_ERROR));
        $stmt->execute();

        return array_values($stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function create(array $data): string
    {
        $id = (string) Uuid::v7();
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'INSERT INTO posts (id, author_id, title, slug, excerpt, body_md, cover_image, status, published_at)
                 VALUES (UUID_TO_BIN(:id), UUID_TO_BIN(:author), :title, :slug, :excerpt, :body, :cover, :status, :pub)'
            )->execute([
                'id' => $id,
                'author' => $data['author_id'],
                'title' => $data['title'],
                'slug' => $data['slug'],
                'excerpt' => $data['excerpt'],
                'body' => $data['body_md'],
                'cover' => $data['cover_image'],
                'status' => $data['status'],
                'pub' => $data['published_at'],
            ]);
            $this->pdo->prepare('INSERT INTO post_vote_counts (post_id, upvotes) VALUES (UUID_TO_BIN(:id), 0)')
                ->execute(['id' => $id]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $id;
    }

    public function update(string $id, array $data): void
    {
        $this->pdo->prepare(
            "UPDATE posts SET title = :title, excerpt = :excerpt, body_md = :body, cover_image = :cover,
                    status = :status, published_at = :pub
              WHERE id = UUID_TO_BIN(:id) AND status <> 'deleted'"
        )->execute([
            'title' => $data['title'],
            'excerpt' => $data['excerpt'],
            'body' => $data['body_md'],
            'cover' => $data['cover_image'],
            'status' => $data['status'],
            'pub' => $data['published_at'],
            'id' => $id,
        ]);
    }

    public function softDelete(string $id, string $deletedBy): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE posts SET status = 'deleted', deleted_at = UTC_TIMESTAMP(), deleted_by = UUID_TO_BIN(:by)
              WHERE id = UUID_TO_BIN(:id) AND status <> 'deleted'"
        );
        $stmt->execute(['by' => $deletedBy, 'id' => $id]);

        return $stmt->rowCount() === 1;
    }
}
