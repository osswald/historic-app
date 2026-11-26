<?php

declare(strict_types=1);

namespace App\Application\Spond;

final class AnbindungenCredentials
{
    /** @return array{username: string, password: string} */
    public function read(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            return ['username' => '', 'password' => ''];
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            return ['username' => '', 'password' => ''];
        }

        $found = ['SPOND_USERNAME' => '', 'SPOND_PASSWORD' => ''];
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

        return ['username' => $found['SPOND_USERNAME'], 'password' => $found['SPOND_PASSWORD']];
    }

    private function unwrap(string $value): string
    {
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[0] === $value[strlen($value) - 1]) {
            return substr($value, 1, -1);
        }

        return $value;
    }
}
