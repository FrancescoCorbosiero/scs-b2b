<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/** Richieste di attivazione profilo dal sito pubblico (docs/06). */
final class AccountRequestRepository
{
    public const STATUSES = ['pending', 'approved', 'rejected'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array{company: string, vat_number: string|null, name: string, email: string,
     *   phone: string, address_street: string, address_city: string, address_zip: string,
     *   country_code: string, locale: string, notes: string|null,
     *   ip_address: string, user_agent: string|null} $data
     */
    public function insert(array $data): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO account_requests (created_at, status, company, vat_number, name, email, phone,
                address_street, address_city, address_zip, country_code, locale, notes, ip_address, user_agent)
             VALUES (?, 'pending', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            date('Y-m-d H:i:s'),
            $data['company'],
            $data['vat_number'],
            $data['name'],
            $data['email'],
            $data['phone'],
            $data['address_street'],
            $data['address_city'],
            $data['address_zip'],
            $data['country_code'],
            $data['locale'],
            $data['notes'],
            $data['ip_address'],
            $data['user_agent'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM account_requests WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** Richiesta ancora in attesa per la stessa email (evita i doppioni). */
    public function findPendingByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM account_requests WHERE LOWER(email) = LOWER(?) AND status = 'pending' LIMIT 1"
        );
        $stmt->execute([trim($email)]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param string|null $status null = tutte
     * @return list<array<string, mixed>>
     */
    public function all(?string $status = null, int $limit = 200): array
    {
        $where = '';
        $params = [];
        if ($status !== null && in_array($status, self::STATUSES, true)) {
            $where = 'WHERE status = ?';
            $params[] = $status;
        }
        $limit = max(1, min(500, $limit));
        $stmt = $this->pdo->prepare("SELECT * FROM account_requests {$where} ORDER BY id DESC LIMIT {$limit}");
        $stmt->execute($params);

        /** @var list<array<string, mixed>> */
        return $stmt->fetchAll();
    }

    public function markApproved(int $id, int $userId): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE account_requests SET status = 'approved', reviewed_at = ?, user_id = ?
             WHERE id = ? AND status = 'pending'"
        );
        $stmt->execute([date('Y-m-d H:i:s'), $userId, $id]);

        return $stmt->rowCount() > 0;
    }

    public function markRejected(int $id): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE account_requests SET status = 'rejected', reviewed_at = ? WHERE id = ? AND status = 'pending'"
        );
        $stmt->execute([date('Y-m-d H:i:s'), $id]);

        return $stmt->rowCount() > 0;
    }

    public function countPending(): int
    {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM account_requests WHERE status = 'pending'");

        return (int) ($stmt === false ? 0 : $stmt->fetchColumn());
    }

    /** Antispam: richieste inviate da un IP nell'ultima ora. */
    public function countRecentByIp(string $ip, int $windowMinutes = 60): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM account_requests WHERE ip_address = ? AND created_at >= ?');
        $stmt->execute([$ip, date('Y-m-d H:i:s', time() - $windowMinutes * 60)]);

        return (int) $stmt->fetchColumn();
    }
}
