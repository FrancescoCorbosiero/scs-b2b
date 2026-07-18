<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class OrderRequestRepository
{
    public const STATUSES = ['pending', 'confirmed', 'cancelled'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * La richiesta nasce 'pending' e SENZA numero ricevuta: entrambi arrivano
     * con la conferma admin (pagamento ricevuto), vedi docs/06.
     *
     * @param array{user_id: int|null, customer_name: string, company: string|null, email: string, phone: string,
     *   address_street: string|null, address_city: string|null, address_zip: string|null,
     *   notes: string|null, locale: string, country_code: string, vat_number: string|null,
     *   vat_scheme: string, vat_rate: string, vat_amount: string, total_gross: string,
     *   total_items: int, total_amount: string,
     *   cart_snapshot: string, ip_address: string, user_agent: string|null} $data
     */
    public function insert(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO order_requests (user_id, created_at, customer_name, company, email, phone,
                address_street, address_city, address_zip, notes, status,
                locale, country_code, vat_number, vat_scheme, vat_rate, vat_amount, total_gross,
                total_items, total_amount, cart_snapshot, email_admin_sent, email_customer_sent, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'pending\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?)'
        );
        $stmt->execute([
            $data['user_id'] ?? null,
            date('Y-m-d H:i:s'),
            $data['customer_name'],
            $data['company'],
            $data['email'],
            $data['phone'],
            $data['address_street'],
            $data['address_city'],
            $data['address_zip'],
            $data['notes'],
            $data['locale'],
            $data['country_code'],
            $data['vat_number'],
            $data['vat_scheme'],
            $data['vat_rate'],
            $data['vat_amount'],
            $data['total_gross'],
            $data['total_items'],
            $data['total_amount'],
            $data['cart_snapshot'],
            $data['ip_address'],
            $data['user_agent'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** Conferma (pagamento ricevuto): registra stato, timestamp e numero ricevuta. */
    public function markConfirmed(int $id, string $receiptNumber): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE order_requests SET status = 'confirmed', confirmed_at = ?, receipt_number = ?
             WHERE id = ? AND status = 'pending'"
        );
        $stmt->execute([date('Y-m-d H:i:s'), $receiptNumber, $id]);

        return $stmt->rowCount() > 0;
    }

    /** Riallineamento righe/totali da /admin (solo richieste ancora in attesa). */
    public function updateTotalsAndSnapshot(
        int $id,
        int $totalItems,
        string $totalAmount,
        string $vatAmount,
        string $totalGross,
        string $snapshotJson,
    ): bool {
        $stmt = $this->pdo->prepare(
            "UPDATE order_requests
             SET total_items = ?, total_amount = ?, vat_amount = ?, total_gross = ?, cart_snapshot = ?
             WHERE id = ? AND status = 'pending'"
        );
        $stmt->execute([$totalItems, $totalAmount, $vatAmount, $totalGross, $snapshotJson, $id]);

        return $stmt->rowCount() > 0;
    }

    public function markCancelled(int $id): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE order_requests SET status = 'cancelled', cancelled_at = ? WHERE id = ? AND status = 'pending'"
        );
        $stmt->execute([date('Y-m-d H:i:s'), $id]);

        return $stmt->rowCount() > 0;
    }

    /** @return array<string, int> status => conteggio */
    public function countByStatus(): array
    {
        $stmt = $this->pdo->query('SELECT status, COUNT(*) AS n FROM order_requests GROUP BY status');
        $counts = ['pending' => 0, 'confirmed' => 0, 'cancelled' => 0];
        foreach ($stmt === false ? [] : $stmt->fetchAll() as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }

        return $counts;
    }

    public function markEmailSent(int $id, string $which): void
    {
        $column = $which === 'admin' ? 'email_admin_sent' : 'email_customer_sent';
        $stmt = $this->pdo->prepare("UPDATE order_requests SET {$column} = 1 WHERE id = ?");
        $stmt->execute([$id]);
    }

    /**
     * Ordini dell'area personale: quelli agganciati all'account + gli storici
     * pre-account con la stessa email.
     *
     * @return list<array<string, mixed>>
     */
    public function forUser(int $userId, string $email): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, created_at, status, receipt_number, country_code, vat_scheme,
                    total_items, total_amount, vat_amount, total_gross
             FROM order_requests
             WHERE user_id = ? OR (user_id IS NULL AND LOWER(email) = LOWER(?))
             ORDER BY id DESC LIMIT 200'
        );
        $stmt->execute([$userId, $email]);

        /** @var list<array<string, mixed>> */
        return $stmt->fetchAll();
    }

    /** Antispam: richieste inviate da un IP nell'ultima ora. */
    public function countRecentByIp(string $ip, int $windowMinutes = 60): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM order_requests WHERE ip_address = ? AND created_at >= ?');
        $stmt->execute([$ip, date('Y-m-d H:i:s', time() - $windowMinutes * 60)]);

        return (int) $stmt->fetchColumn();
    }

    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function paginate(int $page, int $perPage = 20, ?string $status = null): array
    {
        $where = '';
        $params = [];
        if ($status !== null && in_array($status, self::STATUSES, true)) {
            $where = 'WHERE status = ?';
            $params[] = $status;
        }
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM order_requests {$where}");
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $perPage = max(1, min(100, $perPage));
        $offset = max(0, ($page - 1) * $perPage);
        $stmt = $this->pdo->prepare(
            "SELECT id, created_at, customer_name, company, email, phone, status, country_code, vat_scheme,
                    receipt_number, total_items, total_amount, vat_amount, total_gross,
                    email_admin_sent, email_customer_sent
             FROM order_requests {$where} ORDER BY id DESC LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);
        /** @var list<array<string, mixed>> $items */
        $items = $stmt->fetchAll();

        return ['items' => $items, 'total' => $total];
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM order_requests WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function countAll(): int
    {
        return (int) ($this->pdo->query('SELECT COUNT(*) FROM order_requests')?->fetchColumn() ?: 0);
    }
}
