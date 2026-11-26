<?php

declare(strict_types=1);

namespace App\Application\Nextcloud;

final class NextcloudBaseInvalid extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Die Adresse von Nextcloud ist ungültig.');
    }
}
