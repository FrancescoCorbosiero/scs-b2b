<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\UserRepository;
use App\Service\CartService;
use App\Service\OrderService;
use App\Support\ClientIp;
use App\Support\Http;
use App\Support\Lang;
use App\Support\Session;
use App\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class OrderController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly CartService $cart,
        private readonly OrderService $orders,
        private readonly UserRepository $users,
        private readonly ClientIp $clientIp,
        private readonly Lang $lang,
    ) {
    }

    public function form(Request $request, Response $response): Response
    {
        if (!$this->cart->meetsMinimum()) {
            $this->session->flash('error', $this->lang->t('order.error_minimum', ['min' => $this->cart->minOrderItems()]));

            return Http::redirect($response, '/carrello');
        }
        $detail = $this->cart->detail();

        // precompilazione: prima i dati di un submit fallito, poi il profilo account
        $old = $this->session->get('order_form_old');
        if (!is_array($old) || $old === []) {
            $old = $this->profileDefaults();
        }

        return $this->view->render($response, 'order/form.twig', [
            'cart' => $detail,
            'old' => $old,
        ]);
    }

    /** @return array<string, string> checkout precompilato dal profilo dell'account */
    private function profileDefaults(): array
    {
        $userId = $this->session->userId();
        $user = $userId !== null ? $this->users->findActive($userId) : null;
        if ($user === null) {
            return [];
        }

        // NB: niente 'country' qui — il paese lo decide il selettore in header
        // (sessione), che altrimenti verrebbe silenziosamente sovrascritto dal profilo
        return array_filter([
            'customer_name' => (string) $user['name'],
            'company' => (string) ($user['company'] ?? ''),
            'email' => (string) $user['email'],
            'phone' => (string) ($user['phone'] ?? ''),
            'address_street' => (string) ($user['address_street'] ?? ''),
            'address_city' => (string) ($user['address_city'] ?? ''),
            'address_zip' => (string) ($user['address_zip'] ?? ''),
            'vat_number' => (string) ($user['vat_number'] ?? ''),
        ], static fn (string $v): bool => $v !== '');
    }

    public function submit(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $userAgent = $request->getHeaderLine('User-Agent');
        $result = $this->orders->submit($body, $this->clientIp->resolve(), $userAgent);

        if (!$result['ok']) {
            foreach ($result['errors'] as $error) {
                $this->session->flash('error', $error);
            }
            if ($result['cart_adjusted']) {
                return Http::redirect($response, '/carrello');
            }
            $this->session->set('order_form_old', array_intersect_key($body, array_flip([
                'customer_name', 'company', 'email', 'phone', 'notes', 'country', 'vat_number',
            ])));

            return Http::redirect($response, '/richiesta-ordine');
        }

        $this->session->remove('order_form_old');
        $this->session->set('last_order_id', $result['order_id']);

        return Http::redirect($response, '/conferma');
    }

    public function confirmation(Request $request, Response $response): Response
    {
        $orderId = $this->session->get('last_order_id');
        if (!is_int($orderId)) {
            return Http::redirect($response, '/');
        }

        return $this->view->render($response, 'order/confirmation.twig', [
            'order_id' => $orderId,
        ]);
    }
}
