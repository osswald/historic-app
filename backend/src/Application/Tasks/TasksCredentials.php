<?php

declare(strict_types=1);

namespace App\Application\Tasks;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class TasksCredentials
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
        $base = trim($environment['TASKS_API_BASE'] ?? '');
        $token = trim($environment['TASKS_API_KEY'] ?? '');
        $tokenName = 'TASKS_API_KEY';
        if ($token === '') {
            $token = trim($environment['TASKS_API_TOKEN'] ?? '');
            $tokenName = 'TASKS_API_TOKEN';
        }
        if ($this->environment !== 'prod' && $this->environment !== 'test' && ($base === '' || $token === '')) {
            $fromFile = $this->readFile($this->filePath);
            if ($base === '') {
                $base = $fromFile['TASKS_API_BASE'];
            }
            if ($token === '') {
                if ($fromFile['TASKS_API_KEY'] !== '') {
                    $token = $fromFile['TASKS_API_KEY'];
                    $tokenName = 'TASKS_API_KEY';
                } else {
                    $token = $fromFile['TASKS_API_TOKEN'];
                    $tokenName = 'TASKS_API_TOKEN';
                }
            }
        }

        $missing = [];
        if ($base === '') {
            $missing[] = 'TASKS_API_BASE';
        }
        if ($token === '') {
            $missing[] = 'TASKS_API_KEY';
            $missing[] = 'TASKS_API_TOKEN';
        } elseif (strpbrk($token, "\r\n\0") !== false) {
            $missing[] = $tokenName;
        }
        if ($missing !== []) {
            throw new TasksNotConfigured($missing);
        }

        return ['base' => $this->normalizeBase($base), 'token' => $token];
    }

    /** @return array<string, string> */
    public function fromProcess(): array
    {
        $values = [];
        foreach (['TASKS_API_BASE', 'TASKS_API_KEY', 'TASKS_API_TOKEN'] as $key) {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
            $values[$key] = is_string($value) ? $value : '';
        }

        return $values;
    }

    /**
     * The contract base ends in /api/v1. A base that stops at /functions/v1 is the same host.
     */
    public function normalizeBase(string $base): string
    {
        $base = rtrim(trim($base), '/');
        $parts = parse_url($base);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !is_string($parts['host'] ?? null) || $parts['host'] === '') {
            throw new TasksBaseInvalid();
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new TasksBaseInvalid();
        }
        $path = $parts['path'] ?? '';
        if (!str_ends_with($path, '/api/v1') && !str_ends_with($path, '/functions/v1')) {
            throw new TasksBaseInvalid();
        }
        if (str_ends_with($path, '/functions/v1')) {
            $path .= '/api/v1';
        }
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return 'https://'.$parts['host'].$port.$path;
    }

    /** @return array{TASKS_API_BASE: string, TASKS_API_KEY: string, TASKS_API_TOKEN: string} */
    private function readFile(string $path): array
    {
        $found = ['TASKS_API_BASE' => '', 'TASKS_API_KEY' => '', 'TASKS_API_TOKEN' => ''];
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
