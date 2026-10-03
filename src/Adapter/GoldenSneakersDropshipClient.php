<?php

declare(strict_types=1);

namespace App\Adapter;

/**
 * Client per gli ordini dropshipping dell'API GoldenSneakers (tag
 * "dropshipping-orders" della documentazione ufficiale,
 * https://www.goldensneakers.net/api/docs/): POST create-order/,
 * GET order-details/{order_id}/, GET package-details/{package_id}/,
 * POST upload-shipping-label/{order_id}/.
 *
 * ⚠ Dal 02/10/2026 gli ordini NUOVI si creano con l'API ordini
 * (GoldenSneakersOrdersClient, /api/orders/): questo client resta per gli
 * ordini registrati prima, cioè per rileggerne stato/tracking e per
 * l'eventuale upload dell'etichetta. createOrder() non è più chiamato dalla
 * piattaforma ma resta coperto dai test finché esistono ordini legacy.
 *
 * I path sono costanti di classe (*_PATH), NON configurazione: fanno parte
 * del contratto dell'API come il payload. Le vecchie variabili
 * DROPSHIP_*_ENDPOINT non vengono più lette, così un .env copiato da una
 * versione precedente (es. DROPSHIP_CREATE_ENDPOINT=/api/orders-dropship/create/,
 * path inesistente) non può più mandare gli ordini nel vuoto.
 *
 * Modalità e regole di sicurezza (simulation/live, nessun retry sulla POST,
 * esiti certi/incerti) sono quelle di GoldenSneakersApiClient. In
 * simulazione questo client non effettua MAI chiamate HTTP, nemmeno le GET:
 * gli ordini simulati hanno ID fittizi.
 *
 * Stati ordine documentati: UNCONFIRMED, TO_SHIP, ENDED, CANCELED,
 * WAITING_FOR_INVOICE.
 */
final class GoldenSneakersDropshipClient extends GoldenSneakersApiClient
{
    public const STATUSES = ['UNCONFIRMED', 'TO_SHIP', 'ENDED', 'CANCELED', 'WAITING_FOR_INVOICE'];

    /** Path API (base FEED_BASE_URL): i parametri {id} si accodano con lo slash finale. */
    public const CREATE_ORDER_PATH = '/api/orders-dropship/create-order/';
    public const ORDER_DETAILS_PATH = '/api/orders-dropship/order-details/';
    public const PACKAGE_DETAILS_PATH = '/api/orders-dropship/package-details/';
    public const UPLOAD_LABEL_PATH = '/api/orders-dropship/upload-shipping-label/';

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

        // NESSUN retry: postCreate invia una volta sola e classifica l'esito
        $decoded = $this->postCreate(self::CREATE_ORDER_PATH, $payload, 'ordine dropship');

        return [
            'message' => is_string($decoded['message'] ?? null) ? $decoded['message'] : '',
            'order_id' => (int) $decoded['order_id'],
            'total_price' => $this->optionalFloat($decoded['total_price'] ?? null),
            'dropship_package_id' => $this->positiveInt($decoded['dropship_package_id'] ?? null),
            'simulated' => false,
        ];
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

        $url = $this->liveUrl(self::ORDER_DETAILS_PATH) . $vendorOrderId . '/';
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

        $url = $this->liveUrl(self::PACKAGE_DETAILS_PATH) . $packageId . '/';
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

    /**
     * POST upload-shipping-label/{order_id}/ — carica etichetta (PDF/JPG/PNG)
     * e tracking per un ordine creato con client_provides_shipping_label=True.
     * L'API accetta UN solo upload per ordine e rifiuta i duplicati: per
     * questo un retry manuale dopo un errore di rete è sicuro (se il primo
     * upload era passato, il secondo viene respinto senza danni).
     *
     * @param list<string> $trackingNumbers
     * @return array{message: string, order_id: int, file_id: int|null,
     *   tracking_numbers: list<string>, simulated: bool}
     */
    public function uploadShippingLabel(
        int $vendorOrderId,
        string $filePath,
        string $fileName,
        string $mimeType,
        array $trackingNumbers,
    ): array {
        if ($this->isSimulation()) {
            $this->logger->info('SIMULAZIONE upload etichetta dropship: nessuna chiamata HTTP effettuata', [
                'vendor_order_id' => $vendorOrderId, 'file' => $fileName, 'tracking' => count($trackingNumbers),
            ]);

            return [
                'message' => 'Shipping label uploaded successfully (SIMULAZIONE — nessun upload inviato)',
                'order_id' => $vendorOrderId,
                'file_id' => random_int(900000, 999999),
                'tracking_numbers' => $trackingNumbers,
                'simulated' => true,
            ];
        }

        $url = $this->liveUrl(self::UPLOAD_LABEL_PATH) . $vendorOrderId . '/';
        $res = $this->request('POST', $url, [
            'shipping_label' => new \CURLFile($filePath, $mimeType, $fileName),
            'tracking_numbers' => (string) json_encode($trackingNumbers, JSON_UNESCAPED_UNICODE),
        ]);

        if ($res['errno'] !== 0) {
            throw new DropshipException(
                "Upload etichetta fallito ({$res['error']}). Riprovare è sicuro: se il primo upload era arrivato, il fornitore rifiuta il duplicato."
            );
        }
        if ($res['status'] >= 200 && $res['status'] < 300) {
            $decoded = json_decode($res['body'], true);
            if (!is_array($decoded)) {
                throw new DropshipException(
                    "Il fornitore ha risposto HTTP {$res['status']} ma senza JSON leggibile: verificare sul portale se l'etichetta risulta caricata."
                );
            }
            /** @var array<string, mixed> $decoded */
            $this->logger->info('Etichetta dropship caricata presso il fornitore', [
                'vendor_order_id' => $vendorOrderId, 'file_id' => $decoded['file_id'] ?? null,
            ]);

            return [
                'message' => is_string($decoded['message'] ?? null) ? $decoded['message'] : '',
                'order_id' => $this->positiveInt($decoded['order_id'] ?? null) ?? $vendorOrderId,
                'file_id' => $this->positiveInt($decoded['file_id'] ?? null),
                'tracking_numbers' => $this->stringList($decoded['tracking_numbers'] ?? null) ?: $trackingNumbers,
                'simulated' => false,
            ];
        }

        $detail = $this->errorDetail($res['body']);
        throw new DropshipException(
            "Il fornitore ha rifiutato l'etichetta (HTTP {$res['status']}" . ($detail !== '' ? ": {$detail}" : '') . ').'
        );
    }
}
