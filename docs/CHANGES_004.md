# Change set 004: fewer queries + real caching (≈25 min, 3 parallel streams)

Approved by the user on 2026-11-23. Implement it exactly as written (CLAUDE.md A1): no extra layers, no files beyond the list below. Every SQL statement here was run on Docker MySQL 8.4 as `inkwell_app` (results in §6). If something doesn't work as written, **stop and ask**; don't invent a variant.

## 0. Rules for every agent (main agent AND subagents) — read first

The last two change sets were reviewed and found two process failures: subagents **skipped the required skills**, and the N+1 check was never run. So these are hard rules:

1. **Invoke the skills listed for your stream with the Skill tool before you write code**, and apply their checklists to your diff. Reading this doc is not a substitute.
2. **Use the MCP server `inkwell-dev`**: `db_schema` before you write SQL, `list_routes` before you touch a link or form action. Any DB claim in your report must come from a tool result.
3. Stay inside **your own files** (§2). If you need a change in someone else's file, write it in your report; don't edit it.
4. **Your final report must start with a "Skills invoked" line** naming each skill and one finding or "clean". The main agent **rejects** a stream whose report lacks it and runs the skills itself.
5. `composer test` must be green for your test file before you report.

Skills every stream runs: `acc:detect-n-plus-one`, `acc:check-query-efficiency`, `acc:check-sql-injection`, `mysql-patterns`, `acc:check-test-quality`, `acc:testing-knowledge`, `acc:caching-strategies-knowledge`. Stream-specific skills are in §2.

## 1. What changes and why

| Path | Before | After |
|---|---|---|
| Post page view (guest) | 3 queries | 1 (comment ids, index-only); post, HTML, count and comment bodies come from the cache |
| Home page 1 | 1 query | 0 with a warm cache (TTL `CACHE_COUNT_TTL`) |
| Vote | 4 statements (post lookup, upsert, counter with `EXISTS` re-read, state SELECT) | 2 (guarded upsert + counter with `LAST_INSERT_ID`) |
| Comment add / edit / delete | 3 / 3 / 2 | 2 / 2 / 1 |
| Register | 3 (pre-check, insert, re-read) | 1 (the UNIQUE key answers "already registered") |
| Unused images after a post save | 1 query **per image** (N+1) | 1 query for all paths |
| Unique slug | 1 query **per suffix** | 1 query, plus one retry on 1062 (closes deferred item 2) |
| Post update with no changes | full UPDATE, bumps `updated_at` | no write |
| Comment list | every comment, oldest first | newest first, 30 per page, "Older comments" (keyset) |
| SQL text | post columns copied 4×, comment columns 2× | one constant each |

Cache keys (PSR-16 `CacheInterface`, already in the container):

