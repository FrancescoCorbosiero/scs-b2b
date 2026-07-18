<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Repository\VatRateRepository;
use App\Service\VatService;
use App\Tests\Support\TestDb;
use PHPUnit\Framework\TestCase;

final class VatServiceTest extends TestCase
{
    private VatService $vat;

    protected function setUp(): void
    {
        $this->vat = new VatService(new VatRateRepository(TestDb::create()));
    }

    public function testItalyIsAlwaysDomesticEvenWithVatNumber(): void
    {
        $noVat = $this->vat->resolve('IT', null);
        self::assertSame([VatService::SCHEME_DOMESTIC, 22.0], [$noVat['scheme'], $noVat['rate']]);

        $withVat = $this->vat->resolve('IT', 'IT01234567890');
        self::assertSame(VatService::SCHEME_DOMESTIC, $withVat['scheme']);
        self::assertSame(22.0, $withVat['rate']);
        self::assertSame('IT01234567890', $withVat['vat_number']);
    }

    public function testEuCountryWithoutVatNumberPaysLocalRate(): void
    {
        $de = $this->vat->resolve('DE', null);
        self::assertSame([VatService::SCHEME_EU, 19.0], [$de['scheme'], $de['rate']]);

        $hu = $this->vat->resolve('HU', '   ');
        self::assertSame([VatService::SCHEME_EU, 27.0], [$hu['scheme'], $hu['rate']]);
    }

    public function testEuCountryWithVatNumberIsReverseCharge(): void
    {
        $result = $this->vat->resolve('DE', 'DE 123.456.789');
        self::assertSame(VatService::SCHEME_REVERSE_CHARGE, $result['scheme']);
        self::assertSame(0.0, $result['rate']);
        self::assertSame('DE123456789', $result['vat_number'], 'Normalizzata: prefisso paese + soli alfanumerici');
    }

    public function testNonEuCountriesAreExport(): void
    {
        $gb = $this->vat->resolve('GB', null);
        self::assertSame([VatService::SCHEME_EXPORT, 0.0], [$gb['scheme'], $gb['rate']]);

        // anche con partita IVA: extra-UE resta export
        $ch = $this->vat->resolve('CH', 'CHE123456789');
        self::assertSame([VatService::SCHEME_EXPORT, 0.0], [$ch['scheme'], $ch['rate']]);
    }

    public function testUnknownCountryFallsBackToItaly(): void
    {
        $result = $this->vat->resolve('XX', null);
        self::assertSame('IT', $result['country_code']);
        self::assertSame(VatService::SCHEME_DOMESTIC, $result['scheme']);
    }

    public function testGreeceUsesElViesPrefix(): void
    {
        self::assertSame('EL123456789', VatService::normalizeVatNumber('GR123456789', 'GR'));
        self::assertSame('EL123456789', VatService::normalizeVatNumber('123456789', 'GR'));
    }

    public function testVatNumberPlausibility(): void
    {
        self::assertTrue(VatService::isPlausibleVatNumber(null, 'DE'), 'Assente = ammesso (campo facoltativo)');
        self::assertTrue(VatService::isPlausibleVatNumber('DE123456789', 'DE'));
        self::assertTrue(VatService::isPlausibleVatNumber('123456789', 'DE'), 'Senza prefisso: viene aggiunto');
        self::assertTrue(VatService::isPlausibleVatNumber('NL999999999B01', 'NL'));
        self::assertFalse(VatService::isPlausibleVatNumber('123', 'DE'), 'Troppo corta');
        self::assertFalse(VatService::isPlausibleVatNumber('1234567890123456789', 'DE'), 'Troppo lunga');
    }

    public function testVatAmountAndGrossTotalUseIntegerMath(): void
    {
        self::assertSame('220.00', VatService::vatAmount('1000.00', 22.0));
        self::assertSame('1220.00', VatService::grossTotal('1000.00', '220.00'));

        // arrotondamento half-up sul mezzo centesimo: 0,25 × 22% = 0,055 → 0,06
        self::assertSame('0.06', VatService::vatAmount('0.25', 22.0));
        self::assertSame('0.00', VatService::vatAmount('100.00', 0.0));
    }
}
