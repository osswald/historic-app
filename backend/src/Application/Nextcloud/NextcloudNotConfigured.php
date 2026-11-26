<?php

declare(strict_types=1);

namespace App\Application\Nextcloud;

final class NextcloudNotConfigured extends \RuntimeException
{
    /** @param list<string> $missing */
    public function __construct(public readonly array $missing)
    {
        parent::__construct('Nextcloud ist noch nicht verbunden. Es fehlt: '.implode(', ', $missing).'.');
    }
}
