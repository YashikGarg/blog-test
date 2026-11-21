<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\NotFoundException;
use App\Exception\ValidationException;
use App\Model\Post;
use App\Model\PostStatus;
use App\Repository\PostRepositoryInterface;
use Psr\Http\Message\UploadedFileInterface;
use Symfony\Component\Uid\Uuid;

final readonly class PostService
{
    public function __construct(
        private PostRepositoryInterface $posts,
        private MarkdownRendererInterface $markdown,
        private ImageUploader $uploader,
        private int $perPage,
    ) {
    }

    /** @return array{posts: list<Post>, nextCursor: ?string, q: ?string} */
    public function listPublished(?string $cursor, ?string $q): array
    {
        $q = $q === null ? null : mb_substr(trim($q), 0, 100);
        $q = $q === '' ? null : $q;

        $rows = $this->posts->findPublished($this->perPage + 1, $q, $this->decodeCursor($cursor));
        $hasMore = count($rows) > $this->perPage;
        $posts = array_slice($rows, 0, $this->perPage);
        $last = $posts === [] ? null : end($posts);

        return [
            'posts' => $posts,
            'nextCursor' => $hasMore && $last !== null && $last->publishedAt !== null ? $this->encodeCursor($last) : null,
            'q' => $q,
        ];
    }

    /** @return array{post: Post, html: string} */
    public function getPublishedBySlug(string $slug): array
    {
        $post = $this->posts->findBySlug($slug) ?? throw new NotFoundException('Post not found');

        return ['post' => $post, 'html' => $this->render($post)];
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

        return $this->posts->create($data + [
            'author_id' => $authorId,
            'slug' => $this->uniqueSlug($data['title']),
            'cover_image' => $coverPath,
            'published_at' => $data['status'] === PostStatus::Published->value ? gmdate('Y-m-d H:i:s') : null,
        ]);
    }

    /** @param array<string,string> $input title, body, status, excerpt */
    public function update(string $id, array $input, ?UploadedFileInterface $cover): void
    {
        $existing = $this->getForEdit($id);
        $data = $this->validate($input);
        $newCover = $this->hasUpload($cover) ? $this->uploader->store($cover) : null;

        $this->posts->update($id, $data + [
            'cover_image' => $newCover ?? $existing->coverImage,
            // Set the first time the post is published, then kept.
            'published_at' => $existing->publishedAt
                ?? ($data['status'] === PostStatus::Published->value ? gmdate('Y-m-d H:i:s') : null),
        ]);

        if ($newCover !== null && $existing->coverImage !== null) {
            $this->uploader->delete($existing->coverImage);
        }
    }

    /** Soft delete. The cover file is kept so the post stays restorable. */
    public function delete(string $id): void
    {
        if (!$this->posts->softDelete($id)) {
            throw new NotFoundException('Post not found');
        }
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

    private function hasUpload(?UploadedFileInterface $file): bool
    {
        return $file !== null && $file->getError() !== UPLOAD_ERR_NO_FILE;
    }

    private function uniqueSlug(string $title): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title);
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii === false ? '' : $ascii)), '-');
        $base = $base === '' ? 'post' : substr($base, 0, 200);

        $slug = $base;
        for ($n = 2; $this->posts->slugExists($slug, null); $n++) {
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