| Key | Value | Written | Removed / refreshed |
|---|---|---|---|
| `post.{slug}` | `['post' => Post, 'html' => string]` | first published read | post update or delete (service knows `$existing->slug`) |
| `posts.home` | the `listPublished(null, null)` array | first read, TTL `CACHE_COUNT_TTL` | any post create, update or delete |
| `votes.{postId}` | int count | after every vote (the DB's count, never an increment), TTL `CACHE_COUNT_TTL` | overwritten by the next vote |
| `comment.{id}` | `Comment` | after add/edit, and on a cache miss in a list | **deleted** on comment delete; overwritten on edit |

The comment **id list is never cached**: two concurrent pushes into a cached array would lose one id (read → append → write back is not atomic). The ids come from the DB on every view through the index only. Deleted comments disappear because that query filters `status = 'visible'`. The count shown on cards (`posts.home`) and in `Post::$upvotes` can lag by up to `CACHE_COUNT_TTL` (user accepted eventual consistency). The post page shows `votes.{postId}`.

## 2. Streams and file ownership

Run A and B as **two subagents in parallel** while the main agent does M. Give each subagent this whole file plus "You are stream A/B". No file is in two streams.

| Stream | Files (only these) | Extra skills |
|---|---|---|
| **A — comments + post page + frontend** (subagent) | `src/Repository/PdoCommentRepository.php`, `src/Repository/CommentRepositoryInterface.php`, `src/Service/CommentService.php`, `src/Controller/CommentController.php`, `src/Controller/PostController.php`, `views/post/show.twig`, `frontend/src/main.ts`, `frontend/src/http.ts`, `tests/Unit/CommentServiceTest.php` | `acc:check-authorization`, `acc:find-race-conditions`, `acc:check-output-encoding`, `modern-web-guidance:modern-web-guidance` (guides `forms`, `accessible-error-announcement`) |
| **B — votes + register** (subagent) | `src/Repository/PdoVoteRepository.php`, `src/Repository/VoteRepositoryInterface.php`, `src/Service/VoteService.php`, `src/Controller/VoteController.php`, `src/Service/AuthService.php`, `src/Repository/PdoUserRepository.php` (only if needed), `tests/Unit/VoteServiceTest.php`, `tests/Unit/AuthServiceTest.php` | `acc:find-race-conditions`, `acc:check-authentication`, `acc:check-type-juggling` |
| **M — posts, then join** (main agent) | `src/Repository/PdoPostRepository.php`, `src/Repository/PostRepositoryInterface.php`, `src/Service/PostService.php`, `tests/Unit/PostServiceTest.php`; at the join: `config/container.php`, `config/settings.php`, `config/routes.php`, `.env.example`, `.env.local`, `.env.dev`, `.env.prod`, `src/Mcp/DevTools.php`, `bin/mcp-server.php` (only if it lists query names), `CLAUDE.md` | `acc:check-path-traversal` |

Interfaces the streams rely on (fixed here, so nobody waits):
- A calls `VoteService::count(string $postId): int` (B writes it).
- M's container passes these constructor args (A and B write the constructors to match exactly):
  - `CommentService(CommentRepositoryInterface $comments, CacheInterface $cache, int $maxLength, int $perPage)`
  - `VoteService(VoteRepositoryInterface $votes, CacheInterface $cache, int $countTtl)`
  - `PostService(PostRepositoryInterface $posts, MarkdownRendererInterface $markdown, ImageUploader $uploader, CacheInterface $cache, int $perPage, int $listTtl)`

## 3. Stream A — comments, post page, frontend (≈15 min)

### A1. `PdoCommentRepository` (all SQL verified, §6)

```php
private const COLUMNS = "SELECT BIN_TO_UUID(c.id) AS id, BIN_TO_UUID(c.post_id) AS post_id, p.slug AS post_slug,
    BIN_TO_UUID(c.user_id) AS user_id, u.name AS user_name, c.body, c.created_at, c.updated_at
    FROM comments c JOIN users u ON u.id = c.user_id JOIN posts p ON p.id = c.post_id";
public const BY_ID = self::COLUMNS . " WHERE c.id = UUID_TO_BIN(:id) AND c.status = 'visible'";
public const BY_IDS = self::COLUMNS . " WHERE c.status = 'visible' AND c.id IN "; // + "(UUID_TO_BIN(:id0), …)" built in code
// Index-only (idx_comments_post_status_created + PK). Qualify comments.id: a bare `id` in ORDER BY means the
// BIN_TO_UUID alias and causes a filesort (verified).
public const IDS_PAGE = "SELECT BIN_TO_UUID(id) AS id, created_at FROM comments
    WHERE post_id = UUID_TO_BIN(:post) AND status = 'visible'";
public const IDS_CURSOR = ' AND (created_at < :ca OR (created_at = :ca2 AND id < UUID_TO_BIN(:cid)))';
public const IDS_TAIL = ' ORDER BY comments.created_at DESC, comments.id DESC LIMIT :limit';
public const CREATE_ON_PUBLISHED = "INSERT INTO comments (id, post_id, user_id, body)
    SELECT UUID_TO_BIN(:id), p.id, UUID_TO_BIN(:u), :b FROM posts p WHERE p.id = UUID_TO_BIN(:p) AND p.status = 'published'";
public const UPDATE_IF_OWNER = "UPDATE comments SET body = :body
    WHERE id = UUID_TO_BIN(:id) AND status = 'visible' AND user_id = UUID_TO_BIN(:actor)";
public const DELETE_IF_ALLOWED = "UPDATE comments SET status = 'deleted', deleted_at = UTC_TIMESTAMP(), deleted_by = UUID_TO_BIN(:actor)
    WHERE id = UUID_TO_BIN(:id) AND status = 'visible' AND (user_id = UUID_TO_BIN(:actor2) OR :is_admin = 1)";
```

The new interface (`BY_POST`, `findVisibleByPost`, `create`, `update` and `softDelete` are removed):

```php
/** @param array{createdAt:string,id:string}|null $before @return list<array{id:string,createdAt:string}> newest first */
public function visibleIdsPage(string $postId, int $limit, ?array $before): array;
/** @param list<string> $ids (≤ 100) @return array<string,Comment> keyed by id; deleted or unknown ids are absent */
public function findVisibleByIds(array $ids): array;
public function findVisibleById(string $id): ?Comment;
/** @return ?string the new id, or null when the post is not published (0 rows inserted) */
public function createOnPublished(string $postId, string $userId, string $body): ?string;
/** true when 1 row changed. 0 rows means: not visible, not the owner, OR the same text (the service tells them apart) */
public function updateIfOwner(string $id, string $actorId, string $body): bool;
public function softDeleteIfAllowed(string $id, string $actorId, bool $isAdmin): bool;
```

- `findVisibleByIds`: return `[]` for `[]`. Throw `\InvalidArgumentException` when given more than 100 ids. Build the placeholders as `'(' . implode(', ', array_map(fn(int $i) => "UUID_TO_BIN(:id$i)", array_keys($ids))) . ')'` and bind each value. Only the placeholder *count* comes from code, never input text (A7).
- Bind `:limit` with `PDO::PARAM_INT` and `:is_admin` with `PDO::PARAM_INT` (`$isAdmin ? 1 : 0`). Every write returns `$stmt->rowCount() === 1`.

### A2. `CommentService`

`final readonly`, with the constructor from §2. It no longer depends on `PostRepositoryInterface`.

```php
/** @return array{comments: list<Comment>, nextCursor: ?string} */
public function listForPost(string $postId, ?string $cursor): array
{
    $rows = $this->comments->visibleIdsPage($postId, $this->perPage + 1, $this->decodeCursor($cursor));
    $hasMore = count($rows) > $this->perPage;
    $rows = array_slice($rows, 0, $this->perPage);
    $ids = array_column($rows, 'id');

    $cached = [];
    foreach ($this->cache->getMultiple(array_map(self::key(...), $ids)) as $key => $comment) {
        if ($comment instanceof Comment) {
            $cached[$comment->id] = $comment;
        }
    }
    $missing = array_values(array_diff($ids, array_keys($cached)));
    $loaded = $this->comments->findVisibleByIds($missing);          // ONE query for all misses
    if ($loaded !== []) {
        $this->cache->setMultiple(array_combine(array_map(self::key(...), array_keys($loaded)), $loaded));
    }

    $all = $cached + $loaded;
    $list = array_values(array_filter(array_map(fn(string $id) => $all[$id] ?? null, $ids)));
    $last = $rows === [] ? null : end($rows);

    return ['comments' => $list, 'nextCursor' => $hasMore && $last !== null ? $this->encodeCursor($last) : null];
}

public function add(string $postId, string $userId, string $body): Comment
{
    $id = $this->comments->createOnPublished($postId, $userId, $this->validateBody($body))
        ?? throw new NotFoundException('Post not found');
    $comment = $this->comments->findVisibleById($id) ?? throw new \RuntimeException('Comment vanished after insert');
    $this->cache->set(self::key($id), $comment);

    return $comment;
}

/** Only the owner may edit. One conditional UPDATE; the lookup runs only when it changed nothing. */
public function update(string $commentId, string $actorId, string $body): Comment
{
    $changed = $this->comments->updateIfOwner($commentId, $actorId, $this->validateBody($body));
    $comment = $this->comments->findVisibleById($commentId) ?? throw new NotFoundException('Comment not found');
    if (!$changed && $comment->userId !== $actorId) {
        throw new ForbiddenException();
    }
    $this->cache->set(self::key($commentId), $comment); // !$changed + owner = same text: harmless refresh

    return $comment;
}

/** The owner or an admin may delete (soft delete). One conditional UPDATE on success. */
public function delete(string $commentId, string $actorId, Role $actorRole): void
{
    if (!$this->comments->softDeleteIfAllowed($commentId, $actorId, $actorRole->satisfies(Role::Admin))) {
        $this->comments->findVisibleById($commentId) === null
            ? throw new NotFoundException('Comment not found')
            : throw new ForbiddenException();
    }
    $this->cache->delete(self::key($commentId));
}

private static function key(string $id): string
{
    return 'comment.' . $id;
}
```

- Use a plain `if` instead of the ternary-throw in `delete` if PHP rejects it.
- `encodeCursor`/`decodeCursor`: copy `PostService`'s versions. Use `createdAt|id` base64url, and validate the date regex plus `Uuid::isValid`. An invalid cursor is ignored, never an error.
- `validateBody` is unchanged.

### A3. Controllers

- `CommentController::destroy`: `$input = $this->body($request, ['slug']); $id = $this->uuid($vars); $this->comments->delete($id, $this->userId(), $this->role());`. Then respond with `['id' => $id]` and the redirect `$this->postPath($input['slug'], '#comments')`. The forms already post `slug`, and `postPath` validates it. `store` and `update` are unchanged.
- `PostController::show`:
  - Read `$cursor = $request->getQueryParams()['comments_before'] ?? null` (only if `is_string`), then `$page = $this->comments->listForPost($post->id, $cursor)`.
  - If `$cursor !== null && $this->wantsJson($request)`, return `$this->json(['ok' => true, 'comments' => array_map($this->commentView(...), $page['comments']), 'nextCommentCursor' => $page['nextCursor']])`.
  - Otherwise render as now, with `'post' => array_replace($this->card($post), ['id' => $post->id, 'upvotes' => $this->votes->count($post->id)])`, `'comments' => …$page['comments']` and `'nextCommentCursor' => $page['nextCursor']`.
  - Pass `VoteService` to the controller (it already has it as `$this->votes`).

### A4. View and TS

- `show.twig`: in the vote form add `<input type="hidden" name="voted" value="{{ voted ? '0' : '1' }}" data-vote-wanted>`. Without JS, the button then sends the opposite of the current state.
- `show.twig`: after `</ul>` add
  `{% if nextCommentCursor %}<nav class="pager" aria-label="Comment pages"><a class="btn btn--secondary" data-load-more-comments href="/posts/{{ post.slug }}?comments_before={{ nextCommentCursor|url_encode }}#comments">Older comments</a></nav>{% endif %}`
- `http.ts`: add `export interface CommentPage { ok: true; comments: CommentData[]; nextCommentCursor: string | null }` and `isCommentPage`. Write the guard the same way as `isPostPage`, reusing the existing comment-data check.
- `main.ts`:
  - **vote** handler: after the count update add `const wanted = form.querySelector<HTMLInputElement>('[data-vote-wanted]'); if (wanted) wanted.value = r.voted ? '0' : '1';`
  - **comment-create**: `list.append(li)` → `list.prepend(li)`, because the list is newest first.
  - **New click handler**, modelled on the "Older posts" one:
    1. For `a[data-load-more-comments]`, check `isInternalPath(href)`.
    2. Call `send(href, { method: 'GET' }, isCommentPage)`.
    3. For each comment, `cloneTemplate('comment-tpl')`, then `fillComment`, then `list.append`.
    4. If `nextCommentCursor` is non-null, set the link's `href` to the new `?comments_before=` URL (`URLSearchParams`). Otherwise remove the `nav`.
    5. On error, `setStatus(document.getElementById('comments'), …)`.

### A5. `CommentServiceTest` (6 → 9)

Use `new Psr16Cache(new ArrayAdapter())` as the real cache, with at most 3 doubles, AAA and sentence names.

| Test | Change |
|---|---|
| `test_add_saves_comment_on_published_post` | `createOnPublished` returns an id; `findVisibleById` returns the comment |
| `test_add_rejects_empty_comment` | keep |
| **`test_add_throws_when_post_is_not_published`** | new: `createOnPublished` returns null, so `NotFoundException` |
| `test_owner_can_update_own_comment` | `updateIfOwner` returns true |
| `test_user_cannot_update_someone_elses_comment` | `updateIfOwner` returns false and `findVisibleById` returns another user's comment, so `ForbiddenException` |
| `test_user_cannot_delete_someone_elses_comment` | `softDeleteIfAllowed` returns false and `findVisibleById` returns the comment, so `ForbiddenException` |
| `test_admin_can_delete_any_comment` | mock expects `softDeleteIfAllowed($id, $adminId, true)` once and returns true |
| **`test_delete_removes_the_cached_comment`** | new: cache holds `comment.{id}`; after `delete` the key is gone |
| **`test_list_fetches_only_uncached_comments_in_one_query`** | new: ids `[c1, c2]`, `c1` cached; the mock expects `findVisibleByIds(['c2'])` once; the result order is `[c1, c2]` |

## 4. Stream B — votes and register (≈10 min)

### B1. `PdoVoteRepository` (verified, §6)

The user now sends the state they want (`voted=1|0`) instead of a toggle. A double-click keeps the vote on instead of cancelling it.

```php
public const HAS_VOTED = …unchanged…;
public const UPSERT = "INSERT INTO post_votes (post_id, user_id, status)
    SELECT p.id, UUID_TO_BIN(:u), :w FROM posts p WHERE p.id = UUID_TO_BIN(:p) AND p.status = 'published'
    ON DUPLICATE KEY UPDATE status = :w2";
public const COUNT_UPDATE = 'UPDATE post_vote_counts SET upvotes = LAST_INSERT_ID(upvotes + :d) WHERE post_id = UUID_TO_BIN(:p)';
public const COUNT = 'SELECT upvotes FROM post_vote_counts WHERE post_id = UUID_TO_BIN(:p)';
/** Only when the upsert changed nothing: a repeat click (row found → count) or a post that is not published (no row → 404). */
public const PUBLISHED_COUNT = "SELECT c.upvotes FROM post_vote_counts c JOIN posts p ON p.id = c.post_id AND p.status = 'published'
    WHERE c.post_id = UUID_TO_BIN(:p)";
```

The interface replaces `toggle` with:
```php
/** @return array{voted: bool, count: int}|null null when the post is not published */
public function set(string $postId, string $userId, bool $wanted): ?array;
public function count(string $postId): int;   // COUNT; 0 if no row
```

`set` keeps the existing deadlock retry (`isDeadlock`, one retry) around a private `write()`:

```php
private function write(string $postId, string $userId, bool $wanted): ?array
{
    $status = $wanted ? 'active' : 'removed';
    $this->pdo->beginTransaction();
    try {
        $upsert = $this->pdo->prepare(self::UPSERT);
        $upsert->execute(['u' => $userId, 'w' => $status, 'p' => $postId, 'w2' => $status]);
        // rowCount: 1 = new row, 2 = status changed, 0 = nothing changed or post not published (verified).
        $delta = match ($upsert->rowCount()) {
            1 => $wanted ? 1 : 0,
            2 => $wanted ? 1 : -1,
            default => 0,
        };
        if ($delta !== 0) {
            $update = $this->pdo->prepare(self::COUNT_UPDATE);
            $update->execute(['d' => $delta, 'p' => $postId]);
            if ($update->rowCount() !== 1) {
                throw new \RuntimeException('Vote counter row is missing');
            }
        }
        $this->pdo->commit();
    } catch (\Throwable $e) {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        throw $e;
    }
    if ($delta !== 0) {
        return ['voted' => $wanted, 'count' => (int) $this->pdo->lastInsertId()]; // LAST_INSERT_ID(upvotes + d); 0 is returned as '0'
    }
    $stmt = $this->pdo->prepare(self::PUBLISHED_COUNT);   // rare path only
    $stmt->execute(['p' => $postId]);
    $count = $stmt->fetchColumn();

    return $count === false ? null : ['voted' => $wanted, 'count' => (int) $count];
}
```

`STATE` and `toggle` are removed.

### B2. `VoteService`

The constructor is from §2. `PostRepositoryInterface` is gone.

```php
/** @return array{voted: bool, count: int} */
public function vote(string $postId, string $userId, string $wanted): array
{
    $flag = match ($wanted) {
        '1' => true,
        '0' => false,
        default => throw new ValidationException(['voted' => 'Invalid vote']),
    };
    $result = $this->votes->set($postId, $userId, $flag) ?? throw new NotFoundException('Post not found');
    $this->cache->set(self::key($postId), $result['count'], $this->countTtl); // the DB's count, never an increment

    return $result;
}

public function count(string $postId): int
{
    $cached = $this->cache->get(self::key($postId));
    if (is_int($cached)) {
        return $cached;
    }
    $count = $this->votes->count($postId);
    $this->cache->set(self::key($postId), $count, $this->countTtl);

    return $count;
}
```

`hasVoted` is unchanged. `key()` returns `'votes.' . $postId`.

### B3. `VoteController`

- Rename `toggle` → `vote`. M updates `config/routes.php`.
- Read `$input = $this->body($request, ['slug', 'voted'])` and call `$this->votes->vote($id, $this->userId(), $input['voted'])`.
- Catch `ValidationException` and return `respondInvalid($request, $e, null, [], $this->postPath($input['slug']))`.
- Validate before writing, as now.

### B4. `AuthService::register` (3 → 1 query)

- Remove the `findByEmail` pre-check. The existing `catch (PDOException)` with 1062 already returns "This email is already registered". Log `auth.register_invalid` there too.
- Remove the `findById` re-read. Return `new User($id, $email, $name, $hash, Role::User)`, where `$hash` is the `password_hash(...)` result kept in a variable.
- **Verified:** a duplicate email gives 1062.
- `UserRepositoryInterface::findById` stays, because the session user lookup still uses it.

### B5. Tests

**`VoteServiceTest` (2 → 3):**

| Test | What it checks |
|---|---|
| `test_vote_returns_new_state_and_caches_the_count` | `set` returns `['voted' => true, 'count' => 3]`; cache `votes.{id}` is 3 |
| `test_vote_throws_for_unpublished_post` | `set` returns null, so `NotFoundException` |
| **`test_count_comes_from_cache_without_a_query`** | new: cache holds 5; the mock expects `count` **never** |

**`AuthServiceTest`:** `test_register_rejects_duplicate_email` now makes `create` throw a `PDOException` whose `errorInfo` is `['23000', 1062, 'Duplicate']`, and expects a `ValidationException`. The total stays 5.

## 5. Stream M — posts, then the join (≈15 min + 10 min)

### M1. `PdoPostRepository`

- **One column constant**, used by `LIST_COLUMNS`, `BY_SLUG`, `BY_ID` and `ADMIN_LIST`. The list queries keep `'' AS body_md`, so pass the body column in. Two constants are fine:
  - `POST_SELECT_LIST`, with `'' AS body_md`;
  - `POST_SELECT_FULL`, with `p.body_md`.
  
  Both have the same JOINs: `JOIN users u … LEFT JOIN post_vote_counts c …`. Keep `LIST_BY_DATE`'s `FORCE INDEX` exactly as it is.
- **Replace `isUploadInUse` and `UPLOAD_IN_USE`** with:
  ```php
  public const UPLOADS_IN_USE = "SELECT j.path FROM JSON_TABLE(:paths, '$[*]' COLUMNS (path VARCHAR(255) COLLATE utf8mb4_unicode_ci PATH '$')) AS j
      WHERE EXISTS (SELECT 1 FROM posts p WHERE p.status <> 'deleted' AND (p.cover_image = j.path OR LOCATE(j.path, p.body_md) > 0))";
  /** @param list<string> $paths @return list<string> the paths a live post still uses */
  public function uploadsInUse(array $paths): array   // [] → [] without a query; bind json_encode($paths, JSON_THROW_ON_ERROR)
  ```
  The `COLLATE` is required, because without it MySQL errors with 1267. The plan is one hash-join pass over `posts` for all paths, instead of one pass per path. It runs on admin saves only, so this is accepted (§6).
- **Replace `slugExists`, `SLUG_EXISTS` and `SLUG_EXISTS_EXCEPT`** with:
  ```php
  public const SLUG_FAMILY = 'SELECT slug FROM posts WHERE slug = :base OR slug LIKE :prefix';
  /** @return list<string> */
  public function slugsLike(string $base): array   // :prefix = addcslashes($base, '%_\\') . '-%'
  ```
  It uses a `range` scan on `uq_posts_slug`, index only. Escaping matters: `probe_hello` must not match `probe-hello`, which was verified.

### M2. `PostService`

The constructor is from §2.

- `getPublishedBySlug($slug)`:
  1. If `preg_match('/^[a-z0-9-]{1,220}$/', $slug) !== 1`, throw `NotFoundException`. That's no query, and it gives a safe cache key.
  2. Key `'post.' . $slug`: return the cached array if it is set.
  3. Otherwise run `findBySlug`, then `render`, then `set` with the default TTL, and return.
- `listPublished($cursor, $q)`: when both are null/empty after trimming, use the key `posts.home` with TTL `$this->listTtl`. Search and older pages are never cached.
- `create`:
  1. Wrap `$this->posts->create(...)` in `try`.
  2. On a `PDOException` with `errorInfo[1] === 1062`, recompute `uniqueSlug` and retry once.
  3. Then `$this->cache->delete('posts.home')`.
- `update`:
  1. **No-op check first:** if `title`, `body_md` and `excerpt` equal `$existing`'s, `$data['status'] === $existing->status->value` (the model holds the enum), and `$newCoverValue === $existing->coverImage`, return without writing (no write, no file deletes, no cache change).
  2. If a new file was uploaded but every field equals `$existing`, it's not a no-op, because `$newCoverValue` differs.
  3. After the write, `$this->cache->deleteMultiple(['post.' . $existing->slug, 'posts.home'])`, then `deleteUnused` as now.
- `delete`: after `softDelete`, `deleteMultiple(['post.' . $post->slug, 'posts.home'])`.
- `deleteUnused(array $candidates)`:
  ```php
  $candidates = array_values(array_unique($candidates));
  $used = $this->posts->uploadsInUse($candidates);
  foreach (array_diff($candidates, $used) as $path) {
      $this->uploader->delete($path);
  }
  ```
- `uniqueSlug`:
  ```php
  $taken = $this->posts->slugsLike($base);
  $slug = $base;
  for ($n = 2; in_array($slug, $taken, true); $n++) {
      $slug = $base . '-' . $n;
  }
  ```

### M3. `PostServiceTest` (4 → 7)

Use a real `Psr16Cache(new ArrayAdapter())` cache.

| Test | What it checks |
|---|---|
| `test_create_adds_suffix_when_slug_is_taken` | `slugsLike` returns `['hello', 'hello-2']`, so the slug is `hello-3` |
| `test_get_published_by_slug_throws_when_missing` | keep |
| `test_update_with_remove_cover_deletes_the_unused_old_file` | `uploadsInUse` returns `[]` |
| `test_update_keeps_old_cover_still_used_by_another_post` | `uploadsInUse` returns `[$old]` |
| **`test_update_without_changes_writes_nothing`** | new: the mock expects `update` never |
| **`test_published_post_is_read_once_then_served_from_cache`** | new: two calls; `findBySlug` once |
| **`test_update_clears_the_cached_post`** | new: read, update, read; `findBySlug` called twice |

### M4. Join (after A and B report)

1. **Review.** Check each report has the "Skills invoked" line (§0.4). Diff each stream against this doc. If a report lacks the skills, run them yourself on that diff.
2. **`config/settings.php`** gets `'comments_per_page' => $int('COMMENTS_PER_PAGE', 30)` and `'cache' => [..., 'count_ttl' => $int('CACHE_COUNT_TTL', 300)]`. **All four env files** get `COMMENTS_PER_PAGE=30` and `CACHE_COUNT_TTL=300`.
3. **`config/container.php`** wires the three constructors from §2. `VoteService` is no longer `autowire()`; it takes an explicit factory with `count_ttl`.
4. **`config/routes.php`**: `POST /posts/{id:uuid}/vote` → `VoteController::vote`.
5. **`DevTools` whitelist** (`explainQuery`):
   - replace `comments_for_post` with `comment_ids_page`, `comment_ids_page_after_cursor` and `comments_by_ids` (2 ids);
   - replace `vote_state` with `vote_count` and `vote_published_count`;
   - replace `slug_exists` with `slug_family`;
   - replace `upload_in_use` with `uploads_in_use` (2 paths).
   
   If `bin/mcp-server.php` or the tool attribute lists the names, update that text too.
6. **Verify:**
   - `composer test` gives **33** green;
   - `npm run typecheck`, `npm run build`, `composer audit` and `npm audit --omit=dev` are clean;
   - **after `/mcp` reconnects**, run `explain_query` on every new name, `verify_constraints` (15/15) and `verify_vote_counts` (`[]`).

   The expected plans:
   - `comment_ids_page*`: `idx_comments_post_status_created`, *Backward index scan; Using index*, no filesort;
   - `comments_by_ids`: `range PRIMARY`;
   - `slug_family`: `range uq_posts_slug`;
   - `vote_*`: `const PRIMARY`;
   - `uploads_in_use`: hash join, accepted.
7. **Smoke test over HTTP** (`composer serve`), then delete the test rows:
   - a vote on, the same on again, then off: the count goes +1, unchanged, −1, and `verify_vote_counts` returns `[]`;
   - a vote on a draft gives 404;
   - comment add, edit and delete each work, and a deleted comment never reappears;
   - with more than 30 comments, "Older comments" loads the next 30;
   - editing a post shows the new text at once (the cache is cleared);
   - saving with no changes keeps `updated_at`.
8. **`CLAUDE.md`:**
   - **§B5:** the vote row becomes `VoteController::vote`, with body `voted=1|0`, and `GET /posts/{slug}` gains `?comments_before=`.
   - **§B6:** add `COMMENTS_PER_PAGE` and `CACHE_COUNT_TTL`.
   - **§B7:** replace the cache table with the key table in §1.
   - **§A6:** change "Avoid N+1" to add: "Never call a repository inside a loop; batch with `IN (…)` or `JSON_TABLE`. A write that checks a condition is one conditional statement (`UPDATE … WHERE owner`, `INSERT … SELECT … WHERE published`), not a SELECT first."
   - **§B9:** add the result entry.
   - **The header:** list `docs/CHANGES_004.md`.

**No new files.** Tests go 26 → 33.

## 6. Verification already done (Docker MySQL 8.4.13, `inkwell_app`, rolled back)

- **Comment ids page:** with 300 comments, both pages use `idx_comments_post_status_created` with *Backward index scan; Using index* (no filesort once `comments.id` is qualified). Page 1 is newest first, and page 2 starts exactly after item 30.
- **Batch by ids:** `range PRIMARY`, 2 rows.
- **Conditional comment writes:**
  - an edit by the owner changes 1 row, and by a stranger 0;
  - a delete by a non-admin stranger changes 0, by an admin 1, and a second delete 0;
  - EXPLAIN shows `range PRIMARY`.
- **Comment add:** a published post gives 1 row, a draft post 0.
- **Votes:**
  - a new "on" gives 1, a repeat 0, "off" 2, a repeat 0, and a draft 0;
  - the counter via `LAST_INSERT_ID` returns 2, then 1, then `'0'` when it reaches zero;
  - the published-count fallback gives the count for a published post and `false` for a draft;
  - the counter below 0 gives 1264.
- **`uploads_in_use`:** given 3 paths (cover, inline and unused), it returned exactly the 2 in use. It needs `COLLATE utf8mb4_unicode_ci` (otherwise error 1267).
- **`slug_family`:** `hello`, `hello-2` and `hello-3` were found, and `hellox` was not. An escaped `probe_hello` matched nothing.
- **Register:** a duplicate email gives 1062.
- **Gotcha:** never run `ANALYZE TABLE` (or any DDL) inside a probe transaction. It commits implicitly, and the probe rows get saved.
