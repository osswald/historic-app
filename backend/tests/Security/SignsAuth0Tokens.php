<?php

declare(strict_types=1);

namespace App\Tests\Security;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PHPUnit\Framework\TestCase;

trait SignsAuth0Tokens
{
    private static string $privateKey;

    private static Key $publicKey;

    public static function setUpBeforeClass(): void
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        TestCase::assertNotFalse($key);
        $exported = '';
        TestCase::assertTrue(openssl_pkey_export($key, $exported));
        self::$privateKey = $exported;
        $details = openssl_pkey_get_details($key);
        TestCase::assertIsArray($details);
        self::$publicKey = new Key($details['key'], 'RS256');
    }

    /** @param array<string, mixed> $claims */
    private function token(array $claims, string $algorithm = 'RS256'): string
    {
        $claims += [
            'iss' => 'https://login.example.test/',
            'exp' => time() + 600,
            'iat' => time(),
        ];

        return JWT::encode($claims, self::$privateKey, $algorithm);
    }
}
