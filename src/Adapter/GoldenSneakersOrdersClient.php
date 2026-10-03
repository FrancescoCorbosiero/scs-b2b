<?php

declare(strict_types=1);

namespace App\Adapter;

/**
 * Client per gli ordini dell'API GoldenSneakers (/api/orders/, tag
 * "wholesale-orders" della documentazione): è l'API con cui la piattaforma
 * crea l'ordine presso il fornitore quando un cliente invia una richiesta
 * (decisione del titolare del 02/10/2026, docs/09).
 *
 *  - GET  /api/orders/          elenco ordini dell'account
 *  - GET  /api/orders/{id}/     dettaglio: righe, indirizzi, pro-forma,
 *                               fattura e stato del pagamento
 *  - POST /api/orders/create/   creazione ordine
 *
 * Modalità (DROPSHIP_MODE, regole in GoldenSneakersApiClient):
 *  - simulation (default): la POST di creazione NON parte mai, la risposta è
 *    fittizia e marcata `simulated: true`;
 *  - live: la POST parte davvero. Creare un ordine è IRREVERSIBILE: niente
 *    retry, esito incerto ⇒ DropshipUncertainException.
 * Le letture (elenco e dettaglio) sono GET idempotenti, senza effetti presso
 * il fornitore: partono in entrambe le modalità purché FEED_BEARER_TOKEN sia
 * configurato, così l'admin vede gli ordini reali dell'account anche mentre
 * la piattaforma è in simulazione. Il chiamante non le usa per gli ordini
 * simulati, che hanno ID fittizi.
 *
 * Le risposte contengono COSTI del fornitore (prezzi riga, totali, pro-forma):
 * SOLO area admin, mai verso il cliente (Regola d'oro n.1).
 */
final class GoldenSneakersOrdersClient extends GoldenSneakersApiClient
{
    /** Path API (base FEED_BASE_URL): l'ID del dettaglio si accoda con lo slash finale. */
    public const LIST_PATH = '/api/orders/';
    public const DETAIL_PATH = '/api/orders/';
    public const CREATE_PATH = '/api/orders/create/';

    /** Stati noti (stesso ciclo di vita degli ordini dropship). */
    public const STATUSES = ['UNCONFIRMED', 'TO_SHIP', 'ENDED', 'CANCELED', 'WAITING_FOR_INVOICE'];

    /** Campi di billing e shipping_address del dettaglio, nell'ordine della doc. */
    public const BILLING_FIELDS = ['name', 'vat_id', 'full_vat_id', 'address_l1', 'address_l2', 'city', 'zip_code', 'country', 'email', 'phone'];
    public const SHIPPING_FIELDS = ['recipient_name', 'address_l1', 'address_l2', 'city', 'zip_code', 'country', 'email', 'phone'];

    /** Tetto difensivo alla paginazione dell'elenco (stile DRF {results, next}). */
    private const MAX_LIST_PAGES = 50;

    /** Dominio dei documenti (pro-forma, fattura): mai link verso domini terzi. */
    private const DOCUMENT_DOMAIN = 'goldensneakers.net';

    /**
     * POST /api/orders/create/ — crea l'ordine presso il fornitore.
     *
     * @param array{currency: string, shipping_address: array<string, string>,
     *   items: list<array<string, int|string>>} $payload payload esatto dell'API
     * @return array{order_id: int, status: string, currency: string, total_amount: float|null,
     *   created_at: string|null, shipping_cost: float|null, free_shipping: bool|null,
     *   payment_status: string|null, simulated: bool}
     * @throws DropshipException fallimento certo: nessun ordine creato
     * @throws DropshipUncertainException esito ambiguo: l'ordine POTREBBE esistere
     */
    public function createOrder(array $payload): array
    {
        if ($this->isSimulation()) {
            $this->logger->info('SIMULAZIONE creazione ordine GoldenSneakers: nessuna chiamata HTTP effettuata', [
                'items' => count($payload['items']),
                'country' => $payload['shipping_address']['country'] ?? '',
            ]);

            // stessa forma della risposta reale, marcata come simulata: totali
            // e spedizione li calcola il fornitore, qui restano null
            return [
                'order_id' => random_int(900000, 999999),
                'status' => 'UNCONFIRMED',
                'currency' => $payload['currency'],
                'total_amount' => null,
                'created_at' => null,
                'shipping_cost' => null,
                'free_shipping' => null,
                'payment_status' => null,
                'simulated' => true,
            ];
        }

        // NESSUN retry: postCreate invia una volta sola e classifica l'esito
        $decoded = $this->postCreate(self::CREATE_PATH, $payload, 'ordine GoldenSneakers');

        return [
            'order_id' => (int) $decoded['order_id'],
            'status' => $this->statusToken($decoded['status'] ?? null) ?? 'UNCONFIRMED',
            'currency' => $this->currency($decoded['currency'] ?? null) ?? $payload['currency'],
            'total_amount' => $this->optionalFloat($decoded['total_amount'] ?? null),
            'created_at' => $this->optionalString($decoded['created_at'] ?? null),
            'shipping_cost' => $this->optionalFloat($decoded['shipping_cost'] ?? null),
            'free_shipping' => $this->optionalBool($decoded['free_shipping'] ?? null),
            'payment_status' => $this->paymentToken($decoded['payment_status'] ?? null),
            'simulated' => false,
        ];
    }

