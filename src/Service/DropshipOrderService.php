<?php

declare(strict_types=1);

namespace App\Service;

use App\Adapter\DropshipException;
use App\Adapter\GoldenSneakersDropshipClient;
use App\Repository\DropshipOrderRepository;
use App\Repository\ProductRepository;
use App\Support\Config;
use App\Support\Lang;
use App\Support\Session;
use Psr\Log\LoggerInterface;

/**
 * Ordine dropship GoldenSneakers a partire da una richiesta d'ordine
 * (docs/09-order-dropship.md). ANTEPRIMA: in DROPSHIP_MODE=simulation nessuna
 * chiamata parte verso il fornitore.
 *
 * Creare un ordine dropship è IRREVERSIBILE (il fornitore lo conferma e scala
 * il suo stock), quindi il flusso impone TRE conferme, tutte rivalidate lato
 * server, non solo nel browser:
 *   1. invio del form di preparazione (indirizzo + quantità);
 *   2. riepilogo con payload esatto + tre caselle di conferma obbligatorie;
 *   3. digitazione della frase di conferma ("CONFERMA <id richiesta>").
 * La bozza vive in sessione con un token monouso e scade dopo 15 minuti.
 */
final class DropshipOrderService
{
    private const DRAFT_KEY = 'dropship_draft';
    private const DRAFT_TTL_SECONDS = 900;

    /** Caselle di conferma dello step 2: tutte obbligatorie. */
    public const CHECKS = ['check_address', 'check_items', 'check_irreversible'];

    public function __construct(
        private readonly ProductRepository $products,
        private readonly DropshipOrderRepository $dropshipOrders,
        private readonly GoldenSneakersDropshipClient $client,
        private readonly Session $session,
        private readonly Config $config,
        private readonly Lang $lang,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->config->bool('DROPSHIP_ENABLED', false);
    }

    public function mode(): string
    {
        return $this->client->mode();
    }

    public function isSimulation(): bool
    {
        return $this->client->isSimulation();
    }

    public function confirmationPhrase(int $orderRequestId): string
    {
        return 'CONFERMA ' . $orderRequestId;
    }

    // ── Step 1: preparazione ─────────────────────────────────────────

    /**
     * Righe proposte (dal cart_snapshot della richiesta, verificate contro lo
     * stock corrente) e indirizzo precompilato coi dati del cliente.
     *
     * @param array<string, mixed> $orderRequest riga di order_requests
     * @return array{address: array<string, string>, lines: list<array<string, mixed>>,
     *   client_provides_shipping_label: bool}
     */
    public function prepare(array $orderRequest): array
    {
        return [
            'address' => [
                'name' => (string) ($orderRequest['customer_name'] ?? ''),
                'street' => '',
                'city' => '',
                'zip_code' => '',
                'country_code' => 'IT',
                'phone' => (string) ($orderRequest['phone'] ?? ''),
                'email' => (string) ($orderRequest['email'] ?? ''),
            ],
            'lines' => $this->linesFromSnapshot($orderRequest),
            'client_provides_shipping_label' => false,
        ];
    }

