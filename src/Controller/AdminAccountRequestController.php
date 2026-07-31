<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\AccountRequestRepository;
use App\Service\AccountRequestService;
use App\Support\Http;
use App\Support\Lang;
use App\Support\Session;
use App\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Coda delle richieste di profilo (docs/06): l'admin approva — l'account nasce
 * con i dati della richiesta e parte l'invito per la password — oppure rifiuta.
 */
final class AdminAccountRequestController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly AccountRequestRepository $requests,
        private readonly AccountRequestService $service,
        private readonly Lang $lang,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $status = $request->getQueryParams()['stato'] ?? null;
        $status = is_string($status) && in_array($status, AccountRequestRepository::STATUSES, true) ? $status : null;

        return $this->view->render($response, 'admin/account_requests.twig', [
            'requests' => $this->requests->all($status),
            'status' => $status,
            'pending_count' => $this->requests->countPending(),
        ]);
    }

    /** @param array<string, string> $args */
    public function approve(Request $request, Response $response, array $args): Response
    {
        $result = $this->service->approve((int) ($args['id'] ?? 0));
        if (!$result['ok']) {
            $this->session->flash('error', (string) $result['error']);
        } elseif ($result['email_sent']) {
            $this->session->flash('success', $this->lang->t('signup.admin_approved'));
        } else {
            $this->session->flash('error', $this->lang->t('signup.admin_approved_email_failed'));
        }

        return Http::redirect($response, '/admin/richieste-profilo');
    }

    /** @param array<string, string> $args */
    public function reject(Request $request, Response $response, array $args): Response
    {
        $result = $this->service->reject((int) ($args['id'] ?? 0));
        if ($result['ok']) {
            $this->session->flash('success', $this->lang->t('signup.admin_rejected'));
        } else {
            $this->session->flash('error', (string) $result['error']);
        }

        return Http::redirect($response, '/admin/richieste-profilo');
    }
}
