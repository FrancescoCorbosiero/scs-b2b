<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\PricingService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PricingServiceTest extends TestCase
{
    /**
     * Il caso del sample reale: offer 47, markup 30, IVA 22 → 74,542.
     * Il feed mostra presented_price=74.54 (comportamento "none"),
     * con rounding "whole" atteso 75 (vedi docs/08 § domanda aperta n.2).
     */
    public function testSampleFixtureCase(): void
    {
        $none = new PricingService(30, 25, 20, 22, 'none');
        self::assertSame('74.54', $none->pricesFor('47')['base']);

        $whole = new PricingService(30, 25, 20, 22, 'whole');
        self::assertSame('75.00', $whole->pricesFor('47')['base']);

        $half = new PricingService(30, 25, 20, 22, 'half');
        self::assertSame('74.50', $half->pricesFor('47')['base']);
    }

    /**
     * Edge case floating point: 47 × 1,25 × 1,22 = 71,675 esatto, ma in
     * float binario è 71,674999… — la matematica intera deve dare 71,68.
     */
    public function testHalfCentEdgeCaseIsRoundedUp(): void
    {
        $service = new PricingService(30, 25, 20, 22, 'none');
        self::assertSame('71.68', $service->pricesFor('47')['pro']);
    }

    public function testAllPlansWithWholeRounding(): void
    {
        $service = new PricingService(30, 25, 20, 22, 'whole');
        $prices = $service->pricesFor('47');
        self::assertSame(['base' => '75.00', 'pro' => '72.00', 'max' => '69.00'], $prices);
    }

    /** @return list<array{string, string, string}> */
    public static function roundingProvider(): array
    {
        return [
            // offer, modalità, atteso (base, markup 30%)
            ['100', 'none', '158.60'],
            ['100', 'whole', '159.00'],
            ['100', 'half', '158.50'],
            ['0', 'whole', '0.00'],
            ['0.01', 'none', '0.02'],   // 0,01 × 1,586 = 0,01586 → 0,02
            ['999.99', 'none', '1585.98'],
            ['38', 'whole', '60.00'],   // 38 × 1,586 = 60,268
            ['160', 'whole', '254.00'], // 160 × 1,586 = 253,76
        ];
    }

    #[DataProvider('roundingProvider')]
    public function testRoundingModes(string $offer, string $mode, string $expected): void
    {
        $service = new PricingService(30, 25, 20, 22, $mode);
        self::assertSame($expected, $service->pricesFor($offer)['base']);
    }

    public function testInvalidRoundingFallsBackToWhole(): void
    {
        $service = new PricingService(30, 25, 20, 22, 'garbage');
        self::assertSame('75.00', $service->pricesFor('47')['base']);
    }

    public function testDecimalMarkupPercentages(): void
    {
        // markup 22,5% → 47 × 1,225 × 1,22 = 70,2415 → none: 70,24
        $service = new PricingService(22.5, 22.5, 22.5, 22, 'none');
        self::assertSame('70.24', $service->pricesFor('47')['base']);
    }

    public function testPriceForCustomMarkup(): void
    {
        // predisposizione markup_rules v2: override puntuale del markup
        $service = new PricingService(30, 25, 20, 22, 'none');
        self::assertSame('74.54', $service->priceFor('47', 30.0));
        self::assertSame('68.81', $service->priceFor('47', 20.0));
    }
}
