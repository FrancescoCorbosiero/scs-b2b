<?php

declare(strict_types=1);

namespace App\Controller;

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

        return $this->view->render($response, 'order/form.twig', [
            'cart' => $detail,
            'old' => $this->session->get('order_form_old') ?? [],
        ]);
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
