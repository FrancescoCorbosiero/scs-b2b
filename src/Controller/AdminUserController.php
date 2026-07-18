<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\UserRepository;
use App\Repository\VatRateRepository;
use App\Service\UserService;
use App\Support\Http;
use App\Support\Lang;
use App\Support\Session;
use App\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * /admin/clienti: creazione account con invito via email (link monouso per
 * impostare la password — mai password in chiaro), reinvio link, attiva/disattiva.
 */
final class AdminUserController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly UserService $userService,
        private readonly UserRepository $users,
        private readonly VatRateRepository $vatRates,
        private readonly Lang $lang,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        return $this->view->render($response, 'admin/users.twig', [
            'users' => $this->users->all(),
            'vat_countries' => $this->vatRates->all(),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        $result = $this->userService->create((array) ($request->getParsedBody() ?? []));
        if (!$result['ok']) {
            foreach ($result['errors'] as $error) {
                $this->session->flash('error', $error);
            }
        } elseif ($result['email_sent']) {
            $this->session->flash('success', $this->lang->t('users.created_invited'));
        } else {
            $this->session->flash('error', $this->lang->t('users.created_email_failed'));
        }

        return Http::redirect($response, '/admin/clienti');
    }

    /** Reinvia il link di accesso (invito se senza password, altrimenti reset). */
    /** @param array<string, string> $args */
    public function sendLink(Request $request, Response $response, array $args): Response
    {
        $userId = (int) ($args['id'] ?? 0);
        if ($this->users->find($userId) === null) {
            $this->session->flash('error', $this->lang->t('admin.order_not_found'));
        } elseif ($this->userService->sendAccessLink($userId)) {
            $this->session->flash('success', $this->lang->t('users.link_sent'));
        } else {
            $this->session->flash('error', $this->lang->t('users.link_failed'));
        }

        return Http::redirect($response, '/admin/clienti');
    }

    /** @param array<string, string> $args */
    public function toggleActive(Request $request, Response $response, array $args): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $active = ($body['active'] ?? '') === '1';
        if ($this->users->setActive((int) ($args['id'] ?? 0), $active)) {
            $this->session->flash('success', $this->lang->t($active ? 'users.activated' : 'users.deactivated'));
        } else {
            $this->session->flash('error', $this->lang->t('admin.order_not_found'));
        }

        return Http::redirect($response, '/admin/clienti');
    }
}
