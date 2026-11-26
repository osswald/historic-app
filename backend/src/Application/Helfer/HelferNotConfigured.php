<?php

declare(strict_types=1);

namespace App\Application\Helfer;

final class HelferNotConfigured extends \RuntimeException
{
    /** @param list<string> $missing */
    public function __construct(public readonly array $missing)
    {
        parent::__construct('Die Helferplanung ist noch nicht verbunden. Es fehlt: '.implode(', ', $missing).'.');
    }
}
