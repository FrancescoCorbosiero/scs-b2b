<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ManualReceiptRepository;
use App\Repository\UserRepository;
use App\Repository\VatRateRepository;
use App\Service\ManualReceiptService;
use App\Service\ShippingService;
use App\Support\Config;
use App\Support\Http;
use App\Support\Lang;
use App\Support\Session;
use App\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * /admin/proforma: pro-forma manuali (docs/06). Elenco con ricerca, creazione
 * (vuota, da un cliente registrato o duplicando), modifica finché valida,
 * PDF, invio email al cliente e annullamento. Le righe si compongono a mano;
 * /admin/proforma/prodotto cerca uno SKU a catalogo per precompilarle.
 */
final class AdminProformaController
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly Lang $lang,
        private readonly ManualReceiptService $proforma,
        private readonly ManualReceiptRepository $receipts,
        private readonly UserRepository $users,
        private readonly VatRateRepository $vatRates,
        private readonly ShippingService $shipping,
        private readonly Config $config,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $query = $request->getQueryParams();
        $q = is_string($query['q'] ?? null) ? mb_substr(trim($query['q']), 0, 100) : '';
        $page = max(1, (int) ($query['page'] ?? 1));
        $result = $this->receipts->paginate($page, self::PER_PAGE, $q);

        return $this->view->render($response, 'admin/proforma_list.twig', [
            'receipts' => $result['items'],
            'total' => $result['total'],
            'page' => $page,
            'total_pages' => max(1, (int) ceil($result['total'] / self::PER_PAGE)),
            'q' => $q,
        ]);
    }

    /** Nuova pro-forma: vuota, con i dati di un cliente (?cliente=) o copia di un'altra (?da=). */
    public function createForm(Request $request, Response $response): Response
    {
        $query = $request->getQueryParams();
        $form = $this->proforma->emptyForm();
        $context = [];

        $userId = (int) ($query['cliente'] ?? 0);
        $sourceId = (int) ($query['da'] ?? 0);
        if ($userId > 0 && ($user = $this->users->find($userId)) !== null) {
            $form = $this->proforma->formFromUser($user);
            $context['prefilled_from'] = (string) $user['name'];
        } elseif ($sourceId > 0 && ($source = $this->receipts->find($sourceId)) !== null) {
            $form = $this->proforma->formFromReceipt($source);
            $context['duplicate_of'] = (string) $source['receipt_number'];
        }

        return $this->renderForm($response, $form, [], null, $context);
    }

    public function create(Request $request, Response $response): Response
    {
        $result = $this->proforma->validate((array) ($request->getParsedBody() ?? []));
        if (!$result['ok'] || $result['data'] === null) {
            return $this->renderForm($response->withStatus(422), $result['form'], $result['errors'], null);
        }
        $id = $this->proforma->create($result['data']);
        $receipt = $this->receipts->find($id);
        $this->session->flash('success', $this->lang->t('proforma.created', ['number' => (string) ($receipt['receipt_number'] ?? '')]));

        return Http::redirect($response, '/admin/proforma/' . $id);
    }

    /** @param array<string, string> $args */
    public function show(Request $request, Response $response, array $args): Response
    {
        $receipt = $this->receipts->find((int) ($args['id'] ?? 0));
        if ($receipt === null) {
            return $this->notFound($response);
        }

        return $this->view->render($response, 'admin/proforma_detail.twig', [
            'receipt' => $receipt,
            'linked_user' => $receipt['user_id'] !== null ? $this->users->find($receipt['user_id']) : null,
            'bank_configured' => $this->config->bankDetails()['iban'] !== '',
        ]);
    }

    /** @param array<string, string> $args */
    public function editForm(Request $request, Response $response, array $args): Response
    {
        $receipt = $this->receipts->find((int) ($args['id'] ?? 0));
        if ($receipt === null) {
            return $this->notFound($response);
        }
        if ($receipt['status'] !== ManualReceiptRepository::STATUS_ISSUED) {
            $this->session->flash('error', $this->lang->t('proforma.cannot_edit'));

            return Http::redirect($response, '/admin/proforma/' . $receipt['id']);
        }

        return $this->renderForm($response, $this->proforma->formFromReceipt($receipt), [], $receipt);
    }

    /** @param array<string, string> $args */
    public function update(Request $request, Response $response, array $args): Response
    {
        $receipt = $this->receipts->find((int) ($args['id'] ?? 0));
        if ($receipt === null) {
            return $this->notFound($response);
        }
        if ($receipt['status'] !== ManualReceiptRepository::STATUS_ISSUED) {
            $this->session->flash('error', $this->lang->t('proforma.cannot_edit'));

            return Http::redirect($response, '/admin/proforma/' . $receipt['id']);
        }
        $result = $this->proforma->validate((array) ($request->getParsedBody() ?? []));
        if (!$result['ok'] || $result['data'] === null) {
            return $this->renderForm($response->withStatus(422), $result['form'], $result['errors'], $receipt);
        }
        if (!$this->proforma->update($receipt['id'], $result['data'])) {
            $this->session->flash('error', $this->lang->t('proforma.cannot_edit'));
        } else {
            $this->session->flash('success', $this->lang->t('proforma.updated', ['number' => (string) $receipt['receipt_number']]));
        }

        return Http::redirect($response, '/admin/proforma/' . $receipt['id']);
    }

    /** @param array<string, string> $args */
    public function pdf(Request $request, Response $response, array $args): Response
    {
        $receipt = $this->receipts->find((int) ($args['id'] ?? 0));
        if ($receipt === null) {
            return $this->notFound($response);
        }
        $pdf = $this->proforma->pdf($receipt);
        $response->getBody()->write($pdf['content']);

        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'inline; filename="' . $pdf['name'] . '"')
            ->withHeader('Content-Length', (string) strlen($pdf['content']));
    }

    /** @param array<string, string> $args */
    public function send(Request $request, Response $response, array $args): Response
    {
        $receipt = $this->receipts->find((int) ($args['id'] ?? 0));
        if ($receipt === null) {
            return $this->notFound($response);
        }
        $result = $this->proforma->send($receipt);
        if ($result['ok']) {
            $this->session->flash('success', $this->lang->t('proforma.sent', [
                'number' => (string) $receipt['receipt_number'],
                'email' => (string) $receipt['email'],
            ]));
        } else {
            $this->session->flash('error', (string) $result['error']);
        }

        return Http::redirect($response, '/admin/proforma/' . $receipt['id']);
    }

    /** @param array<string, string> $args */
    public function cancel(Request $request, Response $response, array $args): Response
    {
        $receipt = $this->receipts->find((int) ($args['id'] ?? 0));
        if ($receipt === null) {
            return $this->notFound($response);
        }
        if ($this->proforma->cancel($receipt['id'])) {
            $this->session->flash('success', $this->lang->t('proforma.cancelled', ['number' => (string) $receipt['receipt_number']]));
        }

        return Http::redirect($response, '/admin/proforma/' . $receipt['id']);
    }

    /** Ricerca SKU per precompilare una riga: prezzo di listino, mai il costo. */
    public function lookup(Request $request, Response $response): Response
    {
        $sku = $request->getQueryParams()['sku'] ?? '';
        $product = is_string($sku) ? $this->proforma->lookupSku($sku) : null;
        if ($product === null) {
            return Http::json($response, ['ok' => false, 'error' => $this->lang->t('proforma.lookup_not_found')], 404);
        }

        return Http::json($response, ['ok' => true, 'product' => $product]);
    }

    /**
     * @param array<string, mixed> $form
     * @param list<string> $errors
     * @param array<string, mixed>|null $receipt in modifica; null = nuova
     * @param array<string, string> $context
     */
    private function renderForm(Response $response, array $form, array $errors, ?array $receipt, array $context = []): Response
    {
        $users = [];
        foreach ($this->users->all() as $user) {
            $users[] = [
                'id' => (int) $user['id'],
                'label' => trim((string) $user['name'] . ($user['company'] ? ' — ' . $user['company'] : '') . ' · ' . $user['email']),
            ];
        }
        usort($users, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));

        return $this->view->render($response, 'admin/proforma_form.twig', $context + [
            'form' => $form,
            'errors' => $errors,
            'receipt' => $receipt,
            'users' => $users,
            'vat_countries' => $this->vatRates->all(),
            'shipping_rule' => ['free_from' => $this->shipping->freeFromItems(), 'fee' => $this->shipping->fee()],
            'bank_configured' => $this->config->bankDetails()['iban'] !== '',
            'max_lines' => ManualReceiptService::MAX_LINES,
        ]);
    }

    private function notFound(Response $response): Response
    {
        $this->session->flash('error', $this->lang->t('proforma.not_found'));

        return Http::redirect($response, '/admin/proforma');
    }
}
