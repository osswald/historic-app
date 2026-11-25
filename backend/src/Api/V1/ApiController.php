<?php

declare(strict_types=1);

namespace App\Api\V1;

use App\Security\MemberUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

abstract class ApiController extends AbstractController
{
    protected function member(): MemberUser
    {
        $user = $this->getUser();
        if (!$user instanceof MemberUser) {
            throw new AccessDeniedHttpException('Anmeldung erforderlich.');
        }

        return $user;
    }

    protected function jsonData(mixed $data, int $status = 200): JsonResponse
    {
        $response = new JsonResponse($data, $status);
        $response->setEncodingOptions(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $response;
    }
}
