<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Adapter\GoldenSneakersAdapter;
use App\Repository\MarginRuleRepository;
use App\Repository\ProductRepository;
use App\Repository\SettingsRepository;
use App\Repository\SyncLogRepository;
use App\Service\CustomProductService;
use App\Service\FeedSyncService;
use App\Service\MarginResolver;
use App\Service\PricingService;
use App\Support\Config;
use App\Support\Lang;
use App\Tests\Support\TestDb;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Prodotti propri importati da JSON/CSV nel formato del feed (docs/06):
 * stessa validazione e stessi prezzi del feed, ma separati da esso — lo
 * SKU del feed è rifiutato, il sync non li tocca, l'import non tocca il
 * feed, hanno una sezione del catalogo tutta loro.
 */
final class CustomProductServiceTest extends TestCase
{
    private PDO $pdo;
    private string $workDir;
    private ProductRepository $products;

    protected function setUp(): void
    {
        $this->pdo = TestDb::create();
        $this->products = new ProductRepository($this->pdo);
        $this->workDir = sys_get_temp_dir() . '/custom-test-' . bin2hex(random_bytes(4));
        mkdir($this->workDir . '/logs', 0777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->workDir . '/logs/sync.lock');
        @rmdir($this->workDir . '/logs');
        @unlink($this->workDir . '/feed.json');
        @rmdir($this->workDir);
    }

    private function sync(string $feedFixture = ''): FeedSyncService
    {
        $config = new Config([
            'ROOT_PATH' => $this->workDir,
            'FEED_SOURCE' => 'fixture',
            'FEED_FIXTURE_PATH' => $feedFixture,
            'FEED_BASE_URL' => 'https://www.goldensneakers.net',
        ]);

        return new FeedSyncService(
            $this->pdo,
            new GoldenSneakersAdapter($config, new NullLogger()),
            $this->products,
            new SyncLogRepository($this->pdo),
            new PricingService('whole'),
            new MarginResolver(new MarginRuleRepository($this->pdo), new SettingsRepository($this->pdo)),
            $config,
            new NullLogger(),
        );
    }

    private function service(): CustomProductService
    {
        $config = new Config(['ROOT_PATH' => $this->workDir, 'FEED_BASE_URL' => 'https://www.goldensneakers.net']);

        return new CustomProductService(
            new GoldenSneakersAdapter($config, new NullLogger()),
            $this->products,
            $this->sync(),
            new Lang(dirname(__DIR__, 2)),
        );
    }

    /** @param list<array<string, mixed>> $rows */
    private function feedFile(array $rows): string
    {
        $path = $this->workDir . '/feed.json';
        file_put_contents($path, (string) json_encode($rows));

        return $path;
    }

    /** @return array<string, mixed> riga del feed GoldenSneakers (formato assortment-flat) */
    private static function feedRow(string $sku, string $sizeEu, int $qty = 5, string $name = 'Nike Dunk Low'): array
    {
        return [
            'id' => random_int(1000, 99999), 'sku' => $sku, 'product_name' => $name, 'brand_name' => 'Nike',
            'size_mapper_name' => 'Nike MENS', 'barcode' => '0195866000000', 'size_us' => '9', 'size_eu' => $sizeEu,
            'offer_price' => 60, 'presented_price' => 99, 'available_quantity' => $qty,
            'image' => '/images/' . $sku . '/main/', 'image_full_url' => 'https://www.goldensneakers.net/images/' . $sku . '/main/',
            'image_name' => 'main.png',
        ];
    }

    /** @return array<string, mixed> prodotto a DB con le sue taglie */
    private function product(string $sku): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM products WHERE sku = ?');
        $stmt->execute([$sku]);
        $product = $stmt->fetch();
        self::assertIsArray($product, "prodotto {$sku} assente");
        $sizes = $this->pdo->prepare('SELECT * FROM product_sizes WHERE product_id = ? ORDER BY size_eu');
        $sizes->execute([$product['id']]);
        $product['sizes'] = $sizes->fetchAll();

