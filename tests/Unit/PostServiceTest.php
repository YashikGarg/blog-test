<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exception\NotFoundException;
use App\Model\Post;
use App\Model\PostStatus;
use App\Repository\PostRepositoryInterface;
use App\Service\ImageUploader;
use App\Service\MarkdownRendererInterface;
use App\Service\PostService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

#[Group('unit')]
#[CoversClass(PostService::class)]
final class PostServiceTest extends TestCase
{
    /** A generated upload name is 32 hex characters plus the extension; ImageUploader::delete ignores anything else. */
    private const OLD_FILE = '0123456789abcdef0123456789abcdef.png';

    /** @var list<string> */
    private array $tempDirs = [];

    public function test_create_adds_suffix_when_slug_is_taken(): void
    {
        $posts = $this->createMock(PostRepositoryInterface::class);
        $posts->method('slugsLike')->willReturn(['hello-world', 'hello-world-2']);
        $posts->expects(self::once())->method('create')
            ->with(self::callback(static fn (array $data): bool => $data['slug'] === 'hello-world-3'))
            ->willReturn('new-id');
        $service = $this->service($posts);

        $id = $service->create('author-1', ['title' => 'Hello World', 'body' => 'Text', 'status' => 'draft', 'excerpt' => ''], null);

        self::assertSame('new-id', $id);
    }

    public function test_get_published_by_slug_throws_when_missing(): void
    {
        $posts = $this->createStub(PostRepositoryInterface::class);
        $posts->method('findBySlug')->willReturn(null);
        $service = $this->service($posts);

        $this->expectException(NotFoundException::class);

        $service->getPublishedBySlug('nope');
    }

    public function test_update_with_remove_cover_deletes_the_unused_old_file(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/' . self::OLD_FILE, 'png');
        $posts = $this->createMock(PostRepositoryInterface::class);
        $posts->method('findById')->willReturn($this->post('/uploads/' . self::OLD_FILE));
        $posts->method('uploadsInUse')->willReturn([]);
        $posts->expects(self::once())->method('update')
            ->with('post-1', self::callback(static fn (array $data): bool => $data['cover_image'] === null));
        $service = $this->service($posts, $dir);

        $service->update('post-1', ['title' => 'T', 'body' => 'B', 'status' => 'draft', 'excerpt' => '', 'remove_cover' => '1'], null);

        self::assertFileDoesNotExist($dir . '/' . self::OLD_FILE);
    }

    public function test_update_keeps_old_cover_still_used_by_another_post(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/' . self::OLD_FILE, 'png');
        $posts = $this->createStub(PostRepositoryInterface::class);
        $posts->method('findById')->willReturn($this->post('/uploads/' . self::OLD_FILE));
        $posts->method('uploadsInUse')->willReturn(['/uploads/' . self::OLD_FILE]);
        $service = $this->service($posts, $dir);

        $service->update('post-1', ['title' => 'T', 'body' => 'B', 'status' => 'draft', 'excerpt' => '', 'remove_cover' => '1'], null);

        self::assertFileExists($dir . '/' . self::OLD_FILE);
    }

    public function test_update_without_changes_writes_nothing(): void
    {
        $posts = $this->createMock(PostRepositoryInterface::class);
        $posts->method('findById')->willReturn($this->post(null));
        $posts->expects(self::never())->method('update');
        $service = $this->service($posts);

        $service->update('post-1', ['title' => 'Title', 'body' => 'body', 'status' => 'draft', 'excerpt' => ''], null);
    }

    public function test_published_post_is_read_once_then_served_from_cache(): void
    {
        $posts = $this->createMock(PostRepositoryInterface::class);
        $posts->expects(self::once())->method('findBySlug')->willReturn($this->post(null, PostStatus::Published));
        $service = $this->service($posts);

        $service->getPublishedBySlug('title');
        $second = $service->getPublishedBySlug('title');

        self::assertSame('Title', $second['post']->title);
    }

    public function test_update_clears_the_cached_post(): void
    {
        $posts = $this->createMock(PostRepositoryInterface::class);
        $posts->method('findById')->willReturn($this->post(null, PostStatus::Published));
        $posts->expects(self::exactly(2))->method('findBySlug')->willReturn($this->post(null, PostStatus::Published));
        $service = $this->service($posts);
        $service->getPublishedBySlug('title');

        $service->update('post-1', ['title' => 'New title', 'body' => 'body', 'status' => 'published', 'excerpt' => ''], null);
        $service->getPublishedBySlug('title');
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            array_map('unlink', glob($dir . '/*') ?: []);
            rmdir($dir);
        }
        $this->tempDirs = [];
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/inkwell-' . bin2hex(random_bytes(6));
        mkdir($dir);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    private function post(?string $cover, PostStatus $status = PostStatus::Draft): Post
    {
        return new Post('post-1', 'a-1', 'Author', 'Title', 'title', '', 'body', $cover, $status, 0, null, '2026-01-01 00:00:00');
    }

    private function service(PostRepositoryInterface $posts, ?string $uploadDir = null): PostService
    {
        $uploader = new ImageUploader($uploadDir ?? sys_get_temp_dir() . '/inkwell-unused', '/uploads/', 1048576, ['image/png']);

        return new PostService($posts, $this->createStub(MarkdownRendererInterface::class), $uploader, new Psr16Cache(new ArrayAdapter()), 10, 300);
    }
}
