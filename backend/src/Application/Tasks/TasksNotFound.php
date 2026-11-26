<?php

declare(strict_types=1);

namespace App\Application\Tasks;

final class TasksNotFound extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Der Eintrag ist in der Aufgaben-App nicht vorhanden.');
    }
}
