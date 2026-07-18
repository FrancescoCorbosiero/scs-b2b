<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\OrderRequestRepository;
use App\Repository\UserRepository;
use App\Repository\UserTokenRepository;
use App\Service\OrderMailer;
use App\Service\ReceiptService;
use App\Service\UserService;
use App\Support\Http;
use App\Support\Lang;
use App\Support\Session;
use App\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Flussi account lato cliente: impostazione password via token (invito e
 * reset), password dimenticata, e area personale (profilo, ordini, ricevute).
 */
final class AccountController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly UserService $userService,
        private readonly UserRepository $users,
        private readonly UserTokenRepository $tokens,
        private readonly OrderRequestRepository $orders,
        private readonly ReceiptService $receipts,
        private readonly Lang $lang,
    ) {
    }

    // ── Imposta password via token (pubblico: invito E reset) ────────

    public function setPasswordForm(Request $request, Response $response): Response
    {
        $token = $request->getQueryParams()['token'] ?? '';
        $token = is_string($token) ? $token : '';
        if (!$this->tokens->isValid($token)) {
            $this->session->flash('error', $this->lang->t('account.error_token'));

            return Http::redirect($response, '/login');
        }

        return $this->view->render($response, 'account/set_password.twig', ['token' => $token]);
    }

    public function setPasswordSubmit(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $token = is_string($body['token'] ?? null) ? $body['token'] : '';
        $result = $this->userService->completeToken(
            $token,
            is_string($body['password'] ?? null) ? $body['password'] : '',
            is_string($body['password_confirm'] ?? null) ? $body['password_confirm'] : '',
        );
        if (!$result['ok']) {
            foreach ($result['errors'] as $error) {
                $this->session->flash('error', $error);
            }
            // con token ancora valido si ripresenta il form; altrimenti login
            return Http::redirect(
                $response,
                $this->tokens->isValid($token) ? '/account/imposta-password?token=' . rawurlencode($token) : '/login'
            );
        }
        $this->session->flash('success', $this->lang->t('account.password_set'));

        return Http::redirect($response, '/');
    }

    // ── Password dimenticata (pubblico, risposta neutra) ─────────────

    public function forgotForm(Request $request, Response $response): Response
    {
        return $this->view->render($response, 'account/forgot.twig');
    }

    public function forgotSubmit(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $this->userService->requestReset(is_string($body['email'] ?? null) ? $body['email'] : '');
        // sempre lo stesso messaggio: mai rivelare se l'email esiste
        $this->session->flash('success', $this->lang->t('account.reset_requested'));

        return Http::redirect($response, '/login');
    }

    // ── Area personale (richiede account, non ospite) ────────────────

    public function profile(Request $request, Response $response): Response
    {
        $user = $this->currentUser();
        if ($user === null) {
            return $this->redirectToLogin($response);
        }

        return $this->view->render($response, 'account/index.twig', ['user' => $user]);
    }

    public function profileSave(Request $request, Response $response): Response
    {
        $user = $this->currentUser();
        if ($user === null) {
            return $this->redirectToLogin($response);
        }
        $result = $this->userService->updateProfile((int) $user['id'], (array) ($request->getParsedBody() ?? []));
        if ($result['ok']) {
            $this->session->flash('success', $this->lang->t('account.profile_saved'));
        } else {
            foreach ($result['errors'] as $error) {
                $this->session->flash('error', $error);
            }
        }

        return Http::redirect($response, '/account');
    }

    public function passwordSave(Request $request, Response $response): Response
    {
        $user = $this->currentUser();
        if ($user === null) {
            return $this->redirectToLogin($response);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $result = $this->userService->changePassword(
            (int) $user['id'],
            is_string($body['current_password'] ?? null) ? $body['current_password'] : '',
            is_string($body['password'] ?? null) ? $body['password'] : '',
            is_string($body['password_confirm'] ?? null) ? $body['password_confirm'] : '',
        );
        if ($result['ok']) {
            $this->session->flash('success', $this->lang->t('account.password_changed'));
        } else {
            foreach ($result['errors'] as $error) {
                $this->session->flash('error', $error);
            }
        }

        return Http::redirect($response, '/account');
    }

    public function orders(Request $request, Response $response): Response
    {
        $user = $this->currentUser();
        if ($user === null) {
            return $this->redirectToLogin($response);
        }

        return $this->view->render($response, 'account/orders.twig', [
            'orders' => $this->orders->forUser((int) $user['id'], (string) $user['email']),
        ]);
    }

    /**
     * Ricevuta pro-forma del PROPRIO ordine confermato (ownership verificata).
     *
     * @param array<string, string> $args
     */
    public function receiptPdf(Request $request, Response $response, array $args): Response
    {
        $user = $this->currentUser();
        if ($user === null) {
            return $this->redirectToLogin($response);
        }
        $order = $this->orders->find((int) ($args['id'] ?? 0));
        $owned = $order !== null && (
            (int) ($order['user_id'] ?? 0) === (int) $user['id']
            || strcasecmp((string) ($order['email'] ?? ''), (string) $user['email']) === 0
        );
        if (!$owned || ($order['status'] ?? '') !== 'confirmed') {
            $this->session->flash('error', $this->lang->t('account.receipt_unavailable'));

            return Http::redirect($response, '/account/ordini');
        }

        $snapshot = json_decode(is_string($order['cart_snapshot'] ?? null) ? $order['cart_snapshot'] : '[]', true);
        $order['lines'] = is_array($snapshot) && is_array($snapshot['lines'] ?? null) ? $snapshot['lines'] : [];
        // MAI offer_price verso il cliente (Regola d'oro n.1)
        $order = OrderMailer::stripCosts($order);
        $locale = is_string($order['locale'] ?? null) && $order['locale'] !== '' ? $order['locale'] : 'it';
        $pdf = $this->receipts->buildPdf($order, $locale);
        $response->getBody()->write($pdf);

        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'inline; filename="' . $this->receipts->fileName($order, $locale) . '"')
            ->withHeader('Content-Length', (string) strlen($pdf));
    }

    /**
     * Utente loggato con account (null in modalità ospite: l'area personale
     * richiede un account, non basta la password condivisa).
     *
     * @return array<string, mixed>|null
     */
    private function currentUser(): ?array
    {
        $userId = $this->session->userId();

        return $userId !== null ? $this->users->findActive($userId) : null;
    }

    private function redirectToLogin(Response $response): Response
    {
        $this->session->flash('error', $this->lang->t('account.requires_account'));

        return Http::redirect($response, '/login');
    }
}
