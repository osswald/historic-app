<?php

declare(strict_types=1);

namespace App\Application\Tasks;

final class TasksPersonUnknown extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Diese Person ist in der Aufgaben-App unbekannt, archiviert oder gelöscht.');
    }
}
