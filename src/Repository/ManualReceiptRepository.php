<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Pro-forma manuali create da /admin/proforma (docs/06). Il numero arriva
 * dalla stessa serie delle ricevute degli ordini (ReceiptService) e non
 * cambia più; le righe sono JSON con i soli prezzi di vendita.
 */
final class ManualReceiptRepository
{
    public const STATUS_ISSUED = 'issued';
    public const STATUS_CANCELLED = 'cancelled';

    /** Colonne scritte da insert/update: i dati arrivano validati da ManualReceiptService. */
    private const FIELDS = [
        'user_id', 'locale', 'customer_name', 'company', 'email', 'phone',
        'address_street', 'address_city', 'address_zip', 'country_code', 'vat_number',
        'vat_scheme', 'vat_rate', 'vat_amount', 'lines_json', 'total_items', 'total_amount',
        'shipping_amount', 'total_gross', 'notes', 'show_bank',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string, mixed> $data */
    public function insert(string $receiptNumber, array $data): int
    {
        $now = date('Y-m-d H:i:s');
        $columns = implode(', ', self::FIELDS);
        $placeholders = implode(', ', array_fill(0, count(self::FIELDS), '?'));
        $stmt = $this->pdo->prepare(
            "INSERT INTO manual_receipts (receipt_number, status, {$columns}, created_at, updated_at)
             VALUES (?, ?, {$placeholders}, ?, ?)"
        );
        $stmt->execute([$receiptNumber, self::STATUS_ISSUED, ...$this->values($data), $now, $now]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Aggiorna dati e righe di una pro-forma ancora valida: numero, stato e
     * data di emissione non cambiano.
     *
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): bool
    {
        $assignments = implode(', ', array_map(static fn (string $field): string => "{$field} = ?", self::FIELDS));
        $stmt = $this->pdo->prepare(
            "UPDATE manual_receipts SET {$assignments}, updated_at = ? WHERE id = ? AND status = ?"
        );
        $stmt->execute([...$this->values($data), date('Y-m-d H:i:s'), $id, self::STATUS_ISSUED]);

        return $stmt->rowCount() > 0;
    }

    /** @return array<string, mixed>|null con `lines` già decodificate */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM manual_receipts WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : self::hydrate($row);
    }

    /**
     * Elenco per /admin/proforma, più recenti prima; `q` cerca su numero,
     * cliente, azienda ed email.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function paginate(int $page, int $perPage, string $q = ''): array
    {
        $where = '1 = 1';
        $params = [];
        if ($q !== '') {
            $where = '(receipt_number LIKE ? OR customer_name LIKE ? OR company LIKE ? OR email LIKE ?)';
            $like = '%' . $q . '%';
            $params = [$like, $like, $like, $like];
        }

        $count = $this->pdo->prepare("SELECT COUNT(*) FROM manual_receipts WHERE {$where}");
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $offset = max(0, ($page - 1) * $perPage);
        $stmt = $this->pdo->prepare(
            "SELECT id, receipt_number, status, customer_name, company, email, country_code,
                    total_items, total_gross, email_sent_at, created_at
             FROM manual_receipts WHERE {$where}
             ORDER BY created_at DESC, id DESC
             LIMIT " . (int) $perPage . ' OFFSET ' . (int) $offset
        );
        $stmt->execute($params);

        /** @var list<array<string, mixed>> $items */
        $items = $stmt->fetchAll();

        return ['items' => $items, 'total' => $total];
    }

    public function markSent(int $id, string $to): void
    {
        $stmt = $this->pdo->prepare('UPDATE manual_receipts SET email_sent_at = ?, email_sent_to = ? WHERE id = ?');
        $stmt->execute([date('Y-m-d H:i:s'), $to, $id]);
    }

    /** Annulla una pro-forma: il numero resta occupato, il documento non vale più. */
    public function cancel(int $id): bool
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'UPDATE manual_receipts SET status = ?, cancelled_at = ?, updated_at = ? WHERE id = ? AND status = ?'
        );
        $stmt->execute([self::STATUS_CANCELLED, $now, $now, $id, self::STATUS_ISSUED]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $data
     * @return list<mixed>
     */
    private function values(array $data): array
    {
        $values = [];
        foreach (self::FIELDS as $field) {
            $value = $data[$field] ?? null;
            $values[] = is_bool($value) ? (int) $value : $value;
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function hydrate(array $row): array
    {
        $lines = json_decode(is_string($row['lines_json'] ?? null) ? $row['lines_json'] : '[]', true);
        $row['lines'] = is_array($lines) ? array_values(array_filter($lines, is_array(...))) : [];
        unset($row['lines_json']);
        $row['id'] = (int) $row['id'];
        $row['user_id'] = $row['user_id'] !== null ? (int) $row['user_id'] : null;
        $row['total_items'] = (int) $row['total_items'];
        $row['show_bank'] = (int) $row['show_bank'] === 1;
        // MySQL restituisce i DECIMAL come stringhe "12.30", SQLite come numeri
        foreach (['vat_rate', 'vat_amount', 'total_amount', 'shipping_amount', 'total_gross'] as $money) {
            $row[$money] = number_format((float) $row[$money], 2, '.', '');
        }

        return $row;
    }
}
