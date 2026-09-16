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
    /**
     * Aliquote standard "di fabbrica" (le stesse della migrazione 0003): sono
     * la fonte di verità del bottone "Ripristina" in /admin/margini, che
     * riporta indietro una riga modificata a mano.
     *
     * @var array<string, float>
     */
    public const STANDARD_RATES = [
        'IT' => 22.0, 'AT' => 20.0, 'BE' => 21.0, 'BG' => 20.0, 'CY' => 19.0, 'CZ' => 21.0,
        'DE' => 19.0, 'DK' => 25.0, 'EE' => 24.0, 'ES' => 21.0, 'FI' => 25.5, 'FR' => 20.0,
        'GR' => 24.0, 'HR' => 25.0, 'HU' => 27.0, 'IE' => 23.0, 'LT' => 21.0, 'LU' => 17.0,
        'LV' => 21.0, 'MT' => 18.0, 'NL' => 21.0, 'PL' => 23.0, 'PT' => 23.0, 'RO' => 21.0,
        'SE' => 25.0, 'SI' => 22.0, 'SK' => 23.0, 'GB' => 20.0, 'CH' => 8.1,
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** Aliquota standard di un paese, se conosciuta. */
    public static function standardRate(string $countryCode): ?float
    {
        return self::STANDARD_RATES[strtoupper($countryCode)] ?? null;
    }

    /**
     * Riporta tutte le aliquote ai valori standard (bottone "Ripristina").
     *
     * @return int paesi aggiornati
     */
    public function restoreStandardRates(): int
    {
        $changed = 0;
        foreach (self::STANDARD_RATES as $country => $rate) {
            $current = $this->find($country);
            if ($current !== null && abs($current['vat_rate'] - $rate) >= 0.005) {
                $this->updateRate($country, $rate);
                $changed++;
            }
        }

        return $changed;
    }

    /**
     * Azzera tutte le aliquote (bottone "Azzera"): utile quando la fatturazione
     * IVA è gestita fuori dalla piattaforma. Reversibile con "Ripristina".
     *
     * @return int paesi aggiornati
     */
    public function zeroAllRates(): int
    {
        $stmt = $this->pdo->prepare('UPDATE vat_rates SET vat_rate = 0, updated_at = ? WHERE vat_rate <> 0');
        $stmt->execute([date('Y-m-d H:i:s')]);

        return $stmt->rowCount();
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
