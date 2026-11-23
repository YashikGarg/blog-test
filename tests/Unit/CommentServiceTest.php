<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exception\ForbiddenException;
use App\Exception\NotFoundException;
use App\Exception\ValidationException;
use App\Model\Comment;
use App\Model\Role;
use App\Repository\CommentRepositoryInterface;
use App\Service\CommentService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

#[Group('unit')]
#[CoversClass(CommentService::class)]
final class CommentServiceTest extends TestCase
{
    public function test_add_saves_comment_on_published_post(): void
    {
        $comments = $this->createMock(CommentRepositoryInterface::class);
        $comments->expects(self::once())->method('createOnPublished')->with('post-1', 'owner', 'Nice post')->willReturn('c-1');
        $comments->method('findVisibleById')->willReturn($this->comment('c-1', 'owner'));
        $service = $this->service($comments);

        $result = $service->add('post-1', 'owner', '  Nice post  ');

        self::assertSame('c-1', $result->id);
    }

    public function test_add_rejects_empty_comment(): void
    {
        $comments = $this->createMock(CommentRepositoryInterface::class);
        $comments->expects(self::never())->method('createOnPublished');
        $service = $this->service($comments);

        $this->expectException(ValidationException::class);

        $service->add('post-1', 'owner', '   ');
    }

    public function test_add_throws_when_post_is_not_published(): void
    {
        $comments = $this->createStub(CommentRepositoryInterface::class);
        $comments->method('createOnPublished')->willReturn(null);
        $service = $this->service($comments);

        $this->expectException(NotFoundException::class);

        $service->add('post-1', 'owner', 'Nice post');
    }

    public function test_owner_can_update_own_comment(): void
    {
        $comments = $this->createMock(CommentRepositoryInterface::class);
        $comments->expects(self::once())->method('updateIfOwner')->with('c-1', 'owner', 'Edited')->willReturn(true);
        $comments->method('findVisibleById')->willReturn($this->comment('c-1', 'owner', 'Edited'));
        $service = $this->service($comments);

        $result = $service->update('c-1', 'owner', 'Edited');

        self::assertSame('Edited', $result->body);
    }

    public function test_user_cannot_update_someone_elses_comment(): void
    {
        $comments = $this->createStub(CommentRepositoryInterface::class);
        $comments->method('updateIfOwner')->willReturn(false);
        $comments->method('findVisibleById')->willReturn($this->comment('c-1', 'owner'));
        $service = $this->service($comments);

        $this->expectException(ForbiddenException::class);

        $service->update('c-1', 'intruder', 'Edited');
    }

    public function test_user_cannot_delete_someone_elses_comment(): void
    {
        $comments = $this->createStub(CommentRepositoryInterface::class);
        $comments->method('softDeleteIfAllowed')->willReturn(false);
        $comments->method('findVisibleById')->willReturn($this->comment('c-1', 'owner'));
        $service = $this->service($comments);

        $this->expectException(ForbiddenException::class);

        $service->delete('c-1', 'intruder', Role::User);
    }

    public function test_admin_can_delete_any_comment(): void
    {
        $comments = $this->createMock(CommentRepositoryInterface::class);
        $comments->expects(self::once())->method('softDeleteIfAllowed')->with('c-1', 'admin-1', true)->willReturn(true);
        $service = $this->service($comments);

        $service->delete('c-1', 'admin-1', Role::Admin);
    }

    public function test_delete_removes_the_cached_comment(): void
    {
        $cache = new Psr16Cache(new ArrayAdapter());
        $cache->set('comment.c-1', $this->comment('c-1', 'owner'));
        $comments = $this->createStub(CommentRepositoryInterface::class);
        $comments->method('softDeleteIfAllowed')->willReturn(true);
        $service = new CommentService($comments, $cache, 1000, 30);

        $service->delete('c-1', 'owner', Role::User);

        self::assertFalse($cache->has('comment.c-1'));
    }

    public function test_list_fetches_only_uncached_comments_in_one_query(): void
    {
        $cache = new Psr16Cache(new ArrayAdapter());
        $cache->set('comment.c-1', $this->comment('c-1', 'owner'));
        $comments = $this->createMock(CommentRepositoryInterface::class);
        $comments->method('visibleIdsPage')->willReturn([
            ['id' => 'c-1', 'createdAt' => '2026-01-02 00:00:00'],
            ['id' => 'c-2', 'createdAt' => '2026-01-01 00:00:00'],
        ]);
        $comments->expects(self::once())->method('findVisibleByIds')->with(['c-2'])
            ->willReturn(['c-2' => $this->comment('c-2', 'owner')]);
        $service = new CommentService($comments, $cache, 1000, 30);

        $page = $service->listForPost('post-1', null);

        self::assertSame(['c-1', 'c-2'], array_map(static fn(Comment $c) => $c->id, $page['comments']));
    }

    private function service(CommentRepositoryInterface $comments): CommentService
    {
        return new CommentService($comments, new Psr16Cache(new ArrayAdapter()), 1000, 30);
    }

    private function comment(string $id, string $userId, string $body = 'Nice post'): Comment
    {
        return new Comment($id, 'post-1', 'a-post', $userId, 'Ann', $body, '2026-01-01 00:00:00', '2026-01-01 00:00:00');
    }
}
