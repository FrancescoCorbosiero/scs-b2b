<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Registro degli ordini presso GoldenSneakers (docs/09-order-dropship.md).
 * Ogni riga conserva il payload esatto inviato all'API e la risposta
 * ricevuta (simulata finché DROPSHIP_MODE=simulation). `api` dice con quale
 * API è nato l'ordine: 'orders' (/api/orders/, dal 02/10/2026) oppure
 * 'dropship' (orders-dropship/, ordini storici). Tabella a solo uso /admin:
 * contiene costi del fornitore.
 */
final class DropshipOrderRepository
{
    /** Con quale API è nato l'ordine (colonna `api`). */
    public const API_ORDERS = 'orders';
    public const API_DROPSHIP = 'dropship';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array{order_request_id: int|null, mode: string, status: string,
     *   vendor_order_id: int|null, dropship_package_id: int|null, total_price: string|null,
     *   currency: string, request_payload: string, lines_snapshot: string|null,
     *   response_payload: string|null, api?: string, shipping_cost?: string|null,
     *   payment_status?: string|null, is_paid?: bool|null} $data
     */
    public function insert(array $data): int
    {
        $now = date('Y-m-d H:i:s');
        $isPaid = $data['is_paid'] ?? null;
        $stmt = $this->pdo->prepare(
            'INSERT INTO dropship_orders (order_request_id, created_at, updated_at, mode, api, status,
                vendor_order_id, dropship_package_id, total_price, shipping_cost, currency,
                payment_status, is_paid, request_payload, lines_snapshot, response_payload, tracking_numbers)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)'
        );
        $stmt->execute([
            $data['order_request_id'],
            $now,
            $now,
            $data['mode'],
            $data['api'] ?? self::API_DROPSHIP,
            $data['status'],
            $data['vendor_order_id'],
            $data['dropship_package_id'],
            $data['total_price'],
            $data['shipping_cost'] ?? null,
            $data['currency'],
            $data['payment_status'] ?? null,
            $isPaid === null ? null : ($isPaid ? 1 : 0),
            $data['request_payload'],
            $data['lines_snapshot'],
            $data['response_payload'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM dropship_orders WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : self::withPaidFlag($row);
    }

    /**
     * Ordini dropship già registrati per una richiesta (di norma 0 o 1).
     *
     * @return list<array<string, mixed>>
     */
    public function findByOrderRequest(int $orderRequestId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, created_at, mode, api, status, vendor_order_id, total_price, currency,
                    payment_status, is_paid
             FROM dropship_orders WHERE order_request_id = ? ORDER BY id DESC'
        );
        $stmt->execute([$orderRequestId]);

        return array_map(self::withPaidFlag(...), $stmt->fetchAll());
    }

    /**
     * Ultimi ordini registrati dalla piattaforma (pagina admin "Ordini
     * GoldenSneakers"): gli esiti UNKNOWN restano in evidenza lì.
     *
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit = 30): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = $this->pdo->query(
            "SELECT id, order_request_id, created_at, mode, api, status, vendor_order_id, total_price,
                    shipping_cost, currency, payment_status, is_paid
             FROM dropship_orders ORDER BY id DESC LIMIT {$limit}"
        );

        return $stmt === false ? [] : array_map(self::withPaidFlag(...), $stmt->fetchAll());
    }

    /**
     * is_paid come bool|null: MySQL restituisce le TINYINT come stringhe e i
     * template distinguono "pagato" / "da pagare" / "non noto".
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function withPaidFlag(array $row): array
    {
        if (array_key_exists('is_paid', $row)) {
            $row['is_paid'] = $row['is_paid'] === null ? null : (int) $row['is_paid'] === 1;
        }

        return $row;
    }

    /**
     * Righe REALI (mode live) create con l'API ordini, per ID ordine del
     * fornitore: collega l'elenco letto da GoldenSneakers alle richieste
     * della piattaforma. Gli ordini simulati hanno ID fittizi e restano fuori.
     *
     * @param list<int> $vendorOrderIds
     * @return array<int, array{id: int, order_request_id: int|null}> vendor_order_id => riga locale
     */
    public function liveOrdersByVendorIds(array $vendorOrderIds): array
    {
        $ids = array_values(array_unique(array_filter($vendorOrderIds, static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $map = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->pdo->prepare(
                "SELECT id, order_request_id, vendor_order_id FROM dropship_orders
                 WHERE api = ? AND mode = 'live' AND vendor_order_id IN ({$placeholders})
                 ORDER BY id ASC"
            );
            $stmt->execute([self::API_ORDERS, ...$chunk]);
            foreach ($stmt->fetchAll() as $row) {
                $map[(int) $row['vendor_order_id']] = [
                    'id' => (int) $row['id'],
                    'order_request_id' => $row['order_request_id'] !== null ? (int) $row['order_request_id'] : null,
                ];
            }
        }

        return $map;
    }

    /**
     * Registra l'etichetta caricata presso il fornitore (upload monouso) e
     * i tracking comunicati con l'upload.
     *
     * @param list<string> $trackingNumbers
     */
    public function markLabelUploaded(int $id, string $fileName, array $trackingNumbers): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE dropship_orders SET label_uploaded_at = ?, label_file_name = ?, tracking_numbers = ?, updated_at = ? WHERE id = ?'
        );
        $now = date('Y-m-d H:i:s');
        $stmt->execute([
            $now,
            $fileName,
            $trackingNumbers === [] ? null : (string) json_encode($trackingNumbers, JSON_UNESCAPED_UNICODE),
            $now,
            $id,
        ]);
    }

    /**
     * Tracking (e stato) degli ordini dropship per le richieste indicate:
     * SOLO campi mostrabili al rivenditore — mai costi o payload.
     *
     * @param list<int> $orderRequestIds
     * @return array<int, array{status: string, tracking: list<string>}>
     */
    public function trackingByOrderRequestIds(array $orderRequestIds): array
    {
        $ids = array_values(array_filter(array_map(intval(...), $orderRequestIds), static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT order_request_id, status, tracking_numbers
             FROM dropship_orders
             WHERE order_request_id IN ({$placeholders}) AND status <> 'UNKNOWN'
             ORDER BY id ASC"
        );
        $stmt->execute($ids);

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $tracking = json_decode(is_string($row['tracking_numbers'] ?? null) ? $row['tracking_numbers'] : '[]', true);
            // l'ultimo ordine per richiesta vince (di norma ce n'è uno solo)
            $map[(int) $row['order_request_id']] = [
                'status' => (string) $row['status'],
                'tracking' => is_array($tracking) ? array_values(array_filter($tracking, is_string(...))) : [],
            ];
        }

        return $map;
    }

    /**
     * Aggiorna una riga dell'API ordini con l'ultima lettura del dettaglio
     * (GET /api/orders/{id}/): stato, pagamento al fornitore, totale e
     * snapshot completo. I valori null non sovrascrivono quelli noti.
     *
     * @param list<string> $trackingNumbers
     */
    public function updateFromOrdersDetail(
        int $id,
        string $status,
        ?string $paymentStatus,
        ?bool $isPaid,
        ?string $totalPrice,
        array $trackingNumbers,
        string $detailsJson,
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE dropship_orders SET status = ?,
                payment_status = COALESCE(?, payment_status),
                is_paid = COALESCE(?, is_paid),
                total_price = COALESCE(?, total_price),
                tracking_numbers = COALESCE(?, tracking_numbers),
                details_payload = ?,
                updated_at = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $status,
            $paymentStatus,
            $isPaid === null ? null : ($isPaid ? 1 : 0),
            $totalPrice,
            $trackingNumbers === [] ? null : (string) json_encode($trackingNumbers, JSON_UNESCAPED_UNICODE),
            $detailsJson,
            date('Y-m-d H:i:s'),
            $id,
        ]);
    }

    /**
     * Aggiorna la riga con l'ultima lettura dal fornitore (order-details +
     * eventuale package-details). total_price/package_id si toccano solo se
     * il fornitore li fornisce; details_payload è lo snapshot JSON completo.
     *
     * @param list<string> $trackingNumbers
     */
    public function updateFromDetails(
        int $id,
        string $status,
        array $trackingNumbers,
        ?string $totalPrice,
        ?int $packageId,
        ?string $detailsJson,
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE dropship_orders SET status = ?,
                tracking_numbers = ?,
                total_price = COALESCE(?, total_price),
                dropship_package_id = COALESCE(?, dropship_package_id),
                details_payload = COALESCE(?, details_payload),
                updated_at = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $status,
            $trackingNumbers === [] ? null : (string) json_encode($trackingNumbers, JSON_UNESCAPED_UNICODE),
            $totalPrice,
            $packageId,
            $detailsJson,
            date('Y-m-d H:i:s'),
            $id,
        ]);
    }
}