    /**
     * GET /api/orders/ — elenco ordini dell'account, dal più recente come lo
     * restituisce il fornitore. Accetta sia la lista piatta (formato della
     * doc) sia la paginazione {results, next}; le righe senza order_id
     * leggibile vengono scartate.
     *
     * @return list<array{order_id: int, status: string|null, currency: string|null,
     *   total_amount: float|null, created_at: string|null, payment_status: string|null,
     *   is_paid: bool|null, has_proforma: bool, has_invoice: bool}>
     * @throws DropshipException lettura fallita (nessun effetto presso il fornitore)
     */
    public function listOrders(): array
    {
        $url = $this->liveUrl(self::LIST_PATH, forRead: true);
        $orders = [];
        $pages = 0;
        while ($url !== null) {
            if (++$pages > self::MAX_LIST_PAGES) {
                throw new DropshipException('Elenco ordini: troppe pagine (possibile loop di paginazione).');
            }
            $decoded = $this->getJson($url, 'elenco ordini');
            if (array_is_list($decoded)) {
                $rows = $decoded;
                $url = null;
            } elseif (is_array($decoded['results'] ?? null)) {
                $rows = $decoded['results'];
                $url = $this->nextPageUrl($decoded['next'] ?? null);
            } else {
                throw new DropshipException('Risposta elenco ordini illeggibile (né lista né paginata).');
            }

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $orderId = $this->positiveInt($row['order_id'] ?? null);
                if ($orderId === null) {
                    continue;
                }
                $orders[] = [
                    'order_id' => $orderId,
                    'status' => $this->statusToken($row['status'] ?? null),
                    'currency' => $this->currency($row['currency'] ?? null),
                    'total_amount' => $this->optionalFloat($row['total_amount'] ?? null),
                    'created_at' => $this->optionalString($row['created_at'] ?? null),
                    'payment_status' => $this->paymentToken($row['payment_status'] ?? null),
                    'is_paid' => $this->optionalBool($row['is_paid'] ?? null),
                    'has_proforma' => $this->optionalBool($row['has_proforma'] ?? null) === true,
                    'has_invoice' => $this->optionalBool($row['has_invoice'] ?? null) === true,
                ];
            }
        }

