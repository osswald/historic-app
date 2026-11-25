<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\ApiTestCase;

final class HealthTest extends ApiTestCase
{
    public function testHealthDoesNotRequireAToken(): void
    {
        $client = $this->browser();
        $this->request($client, 'GET', '/api/v1/health', token: null);

        self::assertResponseIsSuccessful();
        self::assertSame(['status' => 'ok'], $this->jsonBody($client));
    }
}
