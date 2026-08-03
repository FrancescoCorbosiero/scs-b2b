<?php

declare(strict_types=1);

namespace App\Adapter;

use App\Support\Config;
use Psr\Log\LoggerInterface;

/**
 * Client per il dominio "orders-dropship" dell'API GoldenSneakers
 * (POST create-order/, GET order-details/{order_id}/,
 * GET package-details/{package_id}/ — path confermati su Swagger).
 * L'endpoint upload-shipping-label/{order_id}/ (multipart, solo ordini con
 * client_provides_shipping_label=True) NON è implementato.
 *
 * Due modalità (DROPSHIP_MODE):
 *  - simulation (default): NESSUNA chiamata HTTP, risposte fittizie marcate
 *    `simulated: true`. Qualsiasi valore diverso da "live" degrada qui.
 *  - live: chiamate reali con il bearer token del feed (FEED_BEARER_TOKEN)
 *    sugli endpoint DROPSHIP_*_ENDPOINT. Creare un ordine è IRREVERSIBILE
 *    (il fornitore lo conferma e scala il suo stock reale), quindi:
 *      • la POST di creazione NON viene MAI ritentata automaticamente:
 *        un retry dopo un timeout può produrre un ordine doppio;
 *      • gli esiti ambigui (timeout dopo l'invio, HTTP 5xx, risposta 2xx
 *        illeggibile) sollevano DropshipUncertainException: il chiamante
 *        registra l'accaduto e l'admin verifica sul portale del fornitore
 *        PRIMA di ritentare;
 *      • i fallimenti certi (endpoint non raggiungibile, HTTP 4xx) sollevano
 *        DropshipException: nessun ordine è partito, si può correggere e
 *        ripetere.
 *    La lettura dello stato (GET) è idempotente: un retry con backoff.
 *
 * ⚠ Gli endpoint di default vanno verificati sullo Swagger del fornitore
 * (docs/09) prima di attivare il live: un path sbagliato in produzione
 * significa ordini che falliscono o, peggio, comportamenti inattesi.
 *
 * Stati ordine documentati: UNCONFIRMED, TO_SHIP, ENDED, CANCELED,
 * WAITING_FOR_INVOICE.
 */
final class GoldenSneakersDropshipClient
{
    public const MODE_SIMULATION = 'simulation';
    public const MODE_LIVE = 'live';

    public const STATUSES = ['UNCONFIRMED', 'TO_SHIP', 'ENDED', 'CANCELED', 'WAITING_FOR_INVOICE'];

    /**
     * Errori cURL che avvengono PRIMA che la richiesta parta (DNS, connect,
     * handshake TLS, proxy): il fornitore non ha ricevuto nulla, fallire è
     * sicuro. Tutto il resto (timeout, errori di invio/ricezione) è ambiguo.
     */
    private const PRE_SEND_ERRNOS = [
        CURLE_UNSUPPORTED_PROTOCOL,   // 1
        CURLE_URL_MALFORMAT,          // 3
        CURLE_COULDNT_RESOLVE_PROXY,  // 5
        CURLE_COULDNT_RESOLVE_HOST,   // 6
        CURLE_COULDNT_CONNECT,        // 7
        CURLE_SSL_CONNECT_ERROR,      // 35
    ];

