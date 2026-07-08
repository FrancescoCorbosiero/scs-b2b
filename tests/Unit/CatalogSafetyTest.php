<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Repository\ProductRepository;
use App\Support\XlsxWriter;
use App\Tests\Support\TestDb;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * Regola d'oro n.1: offer_price non deve MAI raggiungere il client.
 * Qui si verifica la difesa a livello query (i metodi catalogo non
 * selezionano la colonna) e l'export XLSX.
 */
final class CatalogSafetyTest extends TestCase
{
    private ProductRepository $products;

    protected function setUp(): void
    {
        $pdo = TestDb::create();
        TestDb::seedProduct($pdo, 'NK1001', 'Nike Dunk Low', 'Nike', [
            ['size_eu' => '42', 'quantity' => 5, 'offer_price' => '47.00', 'price_base' => '100.00'],
            ['size_eu' => '43', 'quantity' => 2, 'offer_price' => '47.00', 'price_base' => '100.00'],
        ]);
        $this->products = new ProductRepository($pdo);
    }

    private const FILTERS = [
        'q' => '', 'brand' => '', 'availability' => '', 'recommended' => false,
        'price_min' => null, 'price_max' => null, 'sort' => 'rilevanza',
    ];

    public function testSearchResultsNeverContainOfferPrice(): void
    {
        $result = $this->products->search(self::FILTERS, 'base', 1, 24, 60, 20);

        self::assertNotEmpty($result['items']);
        $json = json_encode($result, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('offer_price', $json);
        self::assertStringNotContainsString('47', $json, 'Nemmeno il valore del costo deve comparire');
    }

    public function testSizesForProductsExposeOnlyActivePlanPrice(): void
    {
        $result = $this->products->search(self::FILTERS, 'base', 1, 24, 60, 20);
        $ids = array_map(static fn (array $i): int => (int) $i['id'], $result['items']);
        $sizes = $this->products->sizesForProducts($ids, 'base');

        $json = json_encode($sizes, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('offer_price', $json);
        foreach ($sizes as $productSizes) {
            foreach ($productSizes as $size) {
                self::assertArrayHasKey('price', $size);
                self::assertArrayNotHasKey('price_pro', $size, 'Solo il prezzo del piano attivo');
            }
        }
    }

    public function testSearchFiltersByAvailabilityBands(): void
    {
        $pdo = TestDb::create();
        TestDb::seedProduct($pdo, 'HIGH1', 'Alta disp', 'Nike', [['size_eu' => '42', 'quantity' => 70]]);
        TestDb::seedProduct($pdo, 'MED1', 'Media disp', 'Nike', [['size_eu' => '42', 'quantity' => 30]]);
        TestDb::seedProduct($pdo, 'LOW1', 'Bassa disp', 'Nike', [['size_eu' => '42', 'quantity' => 10]]);
        $repo = new ProductRepository($pdo);

        foreach ([['alta', 'HIGH1'], ['media', 'MED1'], ['bassa', 'LOW1']] as [$band, $expected]) {
            $result = $repo->search(['availability' => $band] + self::FILTERS, 'base', 1, 24, 60, 20);
            self::assertCount(1, $result['items'], "Fascia {$band}");
            self::assertSame($expected, $result['items'][0]['sku']);
        }
    }

    public function testXlsxExportContainsCatalogColumnsButNoCost(): void
    {
        $writer = new XlsxWriter();
        $headers = ['SKU', 'Prodotto', 'Brand', 'Taglia EU', 'Taglia US', 'Barcode', 'Quantità', 'Prezzo (BASE, IVA incl.)'];
        $path = $writer->write('Catalogo', $headers, [
            ['NK1001', 'Nike Dunk Low', 'Nike', '42', '8.5', '4067907638411', 5, 100.0],
        ]);

        try {
            $zip = new ZipArchive();
            self::assertTrue($zip->open($path));
            $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
            $workbook = (string) $zip->getFromName('xl/workbook.xml');
            $zip->close();

            self::assertStringContainsString('NK1001', $sheet);
            self::assertStringContainsString('Prezzo (BASE, IVA incl.)', $sheet);
            self::assertStringContainsString('Catalogo', $workbook);
            self::assertStringNotContainsString('offer', $sheet . $workbook);
        } finally {
            @unlink($path);
        }
    }
}
