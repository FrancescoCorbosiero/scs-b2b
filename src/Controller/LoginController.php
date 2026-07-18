<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AuthService;
use App\Service\UserService;
use App\Support\ClientIp;
use App\Support\Config;
use App\Support\Http;
use App\Support\Lang;
use App\Support\Session;
use App\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Login catalogo: con account personale (email + password) oppure, finché
 * GUEST_LOGIN_ENABLED=1, con la password condivisa (modalità ospite di
 * transizione — vedi docs/07).
 */
final class LoginController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly AuthService $auth,
        private readonly UserService $users,
        private readonly ClientIp $clientIp,
        private readonly Config $config,
        private readonly Lang $lang,
    ) {
    }

    public function form(Request $request, Response $response): Response
    {
        if ($this->session->isCatalogAuthed()) {
            return Http::redirect($response, '/');
        }

        return $this->view->render($response, 'login.twig', [
            'guest_login_enabled' => $this->config->bool('GUEST_LOGIN_ENABLED', true),
        ]);
    }

    public function submit(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $email = is_string($body['email'] ?? null) ? trim($body['email']) : '';
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';
        $remember = ($body['remember'] ?? null) === '1';
        $ip = $this->clientIp->resolve();

        // email presente → login con account; vuota → tentativo ospite
        if ($email !== '') {
            if ($this->users->authenticate($email, $password, $ip, $remember)) {
                return Http::redirect($response, '/');
            }
        } elseif ($this->config->bool('GUEST_LOGIN_ENABLED', true)
            && $this->auth->attempt(AuthService::SCOPE_CATALOG, $password, $ip, $remember)) {
            return Http::redirect($response, '/');
        }

        $this->session->flash('error', $this->lang->t('login.failed'));

        return Http::redirect($response, '/login');
    }

    public function logout(Request $request, Response $response): Response
    {
        $this->session->logout(AuthService::SCOPE_CATALOG);

        return Http::redirect($response, '/login');
    }
}
