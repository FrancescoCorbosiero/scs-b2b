<?php

declare(strict_types=1);

namespace App\Adapter;

use App\Support\Config;
use Psr\Log\LoggerInterface;

/**
 * Client per il dominio "order-dropship" dell'API GoldenSneakers
 * (POST creazione ordine, GET /orders-dropship/order-details/{order_id}/).
 *
 * ⚠ ANTEPRIMA — SOLO SIMULAZIONE. Questo client NON effettua MAI chiamate
 * HTTP: con DROPSHIP_MODE=simulation restituisce risposte fittizie nella
 * stessa forma documentata dall'API; con qualsiasi altro valore rifiuta con
 * DropshipException. La modalità live andrà implementata qui (bearer token
 * del feed + endpoint DROPSHIP_*_ENDPOINT) solo quando il flusso sarà stato
 * validato col fornitore: creare un ordine dropship è un'azione IRREVERSIBILE
 * che il fornitore conferma e che scala il suo stock reale.
 *
 * Stati ordine documentati: UNCONFIRMED, TO_SHIP, ENDED, CANCELED,
 * WAITING_FOR_INVOICE.
 */
final class GoldenSneakersDropshipClient
{
    public const MODE_SIMULATION = 'simulation';
    public const MODE_LIVE = 'live';

    public const STATUSES = ['UNCONFIRMED', 'TO_SHIP', 'ENDED', 'CANCELED', 'WAITING_FOR_INVOICE'];

    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger,
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
     *   dropship_package_id: int, simulated: bool}
     */
    public function createOrder(array $payload): array
    {
        if (!$this->isSimulation()) {
            throw new DropshipException(
                'DROPSHIP_MODE=live non ancora implementato: nessun ordine è stato inviato al fornitore.'
            );
        }

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

    /**
     * GET /orders-dropship/order-details/{order_id}/ — dettagli/stato ordine.
     *
     * @return array{order_id: int, status: string, tracking_numbers: list<string>, simulated: bool}
     */
    public function orderDetails(int $vendorOrderId): array
    {
        if (!$this->isSimulation()) {
            throw new DropshipException(
                'DROPSHIP_MODE=live non ancora implementato: nessuna chiamata al fornitore.'
            );
        }

        $this->logger->info('SIMULAZIONE lettura dettagli ordine dropship: nessuna chiamata HTTP effettuata', [
            'vendor_order_id' => $vendorOrderId,
        ]);

        // in simulazione l'ordine resta nello stato iniziale, senza tracking
        return [
            'order_id' => $vendorOrderId,
            'status' => 'UNCONFIRMED',
            'tracking_numbers' => [],
            'simulated' => true,
        ];
    }
}
