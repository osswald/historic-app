<?php

declare(strict_types=1);

namespace App\Application\Gastronomy;

use App\Application\Event\EventCatalog;
use App\Application\InvalidRequest;
use App\Application\ZurichClock;
use App\Domain\Gastronomy\GastroPlan;
use App\Domain\Gastronomy\GastroPlanRepository;
use App\Infrastructure\Audit\AuditRecorder;
use App\Security\MemberUser;
use Doctrine\ORM\EntityManagerInterface;

final class SaveGastroPlan
{
    public function __construct(
        private EventCatalog $events,
        private GastroPlanRepository $plans,
        private GastroAccess $access,
        private GastroPresenter $presenter,
        private AuditRecorder $audit,
        private EntityManagerInterface $entityManager,
        private ZurichClock $clock,
    ) {
    }

    /** @param array<string, mixed> $payload
     *  @return array{canEdit: bool, plan: ?array<string, mixed>}
     */
    public function replace(MemberUser $actor, string $eventId, array $payload): array
    {
        $event = $this->events->visible($eventId, $actor);
        $plan = $this->plans->byEventId($event->id());
        $this->access->assertCanEdit($actor, $plan);
        $input = $this->normalize($payload);
        $now = $this->clock->now();
        if (!$plan instanceof GastroPlan) {
            $plan = new GastroPlan($event, $now);
            $plan->replace($input['notes'], $input['guestCount'], $input['dishes'], $input['responsible'], $now);
            $this->plans->add($plan);
            $this->audit->record($actor->getUserIdentifier(), 'gastro_plan', $plan->id(), 'create', null, $plan->toAudit(), $now, $actor->displayName());
        } else {
            $before = $plan->toAudit();
            $plan->replace($input['notes'], $input['guestCount'], $input['dishes'], $input['responsible'], $now);
            $this->audit->record($actor->getUserIdentifier(), 'gastro_plan', $plan->id(), 'update', $before, $plan->toAudit(), $now, $actor->displayName());
        }
        $this->entityManager->flush();

        return $this->presenter->present($actor, $plan);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{notes: ?string, guestCount: ?int, dishes: list<array{name: string, portions: int}>, responsible: list<array{sub: string, name: string}>}
     */
    private function normalize(array $payload): array
    {
        $errors = [];
        $notes = $payload['notes'] ?? '';
        if ($notes !== null && !is_string($notes)) {
            $errors['notes'] = 'Bitte die Notiz als Text angeben.';
            $notes = '';
        }
        $notes = trim((string) $notes);
        if (mb_strlen($notes) > 4000) {
            $errors['notes'] = 'Die Notiz darf höchstens 4000 Zeichen haben.';
        }

        $guestCount = $this->guestCount($payload['guestCount'] ?? null, $errors);
        $dishes = $this->dishes($payload['dishes'] ?? null, $errors);
        $responsible = $this->responsible($payload['responsible'] ?? null, $errors);
        if ($errors !== []) {
            throw new InvalidRequest($errors);
        }

        return [
            'notes' => $notes !== '' ? $notes : null,
            'guestCount' => $guestCount,
            'dishes' => $dishes,
            'responsible' => $responsible,
        ];
    }

    /** @param array<string, string> $errors */
    private function guestCount(mixed $value, array &$errors): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < 0 || $value > 100000) {
            $errors['guestCount'] = 'Bitte die geschätzte Gästezahl als ganze Zahl zwischen 0 und 100000 angeben.';

            return null;
        }

        return $value;
    }

    /**
     * @param array<string, string> $errors
     * @return list<array{name: string, portions: int}>
     */
    private function dishes(mixed $value, array &$errors): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            $errors['dishes'] = 'Bitte die Gerichte als Liste angeben.';

            return [];
        }
        if (count($value) > 40) {
            $errors['dishes'] = 'Bitte höchstens 40 Gerichte angeben.';

            return [];
        }

        $rows = [];
        foreach ($value as $index => $dish) {
            if (!is_array($dish)) {
                $errors['dishes.'.$index] = 'Das Gericht ist ungültig.';
                continue;
            }
            $name = trim((string) ($dish['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 120) {
                $errors['dishes.'.$index.'.name'] = 'Bitte den Namen des Gerichts mit höchstens 120 Zeichen angeben.';
            }
            $portions = $dish['portions'] ?? null;
            if (!is_int($portions) || $portions < 1 || $portions > 9999) {
                $errors['dishes.'.$index.'.portions'] = 'Bitte die Anzahl Portionen zwischen 1 und 9999 angeben.';
                $portions = 1;
            }
            if ($name !== '' && mb_strlen($name) <= 120) {
                $rows[] = ['name' => $name, 'portions' => $portions];
            }
        }

        return $rows;
    }

    /**
     * @param array<string, string> $errors
     * @return list<array{sub: string, name: string}>
     */
    private function responsible(mixed $value, array &$errors): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            $errors['responsible'] = 'Bitte die Verantwortlichen als Liste angeben.';

            return [];
        }
        if (count($value) > 20) {
            $errors['responsible'] = 'Bitte höchstens 20 Verantwortliche angeben.';

            return [];
        }

        $rows = [];
        $seen = [];
        foreach ($value as $index => $person) {
            if (!is_array($person)) {
                $errors['responsible.'.$index] = 'Die verantwortliche Person ist ungültig.';
                continue;
            }
            $sub = trim((string) ($person['sub'] ?? ''));
            $name = trim((string) ($person['name'] ?? ''));
            if ($sub === '' || mb_strlen($sub) > 190) {
                $errors['responsible.'.$index.'.sub'] = 'Bitte die verantwortliche Person aus dem Mitgliederkreis übernehmen.';
            } elseif (isset($seen[$sub])) {
                $errors['responsible.'.$index.'.sub'] = 'Diese Person ist bereits als verantwortlich genannt.';
            } else {
                $seen[$sub] = true;
            }
            if ($name === '' || mb_strlen($name) > 200) {
                $errors['responsible.'.$index.'.name'] = 'Bitte den Namen der verantwortlichen Person mit höchstens 200 Zeichen angeben.';
            }
            if ($sub !== '' && mb_strlen($sub) <= 190 && $name !== '' && mb_strlen($name) <= 200 && !isset($errors['responsible.'.$index.'.sub'])) {
                $rows[] = ['sub' => $sub, 'name' => $name];
            }
        }

        return $rows;
    }
}
