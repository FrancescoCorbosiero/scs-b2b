<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/** Account clienti (creati dall'admin, invito via email — docs/06). */
final class UserRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE LOWER(email) = LOWER(?)');
        $stmt->execute([trim($email)]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null solo utenti attivi (per login e sessioni) */
    public function findActive(int $id): ?array
    {
        $user = $this->find($id);

        return $user !== null && (bool) $user['is_active'] ? $user : null;
    }

    /**
     * @param array{email: string, name: string, company: string|null, phone: string|null,
     *   vat_number: string|null, country_code: string, locale: string} $data
     */
    public function insert(array $data): int
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (email, password_hash, name, company, phone, vat_number,
                country_code, locale, is_active, created_at, updated_at)
             VALUES (?, NULL, ?, ?, ?, ?, ?, ?, 1, ?, ?)'
        );
        $stmt->execute([
            trim($data['email']), $data['name'], $data['company'], $data['phone'], $data['vat_number'],
            $data['country_code'], $data['locale'], $now, $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function setPasswordHash(int $id, string $hash): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET password_hash = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$hash, date('Y-m-d H:i:s'), $id]);
    }

    public function setActive(int $id, bool $active): bool
    {
        $stmt = $this->pdo->prepare('UPDATE users SET is_active = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$active ? 1 : 0, date('Y-m-d H:i:s'), $id]);

        return $stmt->rowCount() > 0;
    }

    public function touchLastLogin(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET last_login_at = ? WHERE id = ?');
        $stmt->execute([date('Y-m-d H:i:s'), $id]);
    }

    /**
     * Aggiornamento del profilo dall'area personale (l'email la cambia solo l'admin).
     *
     * @param array{name: string, company: string|null, phone: string|null, vat_number: string|null,
     *   address_street: string|null, address_city: string|null, address_zip: string|null,
     *   country_code: string, locale: string} $data
     */
    public function updateProfile(int $id, array $data): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users SET name = ?, company = ?, phone = ?, vat_number = ?,
                address_street = ?, address_city = ?, address_zip = ?, country_code = ?, locale = ?, updated_at = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $data['name'], $data['company'], $data['phone'], $data['vat_number'],
            $data['address_street'], $data['address_city'], $data['address_zip'],
            $data['country_code'], $data['locale'], date('Y-m-d H:i:s'), $id,
        ]);
    }

    /**
     * Lista per /admin/clienti con conteggio richieste collegate.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $stmt = $this->pdo->query(
            'SELECT u.*, (SELECT COUNT(*) FROM order_requests o WHERE o.user_id = u.id) AS orders_count
             FROM users u ORDER BY u.created_at DESC, u.id DESC'
        );

        /** @var list<array<string, mixed>> */
        return $stmt === false ? [] : $stmt->fetchAll();
    }
}
