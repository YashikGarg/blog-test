<?php

declare(strict_types=1);

namespace App\Service;

use Psr\SimpleCache\CacheInterface;

/** Decorator: caches rendered HTML by key. An edit changes the key, so nothing needs invalidating. */
final readonly class CachedMarkdownRenderer implements MarkdownRendererInterface
{
    public function __construct(
        private MarkdownRendererInterface $inner,
        private CacheInterface $cache,
        private int $ttl,
    ) {
    }

    public function render(string $markdown, string $cacheKey): string
    {
        $cached = $this->cache->get($cacheKey);
        if (is_string($cached)) {
            return $cached;
        }
        $html = $this->inner->render($markdown, $cacheKey);
        $this->cache->set($cacheKey, $html, $this->ttl);

        return $html;
    }
}
