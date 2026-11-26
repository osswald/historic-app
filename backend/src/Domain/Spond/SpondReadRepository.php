<?php

declare(strict_types=1);

namespace App\Domain\Spond;

interface SpondReadRepository
{
    public function replace(SpondRead $read): void;

    public function latest(): ?SpondRead;
}
