<?php

declare(strict_types=1);

namespace App\Application\Nextcloud;

final class NextcloudEntry
{
    public function __construct(
        private string $fileId,
        private ?string $ocId,
        private string $name,
        private string $path,
        private bool $collection,
    ) {
    }

    public function fileId(): string
    {
        return $this->fileId;
    }

    public function ocId(): ?string
    {
        return $this->ocId;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function isCollection(): bool
    {
        return $this->collection;
    }
}
