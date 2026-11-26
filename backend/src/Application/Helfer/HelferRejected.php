<?php

declare(strict_types=1);

namespace App\Application\Helfer;

final class HelferRejected extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Die Helferplanung hat den Zugang abgelehnt.');
    }
}
