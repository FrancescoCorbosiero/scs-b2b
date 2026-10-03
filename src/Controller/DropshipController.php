<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\DropshipOrderRepository;
use App\Repository\OrderRequestRepository;
use App\Service\DropshipOrderService;
use App\Support\Http;
use App\Support\Lang;
use App\Support\Session;
use App\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Ordini presso GoldenSneakers, solo area /admin (docs/09):
 *  - flusso manuale a tre step di conferma dalla richiesta d'ordine
 *    (in DROPSHIP_MODE=simulation nessun ordine parte verso il fornitore);
 *  - dettaglio degli ordini registrati dalla piattaforma, con rilettura
 *    dello stato dal fornitore;
 *  - elenco e dettaglio in tempo reale degli ordini sull'account
 *    GoldenSneakers (GET /api/orders/ e /api/orders/{id}/, sola lettura).
 */
final class DropshipController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly Lang $lang,
        private readonly OrderRequestRepository $orders,
        private readonly DropshipOrderRepository $dropshipOrders,
        private readonly DropshipOrderService $dropship,
    ) {
    }

    // ── Step 1: preparazione (indirizzo + quantità) ──────────────────

    /** @param array<string, string> $args */
    public function prepare(Request $request, Response $response, array $args): Response
    {
        $order = $this->findOrderOr404($args, $response);
        if ($order instanceof Response) {
            return $order;
        }
        if (!$this->dropship->isEnabled()) {
            $this->session->flash('error', $this->lang->t('dropship.disabled'));

            return Http::redirect($response, '/admin/richieste/' . $order['id']);
        }
        $this->dropship->discardDraft();

        return $this->view->render($response, 'admin/dropship_prepare.twig', [
            'order' => $order,
            'draft' => $this->dropship->prepare($order),
            'existing' => $this->dropshipOrders->findByOrderRequest((int) $order['id']),
            'is_simulation' => $this->dropship->isSimulation(),
        ]);
    }

    // ── Step 2: riepilogo payload + caselle di conferma ──────────────

    /** @param array<string, string> $args */
    public function review(Request $request, Response $response, array $args): Response
    {
        $order = $this->findOrderOr404($args, $response);
        if ($order instanceof Response) {
            return $order;
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $result = $this->dropship->createDraft($order, $body);
        if (!$result['ok']) {
            foreach ($result['errors'] as $error) {
                $this->session->flash('error', $error);
            }

            return Http::redirect($response, '/admin/richieste/' . $order['id'] . '/dropship');
        }
        $draft = $this->dropship->draftFor((int) $order['id']);
        if ($draft === null) {
            return Http::redirect($response, '/admin/richieste/' . $order['id'] . '/dropship');
        }

        return $this->view->render($response, 'admin/dropship_review.twig', [
            'order' => $order,
            'draft' => $draft,
            'payload_json' => json_encode($draft['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            'is_simulation' => $this->dropship->isSimulation(),
        ]);
    }

    // ── Step 3: frase di conferma ────────────────────────────────────

    /** @param array<string, string> $args */
    public function confirm(Request $request, Response $response, array $args): Response
    {
        $order = $this->findOrderOr404($args, $response);
        if ($order instanceof Response) {
            return $order;
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $result = $this->dropship->confirmChecks((int) $order['id'], $body);
        if (!$result['ok']) {
            foreach ($result['errors'] as $error) {
                $this->session->flash('error', $error);
            }

            return Http::redirect($response, '/admin/richieste/' . $order['id'] . '/dropship');
        }
        $draft = $this->dropship->draftFor((int) $order['id']);
        if ($draft === null) {
            return Http::redirect($response, '/admin/richieste/' . $order['id'] . '/dropship');
        }

        return $this->view->render($response, 'admin/dropship_confirm.twig', [
            'order' => $order,
            'draft' => $draft,
            'phrase' => $this->dropship->confirmationPhrase((int) $order['id']),
            'is_simulation' => $this->dropship->isSimulation(),
        ]);
    }

    // ── Invio (simulato) ─────────────────────────────────────────────

    /** @param array<string, string> $args */
    public function send(Request $request, Response $response, array $args): Response
    {
        $order = $this->findOrderOr404($args, $response);
        if ($order instanceof Response) {
            return $order;
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $result = $this->dropship->send((int) $order['id'], $body);
        if (!$result['ok']) {
            foreach ($result['errors'] as $error) {
                $this->session->flash('error', $error);
            }

            return Http::redirect($response, '/admin/richieste/' . $order['id'] . '/dropship');
        }
        $this->session->flash(
            'success',
            $this->lang->t($this->dropship->isSimulation() ? 'dropship.sent_simulated' : 'dropship.sent', [
                'id' => (int) $result['dropship_id'],
            ])
        );

        return Http::redirect($response, '/admin/dropship/' . $result['dropship_id']);
    }

    // ── Dettaglio ordine dropship ────────────────────────────────────

    /** @param array<string, string> $args */
    public function detail(Request $request, Response $response, array $args): Response
    {
        $dropshipOrder = $this->dropshipOrders->find((int) ($args['id'] ?? 0));
        if ($dropshipOrder === null) {
            $this->session->flash('error', $this->lang->t('admin.order_not_found'));

            return Http::redirect($response, '/admin/richieste');
        }
        $lines = json_decode(is_string($dropshipOrder['lines_snapshot'] ?? null) ? $dropshipOrder['lines_snapshot'] : '[]', true);
        $tracking = json_decode(is_string($dropshipOrder['tracking_numbers'] ?? null) ? $dropshipOrder['tracking_numbers'] : '[]', true);
        // ultima lettura dal fornitore (order-details + package-details), se fatta
        $details = json_decode(is_string($dropshipOrder['details_payload'] ?? null) ? $dropshipOrder['details_payload'] : 'null', true);
        $vendorOrder = is_array($details) && is_array($details['order'] ?? null) ? $details['order'] : null;
        $vendorPackage = is_array($details) && is_array($details['package'] ?? null) ? $details['package'] : null;

        return $this->view->render($response, 'admin/dropship_detail.twig', [
            'ds' => $dropshipOrder,
            'is_orders_api' => ($dropshipOrder['api'] ?? DropshipOrderRepository::API_DROPSHIP) === DropshipOrderRepository::API_ORDERS,
            'label_pending' => $this->dropship->labelPending($dropshipOrder),
            'lines' => is_array($lines) ? $lines : [],
            'tracking' => is_array($tracking) ? $tracking : [],
            'vendor_order' => $vendorOrder,
            // API ordini: il dettaglio già validato dal client (pro-forma, pagamento, indirizzi)
            'vendor_parsed' => is_array($details) && is_array($details['parsed'] ?? null) ? $details['parsed'] : null,
            'vendor_package' => $vendorPackage,
            'details_fetched_at' => is_array($details) && is_string($details['fetched_at'] ?? null) ? $details['fetched_at'] : null,
            'request_json' => (string) ($dropshipOrder['request_payload'] ?? ''),
            'response_json' => (string) ($dropshipOrder['response_payload'] ?? ''),
        ]);
    }

    // ── Ordini sull'account GoldenSneakers (API ordini, sola lettura) ─

    /**
     * Elenco in tempo reale degli ordini dell'account (GET /api/orders/),
     * più il registro degli ordini creati dalla piattaforma (con gli esiti
     * UNKNOWN da verificare in evidenza).
     */
    public function supplierOrders(Request $request, Response $response): Response
    {
        if (!$this->dropship->isEnabled()) {
            $this->session->flash('error', $this->lang->t('dropship.disabled'));

            return Http::redirect($response, '/admin');
        }

        return $this->view->render($response, 'admin/supplier_orders.twig', [
            'remote' => $this->dropship->vendorOrders(),
            'local_orders' => $this->dropshipOrders->recent(30),
            'is_simulation' => $this->dropship->isSimulation(),
        ]);
    }

    /**
     * Dettaglio in tempo reale di un ordine GoldenSneakers (GET
     * /api/orders/{id}/): righe, indirizzi, pro-forma, fattura e pagamento.
     *
     * @param array<string, string> $args
     */
    public function supplierOrder(Request $request, Response $response, array $args): Response
    {
        if (!$this->dropship->isEnabled()) {
            $this->session->flash('error', $this->lang->t('dropship.disabled'));

            return Http::redirect($response, '/admin');
        }
        $vendorOrderId = (int) ($args['id'] ?? 0);
        $result = $this->dropship->vendorOrder($vendorOrderId);
        if (!$result['ok']) {
            $this->session->flash('error', (string) $result['error']);

            return Http::redirect($response, '/admin/ordini-fornitore');
        }

        return $this->view->render($response, 'admin/supplier_order.twig', [
            'vendor_order_id' => $vendorOrderId,
            'vo' => $result['order'],
            'local' => $result['local'],
        ]);
    }

    /** @param array<string, string> $args */
    public function refresh(Request $request, Response $response, array $args): Response
    {
        $dropshipOrder = $this->dropshipOrders->find((int) ($args['id'] ?? 0));
        if ($dropshipOrder === null) {
            $this->session->flash('error', $this->lang->t('admin.order_not_found'));

            return Http::redirect($response, '/admin/richieste');
        }
        $result = $this->dropship->refreshStatus($dropshipOrder);
        $this->session->flash($result['ok'] ? 'success' : 'error', $result['message']);

        return Http::redirect($response, '/admin/dropship/' . $dropshipOrder['id']);
    }

    /**
     * Upload etichetta di spedizione (ordini con
     * client_provides_shipping_label=True): file + tracking al fornitore.
     *
     * @param array<string, string> $args
     */
    public function uploadLabel(Request $request, Response $response, array $args): Response
    {
        $dropshipOrder = $this->dropshipOrders->find((int) ($args['id'] ?? 0));
        if ($dropshipOrder === null) {
            $this->session->flash('error', $this->lang->t('admin.order_not_found'));

            return Http::redirect($response, '/admin/richieste');
        }

        $uploaded = $request->getUploadedFiles()['shipping_label'] ?? null;
        $body = (array) $request->getParsedBody();
        if (!$uploaded instanceof \Psr\Http\Message\UploadedFileInterface
            || $uploaded->getError() !== UPLOAD_ERR_OK) {
            $this->session->flash('error', $this->lang->t('dropship.label_file_required'));

            return Http::redirect($response, '/admin/dropship/' . $dropshipOrder['id']);
        }

        // il service rivalida tipo/MIME/dimensione sul file temporaneo
        $tmpPath = $uploaded->getStream()->getMetadata('uri');
        $result = $this->dropship->uploadLabel($dropshipOrder, [
            'tmp_path' => is_string($tmpPath) ? $tmpPath : '',
            'name' => (string) ($uploaded->getClientFilename() ?? 'label'),
            'size' => (int) ($uploaded->getSize() ?? 0),
        ], is_string($body['tracking_numbers'] ?? null) ? $body['tracking_numbers'] : '');

        $this->session->flash($result['ok'] ? 'success' : 'error', $result['message']);

        return Http::redirect($response, '/admin/dropship/' . $dropshipOrder['id']);
    }

    /**
     * @param array<string, string> $args
     * @return array<string, mixed>|Response
     */
    private function findOrderOr404(array $args, Response $response): array|Response
    {
        $order = $this->orders->find((int) ($args['id'] ?? 0));
        if ($order === null) {
            $this->session->flash('error', $this->lang->t('admin.order_not_found'));

            return Http::redirect($response, '/admin/richieste');
        }

        return $order;
    }
}
