<?php

declare(strict_types=1);

namespace App\Service;

use App\Adapter\DropshipException;
use App\Adapter\DropshipUncertainException;
use App\Adapter\GoldenSneakersApiClient;
use App\Adapter\GoldenSneakersDropshipClient;
use App\Adapter\GoldenSneakersOrdersClient;
use App\Repository\DropshipOrderRepository;
use App\Repository\ProductRepository;
use App\Support\Config;
use App\Support\Lang;
use App\Support\Session;
use Psr\Log\LoggerInterface;

/**
 * Ordine presso GoldenSneakers a partire da una richiesta d'ordine
 * (docs/09-order-dropship.md): il fornitore spedisce direttamente al cliente
 * (o al suo cliente finale), da cui il nome "dropship" che resta nel codice,
 * nella tabella e nelle variabili .env.
 *
 * Dal 02/10/2026 l'ordine si crea con l'API ordini GoldenSneakers
 * (POST /api/orders/create/, GoldenSneakersOrdersClient); l'API
 * orders-dropship serve solo a rileggere gli ordini registrati prima
 * (colonna `api` = 'dropship') e a caricarne l'eventuale etichetta.
 * In DROPSHIP_MODE=simulation nessun ordine parte verso il fornitore.
 *
 * Creare un ordine è IRREVERSIBILE (il fornitore lo prende in carico e
 * impegna il suo stock), quindi il flusso manuale impone TRE conferme, tutte
 * rivalidate lato server, non solo nel browser:
 *   1. invio del form di preparazione (indirizzo + quantità);
 *   2. riepilogo con payload esatto + tre caselle di conferma obbligatorie;
 *   3. digitazione della frase di conferma ("CONFERMA <id richiesta>").
 * La bozza vive in sessione con un token monouso e scade dopo 15 minuti.
 *
 * Protezioni aggiuntive in live:
 *  - DROPSHIP_MAX_ORDER_EUR: tetto sul costo fornitore stimato, verificato
 *    PRIMA della chiamata (0 o assente = nessun tetto);
 *  - esito INCERTO (DropshipUncertainException): l'accaduto viene registrato
 *    con status UNKNOWN e la bozza viene scartata, così un secondo invio
 *    richiede di ripetere le tre conferme DOPO aver verificato sul portale
 *    del fornitore (mai retry ciechi = mai doppi);
 *  - l'invio automatico alla richiesta parte in live solo con
 *    AUTO_DROPSHIP_ALLOW_LIVE=1 (percorso innescato dal cliente).
 *
 * I prodotti propri (products.source = 'custom', importati da /admin) non
 * sono del fornitore: restano SEMPRE fuori dall'ordine GoldenSneakers.
 */
final class DropshipOrderService
{
    private const DRAFT_KEY = 'dropship_draft';
    private const DRAFT_TTL_SECONDS = 900;

    /** Caselle di conferma dello step 2: tutte obbligatorie. */
    public const CHECKS = ['check_address', 'check_items', 'check_irreversible'];

    /** Valuta degli ordini: la piattaforma lavora solo in EUR. */
    public const CURRENCY = 'EUR';

    public function __construct(
        private readonly ProductRepository $products,
        private readonly DropshipOrderRepository $dropshipOrders,
        private readonly GoldenSneakersDropshipClient $legacyClient,
        private readonly GoldenSneakersOrdersClient $ordersClient,
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
        return $this->ordersClient->mode();
    }

    public function isSimulation(): bool
    {
        return $this->ordersClient->isSimulation();
    }

    /** Il token del fornitore c'è: elenco e dettaglio ordini sono leggibili. */
    public function canReadVendorOrders(): bool
    {
        return $this->ordersClient->hasToken();
    }

    public function confirmationPhrase(int $orderRequestId): string
    {
        return 'CONFERMA ' . $orderRequestId;
    }

    // ── Step 1: preparazione ─────────────────────────────────────────

    /**
     * Righe proposte (dal cart_snapshot della richiesta, verificate contro lo
     * stock corrente) e indirizzo di spedizione precompilato: quello del
     * destinatario finale se il rivenditore ha chiesto il dropshipping
     * (ship_to=customer), altrimenti i dati del rivenditore stesso.
     *
     * @param array<string, mixed> $orderRequest riga di order_requests
     * @return array{address: array<string, string>, lines: list<array<string, mixed>>}
     */
    public function prepare(array $orderRequest): array
    {
        return [
            'address' => $this->shippingAddressFor($orderRequest),
            'lines' => $this->linesFromSnapshot($orderRequest),
        ];
    }

