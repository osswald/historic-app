<?php

declare(strict_types=1);

namespace App\Api\V1;

use App\Application\Audit\ListAudit;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AuditController extends ApiController
{
    #[Route('/api/v1/audit', name: 'api_audit', methods: ['GET'])]
    public function index(Request $request, ListAudit $audit): Response
    {
        return $this->jsonData([
            'entries' => $audit(
                $this->member(),
                (string) $request->query->get('entityType', ''),
                (string) $request->query->get('entityId', ''),
            ),
        ]);
    }
}
