<?php

declare(strict_types=1);

namespace App\Application\Helfer;

final class HelferNotFound extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Der Anlass ist in der Helferplanung nicht vorhanden.');
    }
}
