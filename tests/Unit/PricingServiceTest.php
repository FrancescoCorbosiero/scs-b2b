<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\PricingService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PricingServiceTest extends TestCase
{
    /**
     * Il prezzo di listino è NETTO: niente IVA nella formula.
     * Caso del sample reale: offer 47, margine 30% → 61,10.
     */
    public function testPercentMarginWithoutVat(): void
    {
        $none = new PricingService('none');
        self::assertSame('61.10', $none->netPrice('47', 'percent', 30.0));

        $whole = new PricingService('whole');
        self::assertSame('61.00', $whole->netPrice('47', 'percent', 30.0));

        $half = new PricingService('half');
        self::assertSame('61.00', $half->netPrice('47', 'percent', 30.0));
    }

    /** Margine fisso: "le Jordan a 3 euro fissi in più". */
    public function testFixedMargin(): void
    {
        $service = new PricingService('none');
        self::assertSame('50.00', $service->netPrice('47', 'fixed', 3.0));
        self::assertSame('50.99', $service->netPrice('47.99', 'fixed', 3.0));

        // il rounding di listino si applica anche al margine fisso
        $whole = new PricingService('whole');
        self::assertSame('51.00', $whole->netPrice('47.99', 'fixed', 3.0));
    }

    /**
     * Edge case floating point: 47 × 1,225 = 57,575 esatto, ma in float
     * binario è 57,574999… — la matematica intera deve dare 57,58.
     */
    public function testHalfCentEdgeCaseIsRoundedUp(): void
    {
        $service = new PricingService('none');
        self::assertSame('57.58', $service->netPrice('47', 'percent', 22.5));
    }

    /** @return list<array{string, string, string}> */
    public static function roundingProvider(): array
    {
        return [
            // offer, modalità, atteso (margine 30%)
            ['100', 'none', '130.00'],
            ['100.30', 'none', '130.39'],
            ['100.30', 'whole', '130.00'],
            ['100.30', 'half', '130.50'],
            ['0', 'whole', '0.00'],
            ['0.01', 'none', '0.01'],   // 0,013 → 0,01
            ['999.99', 'none', '1299.99'],
            ['38', 'whole', '49.00'],   // 49,40 → 49
            ['38', 'half', '49.50'],
        ];
    }

    #[DataProvider('roundingProvider')]
    public function testRoundingModes(string $offer, string $mode, string $expected): void
    {
        $service = new PricingService($mode);
        self::assertSame($expected, $service->netPrice($offer, 'percent', 30.0));
    }

    public function testInvalidRoundingFallsBackToWhole(): void
    {
        $service = new PricingService('garbage');
        self::assertSame('61.00', $service->netPrice('47', 'percent', 30.0));
    }

    public function testNegativeMarginNeverGoesBelowZero(): void
    {
        $service = new PricingService('none');
        self::assertSame('0.00', $service->netPrice('2', 'fixed', -5.0));
        self::assertSame('1.00', $service->netPrice('2', 'percent', -50.0));
    }
}
