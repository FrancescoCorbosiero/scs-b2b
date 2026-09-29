<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Repository\VatRateRepository;
use App\Service\VatService;
use App\Tests\Support\TestDb;
use PHPUnit\Framework\TestCase;

/**
 * Gli schemi fiscali per paese (VAT_ON_ORDER=1) e il comportamento di
 * default della piattaforma: VAT_ON_ORDER=0, nessuna imposta addebitata al
 * cliente — il rivenditore bonifica sempre il netto.
 */
final class VatServiceTest extends TestCase
{
    /** Imposta addebitata: documenta gli schemi di legge. */
    private VatService $vat;

    /** Comportamento di default: il cliente bonifica il netto. */
    private VatService $netOnly;

    protected function setUp(): void
    {
        $rates = new VatRateRepository(TestDb::create());
        $this->vat = new VatService($rates, chargeVat: true);
        $this->netOnly = new VatService($rates);
    }

    // ── Default: nessuna imposta addebitata (VAT_ON_ORDER=0) ─────────

    public function testByDefaultNoVatIsChargedToTheCustomer(): void
    {
        self::assertFalse($this->netOnly->chargesVat());

        // lo schema resta quello di legge (serve a fatture e note della
        // ricevuta), ma l'aliquota addebitata è 0 per tutti
        foreach ([['IT', null, VatService::SCHEME_DOMESTIC],
                  ['DE', null, VatService::SCHEME_EU],
                  ['DE', 'DE123456789', VatService::SCHEME_REVERSE_CHARGE],
                  ['GB', null, VatService::SCHEME_EXPORT]] as [$country, $vatNumber, $scheme]) {
            $resolved = $this->netOnly->resolve($country, $vatNumber);
            self::assertSame($scheme, $resolved['scheme'], $country);
            self::assertSame(0.0, $resolved['rate'], $country . ': nessuna imposta addebitata');
        }
    }

    /** Il totale da bonificare coincide con l'imponibile: niente sorprese. */
    public function testAmountToTransferEqualsTheNetTotal(): void
    {
        $italian = $this->netOnly->resolve('IT', 'IT01234567890');
        $vatAmount = VatService::vatAmount('610.00', $italian['rate']);

        self::assertSame('0.00', $vatAmount);
        self::assertSame('610.00', VatService::grossTotal('610.00', $vatAmount));
    }

    // ── VAT_ON_ORDER=1: schemi di legge per paese ────────────────────

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

    // ── Nota IVA della ricevuta: prezzo con l'IVA, solo informativo ───

    /** @return array<string, mixed> richiesta IVA non addebitata, come a DB */
    private static function netOrder(array $overrides = []): array
    {
        return $overrides + [
            'country_code' => 'IT',
            'vat_scheme' => VatService::SCHEME_DOMESTIC,
            'vat_rate' => '0.00',
            'vat_amount' => '0.00',
            'total_amount' => '460.00',
            'shipping_amount' => '0.00',
            'total_gross' => '460.00',
        ];
    }

    /** Il caso reale della richiesta #4: 460,00 € netti, IVA 22% non addebitata. */
    public function testIndicativeVatShowsWhatAnItalianRequestWouldCostWithVat(): void
    {
        self::assertSame(
            ['rate' => 22.0, 'taxable' => '460.00', 'vat_amount' => '101.20', 'total_gross' => '561.20'],
            $this->netOnly->indicativeVat(self::netOrder()),
        );
    }

    public function testIndicativeVatIncludesShippingInTheTaxableBase(): void
    {
        $note = $this->netOnly->indicativeVat(self::netOrder([
            'total_amount' => '90.00', 'shipping_amount' => '10.00', 'total_gross' => '100.00',
        ]));

        self::assertNotNull($note);
        self::assertSame('100.00', $note['taxable']);
        self::assertSame('22.00', $note['vat_amount']);
        self::assertSame('122.00', $note['total_gross']);
    }

    public function testIndicativeVatUsesTheCountryRateForLegacyEuScheme(): void
    {
        $note = $this->netOnly->indicativeVat(self::netOrder([
            'country_code' => 'DE', 'vat_scheme' => VatService::SCHEME_EU, 'total_amount' => '100.00',
        ]));

        self::assertNotNull($note);
        self::assertSame(19.0, $note['rate']);
        self::assertSame('119.00', $note['total_gross']);
    }

    /** Reverse charge ed export sono a 0% per legge: la ricevuta ha già la sua nota. */
    public function testNoIndicativeVatWhenTheLegalRateIsZero(): void
    {
        self::assertNull($this->netOnly->indicativeVat(self::netOrder([
            'country_code' => 'DE', 'vat_scheme' => VatService::SCHEME_REVERSE_CHARGE,
        ])));
        self::assertNull($this->netOnly->indicativeVat(self::netOrder([
            'country_code' => 'GB', 'vat_scheme' => VatService::SCHEME_EXPORT,
        ])));
    }

    /** VAT_ON_ORDER=1: l'imposta è già nel totale, la nota non serve. */
    public function testNoIndicativeVatWhenVatWasCharged(): void
    {
        self::assertNull($this->vat->indicativeVat(self::netOrder([
            'vat_rate' => '22.00', 'vat_amount' => '101.20', 'total_gross' => '561.20',
        ])));
    }

    /** Aliquote azzerate da /admin/margini ("Azzera"): vale quella standard di legge. */
    public function testIndicativeVatFallsBackToStandardRateWhenZeroed(): void
    {
        $rates = new VatRateRepository(TestDb::create());
        $rates->zeroAllRates();

        $note = (new VatService($rates))->indicativeVat(self::netOrder());

        self::assertNotNull($note);
        self::assertSame(22.0, $note['rate']);
        self::assertSame('561.20', $note['total_gross']);
    }
}
