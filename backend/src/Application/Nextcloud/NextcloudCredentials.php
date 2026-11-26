<?php

declare(strict_types=1);

namespace App\Application\Nextcloud;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class NextcloudCredentials
{
    public function __construct(
        #[Autowire('%kernel.environment%')]
        private string $environment,
        #[Autowire('%kernel.project_dir%/../.local/anbindungen.env')]
        private string $filePath,
    ) {
    }

    /**
     * @param array<string, string> $environment
     * @return array{base: string, user: string, password: string}
     */
    public function resolve(array $environment): array
    {
        $base = trim($environment['NEXTCLOUD_BASE'] ?? '');
        $user = trim($environment['NEXTCLOUD_USER'] ?? '');
        $password = trim($environment['NEXTCLOUD_APP_PASSWORD'] ?? '');
        if ($this->environment !== 'prod' && $this->environment !== 'test' && ($base === '' || $user === '' || $password === '')) {
            $fromFile = $this->readFile($this->filePath);
            if ($base === '') {
                $base = $fromFile['NEXTCLOUD_BASE'];
            }
            if ($user === '') {
                $user = $fromFile['NEXTCLOUD_USER'];
            }
            if ($password === '') {
                $password = $fromFile['NEXTCLOUD_APP_PASSWORD'];
            }
        }

        $missing = [];
        if ($base === '') {
            $missing[] = 'NEXTCLOUD_BASE';
        }
        if ($user === '' || strpbrk($user, "\r\n\0") !== false) {
            $missing[] = 'NEXTCLOUD_USER';
        }
        if ($password === '' || strpbrk($password, "\r\n\0") !== false) {
            $missing[] = 'NEXTCLOUD_APP_PASSWORD';
        }
        if ($missing !== []) {
            throw new NextcloudNotConfigured($missing);
        }

        return ['base' => $this->normalizeBase($base), 'user' => $user, 'password' => $password];
    }

    /** @return array<string, string> */
    public function fromProcess(): array
    {
        $values = [];
        foreach (['NEXTCLOUD_BASE', 'NEXTCLOUD_USER', 'NEXTCLOUD_APP_PASSWORD'] as $key) {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
            $values[$key] = is_string($value) ? $value : '';
        }

        return $values;
    }

    public function normalizeBase(string $base): string
    {
        $parts = parse_url(trim($base));
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !is_string($parts['host'] ?? null) || $parts['host'] === '') {
            throw new NextcloudBaseInvalid();
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new NextcloudBaseInvalid();
        }
        $path = rtrim($parts['path'] ?? '', '/');
        foreach (['/remote.php/dav/files', '/remote.php/dav', '/remote.php', '/index.php'] as $suffix) {
            if ($path === $suffix || str_ends_with($path, $suffix)) {
                $path = rtrim(substr($path, 0, -strlen($suffix)), '/');
                break;
            }
        }
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return 'https://'.$parts['host'].$port.$path;
    }

    /** @return array{NEXTCLOUD_BASE: string, NEXTCLOUD_USER: string, NEXTCLOUD_APP_PASSWORD: string} */
    private function readFile(string $path): array
    {
        $found = ['NEXTCLOUD_BASE' => '', 'NEXTCLOUD_USER' => '', 'NEXTCLOUD_APP_PASSWORD' => ''];
        if (!is_file($path) || !is_readable($path)) {
            return $found;
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            return $found;
        }
        foreach (preg_split("/\r\n|\n|\r/", $contents) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            if (str_starts_with($key, 'export ')) {
                $key = trim(substr($key, 7));
            }
            if (!array_key_exists($key, $found)) {
                continue;
            }
            $found[$key] = $this->unwrap(trim($value));
        }

        return $found;
    }

    private function unwrap(string $value): string
    {
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[0] === $value[strlen($value) - 1]) {
            return substr($value, 1, -1);
        }

        return $value;
    }
}
