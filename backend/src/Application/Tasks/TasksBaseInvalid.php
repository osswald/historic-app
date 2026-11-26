<?php

declare(strict_types=1);

namespace App\Application\Tasks;

final class TasksBaseInvalid extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('TASKS_API_BASE ist keine verwendbare https-Adresse der Aufgaben-API. Es wurde nichts abgefragt.');
    }
}
