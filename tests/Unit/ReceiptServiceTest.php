<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Repository\VatRateRepository;
use App\Service\ReceiptService;
use App\Service\VatService;
use App\Support\Config;
use App\Support\Lang;
use App\Support\TwigExtension;
use App\Tests\Support\TestDb;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Ricevuta pro-forma: con l'IVA non addebitata (VAT_ON_ORDER=0) la "Nota IVA"
 * dice al cliente quanto varrebbe la richiesta con l'IVA di legge.
 */
final class ReceiptServiceTest extends TestCase
{
    private ReceiptService $receipts;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        $pdo = TestDb::create();
        $lang = new Lang($root);
        $twig = new Environment(new FilesystemLoader($root . '/templates'), ['autoescape' => 'html']);
        $twig->addExtension(new TwigExtension($lang));

        $this->receipts = new ReceiptService(
            $pdo,
            $twig,
            $lang,
            new Config(['ROOT_PATH' => $root]),
            new VatService(new VatRateRepository($pdo)),
        );
    }

    /** @return array<string, mixed> richiesta #4 (IT, 460,00 € netti) come arriva dai controller */
    private static function order(array $overrides = []): array
    {
        return $overrides + [
            'id' => 4,
            'created_at' => '2026-09-28 13:20:05',
            'receipt_number' => 'PF-2026-0004',
            'customer_name' => 'Mario Rossi',
            'company' => 'Rossi Sneakers',
            'email' => 'mario.rossi@example.it',
            'phone' => '+393401234567',
            'country_code' => 'IT',
            'vat_number' => 'IT01234567890',
            'vat_scheme' => 'domestic',
            'vat_rate' => '0.00',
            'vat_amount' => '0.00',
            'total_items' => 7,
            'total_amount' => '460.00',
            'shipping_amount' => '0.00',
            'total_gross' => '460.00',
            'lines' => [[
                'sku' => 'U9060BLK', 'name' => 'New Balance 9060 Black Castlerock Grey',
                'size_eu' => '42', 'size_us' => '8.5', 'barcode' => '196307584340',
                'qty' => 7, 'unit_price' => '65.71', 'subtotal' => '460.00',
            ]],
        ];
    }

    private static function text(string $html): string
    {
        return (string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    public function testItalianReceiptCarriesTheVatNoteWithTheGrossAmount(): void
    {
        $text = self::text($this->receipts->renderHtml(self::order(), 'it'));

        self::assertStringContainsString(
            "Nota IVA: con l'IVA al 22% il totale di questa richiesta sarebbe di 561,20 € (imponibile 460,00 € + IVA 101,20 €).",
            $text,
        );
        // il totale del documento resta il netto bonificato
        self::assertStringContainsString('Totale 460,00 €', $text);
        // la nota IVA sostituisce quella generica: nessun doppione
        self::assertStringNotContainsString('Importo IVA/VAT esclusa', $text);
    }

    public function testEnglishReceiptIsTranslated(): void
    {
        $text = self::text($this->receipts->renderHtml(self::order(), 'en'));

        self::assertStringContainsString(
            'VAT note: with VAT at 22% the total of this request would be 561,20 € (taxable amount 460,00 € + VAT 101,20 €).',
            $text,
        );
    }

    public function testReverseChargeKeepsItsOwnNoteWithoutVatNote(): void
    {
        $text = self::text($this->receipts->renderHtml(self::order([
            'country_code' => 'DE', 'vat_number' => 'DE123456789', 'vat_scheme' => 'reverse_charge',
        ]), 'it'));

        self::assertStringNotContainsString('Nota IVA', $text);
        self::assertStringContainsString('Inversione contabile / reverse charge', $text);
    }

    /** Il PDF reale (dompdf) si genera con la nuova nota senza errori. */
    public function testPdfBuildsWithTheVatNote(): void
    {
        $pdf = $this->receipts->buildPdf(self::order(), 'it');

        self::assertStringStartsWith('%PDF-', $pdf);
    }
}
