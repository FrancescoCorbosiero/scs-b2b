<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Ordini dropship registrati (docs/09-order-dropship.md). Ogni riga conserva
 * il payload esatto che verrebbe inviato all'API e la risposta ricevuta
 * (simulata finché DROPSHIP_MODE=simulation). Tabella a solo uso /admin.
 */
final class DropshipOrderRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array{order_request_id: int|null, mode: string, status: string,
     *   vendor_order_id: int|null, dropship_package_id: int|null, total_price: string|null,
     *   currency: string, request_payload: string, lines_snapshot: string|null,
     *   response_payload: string|null} $data
     */
    public function insert(array $data): int
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO dropship_orders (order_request_id, created_at, updated_at, mode, status,
                vendor_order_id, dropship_package_id, total_price, currency,
                request_payload, lines_snapshot, response_payload, tracking_numbers)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)'
        );
        $stmt->execute([
            $data['order_request_id'],
            $now,
            $now,
            $data['mode'],
            $data['status'],
            $data['vendor_order_id'],
            $data['dropship_package_id'],
            $data['total_price'],
            $data['currency'],
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

        return $row === false ? null : $row;
    }

    /**
     * Ordini dropship già registrati per una richiesta (di norma 0 o 1).
     *
     * @return list<array<string, mixed>>
     */
    public function findByOrderRequest(int $orderRequestId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, created_at, mode, status, vendor_order_id, total_price, currency
             FROM dropship_orders WHERE order_request_id = ? ORDER BY id DESC'
        );
        $stmt->execute([$orderRequestId]);

        /** @var list<array<string, mixed>> */
        return $stmt->fetchAll();
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
