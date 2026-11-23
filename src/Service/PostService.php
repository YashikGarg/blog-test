<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\NotFoundException;
use App\Exception\ValidationException;
use App\Model\Post;
use App\Model\PostStatus;
use App\Repository\PostRepositoryInterface;
use PDOException;
use Psr\Http\Message\UploadedFileInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Uid\Uuid;

final readonly class PostService
{
    private const HOME_KEY = 'posts.home';

    public function __construct(
        private PostRepositoryInterface $posts,
        private MarkdownRendererInterface $markdown,
        private ImageUploader $uploader,
        private CacheInterface $cache,
        private int $perPage,
        private int $listTtl,
    ) {
    }

    /** @return array{posts: list<Post>, nextCursor: ?string, q: ?string} */
    public function listPublished(?string $cursor, ?string $q): array
    {
        $q = $q === null ? null : mb_substr(trim($q), 0, 100);
        $q = $q === '' ? null : $q;

        $before = $this->decodeCursor($cursor);
        // Only the first page without a search is cached (an invalid cursor counts as the first page). Counts on the cards may lag by the TTL.
        $cacheable = $q === null && $before === null;
        if ($cacheable) {
            $cached = $this->cache->get(self::HOME_KEY);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $rows = $this->posts->findPublished($this->perPage + 1, $q, $before);
        $hasMore = count($rows) > $this->perPage;
        $posts = array_slice($rows, 0, $this->perPage);
        $last = $posts === [] ? null : end($posts);

        $page = [
            'posts' => $posts,
            'nextCursor' => $hasMore && $last !== null && $last->publishedAt !== null ? $this->encodeCursor($last) : null,
            'q' => $q,
        ];
        if ($cacheable) {
            $this->cache->set(self::HOME_KEY, $page, $this->listTtl);
        }

        return $page;
    }

    /** @return array{post: Post, html: string} */
    public function getPublishedBySlug(string $slug): array
    {
        // A slug that cannot exist is a 404 with no query, and it keeps the cache key safe.
        if (preg_match('/^[a-z0-9-]{1,220}$/', $slug) !== 1) {
            throw new NotFoundException('Post not found');
        }
        $cached = $this->cache->get('post.' . $slug);
        if (is_array($cached) && ($cached['post'] ?? null) instanceof Post && is_string($cached['html'] ?? null)) {
            return ['post' => $cached['post'], 'html' => $cached['html']];
        }

        $post = $this->posts->findBySlug($slug) ?? throw new NotFoundException('Post not found');
        $page = ['post' => $post, 'html' => $this->render($post)];
        $this->cache->set('post.' . $slug, $page); // removed by update() and delete()

        return $page;
    }

    /** Admin list: draft and published, keyset on id. @return array{posts: list<Post>, nextBefore: ?string} */
    public function listForAdmin(?string $before): array
    {
        $before = $before !== null && Uuid::isValid($before) ? $before : null;
        $rows = $this->posts->findForAdmin($before, $this->perPage + 1);
        $posts = array_slice($rows, 0, $this->perPage);
        $last = $posts === [] ? null : end($posts);

        return ['posts' => $posts, 'nextBefore' => count($rows) > $this->perPage && $last !== null ? $last->id : null];
    }

    public function getForEdit(string $id): Post
    {
        return $this->posts->findById($id) ?? throw new NotFoundException('Post not found');
    }

    /** @param array<string,string> $input title, body, status, excerpt */
    public function create(string $authorId, array $input, ?UploadedFileInterface $cover): string
    {
        $data = $this->validate($input);
        $coverPath = $this->hasUpload($cover) ? $this->uploader->store($cover) : null;

        $row = $data + [
            'author_id' => $authorId,
            'cover_image' => $coverPath,
            'published_at' => $data['status'] === PostStatus::Published->value ? gmdate('Y-m-d H:i:s') : null,
        ];
        try {
            $id = $this->posts->create($row + ['slug' => $this->uniqueSlug($data['title'])]);
        } catch (PDOException $e) {
            // Two posts with the same title saved at the same moment: the UNIQUE slug refuses the second. Look again and retry once.
            if (($e->errorInfo[1] ?? null) !== 1062) {
                throw $e;
            }
            $id = $this->posts->create($row + ['slug' => $this->uniqueSlug($data['title'])]);
        }
        $this->cache->delete(self::HOME_KEY);

        return $id;
    }

    /** @param array<string,string> $input title, body, status, excerpt */
    public function update(string $id, array $input, ?UploadedFileInterface $cover): void
    {
        $existing = $this->getForEdit($id);
        $data = $this->validate($input);
        $newCover = $this->hasUpload($cover) ? $this->uploader->store($cover) : null;
        $removeCover = ($input['remove_cover'] ?? '') === '1';
        $newCoverValue = $newCover ?? ($removeCover ? null : $existing->coverImage);

        // Nothing changed: no write (so updated_at stays), no file deletes, no cache change.
        if ($data['title'] === $existing->title
            && $data['body_md'] === $existing->bodyMd
            && $data['excerpt'] === $existing->excerpt
            && $data['status'] === $existing->status->value
            && $newCoverValue === $existing->coverImage) {
            return;
        }

        $this->posts->update($id, $data + [
            // A new upload wins over the checkbox. Unused old files are deleted after the write (deleteUnused).
            'cover_image' => $newCoverValue,
            // Set the first time the post is published, then kept.
            'published_at' => $existing->publishedAt
                ?? ($data['status'] === PostStatus::Published->value ? gmdate('Y-m-d H:i:s') : null),
        ]);

        $this->cache->deleteMultiple(['post.' . $existing->slug, self::HOME_KEY]);

        // Images the post no longer references: the replaced or removed cover, and inline images dropped from the body.
        $candidates = array_diff($this->uploader->pathsIn($existing->bodyMd), $this->uploader->pathsIn($data['body_md']));
        if ($existing->coverImage !== null && $existing->coverImage !== $newCoverValue) {
            $candidates[] = $existing->coverImage;
        }
        $this->deleteUnused(array_values($candidates));
    }

    /** Admin editor preview: the same CommonMark as the post page, never cached. */
    public function preview(string $markdown): string
    {
        return $this->markdown->render($markdown, null);
    }

    /** Soft delete; then its images that no live post uses are deleted. */
    public function delete(string $id, string $actorId): void
    {
        $post = $this->getForEdit($id); // NotFoundException if missing or already deleted
        if (!$this->posts->softDelete($id, $actorId)) {
            throw new NotFoundException('Post not found');
        }
        $this->cache->deleteMultiple(['post.' . $post->slug, self::HOME_KEY]);
        // The post is 'deleted' now, so it no longer counts as using its images.
        $this->deleteUnused([...$this->uploader->pathsIn($post->bodyMd), ...($post->coverImage !== null ? [$post->coverImage] : [])]);
    }

    /**
     * @param array<string,string> $input
     * @return array{title:string,body_md:string,excerpt:string,status:string}
     */
    private function validate(array $input): array
    {
        $title = trim($input['title'] ?? '');
        $body = trim($input['body'] ?? '');
        $excerpt = trim($input['excerpt'] ?? '');
        $status = PostStatus::tryFrom($input['status'] ?? '');
        $errors = [];

        if (mb_strlen($title) < 1 || mb_strlen($title) > 200) {
            $errors['title'] = 'Enter a title (1 to 200 characters)';
        }
        if ($body === '') {
            $errors['body'] = 'Write something in the body';
        }
        if (mb_strlen($excerpt) > 300) {
            $errors['excerpt'] = 'The summary can be at most 300 characters';
        }
        if ($status === null || $status === PostStatus::Deleted) {
            $errors['status'] = 'Choose draft or published';
        }
        if ($errors !== [] || $status === null) {
            throw new ValidationException($errors);
        }

        return ['title' => $title, 'body_md' => $body, 'excerpt' => $excerpt, 'status' => $status->value];
    }

    /** @param list<string> $candidates paths the post no longer references; each is deleted unless another live post uses it */
    private function deleteUnused(array $candidates): void
    {
        $candidates = array_values(array_unique($candidates));
        $used = $this->posts->uploadsInUse($candidates);
        foreach (array_diff($candidates, $used) as $path) {
            $this->uploader->delete($path);
        }
    }

    private function hasUpload(?UploadedFileInterface $file): bool
    {
        return $file !== null && $file->getError() !== UPLOAD_ERR_NO_FILE;
    }

    private function uniqueSlug(string $title): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title);
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii === false ? '' : $ascii)), '-');
        $base = $base === '' ? 'post' : substr($base, 0, 200);

        $taken = $this->posts->slugsLike($base);
        $slug = $base;
        for ($n = 2; in_array($slug, $taken, true); $n++) {
            $slug = $base . '-' . $n;
        }

        return $slug;
    }

    private function render(Post $post): string
    {
        $stamp = strtotime($post->updatedAt . ' UTC');

        return $this->markdown->render($post->bodyMd, 'md.' . $post->id . '.' . $stamp);
    }

    private function encodeCursor(Post $post): string
    {
        return rtrim(strtr(base64_encode($post->publishedAt . '|' . $post->id), '+/', '-_'), '=');
    }

    /** @return array{publishedAt:string,id:string}|null an invalid cursor is ignored, never an error */
    private function decodeCursor(?string $cursor): ?array
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }
        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($raw === false) {
            return null;
        }
        $parts = explode('|', $raw, 2);
        if (count($parts) !== 2 || preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $parts[0]) !== 1 || !Uuid::isValid($parts[1])) {
            return null;
        }

        return ['publishedAt' => $parts[0], 'id' => $parts[1]];
    }
}
