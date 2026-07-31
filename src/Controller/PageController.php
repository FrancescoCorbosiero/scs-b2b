<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ProductRepository;
use App\Service\AccountRequestService;
use App\Service\CartService;
use App\Service\ShippingService;
use App\Support\ClientIp;
use App\Support\Config;
use App\Support\Http;
use App\Support\Lang;
use App\Support\Session;
use App\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Pagine pubbliche (docs/06 § Sito pubblico): home, spedizioni, come ordinare
 * e richiesta di attivazione profilo. Sono le uniche pagine indicizzabili e
 * NON mostrano mai prezzi o prodotti del feed: solo informazioni commerciali
 * (i prezzi restano dietro login — Regola d'oro n.1 e n.2).
 */
final class PageController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly ProductRepository $products,
        private readonly ShippingService $shipping,
        private readonly CartService $cart,
        private readonly AccountRequestService $accountRequests,
        private readonly ClientIp $clientIp,
        private readonly Config $config,
        private readonly Lang $lang,
    ) {
    }

    public function home(Request $request, Response $response): Response
    {
        $brands = [];
        $catalogSize = 0;
        try {
            foreach ($this->products->activeBrandsWithCounts() as $brand) {
                $brands[] = $brand['brand'];
                $catalogSize += $brand['products'];
            }
        } catch (\Throwable) {
            // la home non deve rompersi se il DB non risponde
            $brands = [];
        }

        return $this->view->render($response, 'pages/home.twig', [
            'public_page' => true,
            'indexable' => true,
            'meta_description' => $this->trans('meta.home_description'),
            'brands' => $brands,
            'catalog_size' => $catalogSize,
            'shipping' => $this->shippingInfo(),
            'min_order_items' => $this->cart->minOrderItems(),
        ]);
    }

    public function shipping(Request $request, Response $response): Response
    {
        return $this->view->render($response, 'pages/shipping.twig', [
            'public_page' => true,
            'indexable' => true,
            'meta_description' => $this->trans('meta.shipping_description'),
            'shipping' => $this->shippingInfo(),
            'min_order_items' => $this->cart->minOrderItems(),
        ]);
    }

    public function howToOrder(Request $request, Response $response): Response
    {
        return $this->view->render($response, 'pages/how_to_order.twig', [
            'public_page' => true,
            'indexable' => true,
            'meta_description' => $this->trans('meta.how_description'),
            'shipping' => $this->shippingInfo(),
            'min_order_items' => $this->cart->minOrderItems(),
        ]);
    }

    public function signupForm(Request $request, Response $response): Response
    {
        $old = $this->session->get('signup_old');
        // il messaggio di conferma si mostra una volta sola (post/redirect/get)
        $sent = $this->session->get('signup_sent') === true;
        if ($sent) {
            $this->session->remove('signup_sent');
        }

        return $this->view->render($response, 'pages/signup.twig', [
            'public_page' => true,
            'indexable' => true,
            'meta_description' => $this->trans('meta.signup_description'),
            'old' => is_array($old) ? $old : [],
            'sent' => $sent,
        ]);
    }

    public function signupSubmit(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $result = $this->accountRequests->submit(
            $body,
            $this->clientIp->resolve(),
            $request->getHeaderLine('User-Agent'),
        );

        if (!$result['ok']) {
            foreach ($result['errors'] as $error) {
                $this->session->flash('error', $error);
            }
            $this->session->set('signup_old', array_intersect_key($body, array_flip([
                'company', 'vat_number', 'name', 'email', 'phone',
                'address_street', 'address_city', 'address_zip', 'country', 'notes',
            ])));

            return Http::redirect($response, '/richiedi-accesso');
        }

        $this->session->remove('signup_old');
        $this->session->set('signup_sent', true);

        return Http::redirect($response, '/richiedi-accesso');
    }

    /** @return array{free_from: int, fee: string, days_min: int, days_max: int} */
    private function shippingInfo(): array
    {
        return [
            'free_from' => $this->shipping->freeFromItems(),
            'fee' => $this->shipping->fee(),
            'days_min' => max(1, $this->config->int('SHIPPING_DAYS_MIN', 4)),
            'days_max' => max(1, $this->config->int('SHIPPING_DAYS_MAX', 5)),
        ];
    }

    private function trans(string $key): string
    {
        return $this->lang->t($key);
    }
}
