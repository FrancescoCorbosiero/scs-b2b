<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\DropshipOrderRepository;
use App\Repository\MarginRuleRepository;
use App\Repository\OrderRequestRepository;
use App\Repository\ProductRepository;
use App\Repository\SettingsRepository;
use App\Repository\SyncLogRepository;
use App\Repository\VatRateRepository;
use App\Service\AuthService;
use App\Service\DropshipOrderService;
use App\Service\FeedSyncService;
use App\Service\OrderMailer;
use App\Service\PricingService;
use App\Service\ReceiptService;
use App\Support\ClientIp;
use App\Support\Http;
use App\Support\Lang;
use App\Support\Session;
use App\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class AdminController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly AuthService $auth,
        private readonly ClientIp $clientIp,
        private readonly Lang $lang,
        private readonly OrderRequestRepository $orders,
        private readonly SyncLogRepository $syncLogs,
        private readonly ProductRepository $products,
        private readonly FeedSyncService $feedSync,
        private readonly DropshipOrderRepository $dropshipOrders,
        private readonly DropshipOrderService $dropship,
        private readonly MarginRuleRepository $marginRules,
        private readonly SettingsRepository $settings,
        private readonly VatRateRepository $vatRates,
        private readonly ReceiptService $receipts,
    ) {
    }

    // ── Login ────────────────────────────────────────────────────────

    public function loginForm(Request $request, Response $response): Response
    {
        if ($this->session->isAdminAuthed()) {
            return Http::redirect($response, '/admin');
        }

        return $this->view->render($response, 'admin/login.twig');
    }

    public function loginSubmit(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';

        if ($this->auth->attempt(AuthService::SCOPE_ADMIN, $password, $this->clientIp->resolve())) {
            return Http::redirect($response, '/admin');
        }
        $this->session->flash('error', $this->lang->t('login.failed'));

        return Http::redirect($response, '/admin/login');
    }

    public function logout(Request $request, Response $response): Response
    {
        $this->session->logout(AuthService::SCOPE_ADMIN);

        return Http::redirect($response, '/admin/login');
    }

    // ── Dashboard ────────────────────────────────────────────────────

    public function dashboard(Request $request, Response $response): Response
    {
        $recentOrders = $this->orders->paginate(1, 5);

        return $this->view->render($response, 'admin/dashboard.twig', [
            'orders_total' => $this->orders->countAll(),
            'recent_orders' => $recentOrders['items'],
            'last_sync' => $this->syncLogs->latest(),
        ]);
    }

    // ── Richieste d'ordine ───────────────────────────────────────────

    public function orders(Request $request, Response $response): Response
    {
        $page = max(1, (int) (($request->getQueryParams()['page'] ?? 1)));
        $perPage = 20;
        $result = $this->orders->paginate($page, $perPage);

        return $this->view->render($response, 'admin/orders.twig', [
            'orders' => $result['items'],
            'total' => $result['total'],
            'page' => $page,
            'total_pages' => max(1, (int) ceil($result['total'] / $perPage)),
        ]);
    }

    /** @param array<string, string> $args */
    public function orderDetail(Request $request, Response $response, array $args): Response
    {
        $order = $this->orders->find((int) ($args['id'] ?? 0));
        if ($order === null) {
            $this->session->flash('error', $this->lang->t('admin.order_not_found'));

            return Http::redirect($response, '/admin/richieste');
        }
        $snapshot = json_decode(is_string($order['cart_snapshot'] ?? null) ? $order['cart_snapshot'] : '[]', true);
        $lines = is_array($snapshot) && is_array($snapshot['lines'] ?? null) ? $snapshot['lines'] : [];

        // margine: totale netto di listino - costo fornitore (solo vista admin)
        $totalCost = 0.0;
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $cost = $line['offer_price'] ?? null;
            if (is_numeric($cost)) {
                $totalCost += (float) $cost * (int) ($line['qty'] ?? 0);
            }
        }

        return $this->view->render($response, 'admin/order_detail.twig', [
            'order' => $order,
            'lines' => $lines,
            'total_cost' => number_format($totalCost, 2, '.', ''),
            'margin' => number_format((float) ($order['total_amount'] ?? 0) - $totalCost, 2, '.', ''),
            'dropship_enabled' => $this->dropship->isEnabled(),
            'dropship_is_simulation' => $this->dropship->isSimulation(),
            'dropship_orders' => $this->dropship->isEnabled()
                ? $this->dropshipOrders->findByOrderRequest((int) $order['id'])
                : [],
        ]);
    }

    // ── Sync feed ────────────────────────────────────────────────────

    public function syncLogs(Request $request, Response $response): Response
    {
        return $this->view->render($response, 'admin/sync.twig', [
            'logs' => $this->syncLogs->recent(30),
        ]);
    }

    public function syncRun(Request $request, Response $response): Response
    {
        set_time_limit(300);
        $result = $this->feedSync->run();
        if ($result['status'] === 'ok') {
            $this->session->flash('success', $this->lang->t('admin.sync_ok', [
                'read' => $result['rows_read'],
                'created' => $result['products_created'],
                'updated' => $result['products_updated'],
                'deactivated' => $result['products_deactivated'],
            ]));
        } else {
            $this->session->flash('error', $this->lang->t('admin.sync_failed', ['error' => (string) $result['message']]));
        }

        return Http::redirect($response, '/admin/sync');
    }

    // ── Gestione margini (regole, default, aliquote VAT) ─────────────

    public function margins(Request $request, Response $response): Response
    {
        $rules = [];
        foreach ($this->marginRules->all() as $rule) {
            $rule['matching_products'] = $this->marginRules->matchingProductsCount($rule['match_type'], $rule['match_value']);
            $rules[] = $rule;
        }

        return $this->view->render($response, 'admin/margins.twig', [
            'rules' => $rules,
            'default_margin_type' => $this->settings->get('default_margin_type', 'percent'),
            'default_margin_value' => $this->settings->get('default_margin_value', '30'),
            'vat_rates' => $this->vatRates->all(),
            'brands' => $this->products->activeBrands(),
        ]);
    }

    public function marginRuleCreate(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $matchType = is_string($body['match_type'] ?? null) ? $body['match_type'] : '';
        $matchValue = is_string($body['match_value'] ?? null) ? trim(mb_substr($body['match_value'], 0, 128)) : '';
        $marginType = is_string($body['margin_type'] ?? null) ? $body['margin_type'] : '';
        $marginValue = $body['margin_value'] ?? null;
        $priority = is_numeric($body['priority'] ?? null) ? (int) $body['priority'] : 100;

        if (!in_array($matchType, MarginRuleRepository::MATCH_TYPES, true)
            || $matchValue === ''
            || !in_array($marginType, PricingService::MARGIN_TYPES, true)
            || !is_numeric($marginValue)
            || (float) $marginValue < -100 || (float) $marginValue > 10000) {
            $this->session->flash('error', $this->lang->t('margins.error_invalid'));

            return Http::redirect($response, '/admin/margini');
        }

        $this->marginRules->insert($priority, $matchType, $matchValue, $marginType, (float) $marginValue);
        $this->session->flash('success', $this->lang->t('margins.rule_saved'));

        return $this->repriceAndRedirect($response);
    }

    /** @param array<string, string> $args */
    public function marginRuleToggle(Request $request, Response $response, array $args): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $active = ($body['active'] ?? '') === '1';
        if (!$this->marginRules->setActive((int) ($args['id'] ?? 0), $active)) {
            $this->session->flash('error', $this->lang->t('admin.order_not_found'));

            return Http::redirect($response, '/admin/margini');
        }
        $this->session->flash('success', $this->lang->t('margins.rule_saved'));

        return $this->repriceAndRedirect($response);
    }

    /** @param array<string, string> $args */
    public function marginRuleDelete(Request $request, Response $response, array $args): Response
    {
        if (!$this->marginRules->delete((int) ($args['id'] ?? 0))) {
            $this->session->flash('error', $this->lang->t('admin.order_not_found'));

            return Http::redirect($response, '/admin/margini');
        }
        $this->session->flash('success', $this->lang->t('margins.rule_deleted'));

        return $this->repriceAndRedirect($response);
    }

    public function marginDefaultSave(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $type = is_string($body['margin_type'] ?? null) ? $body['margin_type'] : '';
        $value = $body['margin_value'] ?? null;

        if (!in_array($type, PricingService::MARGIN_TYPES, true)
            || !is_numeric($value)
            || (float) $value < -100 || (float) $value > 10000) {
            $this->session->flash('error', $this->lang->t('margins.error_invalid'));

            return Http::redirect($response, '/admin/margini');
        }

        $this->settings->set('default_margin_type', $type);
        $this->settings->set('default_margin_value', number_format((float) $value, 2, '.', ''));
        $this->session->flash('success', $this->lang->t('margins.default_saved'));

        return $this->repriceAndRedirect($response);
    }

    public function vatRateSave(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $country = is_string($body['country'] ?? null) ? strtoupper(trim($body['country'])) : '';
        $rate = $body['vat_rate'] ?? null;

        if (!is_numeric($rate) || (float) $rate < 0 || (float) $rate > 100
            || !$this->vatRates->updateRate($country, (float) $rate)) {
            $this->session->flash('error', $this->lang->t('margins.error_invalid'));
        } else {
            // le aliquote toccano solo il VAT a fine ordine: nessun reprice necessario
            $this->session->flash('success', $this->lang->t('margins.vat_saved', ['country' => $country]));
        }

        return Http::redirect($response, '/admin/margini');
    }

    /** Ricalcola i prezzi con le regole aggiornate e torna a /admin/margini. */
    private function repriceAndRedirect(Response $response): Response
    {
        set_time_limit(300);
        $result = $this->feedSync->run(repriceOnly: true);
        if ($result['status'] === 'ok') {
            $this->session->flash('success', $this->lang->t('margins.repriced', ['n' => $result['rows_read']]));
        } else {
            $this->session->flash('error', $this->lang->t('admin.sync_failed', ['error' => (string) $result['message']]));
        }

        return Http::redirect($response, '/admin/margini');
    }

    // ── Ricevuta pro-forma (PDF rigenerato dallo snapshot) ───────────

    /** @param array<string, string> $args */
    public function receiptPdf(Request $request, Response $response, array $args): Response
    {
        $order = $this->orders->find((int) ($args['id'] ?? 0));
        if ($order === null) {
            $this->session->flash('error', $this->lang->t('admin.order_not_found'));

            return Http::redirect($response, '/admin/richieste');
        }
        $snapshot = json_decode(is_string($order['cart_snapshot'] ?? null) ? $order['cart_snapshot'] : '[]', true);
        $order['lines'] = is_array($snapshot) && is_array($snapshot['lines'] ?? null) ? $snapshot['lines'] : [];
        // la ricevuta è un documento per il cliente: MAI offer_price al suo interno
        $order = OrderMailer::stripCosts($order);

        $locale = is_string($order['locale'] ?? null) && $order['locale'] !== '' ? $order['locale'] : 'it';
        $pdf = $this->receipts->buildPdf($order, $locale);
        $response->getBody()->write($pdf);

        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'inline; filename="' . $this->receipts->fileName($order, $locale) . '"')
            ->withHeader('Content-Length', (string) strlen($pdf));
    }

    // ── Flag Recommended ─────────────────────────────────────────────

    public function recommended(Request $request, Response $response): Response
    {
        $query = $request->getQueryParams();
        $sku = is_string($query['sku'] ?? null) ? trim($query['sku']) : '';
        $product = $sku !== '' ? $this->products->findActiveBySku($sku) : null;

        return $this->view->render($response, 'admin/recommended.twig', [
            'sku' => $sku,
            'product' => $product,
            'not_found' => $sku !== '' && $product === null,
        ]);
    }

    public function recommendedToggle(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $sku = is_string($body['sku'] ?? null) ? trim($body['sku']) : '';
        $flag = ($body['recommended'] ?? '') === '1';

        if ($sku !== '' && $this->products->setRecommended($sku, $flag)) {
            $this->session->flash('success', $this->lang->t('admin.recommended_saved', ['sku' => $sku]));
        } else {
            $this->session->flash('error', $this->lang->t('admin.order_not_found'));
        }

        return Http::redirect($response, '/admin/recommended?sku=' . rawurlencode($sku));
    }
}
