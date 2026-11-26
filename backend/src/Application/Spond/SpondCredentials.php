<?php

declare(strict_types=1);

namespace App\Application\Spond;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class SpondCredentials
{
    public function __construct(
        #[Autowire('%kernel.environment%')]
        private string $environment,
        #[Autowire('%kernel.project_dir%/../.local/anbindungen.env')]
        private string $filePath,
        private AnbindungenCredentials $file,
    ) {
    }

    /**
     * Production reads the environment only. A local run may fill gaps from the gitignored file.
     *
     * @param array<string, string> $environment
     * @return array{username: string, password: string}
     */
    public function resolve(array $environment): array
    {
        $username = trim($environment['SPOND_USERNAME'] ?? '');
        $password = trim($environment['SPOND_PASSWORD'] ?? '');
        if ($this->environment !== 'prod' && ($username === '' || $password === '')) {
            $fromFile = $this->file->read($this->filePath);
            if ($username === '') {
                $username = $fromFile['username'];
            }
            if ($password === '') {
                $password = $fromFile['password'];
            }
        }
        if ($username === '' || $password === '') {
            throw new SpondExportException('SPOND_USERNAME und SPOND_PASSWORD fehlen.');
        }

        return ['username' => $username, 'password' => $password];
    }
}