        return $orders;
    }

    /**
     * GET /api/orders/{id}/ — dettaglio completo dell'ordine. `raw` è la
     * risposta integrale (snapshot a DB); il resto è validato campo per campo.
     * Gli URL di pro-forma e fattura passano solo se https sul dominio del
     * fornitore (relativi risolti contro FEED_BASE_URL).
     *
     * @return array{order_id: int, status: string|null, currency: string|null,
     *   total_amount: float|null, created_at: string|null,
     *   billing: array<string, string|null>, shipping_address: array<string, string|null>,
     *   items: list<array{size_id: int|null, sku: string, product_name: string, size_us: string,
     *     quantity: int, unit_price: float|null, total_price: float|null}>,
     *   proforma: array{url: string|null, symbol: string, uploaded_at: string|null}|null,
     *   invoice: array{url: string|null, symbol: string, uploaded_at: string|null}|null,
     *   payment: array{status: string|null, is_paid: bool|null, paid_amount: float|null,
     *     total_amount: float|null, currency: string|null, due_date: string|null},
     *   tracking_numbers: list<string>, raw: array<string, mixed>}
     * @throws DropshipException lettura fallita (nessun effetto presso il fornitore)
     */
    public function orderDetail(int $orderId): array
    {
        $decoded = $this->getJson($this->liveUrl(self::DETAIL_PATH, forRead: true) . $orderId . '/', 'dettaglio ordine');
        if (array_is_list($decoded)) {
            throw new DropshipException('Risposta dettaglio ordine illeggibile (attesa un oggetto).');
        }
        /** @var array<string, mixed> $decoded */
        $readOrderId = $this->positiveInt($decoded['order_id'] ?? null);
        if ($readOrderId === null) {
            throw new DropshipException('Risposta dettaglio ordine illeggibile (manca order_id).');
        }

        $items = [];
        foreach (is_array($decoded['items'] ?? null) ? $decoded['items'] : [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $items[] = [
                'size_id' => $this->positiveInt($item['size_id'] ?? null),
                'sku' => $this->optionalString($item['sku'] ?? null) ?? '',
                'product_name' => $this->optionalString($item['product_name'] ?? null) ?? '',
                'size_us' => $this->optionalString($item['size_us'] ?? null) ?? '',
                'quantity' => max(0, (int) ($this->optionalFloat($item['quantity'] ?? null) ?? 0)),
                'unit_price' => $this->optionalFloat($item['unit_price'] ?? null),
                'total_price' => $this->optionalFloat($item['total_price'] ?? null),
            ];
        }

        $payment = is_array($decoded['payment'] ?? null) ? $decoded['payment'] : [];

        return [
            'order_id' => $readOrderId,
            'status' => $this->statusToken($decoded['status'] ?? null),
            'currency' => $this->currency($decoded['currency'] ?? null),
            'total_amount' => $this->optionalFloat($decoded['total_amount'] ?? null),
            'created_at' => $this->optionalString($decoded['created_at'] ?? null),
            'billing' => $this->fields($decoded['billing'] ?? null, self::BILLING_FIELDS),
            'shipping_address' => $this->fields($decoded['shipping_address'] ?? null, self::SHIPPING_FIELDS),
            'items' => $items,
            'proforma' => $this->document($decoded['proforma'] ?? null),
            'invoice' => $this->document($decoded['invoice'] ?? null),
            'payment' => [
                // l'elenco porta payment_status/is_paid in radice: stessi
                // valori, usati come ripiego se il blocco payment manca
                'status' => $this->paymentToken($payment['status'] ?? $decoded['payment_status'] ?? null),
                'is_paid' => $this->optionalBool($payment['is_paid'] ?? $decoded['is_paid'] ?? null),
                'paid_amount' => $this->optionalFloat($payment['paid_amount'] ?? null),
                'total_amount' => $this->optionalFloat($payment['total_amount'] ?? null),
                'currency' => $this->currency($payment['currency'] ?? null),
                'due_date' => $this->optionalString($payment['due_date'] ?? null),
            ],
            // la doc non li elenca: se il fornitore li aggiunge, la
            // colonna tracking dell'area cliente li mostra già
            'tracking_numbers' => $this->stringList($decoded['tracking_numbers'] ?? null),
            'raw' => $decoded,
        ];
    }

    // ── Validazione campi ────────────────────────────────────────────

    /** Stato ordine: token MAIUSCOLO (es. TO_SHIP), altrimenti null. */
    private function statusToken(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = strtoupper(trim($value));

        return preg_match('/^[A-Z][A-Z_]{1,31}$/', $value) === 1 ? $value : null;
    }

    /** Stato pagamento: token minuscolo (es. unpaid), altrimenti null. */
    private function paymentToken(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = strtolower(trim($value));

        return preg_match('/^[a-z][a-z_]{1,31}$/', $value) === 1 ? $value : null;
    }

    private function currency(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[A-Z]{3}$/', $value) === 1 ? $value : null;
    }

    private function optionalBool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 0 || $value === 1) {
            return $value === 1;
        }
        if (is_string($value) && in_array(strtolower($value), ['true', 'false', '0', '1'], true)) {
            return in_array(strtolower($value), ['true', '1'], true);
        }

        return null;
    }

    /**
     * @param list<string> $keys
     * @return array<string, string|null>
     */
    private function fields(mixed $value, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = is_array($value) ? $this->optionalString($value[$key] ?? null) : null;
        }

        return $out;
    }

    /** @return array{url: string|null, symbol: string, uploaded_at: string|null}|null */
    private function document(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        $url = $this->documentUrl($value['url'] ?? null);
        $symbol = mb_substr($this->optionalString($value['symbol'] ?? null) ?? '', 0, 64);
        if ($url === null && $symbol === '') {
            return null;
        }

        return [
            'url' => $url,
            'symbol' => $symbol,
            'uploaded_at' => $this->optionalString($value['uploaded_at'] ?? null),
        ];
    }

    private function documentUrl(mixed $value): ?string
    {
        $url = $this->optionalString($value);
        if ($url === null) {
            return null;
        }
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            $url = $this->baseUrl() . $url;
        }
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);
        if ($scheme !== 'https' || !is_string($host)) {
            return null;
        }
        $host = strtolower($host);
        if ($host !== self::DOCUMENT_DOMAIN && !str_ends_with($host, '.' . self::DOCUMENT_DOMAIN)) {
            return null;
        }

        return strlen($url) <= 512 ? $url : null;
    }

    /**
     * URL della pagina successiva dell'elenco. Il token viaggia con ogni
     * richiesta: si segue `next` SOLO sullo stesso schema e host di
     * FEED_BASE_URL (relativo → risolto contro la base).
     */
    private function nextPageUrl(mixed $next): ?string
    {
        if (!is_string($next) || trim($next) === '') {
            return null;
        }
        $next = trim($next);
        if (str_starts_with($next, '/') && !str_starts_with($next, '//')) {
            return $this->baseUrl() . $next;
        }
        $base = $this->baseUrl();
        $sameHost = is_string(parse_url($next, PHP_URL_HOST))
            && strcasecmp((string) parse_url($next, PHP_URL_HOST), (string) parse_url($base, PHP_URL_HOST)) === 0
            && parse_url($next, PHP_URL_SCHEME) === parse_url($base, PHP_URL_SCHEME);
        if (!$sameHost) {
            throw new DropshipException('Elenco ordini: la paginazione punta a un host diverso da FEED_BASE_URL, interrotta per non esporre il token.');
        }

        return $next;
    }
}