        return $product;
    }

    private function countProducts(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
    }

    public function testCsvFromItalianExcelIsImportedWithFeedPricing(): void
    {
        // BOM, separatore ";", virgola decimale e taglia "42,5": com'esce da Excel
        $csv = "\u{FEFF}sku;product_name;brand_name;size_eu;size_us;offer_price;available_quantity;image_full_url;id\n"
            . "LOCAL-01;Nike Dunk Low Panda;Nike;42;8.5;\"89,90\";3;https://shoesclothingstore.com/wp-content/uploads/dunk.jpg;11769\n"
            . "LOCAL-01;Nike Dunk Low Panda;Nike;42,5;9;89,90 €;2;https://shoesclothingstore.com/wp-content/uploads/dunk.jpg;11770\n";

        $result = $this->service()->import($csv, 'prodotti.csv', false);

        self::assertTrue($result['ok'], implode(' / ', $result['errors']));
        self::assertSame(1, $result['result']['products_created'] ?? null);
        $product = $this->product('LOCAL-01');
        self::assertSame('custom', $product['source']);
        self::assertSame(5, (int) $product['total_quantity']);
        self::assertSame('https://shoesclothingstore.com/wp-content/uploads/dunk.jpg', $product['image_url'], 'foto dal sito principale ammessa');
        self::assertSame(['42', '42.5'], array_column($product['sizes'], 'size_eu'), 'taglie con il punto, come nel feed');
        $expected = (new PricingService('whole'))->netPrice('89.90', 'percent', 30.0);
        foreach ($product['sizes'] as $size) {
            self::assertSame(89.90, (float) $size['offer_price']);
            self::assertSame((float) $expected, (float) $size['price'], 'prezzo = offer_price + margine, come per il feed');
            self::assertNull($size['supplier_size_id'], 'mai un size_id del fornitore su un prodotto proprio');
        }
    }

    public function testJsonInTheExactFeedFormatIsAccepted(): void
    {
        $json = (string) json_encode([self::feedRow('LOCAL-02', '43'), self::feedRow('LOCAL-02', '44')]);

        $result = $this->service()->import($json, 'export.json', false);

        self::assertTrue($result['ok'], implode(' / ', $result['errors']));
        $product = $this->product('LOCAL-02');
        self::assertSame('custom', $product['source']);
        self::assertSame('https://www.goldensneakers.net/images/LOCAL-02/main/main.png', $product['image_url']);
        self::assertSame([null, null], array_column($product['sizes'], 'supplier_size_id'));
    }

    public function testInvalidRowsAreReportedByLineAndNothingIsWritten(): void
    {
        $csv = "sku,product_name,size_eu,offer_price,available_quantity\n"
            . "LOCAL-01,Buona,42,50,1\n"
            . ",Senza sku,43,50,1\n"
            . "LOCAL-03,Prezzo rotto,44,gratis,1\n";

        $result = $this->service()->import($csv, 'prodotti.csv', false);

        self::assertFalse($result['ok']);
        $errors = implode("\n", $result['errors']);
        self::assertStringContainsString('Riga 3', $errors, 'la riga del foglio, intestazione = 1');
        self::assertStringContainsString('Riga 4', $errors);
        self::assertSame(0, $this->countProducts(), 'tutto o niente: nemmeno la riga buona viene scritta');
    }

    public function testFeedSkusAreRejectedNeverMerged(): void
    {
        TestDb::seedProduct($this->pdo, 'JS3801', 'adidas Gazelle', 'Adidas', [['size_eu' => '42', 'quantity' => 5]]);

        $result = $this->service()->import((string) json_encode([self::feedRow('JS3801', '43')]), 'p.json', false);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('JS3801', implode(' ', $result['errors']));
        $feed = $this->product('JS3801');
        self::assertSame('feed', $feed['source']);
        self::assertCount(1, $feed['sizes'], 'il prodotto del feed resta intatto');
    }

    public function testDuplicateSizeInTheFileIsAnError(): void
    {
        $json = (string) json_encode([self::feedRow('LOCAL-01', '42'), self::feedRow('LOCAL-01', '42')]);

        $result = $this->service()->import($json, 'p.json', false);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('Riga 2', implode(' ', $result['errors']));
    }

    public function testImagesOutsideTheAllowedDomainsAreDropped(): void
    {
        $row = self::feedRow('LOCAL-01', '42');
        $row['image_full_url'] = 'https://cdn.example.com/foto.jpg';
        $row['image_name'] = '';

        $result = $this->service()->import((string) json_encode([$row]), 'p.json', false);

        self::assertTrue($result['ok'], implode(' / ', $result['errors']));
        self::assertNull($this->product('LOCAL-01')['image_url'], 'host non ammesso: segnaposto, come per il feed');
    }

    public function testMissingRequiredColumnsAreNamed(): void
    {
        $result = $this->service()->import("sku;product_name\nA;B\n", 'p.csv', false);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('size_eu, offer_price, available_quantity', implode(' ', $result['errors']));
    }

    public function testReplaceHidesOnlyCustomProductsMissingFromTheFile(): void
    {
        TestDb::seedProduct($this->pdo, 'JS3801', 'adidas Gazelle', 'Adidas', [['size_eu' => '42', 'quantity' => 5]]);
        $service = $this->service();
        $service->import((string) json_encode([self::feedRow('LOCAL-A', '42'), self::feedRow('LOCAL-B', '42')]), 'p.json', false);

        $result = $service->import((string) json_encode([self::feedRow('LOCAL-A', '42', 7)]), 'p.json', true);

        self::assertTrue($result['ok'], implode(' / ', $result['errors']));
        self::assertSame(1, $result['result']['products_deactivated'] ?? null);
        self::assertSame(1, (int) $this->product('LOCAL-A')['is_active']);
        self::assertSame(7, (int) $this->product('LOCAL-A')['total_quantity']);
        self::assertSame(0, (int) $this->product('LOCAL-B')['is_active']);
        self::assertSame(1, (int) $this->product('JS3801')['is_active'], 'un prodotto del feed non si disattiva mai da qui');

        // senza "sostituisci" il file aggiunge/aggiorna e basta: B torna visibile
        $service->import((string) json_encode([self::feedRow('LOCAL-B', '42')]), 'p.json', false);
        self::assertSame(1, (int) $this->product('LOCAL-B')['is_active']);
        self::assertSame(1, (int) $this->product('LOCAL-A')['is_active']);
    }

    public function testFeedSyncNeverTouchesCustomProducts(): void
    {
        $this->service()->import((string) json_encode([self::feedRow('LOCAL-01', '42', 3, 'Prodotto in sede')]), 'p.json', false);

        // il feed non contiene LOCAL-01 (non va disattivato) e prova a usarne lo SKU
        $result = $this->sync($this->feedFile([
            self::feedRow('NK1001', '42'),
            self::feedRow('LOCAL-01', '43', 9, 'Nome dal fornitore'),
        ]))->run();

        self::assertSame('ok', $result['status'], (string) $result['message']);
        self::assertStringContainsString('LOCAL-01', (string) $result['message'], 'lo SKU conteso è segnalato nel log del sync');
        $custom = $this->product('LOCAL-01');
        self::assertSame('custom', $custom['source']);
        self::assertSame(1, (int) $custom['is_active']);
        self::assertSame('Prodotto in sede', $custom['name'], 'il feed non sovrascrive il prodotto proprio');
        self::assertSame(['42'], array_column($custom['sizes'], 'size_eu'));
        self::assertSame('feed', $this->product('NK1001')['source']);
    }

    public function testDownloadableTemplatesAreValidImports(): void
    {
        $service = $this->service();

        $csv = $service->import(CustomProductService::csvTemplate(), 'prodotti-propri-modello.csv', false);
        self::assertTrue($csv['ok'], implode(' / ', $csv['errors']));
        $json = $service->import(CustomProductService::jsonTemplate(), 'prodotti-propri-esempio.json', false);
        self::assertTrue($json['ok'], implode(' / ', $json['errors']));

        self::assertSame(1, $json['result']['products_updated'] ?? null, 'stesso prodotto nei due esempi');
        self::assertCount(2, $this->product('SCS-0001')['sizes']);
    }

    public function testCustomProductsHaveTheirOwnCatalogSection(): void
    {
        TestDb::seedProduct($this->pdo, 'NK1001', 'Nike Dunk Low', 'Nike', [['size_eu' => '42', 'quantity' => 5]]);
        TestDb::seedProduct($this->pdo, 'LOCAL-01', 'Puma Suede', 'Puma', [['size_eu' => '44', 'quantity' => 2]], source: 'custom');
        $filters = ['q' => '', 'brand' => '', 'availability' => '', 'recommended' => false,
            'price_min' => null, 'price_max' => null, 'sort' => 'rilevanza'];

        $feed = $this->products->search($filters + ['source' => 'feed'], 1, 24, 60, 20);
        $custom = $this->products->search($filters + ['source' => 'custom'], 1, 24, 60, 20);

        self::assertSame(['NK1001'], array_column($feed['items'], 'sku'));
        self::assertSame(['LOCAL-01'], array_column($custom['items'], 'sku'));
        self::assertSame([['brand' => 'Puma', 'products' => 1]], $this->products->activeBrandsWithCounts('custom'));
        self::assertSame(['44'], array_column($this->products->activeSizesWithCounts('custom'), 'size_eu'));
        self::assertSame(['feed' => 1, 'custom' => 1], $this->products->activeCountsBySource());
    }

    public function testHideAndDeleteNeverTouchFeedProducts(): void
    {
        $feedId = TestDb::seedProduct($this->pdo, 'NK1001', 'Nike Dunk Low', 'Nike', [['size_eu' => '42', 'quantity' => 5]]);
        $customId = TestDb::seedProduct($this->pdo, 'LOCAL-01', 'Puma Suede', 'Puma', [['size_eu' => '44', 'quantity' => 2]], source: 'custom');

        self::assertFalse($this->products->setCustomActive($feedId, false));
        self::assertFalse($this->products->deleteCustom($feedId));
        self::assertSame(1, (int) $this->product('NK1001')['is_active']);

        self::assertTrue($this->products->setCustomActive($customId, false));
        self::assertTrue($this->products->deleteCustom($customId));
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM product_sizes WHERE product_id = {$customId}")->fetchColumn());
        self::assertSame(1, $this->countProducts());
    }
}
