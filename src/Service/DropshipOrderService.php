<?php

declare(strict_types=1);

namespace App\Service;

use App\Adapter\DropshipException;
use App\Adapter\DropshipUncertainException;
use App\Adapter\GoldenSneakersDropshipClient;
use App\Repository\DropshipOrderRepository;
use App\Repository\ProductRepository;
use App\Support\Config;
use App\Support\Lang;
use App\Support\Session;
use Psr\Log\LoggerInterface;

/**
 * Ordine dropship GoldenSneakers a partire da una richiesta d'ordine
 * (docs/09-order-dropship.md). In DROPSHIP_MODE=simulation nessuna chiamata
 * parte verso il fornitore; in live il client invia davvero.
 *
 * Creare un ordine dropship è IRREVERSIBILE (il fornitore lo conferma e scala
 * il suo stock), quindi il flusso impone TRE conferme, tutte rivalidate lato
 * server, non solo nel browser:
 *   1. invio del form di preparazione (indirizzo + quantità);
 *   2. riepilogo con payload esatto + tre caselle di conferma obbligatorie;
 *   3. digitazione della frase di conferma ("CONFERMA <id richiesta>").
 * La bozza vive in sessione con un token monouso e scade dopo 15 minuti.
 *
 * Protezioni aggiuntive in live:
 *  - DROPSHIP_MAX_ORDER_EUR: tetto sul costo fornitore stimato, verificato
 *    PRIMA della chiamata (0 o assente = nessun tetto);
 *  - esito INCERTO (DropshipUncertainException): l'accaduto viene registrato
 *    in dropship_orders con status UNKNOWN e la bozza viene scartata, così
 *    un secondo invio richiede di ripetere le tre conferme DOPO aver
 *    verificato sul portale del fornitore (mai retry ciechi = mai doppi);
 *  - l'auto-dropship invia in live solo con AUTO_DROPSHIP_ALLOW_LIVE=1
 *    (percorso innescato dal cliente, senza passaggio admin).
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

        $wholesaleTotal = is_string($draft['wholesale_total'] ?? null) ? $draft['wholesale_total'] : '0.00';
        $capError = $this->capError($wholesaleTotal);
        if ($capError !== null) {
            return $fail($capError);
        }

        /** @var array{delivery_address: array<string, string>, client_provides_shipping_label: bool,
         *   items: list<array<string, int|string>>} $payload */
        $payload = $draft['payload'];
        try {
            $response = $this->client->createOrder($payload);
        } catch (DropshipUncertainException $e) {
            // l'ordine POTREBBE esistere presso il fornitore: si registra
            // l'accaduto e si scarta la bozza, così ritentare richiede di
            // ripetere le tre conferme dopo la verifica manuale
            $unknownId = $this->recordUncertain($orderRequestId, $payload, $draft['lines'] ?? [], $wholesaleTotal, $e->getMessage());
            $this->discardDraft();

            return $fail($this->lang->t('dropship.error_uncertain', [
                'id' => $unknownId, 'error' => $e->getMessage(),
            ]));
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
            // in live vale il totale calcolato dall'API; altrimenti la stima a costo fornitore
            'total_price' => $response['total_price'] !== null
                ? number_format($response['total_price'], 2, '.', '')
                : $wholesaleTotal,
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

    // ── Creazione automatica alla richiesta d'ordine (docs/06 e 09) ──

    /**
     * Crea l'ordine dropship direttamente alla richiesta del cliente, con il
     * SUO indirizzo di spedizione, per bloccare lo stock del fornitore prima
     * che arrivi il bonifico (AUTO_DROPSHIP_ON_REQUEST).
     *
     * ⚠ Percorso innescato dal cliente (anche con la password ospite
     * condivisa), senza passaggio admin: per questo resta dietro flag .env
     * (kill-switch), eredita rate limit e ordine minimo della richiesta e in
     * live invia SOLO con l'ulteriore flag AUTO_DROPSHIP_ALLOW_LIVE=1.
     * L'esito è riportato nell'email admin.
     *
     * @param array<string, mixed> $order richiesta appena salvata (con cart_snapshot e indirizzo)
     * @return array{ok: bool, dropship_id: int|null, message: string|null, simulated: bool|null}
     */
    public function autoCreateFromRequest(array $order): array
    {
        $fail = fn (string $message): array => ['ok' => false, 'dropship_id' => null, 'message' => $message, 'simulated' => null];

        if (!$this->isEnabled()) {
            return $fail($this->lang->t('dropship.disabled'));
        }
        if (!$this->isSimulation() && !$this->config->bool('AUTO_DROPSHIP_ALLOW_LIVE', false)) {
            // in live l'invio automatico (innescato dal cliente) richiede un
            // opt-in esplicito: di default resta solo il flusso admin manuale
            return $fail($this->lang->t('dropship.auto_live_disabled'));
        }

        $errors = [];
        $address = $this->validateAddress([
            'name' => (string) ($order['customer_name'] ?? ''),
            'street' => (string) ($order['address_street'] ?? ''),
            'city' => (string) ($order['address_city'] ?? ''),
            'zip_code' => (string) ($order['address_zip'] ?? ''),
            'country_code' => (string) ($order['country_code'] ?? ''),
            'phone' => (string) ($order['phone'] ?? ''),
            'email' => (string) ($order['email'] ?? ''),
        ], $errors);
        if ($errors !== []) {
            return $fail(implode(' ', $errors));
        }

        // righe ordinabili, clampate allo stock corrente (appena rivalidato dal carrello)
        $items = [];
        $included = [];
        $wholesaleCents = 0;
        foreach ($this->linesFromSnapshot($order) as $line) {
            $qty = (int) $line['qty'];
            if (!$line['orderable'] || $qty < 1) {
                continue;
            }
            $items[] = $line['supplier_size_id'] !== null
                ? ['size_id' => $line['supplier_size_id'], 'quantity' => $qty]
                : ['sku' => $line['sku'], 'size_us' => $line['size_us'], 'quantity' => $qty];
            $wholesaleCents += CartService::cents($line['offer_price']) * $qty;
            $included[] = $line;
        }
        if ($items === []) {
            return $fail($this->lang->t('dropship.error_no_items'));
        }

        $wholesaleTotal = CartService::money($wholesaleCents);
        $capError = $this->capError($wholesaleTotal);
        if ($capError !== null) {
            return $fail($capError);
        }

        $payload = [
            'delivery_address' => $address,
            'client_provides_shipping_label' => false,
            'items' => $items,
        ];
        try {
            $response = $this->client->createOrder($payload);
        } catch (DropshipUncertainException $e) {
            // niente retry: si registra l'esito incerto e l'admin verifica
            // sul portale del fornitore (email admin + riga UNKNOWN)
            $orderRequestId = (int) ($order['id'] ?? 0);
            $unknownId = $this->recordUncertain($orderRequestId, $payload, $included, $wholesaleTotal, $e->getMessage());

            return $fail($this->lang->t('dropship.error_uncertain', [
                'id' => $unknownId, 'error' => $e->getMessage(),
            ]));
        } catch (DropshipException $e) {
            $this->logger->error('Auto-dropship rifiutato', ['error' => $e->getMessage()]);

            return $fail($e->getMessage());
        }

        $dropshipId = $this->dropshipOrders->insert([
            'order_request_id' => (int) ($order['id'] ?? 0) > 0 ? (int) $order['id'] : null,
            'mode' => $this->mode(),
            'status' => 'UNCONFIRMED',
            'vendor_order_id' => $response['order_id'],
            'dropship_package_id' => $response['dropship_package_id'],
            'total_price' => $response['total_price'] !== null
                ? number_format($response['total_price'], 2, '.', '')
                : $wholesaleTotal,
            'currency' => 'EUR',
            'request_payload' => (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'lines_snapshot' => (string) json_encode($included, JSON_UNESCAPED_UNICODE),
            'response_payload' => (string) json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        ]);
        $this->logger->info('Ordine dropship automatico registrato', [
            'dropship_id' => $dropshipId,
            'order_request_id' => $order['id'] ?? null,
            'mode' => $this->mode(),
            'simulated' => $response['simulated'],
        ]);

        return ['ok' => true, 'dropship_id' => $dropshipId, 'message' => null, 'simulated' => (bool) $response['simulated']];
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
     * Tetto DROPSHIP_MAX_ORDER_EUR sul costo fornitore stimato, verificato
     * PRIMA della chiamata. 0 o assente = nessun tetto.
     */
    private function capError(string $wholesaleTotal): ?string
    {
        $cap = $this->config->float('DROPSHIP_MAX_ORDER_EUR', 0.0);
        if ($cap <= 0 || CartService::cents($wholesaleTotal) <= (int) round($cap * 100)) {
            return null;
        }

        return $this->lang->t('dropship.error_cap_exceeded', [
            'total' => $wholesaleTotal,
            'cap' => number_format($cap, 2, '.', ''),
        ]);
    }

    /**
     * Registra un esito INCERTO (l'ordine potrebbe esistere presso il
     * fornitore) come riga UNKNOWN, per l'audit e la verifica manuale.
     *
     * @param array<string, mixed> $payload
     * @param list<array<string, mixed>>|mixed $lines
     */
    private function recordUncertain(int $orderRequestId, array $payload, mixed $lines, string $wholesaleTotal, string $error): int
    {
        $unknownId = $this->dropshipOrders->insert([
            'order_request_id' => $orderRequestId > 0 ? $orderRequestId : null,
            'mode' => $this->mode(),
            'status' => 'UNKNOWN',
            'vendor_order_id' => null,
            'dropship_package_id' => null,
            'total_price' => $wholesaleTotal,
            'currency' => 'EUR',
            'request_payload' => (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'lines_snapshot' => (string) json_encode(is_array($lines) ? $lines : [], JSON_UNESCAPED_UNICODE),
            'response_payload' => (string) json_encode(['error' => $error, 'uncertain' => true], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        ]);
        $this->logger->error('Ordine dropship con esito INCERTO registrato: verificare presso il fornitore prima di ritentare', [
            'dropship_id' => $unknownId,
            'order_request_id' => $orderRequestId,
            'error' => $error,
        ]);

        return $unknownId;
    }

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
