<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\VatRateRepository;

/**
 * Applicazione del VAT in base al paese del cliente (solo nel riepilogo
 * ordine e nella ricevuta pro-forma: i prezzi di listino restano netti).
 *
 * - Italia → 'domestic': aliquota IT (anche con partita IVA).
 * - UE ≠ IT con partita IVA plausibile → 'reverse_charge': VAT 0%
 *   (inversione contabile, art. 194–196 Dir. 2006/112/CE).
 * - UE ≠ IT senza partita IVA → 'eu': aliquota standard del paese.
 * - Extra-UE (UK, CH) → 'export': VAT 0% (operazione non imponibile).
 *
 * La partita IVA è validata solo nel FORMATO (normalizzazione + plausibilità):
 * nessuna chiamata VIES. La verifica sostanziale resta a carico del titolare
 * in fase di conferma ordine.
 */
final class VatService
{
    public const SCHEME_DOMESTIC = 'domestic';
    public const SCHEME_EU = 'eu';
    public const SCHEME_REVERSE_CHARGE = 'reverse_charge';
    public const SCHEME_EXPORT = 'export';

    public function __construct(private readonly VatRateRepository $rates)
    {
    }

    /**
     * @return array{country_code: string, scheme: string, rate: float, vat_number: string|null}
     */
    public function resolve(string $countryCode, ?string $vatNumber): array
    {
        $country = $this->rates->find($countryCode) ?? $this->rates->find('IT');
        if ($country === null) {
            // vat_rates vuota (DB non migrato): fallback prudente su IT 22%
            $country = ['country_code' => 'IT', 'vat_rate' => 22.0, 'is_eu' => true, 'sort_order' => 1];
        }
        $code = $country['country_code'];
        $normalized = self::normalizeVatNumber($vatNumber, $code);

        if (!$country['is_eu']) {
            return ['country_code' => $code, 'scheme' => self::SCHEME_EXPORT, 'rate' => 0.0, 'vat_number' => $normalized];
        }
        if ($code === 'IT') {
            return ['country_code' => $code, 'scheme' => self::SCHEME_DOMESTIC, 'rate' => $country['vat_rate'], 'vat_number' => $normalized];
        }
        if ($normalized !== null) {
            return ['country_code' => $code, 'scheme' => self::SCHEME_REVERSE_CHARGE, 'rate' => 0.0, 'vat_number' => $normalized];
        }

        return ['country_code' => $code, 'scheme' => self::SCHEME_EU, 'rate' => $country['vat_rate'], 'vat_number' => null];
    }

    public function isValidCountry(string $countryCode): bool
    {
        return $this->rates->find($countryCode) !== null;
    }

    /**
     * Normalizza in "CCXXXXXXXX" (prefisso paese + caratteri alfanumerici).
     * Ritorna null su input vuoto.
     */
    public static function normalizeVatNumber(?string $raw, string $countryCode): ?string
    {
        if ($raw === null) {
            return null;
        }
        $clean = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $raw));
        if ($clean === '') {
            return null;
        }
        $countryCode = strtoupper($countryCode);
        // la Grecia usa il prefisso VIES "EL", non "GR": si accettano entrambi in input
        $prefix = $countryCode === 'GR' ? 'EL' : $countryCode;
        if (str_starts_with($clean, $prefix)) {
            $clean = substr($clean, strlen($prefix));
        } elseif (str_starts_with($clean, $countryCode)) {
            $clean = substr($clean, strlen($countryCode));
        }

        return $prefix . $clean;
    }

    /** Controllo di plausibilità del formato (7–13 alfanumerici dopo il prefisso paese). */
    public static function isPlausibleVatNumber(?string $raw, string $countryCode): bool
    {
        $normalized = self::normalizeVatNumber($raw, $countryCode);
        if ($normalized === null) {
            return true; // assente = ammesso (il campo è facoltativo)
        }
        $body = substr($normalized, 2);

        return preg_match('/^[A-Z0-9]{7,13}$/', $body) === 1;
    }

    /** VAT in centesimi-safe: round(netto × aliquota), come stringa DECIMAL(10,2). */
    public static function vatAmount(string $netAmount, float $rate): string
    {
        $netCents = (int) round((float) $netAmount * 100);
        $rateBp = (int) round($rate * 100);
        $vatCents = intdiv(2 * $netCents * $rateBp + 10000, 2 * 10000);

        return sprintf('%d.%02d', intdiv($vatCents, 100), $vatCents % 100);
    }

    public static function grossTotal(string $netAmount, string $vatAmount): string
    {
        $cents = (int) round((float) $netAmount * 100) + (int) round((float) $vatAmount * 100);

        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }
}
