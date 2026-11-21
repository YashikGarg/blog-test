<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exception\NotFoundException;
use App\Model\Post;
use App\Model\PostStatus;
use App\Repository\PostRepositoryInterface;
use App\Repository\VoteRepositoryInterface;
use App\Service\VoteService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
#[CoversClass(VoteService::class)]
final class VoteServiceTest extends TestCase
{
    public function test_toggle_returns_new_vote_state(): void
    {
        $posts = $this->createStub(PostRepositoryInterface::class);
        $posts->method('findById')->willReturn($this->post(PostStatus::Published));
        $votes = $this->createStub(VoteRepositoryInterface::class);
        $votes->method('toggle')->willReturn(['voted' => true, 'count' => 3]);
        $service = new VoteService($votes, $posts);

        $result = $service->toggle('post-1', 'user-1');

        self::assertSame(['voted' => true, 'count' => 3], $result);
    }

    public function test_toggle_throws_for_unknown_post(): void
    {
        $posts = $this->createStub(PostRepositoryInterface::class);
        $posts->method('findById')->willReturn(null);
        $votes = $this->createMock(VoteRepositoryInterface::class);
        $votes->expects(self::never())->method('toggle');
        $service = new VoteService($votes, $posts);

        $this->expectException(NotFoundException::class);

        $service->toggle('missing', 'user-1');
    }

    private function post(PostStatus $status): Post
    {
        return new Post('post-1', 'a-1', 'Author', 'Title', 'title', '', 'body', null, $status, 0, '2026-01-01 00:00:00', '2026-01-01 00:00:00');
    }
}
