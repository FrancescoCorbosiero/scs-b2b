<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class SyncLogRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function start(): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO sync_logs (started_at, status, rows_read, products_created, products_updated, products_deactivated)
             VALUES (?, 'error', 0, 0, 0, 0)"
        );
        $stmt->execute([date('Y-m-d H:i:s')]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @param array{rows_read: int, products_created: int, products_updated: int, products_deactivated: int} $counters */
    public function finish(int $id, string $status, array $counters, ?string $message = null): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE sync_logs SET finished_at = ?, status = ?, rows_read = ?, products_created = ?,
                products_updated = ?, products_deactivated = ?, message = ?
             WHERE id = ?'
        );
        $stmt->execute([
            date('Y-m-d H:i:s'),
            $status === 'ok' ? 'ok' : 'error',
            $counters['rows_read'],
            $counters['products_created'],
            $counters['products_updated'],
            $counters['products_deactivated'],
            $message,
            $id,
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function recent(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = $this->pdo->query("SELECT * FROM sync_logs ORDER BY id DESC LIMIT {$limit}");

        /** @var list<array<string, mixed>> */
        return $stmt === false ? [] : $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function latest(): ?array
    {
        $rows = $this->recent(1);

        return $rows[0] ?? null;
    }
}
