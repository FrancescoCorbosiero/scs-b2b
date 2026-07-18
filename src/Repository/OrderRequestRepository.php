<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class OrderRequestRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array{customer_name: string, company: string|null, email: string, phone: string,
     *   notes: string|null, locale: string, country_code: string, vat_number: string|null,
     *   vat_scheme: string, vat_rate: string, vat_amount: string, total_gross: string,
     *   receipt_number: string|null, total_items: int, total_amount: string,
     *   cart_snapshot: string, ip_address: string, user_agent: string|null} $data
     */
    public function insert(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO order_requests (created_at, customer_name, company, email, phone, notes,
                locale, country_code, vat_number, vat_scheme, vat_rate, vat_amount, total_gross, receipt_number,
                total_items, total_amount, cart_snapshot, email_admin_sent, email_customer_sent, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?)'
        );
        $stmt->execute([
            date('Y-m-d H:i:s'),
            $data['customer_name'],
            $data['company'],
            $data['email'],
            $data['phone'],
            $data['notes'],
            $data['locale'],
            $data['country_code'],
            $data['vat_number'],
            $data['vat_scheme'],
            $data['vat_rate'],
            $data['vat_amount'],
            $data['total_gross'],
            $data['receipt_number'],
            $data['total_items'],
            $data['total_amount'],
            $data['cart_snapshot'],
            $data['ip_address'],
            $data['user_agent'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function markEmailSent(int $id, string $which): void
    {
        $column = $which === 'admin' ? 'email_admin_sent' : 'email_customer_sent';
        $stmt = $this->pdo->prepare("UPDATE order_requests SET {$column} = 1 WHERE id = ?");
        $stmt->execute([$id]);
    }

    /** Antispam: richieste inviate da un IP nell'ultima ora. */
    public function countRecentByIp(string $ip, int $windowMinutes = 60): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM order_requests WHERE ip_address = ? AND created_at >= ?');
        $stmt->execute([$ip, date('Y-m-d H:i:s', time() - $windowMinutes * 60)]);

        return (int) $stmt->fetchColumn();
    }

    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function paginate(int $page, int $perPage = 20): array
    {
        $total = (int) ($this->pdo->query('SELECT COUNT(*) FROM order_requests')?->fetchColumn() ?: 0);
        $perPage = max(1, min(100, $perPage));
        $offset = max(0, ($page - 1) * $perPage);
        $stmt = $this->pdo->query(
            "SELECT id, created_at, customer_name, company, email, phone, country_code, vat_scheme,
                    receipt_number, total_items, total_amount, vat_amount, total_gross,
                    email_admin_sent, email_customer_sent
             FROM order_requests ORDER BY id DESC LIMIT {$perPage} OFFSET {$offset}"
        );
        /** @var list<array<string, mixed>> $items */
        $items = $stmt === false ? [] : $stmt->fetchAll();

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
