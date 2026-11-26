<?php

declare(strict_types=1);

namespace App\Application\Nextcloud;

final class NextcloudNotFound extends \RuntimeException
{
    public function __construct(string $message = 'Der Ordner ist in Nextcloud nicht vorhanden.')
    {
        parent::__construct($message);
    }
}
