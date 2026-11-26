<?php

declare(strict_types=1);

namespace App\Application\Tasks;

final class TasksNotConfigured extends \RuntimeException
{
    /** @param list<string> $missing */
    public function __construct(public readonly array $missing)
    {
        parent::__construct('Die Aufgaben-App ist noch nicht verbunden. Es fehlt: '.implode(', ', $missing).'.');
    }
}
