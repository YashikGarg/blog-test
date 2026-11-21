<?php

declare(strict_types=1);

namespace App\Service;

interface MarkdownRendererInterface
{
    /** @param string|null $cacheKey stable key for this exact markdown (decorators may cache by it); null = never cache (previews) */
    public function render(string $markdown, ?string $cacheKey): string;
}
