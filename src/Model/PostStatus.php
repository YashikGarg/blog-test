<?php

declare(strict_types=1);

namespace App\Model;

enum PostStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Deleted = 'deleted';
}
