<?php

declare(strict_types=1);

namespace App\Api\V1;

use App\Application\Spond\AttachSpondParticipation;
use App\Application\Spond\RememberMemberSpondId;
use App\Security\Auth0\Auth0Login;
use App\Security\Auth0\Auth0LoginException;
use App\Security\MemberSession;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class AuthController extends AbstractController
{
    public function __construct(
        private Auth0Login $auth0,
        private MemberSession $sessions,
        private RememberMemberSpondId $spondIds,
        private AttachSpondParticipation $participation,
        private TokenStorageInterface $tokens,
        private LoggerInterface $logger,
    ) {
    }

    #[Route('/api/v1/auth/login', name: 'api_auth_login', methods: ['GET'])]
    public function login(Request $request): RedirectResponse
    {
        if (!$this->auth0->isConfigured()) {
            return $this->home($request, 'nicht-bereit');
        }

        $state = bin2hex(random_bytes(32));
        $this->sessions->rememberState($request, $state);

        return $this->away($this->auth0->authorizeUrl(
            $this->origin($request).'/api/v1/auth/callback',
            $state,
        ));
    }

    #[Route('/api/v1/auth/callback', name: 'api_auth_callback', methods: ['GET'])]
    public function callback(Request $request): RedirectResponse
    {
        $expected = $this->sessions->pullState($request);
        $given = (string) $request->query->get('state', '');
        if (!$this->auth0->isConfigured()) {
            return $this->home($request, 'nicht-bereit');
        }

        $code = (string) $request->query->get('code', '');
        $error = (string) $request->query->get('error', '');
        if ($error !== '' || $code === '' || $expected === null || !hash_equals($expected, $given)) {
            $reason = $error !== '' ? $this->auth0Error($error) : ($expected === null ? 'state-missing' : ($code === '' ? 'code-missing' : 'state-mismatch'));
            $this->logger->warning('Auth0-Rückleitung abgelehnt.', ['reason' => $reason]);

            return $this->home($request, 'fehlgeschlagen');
        }

        try {
            $member = $this->auth0->authenticate($code, $this->origin($request).'/api/v1/auth/callback');
        } catch (Auth0LoginException $exception) {
            $this->logger->warning('Auth0-Anmeldung abgelehnt.', ['type' => $exception::class]);

            return $this->home($request, 'fehlgeschlagen');
        }

        ($this->spondIds)($member);
        ($this->participation)($member);
        $this->sessions->store($request, $member);

        return $this->home($request);
    }

    #[Route('/api/v1/auth/logout', name: 'api_auth_logout', methods: ['POST'])]
    public function logout(Request $request): RedirectResponse
    {
        $returnTo = $this->origin($request).'/';
        $target = $this->auth0->isConfigured() ? $this->auth0->logoutUrl($returnTo) : $returnTo;
        $this->sessions->clear($request);
        $this->tokens->setToken(null);

        return $this->away($target, Response::HTTP_SEE_OTHER);
    }

    private function auth0Error(string $error): string
    {
        return preg_match('/^[a-z0-9_]{1,40}$/', $error) === 1 ? $error : 'error';
    }

    private function origin(Request $request): string
    {
        $host = $request->getHttpHost();
        if ($host === 'app.historic-winterthur.ch') {
            return 'https://'.$host;
        }

        return $request->getSchemeAndHttpHost();
    }

    private function home(Request $request, ?string $notice = null): RedirectResponse
    {
        $target = $this->origin($request).'/';
        if ($notice !== null) {
            $target .= '?anmeldung='.$notice;
        }

        return $this->away($target);
    }

    private function away(string $target, int $status = Response::HTTP_FOUND): RedirectResponse
    {
        $response = new RedirectResponse($target, $status);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
