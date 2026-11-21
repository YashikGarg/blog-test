<?php

declare(strict_types=1);

namespace App\Model;

enum VoteStatus: string
{
    case Active = 'active';
    case Removed = 'removed';
}
