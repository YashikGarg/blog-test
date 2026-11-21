<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exception\ForbiddenException;
use App\Exception\ValidationException;
use App\Model\Comment;
use App\Model\Post;
use App\Model\PostStatus;
use App\Model\Role;
use App\Repository\CommentRepositoryInterface;
use App\Repository\PostRepositoryInterface;
use App\Service\CommentService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
#[CoversClass(CommentService::class)]
final class CommentServiceTest extends TestCase
{
    public function test_add_saves_comment_on_published_post(): void
    {
        $posts = $this->createStub(PostRepositoryInterface::class);
        $posts->method('findById')->willReturn($this->post(PostStatus::Published));
        $comments = $this->createMock(CommentRepositoryInterface::class);
        $comments->expects(self::once())->method('create')->with('post-1', 'owner', 'Nice post')->willReturn('c-1');
        $comments->method('findVisibleById')->willReturn($this->comment('owner'));
        $service = new CommentService($comments, $posts, 1000);

        $result = $service->add('post-1', 'owner', '  Nice post  ');

        self::assertSame('c-1', $result->id);
    }

    public function test_add_rejects_empty_comment(): void
    {
        $comments = $this->createMock(CommentRepositoryInterface::class);
        $comments->expects(self::never())->method('create');
        $service = new CommentService($comments, $this->createStub(PostRepositoryInterface::class), 1000);

        $this->expectException(ValidationException::class);

        $service->add('post-1', 'owner', '   ');
    }

    public function test_owner_can_update_own_comment(): void
    {
        $comments = $this->createMock(CommentRepositoryInterface::class);
        $comments->method('findVisibleById')->willReturn($this->comment('owner'));
        $comments->expects(self::once())->method('update')->with('c-1', 'Edited');
        $service = new CommentService($comments, $this->createStub(PostRepositoryInterface::class), 1000);

        $service->update('c-1', 'owner', 'Edited');
    }

    public function test_user_cannot_update_someone_elses_comment(): void
    {
        $comments = $this->createMock(CommentRepositoryInterface::class);
        $comments->method('findVisibleById')->willReturn($this->comment('owner'));
        $comments->expects(self::never())->method('update');
        $service = new CommentService($comments, $this->createStub(PostRepositoryInterface::class), 1000);

        $this->expectException(ForbiddenException::class);

        $service->update('c-1', 'intruder', 'Edited');
    }

    public function test_user_cannot_delete_someone_elses_comment(): void
    {
        $comments = $this->createMock(CommentRepositoryInterface::class);
        $comments->method('findVisibleById')->willReturn($this->comment('owner'));
        $comments->expects(self::never())->method('softDelete');
        $service = new CommentService($comments, $this->createStub(PostRepositoryInterface::class), 1000);

        $this->expectException(ForbiddenException::class);

        $service->delete('c-1', 'intruder', Role::User);
    }

    public function test_admin_can_delete_any_comment(): void
    {
        $comments = $this->createMock(CommentRepositoryInterface::class);
        $comments->method('findVisibleById')->willReturn($this->comment('owner'));
        $comments->expects(self::once())->method('softDelete')->with('c-1');
        $service = new CommentService($comments, $this->createStub(PostRepositoryInterface::class), 1000);

        $service->delete('c-1', 'admin-1', Role::Admin);
    }

    private function comment(string $userId): Comment
    {
        return new Comment('c-1', 'post-1', 'a-post', $userId, 'Ann', 'Nice post', '2026-01-01 00:00:00', '2026-01-01 00:00:00');
    }

    private function post(PostStatus $status): Post
    {
        return new Post('post-1', 'a-1', 'Author', 'Title', 'title', '', 'body', null, $status, 0, '2026-01-01 00:00:00', '2026-01-01 00:00:00');
    }
}