    /**
     * Valida l'input dello step 1 e crea la bozza in sessione.
     *
     * @param array<string, mixed> $orderRequest
     * @param array<string, mixed> $input
     * @return array{ok: bool, errors: list<string>}
     */
    public function createDraft(array $orderRequest, array $input): array
    {
        $orderRequestId = (int) ($orderRequest['id'] ?? 0);
        $errors = [];

        $address = $this->validateAddress($input, $errors);

        // quantità per riga: indice → qty, rivalidate contro snapshot e stock
        $lines = $this->linesFromSnapshot($orderRequest);
        $qtyInput = is_array($input['qty'] ?? null) ? $input['qty'] : [];
        $included = [];
        $wholesaleCents = 0;
        foreach ($lines as $i => $line) {
            $qty = (int) ($qtyInput[$i] ?? 0);
            if ($qty < 1) {
                continue;
            }
            if (!$line['orderable']) {
                $errors[] = $this->lang->t('dropship.error_line_not_orderable', [
                    'sku' => $line['sku'], 'size' => $line['size_eu'],
                ]);
                continue;
            }
            if ($qty > $line['stock']) {
                $errors[] = $this->lang->t('dropship.error_line_stock', [
                    'sku' => $line['sku'], 'size' => $line['size_eu'], 'stock' => $line['stock'],
                ]);
                continue;
            }
            $line['qty'] = $qty;
            $wholesaleCents += CartService::cents($line['offer_price']) * $qty;
            $included[] = $line;
        }
        if ($included === []) {
            $errors[] = $this->lang->t('dropship.error_no_items');
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        $items = [];
        foreach ($included as $line) {
            // size_id (id riga del feed) è la chiave preferita; in mancanza
            // l'API accetta sku + size_us
            $items[] = $line['supplier_size_id'] !== null
                ? ['size_id' => $line['supplier_size_id'], 'quantity' => $line['qty']]
                : ['sku' => $line['sku'], 'size_us' => $line['size_us'], 'quantity' => $line['qty']];
        }

        $this->session->set(self::DRAFT_KEY, [
            'order_request_id' => $orderRequestId,
            'token' => bin2hex(random_bytes(16)),
            'created_at' => time(),
            'payload' => [
                'delivery_address' => $address,
                'client_provides_shipping_label' => ($input['client_provides_shipping_label'] ?? '') === '1',
                'items' => $items,
            ],
            'lines' => $included,
            'wholesale_total' => CartService::money($wholesaleCents),
            'checks_passed' => false,
        ]);

        return ['ok' => true, 'errors' => []];
    }

    /** @return array<string, mixed>|null la bozza valida e non scaduta per la richiesta */
    public function draftFor(int $orderRequestId): ?array
    {
        $draft = $this->session->get(self::DRAFT_KEY);
        if (!is_array($draft) || (int) ($draft['order_request_id'] ?? 0) !== $orderRequestId) {
            return null;
        }
        if (time() - (int) ($draft['created_at'] ?? 0) > self::DRAFT_TTL_SECONDS) {
            $this->discardDraft();

            return null;
        }

        return $draft;
    }

    public function discardDraft(): void
    {
        $this->session->remove(self::DRAFT_KEY);
    }

    // ── Step 2: riepilogo + caselle di conferma ──────────────────────

    /**
     * @param array<string, mixed> $input
     * @return array{ok: bool, errors: list<string>}
     */
    public function confirmChecks(int $orderRequestId, array $input): array
    {
        $draft = $this->draftFor($orderRequestId);
        if ($draft === null || !$this->tokenMatches($draft, $input)) {
            return ['ok' => false, 'errors' => [$this->lang->t('dropship.error_draft_expired')]];
        }
        foreach (self::CHECKS as $check) {
            if (($input[$check] ?? '') !== '1') {
                return ['ok' => false, 'errors' => [$this->lang->t('dropship.error_checks_required')]];
            }
        }
        $draft['checks_passed'] = true;
        $this->session->set(self::DRAFT_KEY, $draft);

        return ['ok' => true, 'errors' => []];
    }

    // ── Step 3: frase di conferma + invio ────────────────────────────

    /**
     * Ultima barriera: token + caselle già validate + frase digitata. Solo
     * dopo, l'ordine passa al client (che in simulazione non invia nulla).
     *
     * @param array<string, mixed> $input
     * @return array{ok: bool, errors: list<string>, dropship_id: int|null}
     */
    public function send(int $orderRequestId, array $input): array
    {
        $fail = fn (string $error): array => ['ok' => false, 'errors' => [$error], 'dropship_id' => null];

        $draft = $this->draftFor($orderRequestId);
        if ($draft === null || !$this->tokenMatches($draft, $input)) {
            return $fail($this->lang->t('dropship.error_draft_expired'));
        }
        if (($draft['checks_passed'] ?? false) !== true) {
            return $fail($this->lang->t('dropship.error_checks_required'));
        }
        $phrase = is_string($input['confirmation_phrase'] ?? null) ? trim($input['confirmation_phrase']) : '';
        if (strcasecmp($phrase, $this->confirmationPhrase($orderRequestId)) !== 0) {
            return $fail($this->lang->t('dropship.error_phrase', [
                'phrase' => $this->confirmationPhrase($orderRequestId),
            ]));
        }

        if (!$this->isSimulation()) {
            // modalità live non implementata in questa anteprima: rifiuta
            // esplicitamente, nessun ordine parte
            return $fail($this->lang->t('dropship.error_live_disabled'));
        }

        /** @var array{delivery_address: array<string, string>, client_provides_shipping_label: bool,
         *   items: list<array<string, int|string>>} $payload */
        $payload = $draft['payload'];
        try {
            $response = $this->client->createOrder($payload);
        } catch (DropshipException $e) {
            $this->logger->error('Creazione ordine dropship rifiutata', ['error' => $e->getMessage()]);

            return $fail($e->getMessage());
        }

        $dropshipId = $this->dropshipOrders->insert([
            'order_request_id' => $orderRequestId > 0 ? $orderRequestId : null,
            'mode' => $this->mode(),
            'status' => 'UNCONFIRMED',
            'vendor_order_id' => $response['order_id'],
            'dropship_package_id' => $response['dropship_package_id'],
            // stima a costo fornitore: il totale reale lo calcola l'API
            'total_price' => is_string($draft['wholesale_total'] ?? null) ? $draft['wholesale_total'] : null,
            'currency' => 'EUR',
            'request_payload' => (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'lines_snapshot' => (string) json_encode($draft['lines'], JSON_UNESCAPED_UNICODE),
            'response_payload' => (string) json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        ]);
        $this->discardDraft();
        $this->logger->info('Ordine dropship registrato', [
            'dropship_id' => $dropshipId,
            'order_request_id' => $orderRequestId,
            'mode' => $this->mode(),
            'simulated' => $response['simulated'],
        ]);

        return ['ok' => true, 'errors' => [], 'dropship_id' => $dropshipId];
    }

    /**
     * Rilegge lo stato dal fornitore (in simulazione: risposta fittizia,
     * nessuna chiamata) e aggiorna il record.
     *
     * @param array<string, mixed> $dropshipOrder riga di dropship_orders
     * @return array{ok: bool, message: string}
     */
    public function refreshStatus(array $dropshipOrder): array
    {
        $vendorOrderId = (int) ($dropshipOrder['vendor_order_id'] ?? 0);
        if ($vendorOrderId <= 0) {
            return ['ok' => false, 'message' => $this->lang->t('dropship.error_no_vendor_id')];
        }
        try {
            $details = $this->client->orderDetails($vendorOrderId);
        } catch (DropshipException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
        $status = in_array($details['status'], GoldenSneakersDropshipClient::STATUSES, true)
            ? $details['status']
            : (string) $dropshipOrder['status'];
        $this->dropshipOrders->updateStatus((int) $dropshipOrder['id'], $status, $details['tracking_numbers']);

        return [
            'ok' => true,
            'message' => $this->lang->t(
                $details['simulated'] ? 'dropship.refresh_simulated' : 'dropship.refresh_done',
                ['status' => $status]
            ),
        ];
    }

    // ── Interni ──────────────────────────────────────────────────────

    /**
     * Righe del cart_snapshot confrontate con lo stock e i size_id correnti.
     *
     * @param array<string, mixed> $orderRequest
     * @return list<array<string, mixed>>
     */
    private function linesFromSnapshot(array $orderRequest): array
    {
        $snapshot = json_decode(is_string($orderRequest['cart_snapshot'] ?? null) ? $orderRequest['cart_snapshot'] : '[]', true);
        $rawLines = is_array($snapshot) && is_array($snapshot['lines'] ?? null) ? $snapshot['lines'] : [];

        $skus = [];
        foreach ($rawLines as $line) {
            if (is_array($line) && is_string($line['sku'] ?? null)) {
                $skus[$line['sku']] = true;
            }
        }
        $current = $this->products->dropshipDataForSkuSizes(array_keys($skus));

        $lines = [];
        foreach ($rawLines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $sku = (string) ($line['sku'] ?? '');
            $sizeEu = (string) ($line['size_eu'] ?? '');
            $requested = max(0, (int) ($line['qty'] ?? 0));
            if ($sku === '' || $sizeEu === '' || $requested < 1) {
                continue;
            }
            $size = $current[$sku][$sizeEu] ?? null;
            $stock = $size['quantity'] ?? 0;
            $supplierSizeId = $size['supplier_size_id'] ?? null;
            $sizeUs = $size['size_us'] ?? (string) ($line['size_us'] ?? '');

            $issue = null;
            if ($size === null) {
                $issue = $this->lang->t('dropship.issue_size_gone');
            } elseif ($supplierSizeId === null && $sizeUs === '') {
                // senza size_id né size_us l'API non può identificare la taglia
                $issue = $this->lang->t('dropship.issue_no_size_id');
            } elseif ($requested > $stock) {
                $issue = $this->lang->t('dropship.issue_stock_reduced', ['stock' => $stock]);
            }

            $lines[] = [
                'sku' => $sku,
                'name' => (string) ($line['name'] ?? ''),
                'size_eu' => $sizeEu,
                'size_us' => $sizeUs,
                'requested' => $requested,
                'qty' => min($requested, $stock),
                'stock' => $stock,
                'supplier_size_id' => $supplierSizeId,
                'offer_price' => (string) ($size['offer_price'] ?? '0.00'),
                'orderable' => $size !== null && ($supplierSizeId !== null || $sizeUs !== ''),
                'issue' => $issue,
            ];
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $input
     * @param list<string> $errors
     * @return array<string, string>
     */
    private function validateAddress(array $input, array &$errors): array
    {
        $field = static function (string $key, int $max) use ($input): string {
            $value = $input[$key] ?? '';

            return is_string($value) ? mb_substr(trim(strip_tags($value)), 0, $max) : '';
        };

        $address = [
            'name' => $field('name', 128),
            'city' => $field('city', 128),
            'zip_code' => $field('zip_code', 16),
            'street' => $field('street', 255),
            // niente truncation: "ITA" deve fallire la validazione, non diventare "IT"
            'country_code' => strtoupper($field('country_code', 8)),
            'phone' => $field('phone', 32),
            'email' => $field('email', 255),
        ];

        foreach (['name', 'street', 'city', 'zip_code', 'phone'] as $required) {
            if ($address[$required] === '') {
                $errors[] = $this->lang->t('dropship.error_address_' . $required);
            }
        }
        if (preg_match('/^[A-Z]{2}$/', $address['country_code']) !== 1) {
            $errors[] = $this->lang->t('dropship.error_address_country');
        }
        if ($address['email'] === '' || filter_var($address['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = $this->lang->t('dropship.error_address_email');
        }

        return $address;
    }

    /**
     * @param array<string, mixed> $draft
     * @param array<string, mixed> $input
     */
    private function tokenMatches(array $draft, array $input): bool
    {
        $token = $input['_draft_token'] ?? null;
        $expected = $draft['token'] ?? '';

        return is_string($token) && is_string($expected) && $expected !== ''
            && hash_equals($expected, $token);
    }
}
