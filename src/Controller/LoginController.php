<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AuthService;
use App\Support\ClientIp;
use App\Support\Http;
use App\Support\Lang;
use App\Support\Session;
use App\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class LoginController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly AuthService $auth,
        private readonly ClientIp $clientIp,
        private readonly Lang $lang,
    ) {
    }

    public function form(Request $request, Response $response): Response
    {
        if ($this->session->isCatalogAuthed()) {
            return Http::redirect($response, '/');
        }

        return $this->view->render($response, 'login.twig');
    }

    public function submit(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';
        $remember = ($body['remember'] ?? null) === '1';

        if ($this->auth->attempt(AuthService::SCOPE_CATALOG, $password, $this->clientIp->resolve(), $remember)) {
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
