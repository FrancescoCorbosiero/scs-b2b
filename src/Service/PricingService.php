<?php

declare(strict_types=1);

namespace App\Service;

use App\Support\Config;

/**
 * Prezzo di listino NETTO (VAT esclusa): offer_price + margine.
 *
 * Il margine arriva dalle regole admin (vedi MarginResolver): percentuale
 * (price = offer × (1 + m/100)), importo fisso (price = offer + m) oppure
 * prezzo fisso (price = m, il prezzo del feed viene ignorato del tutto).
 * Il VAT non entra MAI nel prezzo di listino: viene calcolato a parte in
 * base al paese del cliente (VatService) solo nel riepilogo ordine/ricevuta.
 *
 * Tutta la matematica è in interi (centesimi e punti base) per evitare gli
 * errori di rappresentazione binaria dei float: es. 47 × 1,225 = 57,575
 * esatto, che in float è 57,574999… e con un round() ingenuo diventerebbe
 * 57,57 invece di 57,58.
 */
final class PricingService
{
    public const ROUNDING_MODES = ['whole', 'half', 'none'];

    /** percent: offer × (1+m/100) · fixed: offer + m · fixed_price: m (prezzo imposto dall'admin). */
    public const MARGIN_TYPES = ['percent', 'fixed', 'fixed_price'];

    /** Il margine di DEFAULT vale su tutto il catalogo: un prezzo fisso lì non ha senso. */
    public const DEFAULT_MARGIN_TYPES = ['percent', 'fixed'];

    private readonly string $rounding;

    public function __construct(string $rounding)
    {
        $this->rounding = in_array($rounding, self::ROUNDING_MODES, true) ? $rounding : 'whole';
    }

    public static function fromConfig(Config $config): self
    {
        return new self($config->str('PRICE_ROUNDING', 'whole'));
    }

    /**
     * Prezzo netto come stringa decimale a 2 cifre, pronta per DECIMAL(10,2).
     * Un margine negativo non può portare il prezzo sotto zero (clamp a 0).
     */
    public function netPrice(string|float $offerPrice, string $marginType, float $marginValue): string
    {
        // prezzo fisso: è l'admin a decidere la cifra esatta, quindi niente
        // offer_price e niente arrotondamento (99,90 deve restare 99,90)
        if ($marginType === 'fixed_price') {
            $cents = max(0, (int) round($marginValue * 100));

            return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
        }

        $offerCents = (int) round((float) $offerPrice * 100);

        if ($marginType === 'fixed') {
            $numerator = $offerCents + (int) round($marginValue * 100);
            $denominator = 1;
        } else {
            // punti base: 30% → 3000
            $marginBp = (int) round($marginValue * 100);
            $numerator = $offerCents * (10000 + $marginBp);
            $denominator = 10000;
        }
        $numerator = max(0, $numerator);

        $cents = match ($this->rounding) {
            'whole' => self::divRoundHalfUp($numerator, $denominator * 100) * 100,
            'half' => self::divRoundHalfUp($numerator, $denominator * 50) * 50,
            default => self::divRoundHalfUp($numerator, $denominator),
        };

        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    private static function divRoundHalfUp(int $numerator, int $denominator): int
    {
        return intdiv(2 * $numerator + $denominator, 2 * $denominator);
    }
}
