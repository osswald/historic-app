<?php

declare(strict_types=1);

namespace App\Domain\Nextcloud;

final class EventHasNoDate extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Der Anlass hat noch kein Datum.');
    }
}
