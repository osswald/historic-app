<?php

declare(strict_types=1);

namespace App\Domain\Ride;

enum DayOffset: int
{
    case Previous = -1;
    case Same = 0;
    case Next = 1;
}
