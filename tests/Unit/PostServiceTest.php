<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exception\NotFoundException;
use App\Repository\PostRepositoryInterface;
use App\Service\ImageUploader;
use App\Service\MarkdownRendererInterface;
use App\Service\PostService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
#[CoversClass(PostService::class)]
final class PostServiceTest extends TestCase
{
    public function test_create_adds_suffix_when_slug_is_taken(): void
    {
        $posts = $this->createMock(PostRepositoryInterface::class);
        $posts->method('slugExists')->willReturnMap([
            ['hello-world', null, true],
            ['hello-world-2', null, false],
        ]);
        $posts->expects(self::once())->method('create')
            ->with(self::callback(static fn (array $data): bool => $data['slug'] === 'hello-world-2'))
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

    private function service(PostRepositoryInterface $posts): PostService
    {
        $uploader = new ImageUploader(sys_get_temp_dir() . '/inkwell-unused', '/uploads/', 1048576, ['image/png']);

        return new PostService($posts, $this->createStub(MarkdownRendererInterface::class), $uploader, 10);
    }
}
