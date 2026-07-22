<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\CartService;
use App\Service\VatService;
use App\Support\Http;
use App\Support\Lang;
use App\Support\Session;
use App\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class CartController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly CartService $cart,
        private readonly VatService $vat,
        private readonly Lang $lang,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $detail = $this->cart->detail();

        // stima VAT sul paese selezionato in header (senza P.IVA: il reverse
        // charge eventuale si applica al checkout) — feedback visivo immediato
        $vat = $this->vat->resolve($this->session->country(), null);
        $vatAmount = VatService::vatAmount($detail['total_amount'], $vat['rate']);

        return $this->view->render($response, 'cart/index.twig', [
            'cart' => $detail,
            'cart_vat' => [
                'country' => $vat['country_code'],
                'scheme' => $vat['scheme'],
                'rate' => $vat['rate'],
                'amount' => $vatAmount,
                'gross' => VatService::grossTotal($detail['total_amount'], $vatAmount),
            ],
            'min_order_items' => $this->cart->minOrderItems(),
            'meets_minimum' => $detail['total_items'] >= $this->cart->minOrderItems(),
        ]);
    }

    public function add(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $sku = is_string($body['sku'] ?? null) ? trim($body['sku']) : '';
        if ($sku === '' || !$this->cart->addProduct($sku)) {
            $this->session->flash('error', $this->lang->t('cart.product_not_found'));

            return Http::redirect($response, Http::safeInternalPath($body['redirect'] ?? null, '/'));
        }

        return Http::redirect($response, '/carrello#' . rawurlencode($sku));
    }

    /**
     * Aggiornamento quantità di una taglia. Risponde in JSON alle richieste
     * fetch (X-Requested-With), con redirect ai form senza JavaScript.
     * Il JSON contiene SOLO quantità e totali netti di listino (VAT esclusa).
     */
    public function update(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $sku = is_string($body['sku'] ?? null) ? trim($body['sku']) : '';
        $sizeEu = is_string($body['size_eu'] ?? null) ? trim($body['size_eu']) : '';
        $qty = is_numeric($body['qty'] ?? null) ? (int) $body['qty'] : 0;

        $applied = $sku !== '' && $sizeEu !== '' ? $this->cart->setQuantity($sku, $sizeEu, $qty) : 0;

        if ($request->getHeaderLine('X-Requested-With') === 'fetch') {
            $detail = $this->cart->detail();
            $productTotal = '0.00';
            $productItems = 0;
            foreach ($detail['products'] as $product) {
                if ($product['sku'] === $sku) {
                    $productTotal = $product['product_total'];
                    $productItems = $product['product_items'];
                }
            }

            return Http::json($response, [
                'ok' => true,
                'applied' => $applied,
                'product_items' => $productItems,
                'product_total' => $productTotal,
                'total_items' => $detail['total_items'],
                'total_amount' => $detail['total_amount'],
                'meets_minimum' => $detail['total_items'] >= $this->cart->minOrderItems(),
            ]);
        }

        return Http::redirect($response, '/carrello#' . rawurlencode($sku));
    }

    public function takeAll(Request $request, Response $response): Response
    {
        $sku = $this->skuFrom($request);
        if ($sku !== '') {
            $this->cart->takeAll($sku);
        }

        return Http::redirect($response, '/carrello#' . rawurlencode($sku));
    }

    public function clearProduct(Request $request, Response $response): Response
    {
        $sku = $this->skuFrom($request);
        if ($sku !== '') {
            $this->cart->clearProduct($sku);
        }

        return Http::redirect($response, '/carrello#' . rawurlencode($sku));
    }

    public function remove(Request $request, Response $response): Response
    {
        $sku = $this->skuFrom($request);
        if ($sku !== '') {
            $this->cart->removeProduct($sku);
        }

        return Http::redirect($response, '/carrello');
    }

    private function skuFrom(Request $request): string
    {
        $body = (array) ($request->getParsedBody() ?? []);

        return is_string($body['sku'] ?? null) ? trim($body['sku']) : '';
    }
}
