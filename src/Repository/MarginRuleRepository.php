<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Regole margine gestite da /admin/margini. Vince la prima regola attiva in
 * ordine di priority crescente (a parità, quella creata prima).
 */
final class MarginRuleRepository
{
    public const MATCH_TYPES = ['brand', 'name'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array{id: int, priority: int, match_type: string, match_value: string, margin_type: string, margin_value: float, is_active: bool}> */
    public function all(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, priority, match_type, match_value, margin_type, margin_value, is_active
             FROM margin_rules ORDER BY priority ASC, id ASC'
        );

        return array_map(self::hydrate(...), $stmt === false ? [] : $stmt->fetchAll());
    }

    /** @return list<array{id: int, priority: int, match_type: string, match_value: string, margin_type: string, margin_value: float, is_active: bool}> */
    public function activeOrdered(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, priority, match_type, match_value, margin_type, margin_value, is_active
             FROM margin_rules WHERE is_active = 1 ORDER BY priority ASC, id ASC'
        );

        return array_map(self::hydrate(...), $stmt === false ? [] : $stmt->fetchAll());
    }

    public function insert(int $priority, string $matchType, string $matchValue, string $marginType, float $marginValue): int
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO margin_rules (priority, match_type, match_value, margin_type, margin_value, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 1, ?, ?)'
        );
        $stmt->execute([$priority, $matchType, $matchValue, $marginType, number_format($marginValue, 2, '.', ''), $now, $now]);

        return (int) $this->pdo->lastInsertId();
    }

    public function setActive(int $id, bool $active): bool
    {
        $stmt = $this->pdo->prepare('UPDATE margin_rules SET is_active = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$active ? 1 : 0, date('Y-m-d H:i:s'), $id]);

        return $stmt->rowCount() > 0;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM margin_rules WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->rowCount() > 0;
    }

    /** Quanti prodotti attivi corrispondono a una regola (anteprima in /admin/margini). */
    public function matchingProductsCount(string $matchType, string $matchValue): int
    {
        if ($matchType === 'brand') {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM products WHERE is_active = 1 AND LOWER(brand) = LOWER(?)');
            $stmt->execute([$matchValue]);
        } else {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM products WHERE is_active = 1 AND LOWER(name) LIKE LOWER(?)');
            $stmt->execute(['%' . addcslashes($matchValue, '%_\\') . '%']);
        }

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, priority: int, match_type: string, match_value: string, margin_type: string, margin_value: float, is_active: bool}
     */
    private static function hydrate(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'priority' => (int) $row['priority'],
            'match_type' => (string) $row['match_type'],
            'match_value' => (string) $row['match_value'],
            'margin_type' => (string) $row['margin_type'],
            'margin_value' => (float) $row['margin_value'],
            'is_active' => (bool) $row['is_active'],
        ];
    }
}
