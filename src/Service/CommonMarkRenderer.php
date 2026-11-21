<?php

declare(strict_types=1);

namespace App\Service;

use League\CommonMark\CommonMarkConverter;

final class CommonMarkRenderer implements MarkdownRendererInterface
{
    private CommonMarkConverter $converter;

    public function __construct()
    {
        $this->converter = new CommonMarkConverter([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 50,
        ]);
    }

    public function render(string $markdown, string $cacheKey): string
    {
        return (string) $this->converter->convert($markdown);
    }
}
