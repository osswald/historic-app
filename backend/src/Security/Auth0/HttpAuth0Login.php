<?php

declare(strict_types=1);

namespace App\Security\Auth0;

use App\Security\MemberUser;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class HttpAuth0Login implements Auth0Login
{
    public function __construct(
        private Auth0Tenant $tenant,
        private Auth0TokenReader $tokens,
        private HttpClientInterface $http,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->tenant->configured();
    }

    public function authorizeUrl(string $redirectUri, string $state): string
    {
        // Die API-Audience nicht mitschicken. Auth0 kennt die Ressource, hat diese
        // Anwendung aber nicht dafür freigegeben und schickt sonst sofort mit
        // invalid_request zurück, noch bevor das Login erscheint.
        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $this->tenant->clientId(),
            'redirect_uri' => $redirectUri,
            'scope' => 'openid profile email',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);

        return $this->tenant->issuer().'authorize?'.$query;
    }

    public function authenticate(#[\SensitiveParameter] string $code, string $redirectUri): MemberUser
    {
        if (!$this->isConfigured()) {
            throw new Auth0LoginException('Die Auth0-Anmeldung ist noch nicht eingerichtet.');
        }

        try {
            $response = $this->http->request('POST', 'https://'.$this->tenant->domain().'/oauth/token', [
                'body' => [
                    'grant_type' => 'authorization_code',
                    'client_id' => $this->tenant->clientId(),
                    'client_secret' => $this->tenant->clientSecret(),
                    'code' => $code,
                    'redirect_uri' => $redirectUri,
                ],
                'timeout' => 10,
                'max_duration' => 15,
                'max_redirects' => 0,
            ]);
            $status = $response->getStatusCode();
        } catch (\Throwable) {
            throw new Auth0LoginException('Die Anmeldung hat nicht geklappt.');
        }
        if ($status !== 200) {
            throw new Auth0LoginException('Die Anmeldung hat nicht geklappt.');
        }

        try {
            $data = $response->toArray(false);
        } catch (\Throwable) {
            throw new Auth0LoginException('Die Anmeldung hat nicht geklappt.');
        }

        $access = $data['access_token'] ?? null;
        if (!is_string($access) || $access === '') {
            $access = null;
        }
        $id = $data['id_token'] ?? null;
        if (!is_string($id) || $id === '') {
            $id = null;
        }
        if ($access === null && $id === null) {
            throw new Auth0LoginException('Die Anmeldung hat nicht geklappt.');
        }

        try {
            if ($access !== null) {
                return $this->tokens->memberFromTokens($access, $id);
            }
        } catch (Auth0TokenException) {
            // Ohne freigegebene API liefert Auth0 ein opakes Access-Token.
        }
        if ($id === null) {
            throw new Auth0LoginException('Die Anmeldung hat nicht geklappt.');
        }

        try {
            return $this->tokens->memberFromIdToken($id);
        } catch (Auth0TokenException) {
            throw new Auth0LoginException('Die Anmeldung hat nicht geklappt.');
        }
    }

    public function logoutUrl(string $returnTo): string
    {
        $query = http_build_query([
            'client_id' => $this->tenant->clientId(),
            'returnTo' => $returnTo,
        ], '', '&', PHP_QUERY_RFC3986);

        return 'https://'.$this->tenant->domain().'/v2/logout?'.$query;
    }
}
