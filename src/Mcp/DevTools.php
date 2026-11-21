<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Repository\{PdoCommentRepository, PdoLoginAttemptRepository, PdoPostRepository, PdoUserRepository, PdoVoteRepository};
use PDO;
use Symfony\Component\Uid\Uuid;

/** Dev-only, read-only MCP tools. They never accept raw SQL: explain_query takes a whitelisted name. */
final readonly class DevTools
{
    private const TABLES = ['users', 'posts', 'comments', 'post_votes', 'post_vote_counts', 'login_attempts', 'schema_migrations'];

    public function __construct(private PDO $pdo, private string $routesFile)
    {
    }

    /** SHOW CREATE TABLE for every table. */
    public function dbSchema(): array
    {
        $out = [];
        foreach (self::TABLES as $table) {
            $row = $this->pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM);
            $out[$table] = $row[1] ?? null;
        }

        return $out;
    }

    /**
     * EXPLAIN one of the named repository queries.
     *
     * @param string $name one of: list_published, list_published_after_cursor, search, admin_list, post_by_slug, post_by_id, slug_exists, upload_in_use, comments_for_post, vote_state, has_voted, login_lookup
     */
    public function explainQuery(string $name): array
    {
        $post = $this->pdo->query("SELECT BIN_TO_UUID(id) AS id, slug, published_at FROM posts ORDER BY id DESC LIMIT 1")->fetch();
        $user = $this->pdo->query('SELECT BIN_TO_UUID(id) AS id FROM users ORDER BY id LIMIT 1')->fetch();
        $postId = $post['id'] ?? (string) Uuid::v7();
        $userId = $user['id'] ?? (string) Uuid::v7();
        $slug = $post['slug'] ?? 'x';
        $pub = $post['published_at'] ?? '2026-01-01 00:00:00';

        $queries = [
            'list_published' => [PdoPostRepository::LIST_BY_DATE . PdoPostRepository::LIST_TAIL, ['limit' => 11]],
            'list_published_after_cursor' => [
                PdoPostRepository::LIST_BY_DATE . PdoPostRepository::CURSOR . PdoPostRepository::LIST_TAIL,
                ['pa' => $pub, 'pa2' => $pub, 'id' => $postId, 'limit' => 11],
            ],
            'search' => [
                PdoPostRepository::LIST . PdoPostRepository::SEARCH . PdoPostRepository::LIST_TAIL,
                ['q' => 'welcome', 'limit' => 11],
            ],
            'admin_list' => [PdoPostRepository::ADMIN_LIST . PdoPostRepository::ADMIN_TAIL, ['limit' => 11]],
            'post_by_slug' => [PdoPostRepository::BY_SLUG, ['slug' => $slug]],
            'post_by_id' => [PdoPostRepository::BY_ID, ['id' => $postId]],
            'slug_exists' => [PdoPostRepository::SLUG_EXISTS . ' LIMIT 1', ['slug' => $slug]],
            'upload_in_use' => [PdoPostRepository::UPLOAD_IN_USE, ['path' => '/uploads/x.jpg', 'path2' => '/uploads/x.jpg']],
            'comments_for_post' => [PdoCommentRepository::BY_POST, ['post' => $postId]],
            'vote_state' => [PdoVoteRepository::STATE, ['u' => $userId, 'p' => $postId]],
            'has_voted' => [PdoVoteRepository::HAS_VOTED, ['p' => $postId, 'u' => $userId]],
            'login_lookup' => [PdoLoginAttemptRepository::LOOKUP, ['k' => sha1('probe')]],
        ];
        if (!array_key_exists($name, $queries)) {
            return ['error' => 'Unknown query name', 'allowed' => array_keys($queries)];
        }

        [$sql, $params] = $queries[$name];
        $stmt = $this->pdo->prepare('EXPLAIN ' . $sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** Runs 15 bad writes inside a transaction that is always rolled back. Each must fail with the expected error. */
    public function verifyConstraints(): array
    {
        $user = $this->pdo->query('SELECT BIN_TO_UUID(id) AS id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $post = $this->pdo->query('SELECT BIN_TO_UUID(id) AS id FROM posts ORDER BY id LIMIT 1')->fetchColumn();
        if ($user === false || $post === false) {
            return ['error' => 'Run php bin/migrate.php first (needs a user and a post)'];
        }

        $newPost = static fn(string $status, ?string $published, ?string $deleted, string $title = 'T'): array => [
            'INSERT INTO posts (id, author_id, title, slug, body_md, status, published_at, deleted_at)
             VALUES (UUID_TO_BIN(:id), UUID_TO_BIN(:u), :t, :s, \'b\', :st, :p, :d)',
            ['id' => (string) Uuid::v7(), 'u' => $user, 't' => $title, 's' => 'chk-' . bin2hex(random_bytes(6)), 'st' => $status, 'p' => $published, 'd' => $deleted],
        ];
        $newComment = static fn(string $status, ?string $deleted, string $body = 'hi'): array => [
            'INSERT INTO comments (id, post_id, user_id, body, status, deleted_at) VALUES (UUID_TO_BIN(:id), UUID_TO_BIN(:p), UUID_TO_BIN(:u), :b, :st, :d)',
            ['id' => (string) Uuid::v7(), 'p' => $post, 'u' => $user, 'b' => $body, 'st' => $status, 'd' => $deleted],
        ];

        $cases = [
            [1, 'post status abcd', 'chk_posts_status', $newPost('abcd', null, null)],
            [2, "user role superuser", 'chk_users_role', ['INSERT INTO users (id, email, name, password_hash, role) VALUES (UUID_TO_BIN(:id), :e, \'n\', \'h\', \'superuser\')', ['id' => (string) Uuid::v7(), 'e' => 'bad-' . bin2hex(random_bytes(4)) . '@x.test']]],
            [3, 'comment status hidden', 'chk_comments_status', $newComment('hidden', null)],
            [4, 'vote status abcd', 'chk_votes_status', ['INSERT INTO post_votes (post_id, user_id, status) VALUES (UUID_TO_BIN(:p), UUID_TO_BIN(:u), \'abcd\')', ['p' => $post, 'u' => $user]]],
            [5, 'published without published_at', 'chk_posts_published_at', $newPost('published', null, null)],
            [6, 'deleted post without deleted_at', 'chk_posts_deleted_at', $newPost('deleted', null, null)],
            [7, 'deleted comment without deleted_at', 'chk_comments_deleted_at', $newComment('deleted', null)],
            [8, 'empty post title', 'chk_posts_title', $newPost('draft', null, null, '')],
            [9, 'empty comment body', 'chk_comments_body', $newComment('visible', null, '')],
            [10, 'duplicate vote', 'post_votes.PRIMARY', ['INSERT INTO post_votes (post_id, user_id, status) VALUES (UUID_TO_BIN(:p), UUID_TO_BIN(:u), \'active\')', ['p' => $post, 'u' => $user]]],
            [11, 'DELETE FROM posts', 'DELETE command denied', ['DELETE FROM posts WHERE 1 = 0', []]],
            [12, 'upvotes - 1 on a zero counter', 'out of range', ['UPDATE post_vote_counts SET upvotes = upvotes - 1 WHERE post_id = UUID_TO_BIN(:p)', []]],
            [13, 'counter row for unknown post', 'foreign key constraint fails', ['INSERT INTO post_vote_counts (post_id, upvotes) VALUES (UUID_TO_BIN(:p), 0)', ['p' => (string) Uuid::v7()]]],
            [14, 'deleted_by on a visible comment', 'chk_comments_deleted_by', ['INSERT INTO comments (id, post_id, user_id, body, status, deleted_by) VALUES (UUID_TO_BIN(:id), UUID_TO_BIN(:p), UUID_TO_BIN(:u), \'x\', \'visible\', UUID_TO_BIN(:u2))', ['id' => (string) Uuid::v7(), 'p' => $post, 'u' => $user, 'u2' => $user]]],
            [15, 'deleted_by unknown user', 'foreign key constraint fails', ['INSERT INTO posts (id, author_id, title, slug, body_md, status, deleted_at, deleted_by) VALUES (UUID_TO_BIN(:id), UUID_TO_BIN(:u), \'D\', :s, \'b\', \'deleted\', UTC_TIMESTAMP(), UUID_TO_BIN(:x))', ['id' => (string) Uuid::v7(), 'u' => $user, 's' => 'dby-' . bin2hex(random_bytes(6)), 'x' => (string) Uuid::v7()]]],
        ];

        $results = [];
        $this->pdo->beginTransaction();
        try {
            // Fixtures for #10 (a first valid vote) and #12 (a fresh post whose counter is 0).
            $this->pdo->prepare("INSERT INTO post_votes (post_id, user_id, status) VALUES (UUID_TO_BIN(:p), UUID_TO_BIN(:u), 'active')")
                ->execute(['p' => $post, 'u' => $user]);
            $zeroPost = (string) Uuid::v7();
            $this->pdo->prepare("INSERT INTO posts (id, author_id, title, slug, body_md) VALUES (UUID_TO_BIN(:id), UUID_TO_BIN(:u), 'Z', :s, 'b')")
                ->execute(['id' => $zeroPost, 'u' => $user, 's' => 'zero-' . bin2hex(random_bytes(6))]);
            $this->pdo->prepare('INSERT INTO post_vote_counts (post_id, upvotes) VALUES (UUID_TO_BIN(:id), 0)')->execute(['id' => $zeroPost]);

            foreach ($cases as [$n, $label, $expected, [$sql, $params]]) {
                if ($n === 12) {
                    $params = ['p' => $zeroPost];
                }
                $results[] = $this->attempt($n, $label, $expected, $sql, $params);
            }
        } finally {
            $this->pdo->rollBack();
        }

        return $results;
    }

    /** Posts whose counter differs from the real number of active votes. Must be []. */
    public function verifyVoteCounts(): array
    {
        return $this->pdo->query(
            "SELECT t.post_id, t.counter, t.actual FROM (
                SELECT BIN_TO_UUID(p.id) AS post_id, c.upvotes AS counter,
                       (SELECT COUNT(*) FROM post_votes v WHERE v.post_id = p.id AND v.status = 'active') AS actual
                  FROM posts p JOIN post_vote_counts c ON c.post_id = p.id
             ) t WHERE t.counter <> t.actual"
        )->fetchAll();
    }

    /** The route table from config/routes.php. */
    public function listRoutes(): array
    {
        $collector = new class {
            /** @var list<array{method:string,path:string,handler:string,role:string}> */
            public array $routes = [];

            public function addRoute(string $method, string $path, array $handler): void
            {
                $this->routes[] = [
                    'method' => $method,
                    'path' => $path,
                    'handler' => $handler[0] . '::' . $handler[1],
                    'role' => isset($handler[2]) ? $handler[2]->value : '-',
                    'guest_only' => (bool) ($handler[3] ?? false),
                ];
            }
        };
        (require $this->routesFile)($collector);

        return $collector->routes;
    }

    /** @param array<string,mixed> $params */
    private function attempt(int $n, string $label, string $expected, string $sql, array $params): array
    {
        try {
            $this->pdo->prepare($sql)->execute($params);

            return ['#' => $n, 'case' => $label, 'result' => 'FAIL', 'detail' => 'write succeeded'];
        } catch (\PDOException $e) {
            $ok = str_contains($e->getMessage(), $expected);

            return ['#' => $n, 'case' => $label, 'result' => $ok ? 'PASS' : 'FAIL', 'detail' => $ok ? $expected : $e->getMessage()];
        }
    }
}
