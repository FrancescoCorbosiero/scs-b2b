<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Adapter\GoldenSneakersAdapter;
use App\Repository\MarginRuleRepository;
use App\Repository\ProductRepository;
use App\Repository\SettingsRepository;
use App\Repository\SyncLogRepository;
use App\Service\FeedSyncService;
use App\Service\MarginResolver;
use App\Service\PricingService;
use App\Support\Config;
use App\Tests\Support\TestDb;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class FeedSyncServiceTest extends TestCase
{
    private PDO $pdo;
    private string $workDir;

    protected function setUp(): void
    {
        $this->pdo = TestDb::create();
        $this->workDir = sys_get_temp_dir() . '/sync-test-' . bin2hex(random_bytes(4));
        mkdir($this->workDir, 0777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->workDir . '/logs/sync.lock');
        @rmdir($this->workDir . '/logs');
        @unlink($this->workDir . '/feed.json');
        @rmdir($this->workDir);
    }

    private function serviceFor(string $fixturePath, string $rounding = 'whole'): FeedSyncService
    {
        $config = new Config([
            'ROOT_PATH' => $this->workDir,
            'FEED_SOURCE' => 'fixture',
            'FEED_FIXTURE_PATH' => $fixturePath,
        ]);

        return new FeedSyncService(
            $this->pdo,
            new GoldenSneakersAdapter($config, new NullLogger()),
            new ProductRepository($this->pdo),
            new SyncLogRepository($this->pdo),
            new PricingService($rounding),
            new MarginResolver(new MarginRuleRepository($this->pdo), new SettingsRepository($this->pdo)),
            $config,
            new NullLogger(),
        );
    }

    private function realFixture(): string
    {
        return dirname(__DIR__, 2) . '/fixtures/goldensneakers-dev.json';
    }

    public function testSyncFromFixturePopulatesNetPrices(): void
    {
        $result = $this->serviceFor($this->realFixture())->run();

        self::assertSame('ok', $result['status']);
        self::assertSame(63, $result['rows_read']);
        self::assertSame(12, $result['products_created']);
        self::assertSame(0, $result['products_updated']);

        // sample reale: offer 47, margine default 30%, rounding whole → 61 (NETTO, senza IVA)
        // (normalizzato a 2 decimali: SQLite non conserva gli zeri finali dei DECIMAL)
        $stmt = $this->pdo->query(
            "SELECT s.price FROM product_sizes s
             JOIN products p ON p.id = s.product_id WHERE p.sku = 'JS3801' LIMIT 1"
        );
        $price = $stmt === false ? null : $stmt->fetchColumn();
        self::assertSame('61.00', number_format((float) $price, 2, '.', ''));

        // denormalizzazioni: total_quantity e min_price
        $stmt = $this->pdo->query("SELECT total_quantity, min_price FROM products WHERE sku = 'JS3801'");
        $product = $stmt === false ? [] : $stmt->fetch();
        self::assertSame(3, (int) $product['total_quantity']);
        self::assertSame('61.00', number_format((float) $product['min_price'], 2, '.', ''));

        // sync_logs registrato
        $log = (new SyncLogRepository($this->pdo))->latest();
        self::assertNotNull($log);
        self::assertSame('ok', $log['status']);
    }

    public function testSecondRunIsIdempotent(): void
    {
        $service = $this->serviceFor($this->realFixture());
        $service->run();

        $before = $this->dumpCatalog();
        $result = $service->run();

        self::assertSame('ok', $result['status']);
        self::assertSame(0, $result['products_created'], 'Secondo run: nessun prodotto nuovo');
        self::assertSame(12, $result['products_updated']);
        self::assertSame(0, $result['products_deactivated']);
        self::assertSame($before, $this->dumpCatalog(), 'Lo stato del catalogo non cambia');
    }

    public function testProductsMissingFromFeedAreDeactivatedNotDeleted(): void
    {
        $this->serviceFor($this->realFixture())->run();

        // feed ridotto: resta solo JS3801
        $rows = json_decode((string) file_get_contents($this->realFixture()), true);
        $reduced = array_values(array_filter(is_array($rows) ? $rows : [], static fn ($r) => is_array($r) && $r['sku'] === 'JS3801'));
        file_put_contents($this->workDir . '/feed.json', json_encode($reduced));

        $result = $this->serviceFor($this->workDir . '/feed.json')->run();

        self::assertSame('ok', $result['status']);
        self::assertSame(11, $result['products_deactivated']);
        $active = (int) ($this->pdo->query('SELECT COUNT(*) FROM products WHERE is_active = 1')?->fetchColumn() ?: 0);
        $total = (int) ($this->pdo->query('SELECT COUNT(*) FROM products')?->fetchColumn() ?: 0);
        self::assertSame(1, $active);
        self::assertSame(12, $total, 'I prodotti spariti restano a DB (disattivati)');
    }

    public function testBrokenFeedLeavesCatalogUntouched(): void
    {
        $this->serviceFor($this->realFixture())->run();
        $before = $this->dumpCatalog();

        file_put_contents($this->workDir . '/feed.json', '{"non": "una lista"}');
        $result = $this->serviceFor($this->workDir . '/feed.json')->run();

        self::assertSame('error', $result['status']);
        self::assertSame($before, $this->dumpCatalog(), 'Un feed rotto non deve mai toccare il catalogo');

        $log = (new SyncLogRepository($this->pdo))->latest();
        self::assertNotNull($log);
        self::assertSame('error', $log['status']);
    }

    public function testEmptyFeedAbortsForSafety(): void
    {
        $this->serviceFor($this->realFixture())->run();
        $before = $this->dumpCatalog();

        file_put_contents($this->workDir . '/feed.json', '[]');
        $result = $this->serviceFor($this->workDir . '/feed.json')->run();

        self::assertSame('error', $result['status']);
        self::assertSame($before, $this->dumpCatalog(), 'Feed vuoto → catalogo invariato (mai svuotarlo)');
    }

    /**
     * Il feed reale contiene SKU interamente numerici: PHP converte le chiavi
     * array numeriche in int, e il sync deve ricastarle a stringa (regressione
     * del primo sync live: TypeError su findIdBySku).
     */
    public function testNumericSkusSurviveGrouping(): void
    {
        file_put_contents($this->workDir . '/feed.json', json_encode([
            ['sku' => '394215', 'product_name' => 'SKU numerico', 'brand_name' => 'Nike',
             'size_eu' => '42', 'size_us' => 8.5, 'barcode' => 4067907638411,
             'offer_price' => 50, 'available_quantity' => 3],
            ['sku' => 394216, 'product_name' => 'SKU numerico int nel JSON', 'brand_name' => 'Nike',
             'size_eu' => '43', 'offer_price' => 60, 'available_quantity' => 1],
        ]));

        $result = $this->serviceFor($this->workDir . '/feed.json')->run();

        self::assertSame('ok', $result['status'], (string) $result['message']);
        self::assertSame(2, $result['products_created']);
        $stmt = $this->pdo->query("SELECT sku, name FROM products ORDER BY sku");
        $rows = $stmt === false ? [] : $stmt->fetchAll();
        self::assertSame('394215', (string) $rows[0]['sku']);
        self::assertSame('394216', (string) $rows[1]['sku']);

        // i campi numerici del JSON (size_us, barcode) diventano stringhe
        $size = $this->pdo->query("SELECT size_us, barcode FROM product_sizes LIMIT 1");
        $sizeRow = $size === false ? [] : $size->fetch();
        self::assertSame('8.5', (string) $sizeRow['size_us']);
        self::assertSame('4067907638411', (string) $sizeRow['barcode']);
    }

    public function testRepriceAppliesUpdatedDefaultMargin(): void
    {
        $this->serviceFor($this->realFixture())->run();

        // il default cambia da /admin: 50% con rounding none → 47 × 1,50 = 70,50
        $settings = new SettingsRepository($this->pdo);
        $settings->set('default_margin_value', '50');

        $result = $this->serviceFor($this->realFixture(), 'none')->run(repriceOnly: true);

        self::assertSame('ok', $result['status']);
        $stmt = $this->pdo->query(
            "SELECT s.price FROM product_sizes s JOIN products p ON p.id = s.product_id WHERE p.sku = 'JS3801' LIMIT 1"
        );
        self::assertSame('70.50', number_format((float) ($stmt === false ? 0 : $stmt->fetchColumn()), 2, '.', ''));
    }

    public function testRepriceAppliesBrandRuleOverDefault(): void
    {
        $this->serviceFor($this->realFixture())->run();

        $brandStmt = $this->pdo->query("SELECT brand FROM products WHERE sku = 'JS3801'");
        $brand = (string) ($brandStmt === false ? '' : $brandStmt->fetchColumn());
        self::assertNotSame('', $brand);

        // "le Jordan a 3 euro fissi in più": regola brand → offer 47 + 3 = 50
        (new MarginRuleRepository($this->pdo))->insert(10, 'brand', $brand, 'fixed', 3.0);

        $result = $this->serviceFor($this->realFixture(), 'none')->run(repriceOnly: true);

        self::assertSame('ok', $result['status']);
        $stmt = $this->pdo->query(
            "SELECT s.price FROM product_sizes s JOIN products p ON p.id = s.product_id WHERE p.sku = 'JS3801' LIMIT 1"
        );
        self::assertSame('50.00', number_format((float) ($stmt === false ? 0 : $stmt->fetchColumn()), 2, '.', ''));

        // gli altri brand restano al margine di default (30%)
        $other = $this->pdo->query(
            "SELECT s.price, s.offer_price FROM product_sizes s JOIN products p ON p.id = s.product_id
             WHERE p.brand <> " . $this->pdo->quote($brand) . ' LIMIT 1'
        );
        $row = $other === false ? false : $other->fetch();
        self::assertNotFalse($row);
        $expected = (new PricingService('none'))->netPrice((string) $row['offer_price'], 'percent', 30.0);
        self::assertSame($expected, number_format((float) $row['price'], 2, '.', ''));
    }

    /** @return list<array<string, mixed>> */
    private function dumpCatalog(): array
    {
        $stmt = $this->pdo->query(
            'SELECT p.sku, p.name, p.is_active, p.total_quantity, s.size_eu, s.quantity, s.price
             FROM products p LEFT JOIN product_sizes s ON s.product_id = p.id
             ORDER BY p.sku, s.size_eu'
        );

        /** @var list<array<string, mixed>> */
        return $stmt === false ? [] : $stmt->fetchAll();
    }
}
