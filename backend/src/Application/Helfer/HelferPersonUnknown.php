<?php

declare(strict_types=1);

namespace App\Application\Helfer;

final class HelferPersonUnknown extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Diese Person ist in der Helferplanung kein aktiver Helfer.');
    }
}
