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
 * Flusso ordine dropship GoldenSneakers, solo area /admin (docs/09).
 * Tre step di conferma prima dell'invio; in DROPSHIP_MODE=simulation
 * nessuna chiamata parte verso il fornitore.
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

        return $this->view->render($response, 'admin/dropship_detail.twig', [
            'ds' => $dropshipOrder,
            'lines' => is_array($lines) ? $lines : [],
            'tracking' => is_array($tracking) ? $tracking : [],
            'request_json' => (string) ($dropshipOrder['request_payload'] ?? ''),
            'response_json' => (string) ($dropshipOrder['response_payload'] ?? ''),
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