    /**
     * shipping_address dell'API ordini per la richiesta: destinatario finale
     * con ship_to=customer, altrimenti il rivenditore. L'email di contatto
     * resta SEMPRE quella del rivenditore: il cliente finale non deve
     * ricevere comunicazioni dal fornitore.
     *
     * @param array<string, mixed> $orderRequest
     * @return array<string, string>
     */
    private function shippingAddressFor(array $orderRequest): array
    {
        $toCustomer = ($orderRequest['ship_to'] ?? '') === 'customer';
        $pick = static fn (string $reseller, string $recipient): string => (string) ($orderRequest[$toCustomer ? $recipient : $reseller] ?? '');

        return [
            'recipient_name' => $pick('customer_name', 'recipient_name'),
            'address_l1' => $pick('address_street', 'recipient_street'),
            'address_l2' => '',
            'city' => $pick('address_city', 'recipient_city'),
            'zip_code' => $pick('address_zip', 'recipient_zip'),
            'country' => $pick('country_code', 'recipient_country'),
            'phone' => $pick('phone', 'recipient_phone'),
            'email' => (string) ($orderRequest['email'] ?? ''),
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
            $included[] = $line;
        }
        if ($included === []) {
            $errors[] = $this->lang->t('dropship.error_no_items');
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        $this->session->set(self::DRAFT_KEY, [
            'order_request_id' => $orderRequestId,
            'token' => bin2hex(random_bytes(16)),
            'created_at' => time(),
            'payload' => $this->buildPayload($address, $included),
            'lines' => $included,
            'wholesale_total' => $this->wholesaleTotal($included),
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

        /** @var array{currency: string, shipping_address: array<string, string>,
         *   items: list<array<string, int|string>>} $payload */
        $payload = $draft['payload'];
        /** @var list<array<string, mixed>> $lines */
        $lines = is_array($draft['lines'] ?? null) ? $draft['lines'] : [];
        try {
            $response = $this->ordersClient->createOrder($payload);
        } catch (DropshipUncertainException $e) {
            // l'ordine POTREBBE esistere presso il fornitore: si registra
            // l'accaduto e si scarta la bozza, così ritentare richiede di
            // ripetere le tre conferme dopo la verifica manuale
            $unknownId = $this->recordUncertain($orderRequestId, $payload, $lines, $wholesaleTotal, $e->getMessage());
            $this->discardDraft();

            return $fail($this->lang->t('dropship.error_uncertain', [
                'id' => $unknownId, 'error' => $e->getMessage(),
            ]));
        } catch (DropshipException $e) {
            $this->logger->error('Creazione ordine GoldenSneakers rifiutata', ['error' => $e->getMessage()]);

            return $fail($e->getMessage());
        }

        $dropshipId = $this->recordCreated($orderRequestId, $payload, $lines, $wholesaleTotal, $response);
        $this->discardDraft();
        $this->logger->info('Ordine GoldenSneakers registrato', [
            'dropship_id' => $dropshipId,
            'order_request_id' => $orderRequestId,
            'vendor_order_id' => $response['order_id'],
            'simulated' => $response['simulated'],
        ]);

        return ['ok' => true, 'errors' => [], 'dropship_id' => $dropshipId];
    }

    // ── Creazione automatica alla richiesta d'ordine (docs/06 e 09) ──

    /**
     * Crea l'ordine GoldenSneakers direttamente alla richiesta del cliente,
     * con il SUO indirizzo di spedizione, per bloccare lo stock del fornitore
     * prima che arrivi il bonifico (AUTO_DROPSHIP_ON_REQUEST).
     *
     * ⚠ Percorso innescato dal cliente (anche con la password ospite
     * condivisa), senza passaggio admin: per questo resta dietro flag .env
     * (kill-switch), eredita rate limit e ordine minimo della richiesta e in
     * live invia SOLO con l'ulteriore flag AUTO_DROPSHIP_ALLOW_LIVE=1.
     * Le righe di prodotti propri restano fuori: se la richiesta contiene
     * SOLO prodotti propri non c'è nulla da ordinare (`skipped`).
     * L'esito è riportato nell'email admin.
     *
     * @param array<string, mixed> $order richiesta appena salvata (con cart_snapshot e indirizzo)
     * @return array{ok: bool, skipped: bool, dropship_id: int|null, vendor_order_id: int|null,
     *   total_amount: float|null, shipping_cost: float|null, message: string|null, simulated: bool|null}
     */
    public function autoCreateFromRequest(array $order): array
    {
        $result = static fn (bool $ok, ?string $message, bool $skipped = false): array => [
            'ok' => $ok, 'skipped' => $skipped, 'dropship_id' => null, 'vendor_order_id' => null,
            'total_amount' => null, 'shipping_cost' => null, 'message' => $message, 'simulated' => null,
        ];

        if (!$this->isEnabled()) {
            return $result(false, $this->lang->t('dropship.disabled'));
        }

        // righe ordinabili, clampate allo stock corrente (appena rivalidato dal carrello)
        $lines = $this->linesFromSnapshot($order);
        $included = [];
        foreach ($lines as $line) {
            if ($line['orderable'] && (int) $line['qty'] >= 1) {
                $included[] = $line;
            }
        }
        if ($included === []) {
            $onlyCustom = $lines !== [] && array_filter($lines, static fn (array $l): bool => !$l['custom']) === [];

            return $onlyCustom
                ? $result(false, $this->lang->t('dropship.auto_only_custom'), skipped: true)
                : $result(false, $this->lang->t('dropship.error_no_items'));
        }

        if (!$this->isSimulation() && !$this->config->bool('AUTO_DROPSHIP_ALLOW_LIVE', false)) {
            // in live l'invio automatico (innescato dal cliente) richiede un
            // opt-in esplicito: di default resta solo il flusso admin manuale
            return $result(false, $this->lang->t('dropship.auto_live_disabled'));
        }

        $errors = [];
        // con ship_to=customer l'ordine parte con l'indirizzo del cliente
        // finale del rivenditore (dropshipping, docs/09)
        $address = $this->validateAddress($this->shippingAddressFor($order), $errors);
        if ($errors !== []) {
            return $result(false, implode(' ', $errors));
        }

        $wholesaleTotal = $this->wholesaleTotal($included);
        $capError = $this->capError($wholesaleTotal);
        if ($capError !== null) {
            return $result(false, $capError);
        }

        $orderRequestId = (int) ($order['id'] ?? 0);
        $payload = $this->buildPayload($address, $included);
        try {
            $response = $this->ordersClient->createOrder($payload);
        } catch (DropshipUncertainException $e) {
            // niente retry: si registra l'esito incerto e l'admin verifica
            // sul portale del fornitore (email admin + riga UNKNOWN)
            $unknownId = $this->recordUncertain($orderRequestId, $payload, $included, $wholesaleTotal, $e->getMessage());

            return $result(false, $this->lang->t('dropship.error_uncertain', [
                'id' => $unknownId, 'error' => $e->getMessage(),
            ]));
        } catch (DropshipException $e) {
            $this->logger->error('Ordine GoldenSneakers automatico rifiutato', ['error' => $e->getMessage()]);

            return $result(false, $e->getMessage());
        }

        $dropshipId = $this->recordCreated($orderRequestId, $payload, $included, $wholesaleTotal, $response);
        $this->logger->info('Ordine GoldenSneakers automatico registrato', [
            'dropship_id' => $dropshipId,
            'order_request_id' => $orderRequestId,
            'vendor_order_id' => $response['order_id'],
            'simulated' => $response['simulated'],
        ]);

        return [
            'ok' => true,
            'skipped' => false,
            'dropship_id' => $dropshipId,
            'vendor_order_id' => $response['order_id'],
            'total_amount' => $response['total_amount'],
            'shipping_cost' => $response['shipping_cost'],
            'message' => null,
            'simulated' => $response['simulated'],
        ];
    }

    // ── Lettura dal fornitore ────────────────────────────────────────

    /**
     * Rilegge dal fornitore lo stato di un ordine registrato e salva lo
     * snapshot in details_payload. Le GET sono idempotenti: un fallimento qui
     * non tocca mai nulla presso il fornitore.
     *  - API ordini: GET /api/orders/{id}/ (stato, pagamento, pro-forma,
     *    fattura). Gli ordini simulati hanno ID fittizi: nessuna chiamata.
     *  - API orders-dropship (storici): order-details + package-details.
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

        return ($dropshipOrder['api'] ?? DropshipOrderRepository::API_DROPSHIP) === DropshipOrderRepository::API_ORDERS
            ? $this->refreshFromOrdersApi($dropshipOrder, $vendorOrderId)
            : $this->refreshFromLegacyApi($dropshipOrder, $vendorOrderId);
    }

    /**
     * @param array<string, mixed> $dropshipOrder
     * @return array{ok: bool, message: string}
     */
    private function refreshFromOrdersApi(array $dropshipOrder, int $vendorOrderId): array
    {
        if (($dropshipOrder['mode'] ?? '') !== GoldenSneakersApiClient::MODE_LIVE) {
            // ordine simulato: l'ID è fittizio, non esiste presso il fornitore
            return ['ok' => true, 'message' => $this->lang->t('dropship.refresh_simulated', [
                'status' => (string) ($dropshipOrder['status'] ?? ''),
            ])];
        }
        try {
            $detail = $this->ordersClient->orderDetail($vendorOrderId);
        } catch (DropshipException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        // stati fuori dalla lista nota non sovrascrivono quello salvato (lo
        // snapshot li riporta comunque, tali e quali)
        $status = $detail['status'] !== null && in_array($detail['status'], GoldenSneakersOrdersClient::STATUSES, true)
            ? $detail['status']
            : (string) $dropshipOrder['status'];
        $parsed = $detail;
        unset($parsed['raw']);
        $this->dropshipOrders->updateFromOrdersDetail(
            (int) $dropshipOrder['id'],
            $status,
            $detail['payment']['status'],
            $detail['payment']['is_paid'],
            $detail['total_amount'] !== null ? number_format($detail['total_amount'], 2, '.', '') : null,
            $detail['tracking_numbers'],
            (string) json_encode([
                'order' => $detail['raw'],
                'parsed' => $parsed,
                'fetched_at' => date('Y-m-d H:i:s'),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        );

        return ['ok' => true, 'message' => $this->lang->t('dropship.refresh_done', ['status' => $status])];
    }

    /**
     * Ordini storici dell'API orders-dropship (docs/09 § Ordini storici).
     *
     * @param array<string, mixed> $dropshipOrder
     * @return array{ok: bool, message: string}
     */
    private function refreshFromLegacyApi(array $dropshipOrder, int $vendorOrderId): array
    {
        try {
            $details = $this->legacyClient->orderDetails($vendorOrderId);
        } catch (DropshipException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        // il pacchetto è informativo: un suo errore non blocca l'aggiornamento
        $package = null;
        $packageId = $details['dropship_package_id'] ?? ((int) ($dropshipOrder['dropship_package_id'] ?? 0) ?: null);
        if ($packageId !== null && !$details['simulated']) {
            try {
                $package = $this->legacyClient->packageDetails($packageId);
            } catch (DropshipException $e) {
                $this->logger->warning('Lettura pacchetto dropship fallita', [
                    'package_id' => $packageId, 'error' => $e->getMessage(),
                ]);
            }
        }

        // stati fuori dalla lista documentata non sovrascrivono quello salvato
        $status = in_array($details['status'], GoldenSneakersDropshipClient::STATUSES, true)
            ? $details['status']
            : (string) $dropshipOrder['status'];
        $this->dropshipOrders->updateFromDetails(
            (int) $dropshipOrder['id'],
            $status,
            $details['tracking_numbers'],
            $details['total_amount'] !== null ? number_format($details['total_amount'], 2, '.', '') : null,
            $packageId,
            $details['simulated'] ? null : (string) json_encode([
                'order' => $details['raw'],
                'package' => $package['raw'] ?? null,
                'fetched_at' => date('Y-m-d H:i:s'),
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        );

        return [
            'ok' => true,
            'message' => $this->lang->t(
                $details['simulated'] ? 'dropship.refresh_simulated' : 'dropship.refresh_done',
                ['status' => $status]
            ),
        ];
    }

    /**
     * Elenco degli ordini sull'account GoldenSneakers (GET /api/orders/),
     * collegati alle righe locali e quindi alle richieste d'ordine. Sola
     * lettura: funziona anche in simulazione, purché ci sia il token.
     *
     * @return array{ok: bool, error: string|null, orders: list<array<string, mixed>>}
     */
    public function vendorOrders(): array
    {
        if (!$this->ordersClient->hasToken()) {
            return ['ok' => false, 'error' => $this->lang->t('supplier.error_no_token'), 'orders' => []];
        }
        try {
            $orders = $this->ordersClient->listOrders();
        } catch (DropshipException $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'orders' => []];
        }
        $local = $this->dropshipOrders->liveOrdersByVendorIds(array_column($orders, 'order_id'));
        $out = [];
        foreach ($orders as $order) {
            $out[] = $order + ['local' => $local[$order['order_id']] ?? null];
        }

        return ['ok' => true, 'error' => null, 'orders' => $out];
    }

    /**
     * Dettaglio in tempo reale di un ordine GoldenSneakers (GET
     * /api/orders/{id}/), con l'eventuale riga locale collegata.
     *
     * @return array{ok: bool, error: string|null, order: array<string, mixed>|null,
     *   local: array{id: int, order_request_id: int|null}|null}
     */
    public function vendorOrder(int $vendorOrderId): array
    {
        if (!$this->ordersClient->hasToken()) {
            return ['ok' => false, 'error' => $this->lang->t('supplier.error_no_token'), 'order' => null, 'local' => null];
        }
        try {
            $detail = $this->ordersClient->orderDetail($vendorOrderId);
        } catch (DropshipException $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'order' => null, 'local' => null];
        }
        unset($detail['raw']);

        return [
            'ok' => true,
            'error' => null,
            'order' => $detail,
            'local' => $this->dropshipOrders->liveOrdersByVendorIds([$vendorOrderId])[$vendorOrderId] ?? null,
        ];
    }

    // ── Upload etichetta di spedizione (solo ordini storici, docs/09) ─

    /** Estensioni/MIME ammessi dall'API upload-shipping-label. */
    private const LABEL_TYPES = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
    ];
    private const LABEL_MAX_BYTES = 10 * 1024 * 1024;
    private const LABEL_MAX_TRACKING = 20;

    /**
     * L'ordine aspetta un'etichetta nostra e non ne ha ancora una caricata.
     * Esiste solo per gli ordini storici dell'API orders-dropship: l'API
     * ordini non prevede client_provides_shipping_label.
     *
     * @param array<string, mixed> $dropshipOrder
     */
    public function labelPending(array $dropshipOrder): bool
    {
        if (($dropshipOrder['api'] ?? DropshipOrderRepository::API_DROPSHIP) !== DropshipOrderRepository::API_DROPSHIP) {
            return false;
        }
        $payload = json_decode(is_string($dropshipOrder['request_payload'] ?? null) ? $dropshipOrder['request_payload'] : '{}', true);

        return is_array($payload)
            && ($payload['client_provides_shipping_label'] ?? false) === true
            && ($dropshipOrder['label_uploaded_at'] ?? null) === null
            && (int) ($dropshipOrder['vendor_order_id'] ?? 0) > 0
            && $dropshipOrder['status'] !== 'UNKNOWN';
    }

    /**
     * Carica l'etichetta presso il fornitore. L'upload è MONOUSO lato API
     * (i duplicati vengono rifiutati), quindi un retry dopo un errore di
     * rete è sicuro.
     *
     * @param array<string, mixed> $dropshipOrder riga di dropship_orders
     * @param array{tmp_path: string, name: string, size: int} $file file ricevuto dal form
     * @param string $trackingInput tracking separati da virgola/riga
     * @return array{ok: bool, message: string}
     */
    public function uploadLabel(array $dropshipOrder, array $file, string $trackingInput): array
    {
        $fail = fn (string $key, array $params = []): array => ['ok' => false, 'message' => $this->lang->t($key, $params)];

        if (!$this->labelPending($dropshipOrder)) {
            return $fail('dropship.label_not_pending');
        }

        // file: estensione, MIME reale e dimensione
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $mime = self::LABEL_TYPES[$ext] ?? null;
        if ($mime === null) {
            return $fail('dropship.label_bad_type');
        }
        if (!is_file($file['tmp_path']) || $file['size'] <= 0 || $file['size'] > self::LABEL_MAX_BYTES) {
            return $fail('dropship.label_bad_size');
        }
        $detected = mime_content_type($file['tmp_path']);
        if ($detected !== $mime) {
            return $fail('dropship.label_bad_type');
        }

        // tracking: uno per riga o separati da virgola, formato corriere
        $tracking = [];
        foreach (preg_split('/[\s,;]+/', $trackingInput) ?: [] as $number) {
            $number = trim($number);
            if ($number === '') {
                continue;
            }
            if (preg_match('/^[A-Za-z0-9\-]{4,64}$/', $number) !== 1) {
                return $fail('dropship.label_bad_tracking', ['number' => $number]);
            }
            $tracking[] = $number;
        }
        $tracking = array_values(array_unique($tracking));
        if ($tracking === [] || count($tracking) > self::LABEL_MAX_TRACKING) {
            return $fail('dropship.label_tracking_required');
        }

        $safeName = mb_substr(basename($file['name']), 0, 255);
        try {
            $response = $this->legacyClient->uploadShippingLabel(
                (int) $dropshipOrder['vendor_order_id'],
                $file['tmp_path'],
                $safeName,
                $mime,
                $tracking,
            );
        } catch (DropshipException $e) {
            $this->logger->error('Upload etichetta dropship fallito', [
                'dropship_id' => $dropshipOrder['id'] ?? null, 'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'message' => $e->getMessage()];
        }

        $this->dropshipOrders->markLabelUploaded((int) $dropshipOrder['id'], $safeName, $response['tracking_numbers']);
        $this->logger->info('Etichetta dropship caricata', [
            'dropship_id' => $dropshipOrder['id'] ?? null,
            'simulated' => $response['simulated'],
        ]);

        return [
            'ok' => true,
            'message' => $this->lang->t($response['simulated'] ? 'dropship.label_uploaded_simulated' : 'dropship.label_uploaded'),
        ];
    }

    // ── Interni ──────────────────────────────────────────────────────

    /**
     * Payload esatto di POST /api/orders/create/: size_id (id riga del feed)
     * è la chiave preferita, in mancanza l'API accetta sku + size_us. Nessun
     * prezzo nel payload: il totale lo calcola il fornitore.
     *
     * @param array<string, string> $address shipping_address già validato
     * @param list<array<string, mixed>> $lines righe incluse (con qty)
     * @return array{currency: string, shipping_address: array<string, string>,
     *   items: list<array<string, int|string>>}
     */
    private function buildPayload(array $address, array $lines): array
    {
        $items = [];
        foreach ($lines as $line) {
            $qty = (int) $line['qty'];
            $items[] = is_int($line['supplier_size_id'])
                ? ['size_id' => $line['supplier_size_id'], 'quantity' => $qty]
                : ['sku' => (string) $line['sku'], 'size_us' => (string) $line['size_us'], 'quantity' => $qty];
        }

        return [
            'currency' => self::CURRENCY,
            'shipping_address' => $address,
            'items' => $items,
        ];
    }

    /** @param list<array<string, mixed>> $lines righe incluse (offer_price × qty) */
    private function wholesaleTotal(array $lines): string
    {
        $cents = 0;
        foreach ($lines as $line) {
            $cents += CartService::cents((string) $line['offer_price']) * (int) $line['qty'];
        }

        return CartService::money($cents);
    }

    /**
     * Registra l'ordine creato (o simulato) con i dati della risposta.
     *
     * @param array<string, mixed> $payload
     * @param list<array<string, mixed>> $lines
     * @param array{order_id: int, status: string, currency: string, total_amount: float|null,
     *   created_at: string|null, shipping_cost: float|null, free_shipping: bool|null,
     *   payment_status: string|null, simulated: bool} $response
     */
    private function recordCreated(int $orderRequestId, array $payload, array $lines, string $wholesaleTotal, array $response): int
    {
        return $this->dropshipOrders->insert([
            'order_request_id' => $orderRequestId > 0 ? $orderRequestId : null,
            'mode' => $response['simulated'] ? GoldenSneakersApiClient::MODE_SIMULATION : GoldenSneakersApiClient::MODE_LIVE,
            'api' => DropshipOrderRepository::API_ORDERS,
            'status' => $response['status'],
            'vendor_order_id' => $response['order_id'],
            'dropship_package_id' => null,
            // in live vale il totale calcolato dall'API; altrimenti la stima a costo fornitore
            'total_price' => $response['total_amount'] !== null
                ? number_format($response['total_amount'], 2, '.', '')
                : $wholesaleTotal,
            'shipping_cost' => $response['shipping_cost'] !== null
                ? number_format($response['shipping_cost'], 2, '.', '')
                : null,
            'currency' => $response['currency'],
            'payment_status' => $response['payment_status'],
            'request_payload' => (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'lines_snapshot' => (string) json_encode($lines, JSON_UNESCAPED_UNICODE),
            'response_payload' => (string) json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        ]);
    }

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
     * @param list<array<string, mixed>> $lines
     */
    private function recordUncertain(int $orderRequestId, array $payload, array $lines, string $wholesaleTotal, string $error): int
    {
        $unknownId = $this->dropshipOrders->insert([
            'order_request_id' => $orderRequestId > 0 ? $orderRequestId : null,
            'mode' => $this->mode(),
            'api' => DropshipOrderRepository::API_ORDERS,
            'status' => 'UNKNOWN',
            'vendor_order_id' => null,
            'dropship_package_id' => null,
            'total_price' => $wholesaleTotal,
            'currency' => self::CURRENCY,
            'request_payload' => (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'lines_snapshot' => (string) json_encode($lines, JSON_UNESCAPED_UNICODE),
            'response_payload' => (string) json_encode(['error' => $error, 'uncertain' => true], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        ]);
        $this->logger->error('Ordine GoldenSneakers con esito INCERTO registrato: verificare presso il fornitore prima di ritentare', [
            'dropship_id' => $unknownId,
            'order_request_id' => $orderRequestId,
            'error' => $error,
        ]);

        return $unknownId;
    }

    /**
     * Righe del cart_snapshot confrontate con stock, size_id e origine
     * correnti. I prodotti propri non sono ordinabili al fornitore: la
     * verifica sull'origine si fa sia sullo snapshot sia sul catalogo, così
     * vale anche per le richieste salvate prima dell'import.
     *
     * @param array<string, mixed> $orderRequest
     * @return list<array{sku: string, name: string, size_eu: string, size_us: string, requested: int,
     *   qty: int, stock: int, supplier_size_id: int|null, offer_price: string, custom: bool,
     *   orderable: bool, issue: string|null}>
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
        $current = $this->products->dropshipDataForSkuSizes(array_map(strval(...), array_keys($skus)));

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
            $custom = ($line['source'] ?? null) === ProductRepository::SOURCE_CUSTOM
                || ($size['source'] ?? null) === ProductRepository::SOURCE_CUSTOM;

            $issue = null;
            if ($custom) {
                $issue = $this->lang->t('dropship.issue_custom_product');
            } elseif ($size === null) {
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
                'custom' => $custom,
                'orderable' => !$custom && $size !== null && ($supplierSizeId !== null || $sizeUs !== ''),
                'issue' => $issue,
            ];
        }

        return $lines;
    }

    /**
     * shipping_address dell'API ordini, validato. Obbligatori: destinatario,
     * indirizzo, città, CAP, telefono, paese ISO a 2 lettere, email.
     *
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
            'recipient_name' => $field('recipient_name', 128),
            'address_l1' => $field('address_l1', 255),
            'address_l2' => $field('address_l2', 255),
            'city' => $field('city', 128),
            'zip_code' => $field('zip_code', 16),
            // niente truncation: "ITA" deve fallire la validazione, non diventare "IT"
            'country' => strtoupper($field('country', 8)),
            'phone' => $field('phone', 32),
            'email' => $field('email', 255),
        ];

        foreach (['recipient_name', 'address_l1', 'city', 'zip_code', 'phone'] as $required) {
            if ($address[$required] === '') {
                $errors[] = $this->lang->t('dropship.error_address_' . $required);
            }
        }
        if (preg_match('/^[A-Z]{2}$/', $address['country']) !== 1) {
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
