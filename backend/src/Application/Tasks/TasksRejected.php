<?php

declare(strict_types=1);

namespace App\Application\Tasks;

final class TasksRejected extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Der Zugang zur Aufgaben-App wurde abgelehnt.');
    }
}
