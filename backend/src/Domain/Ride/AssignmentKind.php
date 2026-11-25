<?php

declare(strict_types=1);

namespace App\Domain\Ride;

enum AssignmentKind: string
{
    case Own = 'own';
    case External = 'external';
}
