<?php

declare(strict_types=1);

namespace App\Application;

final class InvalidRequest extends \RuntimeException
{
    /** @param array<string, string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Die Eingaben sind unvollständig.');
    }
}
