<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Aliquote VAT standard per paese (UE-27 + UK/CH), seed nella migrazione 0003.
 * is_eu = false → extra-UE: l'ordine è trattato come export (VAT 0%).
 */
final class VatRateRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array{country_code: string, vat_rate: float, is_eu: bool, sort_order: int}> */
    public function all(): array
    {
        $stmt = $this->pdo->query(
            'SELECT country_code, vat_rate, is_eu, sort_order FROM vat_rates ORDER BY sort_order ASC, country_code ASC'
        );

        return array_map(self::hydrate(...), $stmt === false ? [] : $stmt->fetchAll());
    }

    /** @return array{country_code: string, vat_rate: float, is_eu: bool, sort_order: int}|null */
    public function find(string $countryCode): ?array
    {
        $stmt = $this->pdo->prepare('SELECT country_code, vat_rate, is_eu, sort_order FROM vat_rates WHERE country_code = ?');
        $stmt->execute([strtoupper($countryCode)]);
        $row = $stmt->fetch();

        return $row === false ? null : self::hydrate($row);
    }

    public function updateRate(string $countryCode, float $rate): bool
    {
        $stmt = $this->pdo->prepare('UPDATE vat_rates SET vat_rate = ?, updated_at = ? WHERE country_code = ?');
        $stmt->execute([number_format($rate, 2, '.', ''), date('Y-m-d H:i:s'), strtoupper($countryCode)]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{country_code: string, vat_rate: float, is_eu: bool, sort_order: int}
     */
    private static function hydrate(array $row): array
    {
        return [
            'country_code' => (string) $row['country_code'],
            'vat_rate' => (float) $row['vat_rate'],
            'is_eu' => (bool) $row['is_eu'],
            'sort_order' => (int) $row['sort_order'],
        ];
    }
}
