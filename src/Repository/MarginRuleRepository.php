<?php

declare(strict_types=1);

namespace App\Repository;

use App\Service\SizeCategory;
use PDO;

/**
 * Regole margine gestite da /admin/margini. Le regole SKU (le più specifiche)
 * vengono valutate per prime; poi vince la prima regola attiva in ordine di
 * priority crescente (a parità, quella creata prima).
 */
final class MarginRuleRepository
{
    public const MATCH_TYPES = ['brand', 'name', 'sku', 'size_category'];

    /**
     * Regole "di partenza" indicate dal titolare (migrazione 0006): sono ciò
     * che ripristina il bottone "Ripristina" in /admin/margini dopo un
     * azzeramento o una serie di modifiche.
     *
     * @var list<array{priority: int, match_type: string, match_value: string, margin_type: string, margin_value: float}>
     */
    public const STARTING_RULES = [
        ['priority' => 100, 'match_type' => 'brand', 'match_value' => 'Adidas', 'margin_type' => 'percent', 'margin_value' => 5.0],
        ['priority' => 100, 'match_type' => 'brand', 'match_value' => 'Autry', 'margin_type' => 'fixed', 'margin_value' => 2.0],
        ['priority' => 100, 'match_type' => 'brand', 'match_value' => 'Asics', 'margin_type' => 'fixed', 'margin_value' => 2.0],
        ['priority' => 100, 'match_type' => 'brand', 'match_value' => 'Jordan', 'margin_type' => 'fixed', 'margin_value' => 3.0],
        ['priority' => 100, 'match_type' => 'brand', 'match_value' => 'Nike', 'margin_type' => 'fixed', 'margin_value' => 3.0],
        ['priority' => 100, 'match_type' => 'brand', 'match_value' => 'Puma', 'margin_type' => 'fixed', 'margin_value' => 2.0],
        ['priority' => 100, 'match_type' => 'brand', 'match_value' => 'Timberland', 'margin_type' => 'fixed', 'margin_value' => 3.0],
        ['priority' => 100, 'match_type' => 'brand', 'match_value' => 'Ugg', 'margin_type' => 'fixed', 'margin_value' => 3.0],
        ['priority' => 100, 'match_type' => 'brand', 'match_value' => 'Vans', 'margin_type' => 'fixed', 'margin_value' => 2.0],
        ['priority' => 100, 'match_type' => 'brand', 'match_value' => 'Yeezy', 'margin_type' => 'fixed', 'margin_value' => 3.0],
        ['priority' => 100, 'match_type' => 'brand', 'match_value' => 'Birkenstock', 'margin_type' => 'fixed', 'margin_value' => 2.0],
        ['priority' => 100, 'match_type' => 'brand', 'match_value' => 'New Balance', 'margin_type' => 'fixed', 'margin_value' => 2.0],
        ['priority' => 100, 'match_type' => 'brand', 'match_value' => 'In', 'margin_type' => 'fixed', 'margin_value' => 2.0],
        ['priority' => 100, 'match_type' => 'brand', 'match_value' => 'Saucony', 'margin_type' => 'fixed', 'margin_value' => 2.0],
    ];

    /** SKU first, poi priority: stesso ordine di valutazione del MarginResolver. */
    private const EVAL_ORDER = "ORDER BY CASE WHEN match_type = 'sku' THEN 0 ELSE 1 END, priority ASC, id ASC";

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array{id: int, priority: int, match_type: string, match_value: string, margin_type: string, margin_value: float, is_active: bool}> */
    public function all(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, priority, match_type, match_value, margin_type, margin_value, is_active
             FROM margin_rules ' . self::EVAL_ORDER
        );

        return array_map(self::hydrate(...), $stmt === false ? [] : $stmt->fetchAll());
    }

    /** @return list<array{id: int, priority: int, match_type: string, match_value: string, margin_type: string, margin_value: float, is_active: bool}> */
    public function activeOrdered(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, priority, match_type, match_value, margin_type, margin_value, is_active
             FROM margin_rules WHERE is_active = 1 ' . self::EVAL_ORDER
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

    /**
     * Elimina tutte le regole (bottone "Azzera"): resta solo il margine di
     * default. Reversibile con "Ripristina" (STARTING_RULES).
     *
     * @return int regole eliminate
     */
    public function deleteAll(): int
    {
        $stmt = $this->pdo->query('DELETE FROM margin_rules');

        return $stmt === false ? 0 : $stmt->rowCount();
    }

    /**
     * Rimette le regole di partenza al posto di quelle attuali (bottone
     * "Ripristina"): sostituzione integrale, così il risultato è sempre lo
     * stesso a prescindere da cosa c'era prima.
     *
     * @return int regole inserite
     */
    public function restoreStartingRules(): int
    {
        $this->deleteAll();
        foreach (self::STARTING_RULES as $rule) {
            $this->insert(
                $rule['priority'],
                $rule['match_type'],
                $rule['match_value'],
                $rule['margin_type'],
                $rule['margin_value'],
            );
        }

        return count(self::STARTING_RULES);
    }

    /** Quanti prodotti attivi corrispondono a una regola (anteprima in /admin/margini). */
    public function matchingProductsCount(string $matchType, string $matchValue): int
    {
        if ($matchType === 'sku') {
            $skus = self::skuTokens($matchValue);
            if ($skus === []) {
                return 0;
            }
            $placeholders = implode(', ', array_fill(0, count($skus), '?'));
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) FROM products WHERE is_active = 1 AND LOWER(sku) IN ({$placeholders})"
            );
            $stmt->execute($skus);
        } elseif ($matchType === 'size_category') {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM products WHERE is_active = 1 AND size_category = ?');
            $stmt->execute([SizeCategory::normalize(mb_strtolower(trim($matchValue)))]);
        } elseif ($matchType === 'brand') {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM products WHERE is_active = 1 AND LOWER(brand) = LOWER(?)');
            $stmt->execute([$matchValue]);
        } else {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM products WHERE is_active = 1 AND LOWER(name) LIKE LOWER(?)');
            $stmt->execute(['%' . addcslashes($matchValue, '%_\\') . '%']);
        }

        return (int) $stmt->fetchColumn();
    }

    /**
     * SKU di una regola 'sku': match_value spezzato sulle virgole, minuscolo,
     * senza spazi ai bordi (tokenizzazione condivisa con MarginResolver).
     *
     * @return list<string>
     */
    public static function skuTokens(string $matchValue): array
    {
        $tokens = [];
        foreach (explode(',', mb_strtolower($matchValue)) as $token) {
            $token = trim($token);
            if ($token !== '') {
                $tokens[] = $token;
            }
        }

        return $tokens;
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
