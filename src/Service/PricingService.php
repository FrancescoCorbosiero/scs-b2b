<?php

declare(strict_types=1);

namespace App\Service;

use App\Support\Config;

/**
 * Prezzi per piano: prezzo(P) = round(offer_price × (1 + MARKUP_P/100) × (1 + VAT/100)).
 *
 * Il calcolo replica la formula dell'API GoldenSneakers ma avviene SOLO nel
 * nostro backend, partendo dall'offer_price grezzo. Tutta la matematica è in
 * interi (centesimi e punti base) per evitare gli errori di rappresentazione
 * binaria dei float: es. 47 × 1,25 × 1,22 = 71,675 esatto, che in float è
 * 71,674999… e con un round() ingenuo diventerebbe 71,67 invece di 71,68.
 *
 * Predisposto per gli override per brand/piano (markup_rules, v2): basta
 * passare un markup diverso a priceFor().
 */
final class PricingService
{
    public const ROUNDING_MODES = ['whole', 'half', 'none'];

    /** Punti base: 30% → 3000. */
    private readonly int $markupBaseBp;
    private readonly int $markupProBp;
    private readonly int $markupMaxBp;
    private readonly int $vatBp;
    private readonly string $rounding;

    public function __construct(float $markupBase, float $markupPro, float $markupMax, float $vat, string $rounding)
    {
        $this->markupBaseBp = self::toBasisPoints($markupBase);
        $this->markupProBp = self::toBasisPoints($markupPro);
        $this->markupMaxBp = self::toBasisPoints($markupMax);
        $this->vatBp = self::toBasisPoints($vat);
        $this->rounding = in_array($rounding, self::ROUNDING_MODES, true) ? $rounding : 'whole';
    }

    public static function fromConfig(Config $config): self
    {
        return new self(
            $config->float('MARKUP_BASE', 30.0),
            $config->float('MARKUP_PRO', 25.0),
            $config->float('MARKUP_MAX', 20.0),
            $config->float('VAT_PERCENTAGE', 22.0),
            $config->str('PRICE_ROUNDING', 'whole'),
        );
    }

    /**
     * I tre prezzi (IVA inclusa) come stringhe decimali a 2 cifre, pronte per DECIMAL(10,2).
     *
     * @return array{base: string, pro: string, max: string}
     */
    public function pricesFor(string|float $offerPrice): array
    {
        return [
            'base' => $this->compute($offerPrice, $this->markupBaseBp),
            'pro' => $this->compute($offerPrice, $this->markupProBp),
            'max' => $this->compute($offerPrice, $this->markupMaxBp),
        ];
    }

    public function priceFor(string|float $offerPrice, float $markupPercent): string
    {
        return $this->compute($offerPrice, self::toBasisPoints($markupPercent));
    }

    private function compute(string|float $offerPrice, int $markupBp): string
    {
        $offerCents = (int) round((float) $offerPrice * 100);
        // numeratore in centesimi × 10^8 (due fattori in punti base)
        $numerator = $offerCents * (10000 + $markupBp) * (10000 + $this->vatBp);
        $denominator = 100000000;

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

    private static function toBasisPoints(float $percent): int
    {
        return (int) round($percent * 100);
    }
}
