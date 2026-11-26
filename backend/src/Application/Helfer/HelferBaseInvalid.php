<?php

declare(strict_types=1);

namespace App\Application\Helfer;

final class HelferBaseInvalid extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Die Adresse der Helferplanung ist ungültig.');
    }
}
