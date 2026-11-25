<?php

declare(strict_types=1);

namespace App\Api\V1;

use App\Application\InvalidRequest;
use App\Application\NotFound;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[AsEventListener(event: KernelEvents::EXCEPTION)]
final class ApiExceptionListener
{
    public function __construct(
        #[Autowire('%kernel.debug%')]
        private bool $debug,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        $throwable = $event->getThrowable();
        $errors = null;
        if ($throwable instanceof InvalidRequest) {
            $status = 422;
            $message = $throwable->getMessage();
            $errors = $throwable->errors;
        } elseif ($throwable instanceof NotFound) {
            $status = 404;
            $message = $throwable->getMessage();
        } elseif ($throwable instanceof AccessDeniedException) {
            $status = 403;
            $message = $throwable->getMessage() !== '' && $throwable->getMessage() !== 'Access Denied.'
                ? $throwable->getMessage()
                : 'Dafür fehlt die Berechtigung.';
        } elseif ($throwable instanceof HttpExceptionInterface) {
            $status = $throwable->getStatusCode();
            $message = $throwable->getMessage() !== '' ? $throwable->getMessage() : 'Die Anfrage wurde abgelehnt.';
        } else {
            $this->logger->error($throwable->getMessage(), ['exception' => $throwable]);
            $status = 500;
            $message = $this->debug ? $throwable->getMessage() : 'Unerwarteter Fehler.';
        }

        $payload = ['error' => $message];
        if ($errors !== null) {
            $payload['errors'] = $errors;
        }
        $response = new JsonResponse($payload, $status);
        $response->setEncodingOptions(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $event->setResponse($response);
    }
}
