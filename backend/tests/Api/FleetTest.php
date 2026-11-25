<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\ApiTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class FleetTest extends ApiTestCase
{
    public function testFleetCanCreateAndUpdate(): void
    {
        $this->withGroups('Technische Kommission', function (KernelBrowser $client): void {
            $this->request($client, 'POST', '/api/v1/vehicles', $this->vehicle('Be 4/6'));
            self::assertResponseStatusCodeSame(201);
            $created = $this->jsonBody($client);
            self::assertSame('Be 4/6', $created['name']);
            self::assertNull($created['number']);
            self::assertSame('ready', $created['status']);
            self::assertSame('Einsatzbereit', $created['statusLabel']);
            self::assertTrue($created['tracksMotorHours']);
            self::assertSame([], $created['batteryCharges']);
            self::assertSame([], $created['meterReadings']);
            $id = $created['id'];
            self::assertIsString($id);

            $payload = $this->vehicle('Be 4/6');
            $payload['number'] = '12320';
            $payload['status'] = 'limited';
            $payload['technicalNote'] = 'Technische Übersicht ohne Wartungsplan.';
            $this->request($client, 'PUT', '/api/v1/vehicles/'.$id, $payload);
            self::assertResponseIsSuccessful();
            $updated = $this->jsonBody($client);
            self::assertSame('12320', $updated['number']);
            self::assertSame('limited', $updated['status']);
            self::assertSame('Eingeschränkt', $updated['statusLabel']);
            self::assertSame('Technische Übersicht ohne Wartungsplan.', $updated['technicalNote']);
            self::assertSame(['create', 'update'], $this->auditActions('vehicle'));
        });
    }

    public function testOrganizerAndMemberCannotChangeTheRegister(): void
    {
        $admin = $this->browser();
        $this->request($admin, 'POST', '/api/v1/vehicles', $this->vehicle('Be 4/6', '12320'));
        self::assertResponseStatusCodeSame(201);
        $id = $this->jsonBody($admin)['id'];
        self::assertIsString($id);

        foreach (['Vorstand', 'Mitglieder'] as $group) {
            $this->withGroups($group, function (KernelBrowser $client) use ($id): void {
                $this->request($client, 'POST', '/api/v1/vehicles', $this->vehicle('Neu'));
                self::assertResponseStatusCodeSame(403);
                self::assertSame(
                    'Den Fahrzeugstamm bearbeitet die Technische Kommission.',
                    $this->jsonBody($client)['error'],
                );

                $payload = $this->vehicle('Geändert');
                $this->request($client, 'PUT', '/api/v1/vehicles/'.$id, $payload);
                self::assertResponseStatusCodeSame(403);
            });
        }

        $this->withGroups('Mitglieder', function (KernelBrowser $member) use ($id): void {
            $this->request($member, 'GET', '/api/v1/vehicles');
            self::assertResponseIsSuccessful();
            $body = $this->jsonBody($member);
            self::assertFalse($body['canEdit']);
            self::assertFalse($body['canViewTechnical']);
            $vehicle = $body['vehicles'][0];
            self::assertSame($id, $vehicle['id']);
            self::assertSame('Be 4/6', $vehicle['name']);
            self::assertSame('12320', $vehicle['number']);
            self::assertSame('ready', $vehicle['status']);
            self::assertArrayNotHasKey('technicalNote', $vehicle);
            self::assertArrayNotHasKey('batteryCharges', $vehicle);
            self::assertArrayNotHasKey('meterReadings', $vehicle);
            self::assertArrayNotHasKey('tracksMotorHours', $vehicle);
        });

        $adminAgain = $this->browser();
        $this->request($adminAgain, 'GET', '/api/v1/vehicles');
        self::assertSame('Be 4/6', $this->jsonBody($adminAgain)['vehicles'][0]['name']);
    }

    public function testFleetCanRecordAMeterReading(): void
    {
        $vehicleId = '';
        $this->withGroups('Technische Kommission', function (KernelBrowser $client) use (&$vehicleId): void {
            $this->request($client, 'POST', '/api/v1/vehicles', $this->vehicle('Be 4/6'));
            self::assertResponseStatusCodeSame(201);
            $vehicleId = $this->jsonBody($client)['id'];
            self::assertIsString($vehicleId);

            $this->request($client, 'POST', '/api/v1/vehicles/'.$vehicleId.'/meter-readings', [
                'kind' => 'motor_hours',
                'value' => '1234.5',
                'readOn' => '2026-11-20',
            ]);
            self::assertResponseStatusCodeSame(201);
            $reading = $this->jsonBody($client)['meterReadings'][0];
            self::assertSame('motor_hours', $reading['kind']);
            self::assertSame('Motorstunden', $reading['kindLabel']);
            self::assertSame('1234.5', $reading['value']);
            self::assertSame('2026-11-20', $reading['readOn']);
            self::assertSame('Demo Mitglied', $reading['recordedBy']);
            self::assertSame(['create'], $this->auditActions('meter_reading'));

            $dining = $this->vehicle('Speisewagen Dr');
            $dining['tracksMotorHours'] = false;
            $this->request($client, 'POST', '/api/v1/vehicles', $dining);
            self::assertResponseStatusCodeSame(201);
            $diningId = $this->jsonBody($client)['id'];
            $this->request($client, 'POST', '/api/v1/vehicles/'.$diningId.'/meter-readings', [
                'kind' => 'motor_hours',
                'value' => '10',
                'readOn' => '2026-11-20',
            ]);
            self::assertResponseStatusCodeSame(422);
            self::assertSame(
                'Dieses Fahrzeug hat keinen Motorstunden-Zähler.',
                $this->jsonBody($client)['errors']['kind'],
            );
        });

        $this->withGroups('Mitglieder', function (KernelBrowser $member) use ($vehicleId): void {
            $this->request($member, 'POST', '/api/v1/vehicles/'.$vehicleId.'/meter-readings', [
                'kind' => 'kilometres',
                'value' => '10',
                'readOn' => '2026-11-20',
            ]);
            self::assertResponseStatusCodeSame(403);
            self::assertSame(
                'Den Fahrzeugstamm bearbeitet die Technische Kommission.',
                $this->jsonBody($member)['error'],
            );

            $this->request($member, 'GET', '/api/v1/vehicles');
            $listed = $this->jsonBody($member)['vehicles'][0];
            self::assertArrayNotHasKey('meterReadings', $listed);
        });
    }

    public function testAdminCanUpdate(): void
    {
        $this->withGroups('admin', function (KernelBrowser $client): void {
            $this->request($client, 'POST', '/api/v1/vehicles', $this->vehicle('Be 4/6'));
            self::assertResponseStatusCodeSame(201);
            $id = $this->jsonBody($client)['id'];
            $payload = $this->vehicle('Be 4/6');
            $payload['status'] = 'blocked';
            $this->request($client, 'PUT', '/api/v1/vehicles/'.$id, $payload);
            self::assertResponseIsSuccessful();
            self::assertSame('blocked', $this->jsonBody($client)['status']);
            self::assertSame('Gesperrt', $this->jsonBody($client)['statusLabel']);
        });
    }

    /** @param callable(KernelBrowser): void $callback */
    private function withGroups(string $groups, callable $callback): void
    {
        $env = $_ENV['DEV_USER_GROUPS'] ?? 'admin,Mitglieder';
        $server = $_SERVER['DEV_USER_GROUPS'] ?? $env;
        $exported = getenv('DEV_USER_GROUPS');
        $_ENV['DEV_USER_GROUPS'] = $groups;
        $_SERVER['DEV_USER_GROUPS'] = $groups;
        putenv('DEV_USER_GROUPS='.$groups);

        try {
            self::ensureKernelShutdown();
            $callback($this->browser());
        } finally {
            $_ENV['DEV_USER_GROUPS'] = $env;
            $_SERVER['DEV_USER_GROUPS'] = $server;
            if ($exported === false) {
                putenv('DEV_USER_GROUPS');
            } else {
                putenv('DEV_USER_GROUPS='.$exported);
            }
            self::ensureKernelShutdown();
        }
    }

    /** @return list<string> */
    private function auditActions(string $entityType): array
    {
        $doctrine = static::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $connection = $doctrine->getConnection();
        self::assertInstanceOf(Connection::class, $connection);
        $actions = $connection->fetchFirstColumn(
            "SELECT action FROM audit_entry WHERE entity_type = :type ORDER BY occurred_at ASC, action ASC",
            ['type' => $entityType],
        );
        self::assertIsArray($actions);

        return array_map(static fn (mixed $action): string => (string) $action, $actions);
    }

    /** @return array<string, mixed> */
    private function vehicle(string $name, ?string $number = null): array
    {
        return [
            'name' => $name,
            'number' => $number,
            'status' => 'ready',
            'tracksMotorHours' => true,
            'technicalNote' => null,
        ];
    }
}
