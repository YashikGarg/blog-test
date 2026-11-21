<?php

declare(strict_types=1);

namespace App\Model;

enum CommentStatus: string
{
    case Visible = 'visible';
    case Deleted = 'deleted';
}
