<?php

declare(strict_types=1);

namespace App\Application\Helfer;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class HelferCredentials
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
     * @return array{base: string, token: string}
     */
    public function resolve(array $environment): array
    {
        $base = trim($environment['HELFER_API_BASE'] ?? '');
        $token = trim($environment['HELFER_API_KEY'] ?? '');
        $tokenName = 'HELFER_API_KEY';
        if ($token === '') {
            $token = trim($environment['HELFER_API_TOKEN'] ?? '');
            $tokenName = 'HELFER_API_TOKEN';
        }
        if ($this->environment !== 'prod' && $this->environment !== 'test' && ($base === '' || $token === '')) {
            $fromFile = $this->readFile($this->filePath);
            if ($base === '') {
                $base = $fromFile['HELFER_API_BASE'];
            }
            if ($token === '') {
                if ($fromFile['HELFER_API_KEY'] !== '') {
                    $token = $fromFile['HELFER_API_KEY'];
                    $tokenName = 'HELFER_API_KEY';
                } else {
                    $token = $fromFile['HELFER_API_TOKEN'];
                    $tokenName = 'HELFER_API_TOKEN';
                }
            }
        }

        $missing = [];
        if ($base === '') {
            $missing[] = 'HELFER_API_BASE';
        }
        if ($token === '') {
            $missing[] = 'HELFER_API_KEY';
            $missing[] = 'HELFER_API_TOKEN';
        } elseif (strpbrk($token, "\r\n\0") !== false) {
            $missing[] = $tokenName;
        }
        if ($missing !== []) {
            throw new HelferNotConfigured($missing);
        }

        return ['base' => $this->normalizeBase($base), 'token' => $token];
    }

    /** @return array<string, string> */
    public function fromProcess(): array
    {
        $values = [];
        foreach (['HELFER_API_BASE', 'HELFER_API_KEY', 'HELFER_API_TOKEN'] as $key) {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
            $values[$key] = is_string($value) ? $value : '';
        }

        return $values;
    }

    public function normalizeBase(string $base): string
    {
        $base = rtrim(trim($base), '/');
        $parts = parse_url($base);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !is_string($parts['host'] ?? null) || $parts['host'] === '') {
            throw new HelferBaseInvalid();
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new HelferBaseInvalid();
        }
        $path = $parts['path'] ?? '';
        if ($path === '' || $path === '/') {
            $path = '/api/public/v1';
        }
        if (!str_ends_with($path, '/api/public/v1') && !str_ends_with($path, '/api/v1')) {
            throw new HelferBaseInvalid();
        }
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return 'https://'.$parts['host'].$port.$path;
    }

    /** @return array{HELFER_API_BASE: string, HELFER_API_KEY: string, HELFER_API_TOKEN: string} */
    private function readFile(string $path): array
    {
        $found = ['HELFER_API_BASE' => '', 'HELFER_API_KEY' => '', 'HELFER_API_TOKEN' => ''];
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