    /**
     * @param \Closure|null $transport SOLO per i test: sostituisce cURL.
     *   Firma: fn(string $method, string $url, list<string> $headers,
     *   ?string $body, int $timeout): array{status: int, body: string,
     *   errno: int, error: string}
     */
    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly ?\Closure $transport = null,
    ) {
    }

    public function mode(): string
    {
        // qualsiasi valore diverso da "live" degrada a simulazione: mai
        // inviare un ordine reale per un errore di battitura in .env
        return strtolower($this->config->str('DROPSHIP_MODE', self::MODE_SIMULATION)) === self::MODE_LIVE
            ? self::MODE_LIVE
            : self::MODE_SIMULATION;
    }

    public function isSimulation(): bool
    {
        return $this->mode() === self::MODE_SIMULATION;
    }

    /**
     * Crea l'ordine dropship presso il fornitore.
     *
     * @param array{delivery_address: array<string, string>, client_provides_shipping_label: bool,
     *   items: list<array<string, int|string>>} $payload payload esatto dell'API
     * @return array{message: string, order_id: int, total_price: float|null,
     *   dropship_package_id: int|null, simulated: bool}
     * @throws DropshipException fallimento certo: nessun ordine creato
     * @throws DropshipUncertainException esito ambiguo: l'ordine POTREBBE esistere
     */
    public function createOrder(array $payload): array
    {
        if ($this->isSimulation()) {
            $this->logger->info('SIMULAZIONE creazione ordine dropship: nessuna chiamata HTTP effettuata', [
                'items' => count($payload['items']),
                'country' => $payload['delivery_address']['country_code'] ?? '',
            ]);

            // risposta nella stessa forma del sample API, marcata come simulata;
            // total_price reale lo calcola il fornitore: qui resta null
            return [
                'message' => 'Dropship order created successfully (SIMULAZIONE — nessun ordine inviato)',
                'order_id' => random_int(900000, 999999),
                'total_price' => null,
                'dropship_package_id' => random_int(900000, 999999),
                'simulated' => true,
            ];
        }

        $url = $this->liveUrl('DROPSHIP_CREATE_ENDPOINT', '/api/orders-dropship/create-order/');
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            throw new DropshipException('Payload non serializzabile in JSON: nessun ordine inviato.');
        }

        // NESSUN retry: la creazione non è idempotente
        $res = $this->request('POST', $url, $body);

        if ($res['errno'] !== 0) {
            if (in_array($res['errno'], self::PRE_SEND_ERRNOS, true)) {
                $this->logger->error('Creazione ordine dropship: connessione fallita, nessun invio', [
                    'errno' => $res['errno'], 'error' => $res['error'],
                ]);
                throw new DropshipException(
                    "Fornitore non raggiungibile ({$res['error']}): nessun ordine è stato inviato. Riprova più tardi."
                );
            }
            $this->logger->error('Creazione ordine dropship: esito INCERTO (errore di rete dopo l\'invio)', [
                'errno' => $res['errno'], 'error' => $res['error'],
            ]);
            throw new DropshipUncertainException(
                "Errore di rete dopo l'invio ({$res['error']}): l'ordine potrebbe essere stato creato."
            );
        }

        $status = $res['status'];
        if ($status >= 200 && $status < 300) {
            $decoded = json_decode($res['body'], true);
            $orderId = is_array($decoded) ? $this->positiveInt($decoded['order_id'] ?? null) : null;
            if ($orderId === null) {
                $this->logger->error('Creazione ordine dropship: HTTP 2xx ma risposta illeggibile', [
                    'status' => $status, 'body' => mb_substr($res['body'], 0, 500),
                ]);
                throw new DropshipUncertainException(
                    "Il fornitore ha risposto HTTP {$status} ma senza un order_id leggibile: l'ordine potrebbe essere stato creato."
                );
            }
            /** @var array<string, mixed> $decoded */
            $totalPrice = $decoded['total_price'] ?? null;
            $this->logger->info('Ordine dropship creato presso il fornitore', [
                'order_id' => $orderId, 'status' => $status,
            ]);

            return [
                'message' => is_string($decoded['message'] ?? null) ? $decoded['message'] : '',
                'order_id' => $orderId,
                'total_price' => is_int($totalPrice) || is_float($totalPrice) || (is_string($totalPrice) && is_numeric($totalPrice))
                    ? (float) $totalPrice
                    : null,
                'dropship_package_id' => $this->positiveInt($decoded['dropship_package_id'] ?? null),
                'simulated' => false,
            ];
        }

        if ($status >= 400 && $status < 500) {
            // rifiuto esplicito del fornitore: nessun ordine creato
            $detail = $this->errorDetail($res['body']);
            $this->logger->error('Creazione ordine dropship rifiutata dal fornitore', [
                'status' => $status, 'detail' => $detail,
            ]);
            throw new DropshipException(
                "Il fornitore ha rifiutato l'ordine (HTTP {$status}" . ($detail !== '' ? ": {$detail}" : '') . '). Nessun ordine è stato creato.'
            );
        }

        if ($status >= 300 && $status < 400) {
            // redirect su una POST = endpoint configurato male: la richiesta
            // non viene processata dal fornitore
            throw new DropshipException(
                "Endpoint di creazione mal configurato (HTTP {$status}, redirect): verificare DROPSHIP_CREATE_ENDPOINT sullo Swagger. Nessun ordine creato."
            );
        }

        // 5xx o status anomalo: il fornitore potrebbe aver processato l'ordine
        $this->logger->error('Creazione ordine dropship: esito INCERTO', [
            'status' => $status, 'body' => mb_substr($res['body'], 0, 500),
        ]);
        throw new DropshipUncertainException(
            "Errore del fornitore (HTTP {$status}): l'ordine potrebbe essere stato creato."
        );
    }

    /**
     * GET order-details/{order_id}/ — dettagli/stato ordine. Idempotente:
     * un retry con backoff. Ogni fallimento è sicuro (nessuna scrittura).
     *
     * `raw` è la risposta completa del fornitore (per lo snapshot a DB);
     * `items` sono le righe validate: size_id, sku, size_us, product_name,
     * quantity, unit_price, total_price. I prezzi sono COSTI del fornitore:
     * solo area admin, mai verso il cliente (Regola d'oro n.1).
     *
     * @return array{order_id: int, status: string, tracking_numbers: list<string>,
     *   total_amount: float|null, currency: string|null, created_at: string|null,
     *   dropship_package_id: int|null,
     *   items: list<array{size_id: int|null, sku: string, size_us: string,
     *     product_name: string, quantity: int, unit_price: float|null, total_price: float|null}>,
     *   raw: array<string, mixed>, simulated: bool}
     */
    public function orderDetails(int $vendorOrderId): array
    {
        if ($this->isSimulation()) {
            $this->logger->info('SIMULAZIONE lettura dettagli ordine dropship: nessuna chiamata HTTP effettuata', [
                'vendor_order_id' => $vendorOrderId,
            ]);

            // in simulazione l'ordine resta nello stato iniziale, senza tracking
            return [
                'order_id' => $vendorOrderId,
                'status' => 'UNCONFIRMED',
                'tracking_numbers' => [],
                'total_amount' => null,
                'currency' => null,
                'created_at' => null,
                'dropship_package_id' => null,
                'items' => [],
                'raw' => ['simulated' => true, 'order_id' => $vendorOrderId, 'status' => 'UNCONFIRMED'],
                'simulated' => true,
            ];
        }

        $url = $this->liveUrl('DROPSHIP_DETAILS_ENDPOINT', '/api/orders-dropship/order-details/')
            . $vendorOrderId . '/';
        $decoded = $this->getJson($url, 'dettagli ordine');

        $status = is_string($decoded['status'] ?? null) ? $decoded['status'] : null;
        if ($status === null) {
            throw new DropshipException('Risposta dettagli ordine illeggibile (manca lo status).');
        }

        $items = [];
        foreach (is_array($decoded['items'] ?? null) ? $decoded['items'] : [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $items[] = [
                'size_id' => $this->positiveInt($item['size_id'] ?? null),
                'sku' => is_string($item['sku'] ?? null) ? $item['sku'] : '',
                'size_us' => is_string($item['size_us'] ?? null) ? $item['size_us'] : (string) ($item['size_us'] ?? ''),
                'product_name' => is_string($item['product_name'] ?? null) ? $item['product_name'] : '',
                'quantity' => max(0, (int) ($item['quantity'] ?? 0)),
                'unit_price' => $this->optionalFloat($item['unit_price'] ?? null),
                'total_price' => $this->optionalFloat($item['total_price'] ?? null),
            ];
        }

        return [
            'order_id' => $this->positiveInt($decoded['order_id'] ?? null) ?? $vendorOrderId,
            'status' => $status,
            'tracking_numbers' => $this->stringList($decoded['tracking_numbers'] ?? null),
            'total_amount' => $this->optionalFloat($decoded['total_amount'] ?? null),
            'currency' => is_string($decoded['currency'] ?? null) ? $decoded['currency'] : null,
            'created_at' => is_string($decoded['created_at'] ?? null) ? $decoded['created_at'] : null,
            'dropship_package_id' => $this->positiveInt($decoded['dropship_package_id'] ?? null),
            'items' => $items,
            'raw' => $decoded,
            'simulated' => false,
        ];
    }

    /**
     * GET package-details/{package_id}/ — stato del pacchetto dropship che
     * raggruppa più ordini. Idempotente, stessa politica della GET dettagli.
     *
     * @return array{package_id: int, status: string, creation_date: string|null,
     *   last_update_date: string|null, total_order_count: int|null,
     *   total_order_price: float|null,
     *   orders: list<array{order_id: int|null, status: string, created_at: string|null, total_price: float|null}>,
     *   raw: array<string, mixed>, simulated: bool}
     */
    public function packageDetails(int $packageId): array
    {
        if ($this->isSimulation()) {
            $this->logger->info('SIMULAZIONE lettura dettagli pacchetto dropship: nessuna chiamata HTTP effettuata', [
                'package_id' => $packageId,
            ]);

            return [
                'package_id' => $packageId,
                'status' => 'UNCONFIRMED',
                'creation_date' => null,
                'last_update_date' => null,
                'total_order_count' => null,
                'total_order_price' => null,
                'orders' => [],
                'raw' => ['simulated' => true, 'package_id' => $packageId],
                'simulated' => true,
            ];
        }

        $url = $this->liveUrl('DROPSHIP_PACKAGE_ENDPOINT', '/api/orders-dropship/package-details/')
            . $packageId . '/';
        $decoded = $this->getJson($url, 'dettagli pacchetto');

        $status = is_string($decoded['status'] ?? null) ? $decoded['status'] : null;
        if ($status === null) {
            throw new DropshipException('Risposta dettagli pacchetto illeggibile (manca lo status).');
        }

        $orders = [];
        foreach (is_array($decoded['orders'] ?? null) ? $decoded['orders'] : [] as $order) {
            if (!is_array($order)) {
                continue;
            }
            $orders[] = [
                'order_id' => $this->positiveInt($order['order_id'] ?? null),
                'status' => is_string($order['status'] ?? null) ? $order['status'] : '',
                'created_at' => is_string($order['created_at'] ?? null) ? $order['created_at'] : null,
                'total_price' => $this->optionalFloat($order['total_price'] ?? null),
            ];
        }

        return [
            'package_id' => $this->positiveInt($decoded['package_id'] ?? null) ?? $packageId,
            'status' => $status,
            'creation_date' => is_string($decoded['creation_date'] ?? null) ? $decoded['creation_date'] : null,
            'last_update_date' => is_string($decoded['last_update_date'] ?? null) ? $decoded['last_update_date'] : null,
            'total_order_count' => $this->positiveInt($decoded['total_order_count'] ?? null),
            'total_order_price' => $this->optionalFloat($decoded['total_order_price'] ?? null),
            'orders' => $orders,
            'raw' => $decoded,
            'simulated' => false,
        ];
    }

    // ── Interni (solo live) ──────────────────────────────────────────

    /**
     * GET idempotente con un retry e backoff: usata per le letture, mai
     * per la creazione.
     *
     * @return array<string, mixed> il JSON della risposta
     */
    private function getJson(string $url, string $context): array
    {
        $lastError = '';
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $res = $this->request('GET', $url, null);
            if ($res['errno'] === 0 && $res['status'] >= 200 && $res['status'] < 300) {
                $decoded = json_decode($res['body'], true);
                if (is_array($decoded)) {
                    /** @var array<string, mixed> $decoded */
                    return $decoded;
                }
                throw new DropshipException("Risposta {$context} non è JSON valido.");
            }
            $lastError = $res['errno'] !== 0
                ? $res['error']
                : 'HTTP ' . $res['status'] . ($this->errorDetail($res['body']) !== '' ? ': ' . $this->errorDetail($res['body']) : '');
            $this->logger->warning("Lettura {$context} fallita", [
                'url' => $url, 'attempt' => $attempt, 'error' => $lastError,
            ]);
            if ($attempt < 2) {
                sleep(2);
            }
        }

        throw new DropshipException("Lettura {$context} fallita: {$lastError}");
    }

    private function optionalFloat(mixed $value): ?float
    {
        return is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))
            ? (float) $value
            : null;
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        $list = [];
        foreach (is_array($value) ? $value : [] as $entry) {
            if (is_string($entry) && $entry !== '') {
                $list[] = $entry;
            } elseif (is_int($entry)) {
                $list[] = (string) $entry;
            }
        }

        return $list;
    }

    /** Base URL + endpoint configurato, con token verificato PRIMA di ogni invio. */
    private function liveUrl(string $endpointKey, string $endpointDefault): string
    {
        if ($this->config->str('FEED_BEARER_TOKEN') === '') {
            throw new DropshipException('FEED_BEARER_TOKEN mancante: impossibile usare DROPSHIP_MODE=live. Nessun ordine inviato.');
        }
        $endpoint = trim($this->config->str($endpointKey, $endpointDefault));
        if ($endpoint === '' || !str_starts_with($endpoint, '/')) {
            throw new DropshipException("{$endpointKey} mancante o non valido (deve iniziare con '/'). Nessun ordine inviato.");
        }

        return rtrim($this->config->str('FEED_BASE_URL', 'https://www.goldensneakers.net'), '/')
            . '/' . trim($endpoint, '/') . '/';
    }

    /** @return array{status: int, body: string, errno: int, error: string} */
    private function request(string $method, string $url, ?string $body): array
    {
        $timeout = max(5, $this->config->int('DROPSHIP_HTTP_TIMEOUT', 30));
        $headers = [
            'Authorization: Bearer ' . $this->config->str('FEED_BEARER_TOKEN'),
            'Accept: application/json',
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        if ($this->transport !== null) {
            /** @var array{status: int, body: string, errno: int, error: string} */
            return ($this->transport)($method, $url, $headers, $body, $timeout);
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new DropshipException('Inizializzazione cURL fallita: nessuna richiesta inviata.');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            // MAI seguire redirect: una POST replicata altrove è imprevedibile
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_USERAGENT => 'SCS-B2B-Catalog/1.0 (+https://b2b.shoesclothingstore.com)',
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $responseBody = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($ch);
        // mai loggare né rilanciare gli header: contengono il token
        $error = curl_error($ch);
        curl_close($ch);

        return [
            'status' => $status,
            'body' => is_string($responseBody) ? $responseBody : '',
            'errno' => $errno,
            'error' => $error,
        ];
    }

    private function positiveInt(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }

    /** Messaggio d'errore leggibile dal body del fornitore (troncato, mai HTML). */
    private function errorDetail(string $body): string
    {
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            foreach (['message', 'detail', 'error'] as $key) {
                if (is_string($decoded[$key] ?? null) && $decoded[$key] !== '') {
                    return mb_substr($decoded[$key], 0, 300);
                }
            }
            $flat = json_encode($decoded, JSON_UNESCAPED_UNICODE);

            return is_string($flat) ? mb_substr($flat, 0, 300) : '';
        }
        $text = trim(strip_tags($body));

        return mb_substr($text, 0, 300);
    }
}
