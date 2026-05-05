<?php

declare(strict_types=1);

namespace App\Message;

enum Operation: string
{
    case Upsert = 'upsert';
    case Delete = 'delete';
}
