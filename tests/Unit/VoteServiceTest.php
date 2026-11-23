<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exception\NotFoundException;
use App\Repository\VoteRepositoryInterface;
use App\Service\VoteService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

#[Group('unit')]
#[CoversClass(VoteService::class)]
final class VoteServiceTest extends TestCase
{
    public function test_changed_vote_updates_the_cached_count_without_a_query(): void
    {
        $votes = $this->createMock(VoteRepositoryInterface::class);
        $votes->method('set')->willReturn(true);
        $votes->expects(self::never())->method('count');
        $cache = new Psr16Cache(new ArrayAdapter());
        $cache->set('votes.post-1', 5);
        $service = new VoteService($votes, $cache, 300);

        $result = $service->vote('post-1', 'user-1', '0');

        self::assertSame(['voted' => false, 'changed' => true], $result);
        self::assertSame(4, $cache->get('votes.post-1'));
    }

    public function test_repeat_vote_keeps_the_cached_count(): void
    {
        $votes = $this->createStub(VoteRepositoryInterface::class);
        $votes->method('set')->willReturn(false);
        $cache = new Psr16Cache(new ArrayAdapter());
        $cache->set('votes.post-1', 5);
        $service = new VoteService($votes, $cache, 300);

        $result = $service->vote('post-1', 'user-1', '1');

        self::assertSame(['voted' => true, 'changed' => false], $result);
        self::assertSame(5, $cache->get('votes.post-1'));
    }

    public function test_vote_throws_for_unpublished_post(): void
    {
        $votes = $this->createStub(VoteRepositoryInterface::class);
        $votes->method('set')->willReturn(null);
        $service = new VoteService($votes, new Psr16Cache(new ArrayAdapter()), 300);

        $this->expectException(NotFoundException::class);

        $service->vote('draft-1', 'user-1', '1');
    }

    public function test_count_comes_from_cache_without_a_query(): void
    {
        $votes = $this->createMock(VoteRepositoryInterface::class);
        $votes->expects(self::never())->method('count');
        $cache = new Psr16Cache(new ArrayAdapter());
        $cache->set('votes.post-1', 5);
        $service = new VoteService($votes, $cache, 300);

        $count = $service->count('post-1');

        self::assertSame(5, $count);
    }
}
