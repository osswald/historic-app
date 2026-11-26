<?php

declare(strict_types=1);

namespace App\Application\Nextcloud;

final class NextcloudRejected extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Nextcloud hat die Anmeldung abgelehnt.');
    }
}
