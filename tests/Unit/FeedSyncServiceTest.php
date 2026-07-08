<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Adapter\GoldenSneakersAdapter;
use App\Repository\ProductRepository;
use App\Repository\SyncLogRepository;
use App\Service\FeedSyncService;
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

    private function serviceFor(string $fixturePath): FeedSyncService
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
            new PricingService(30, 25, 20, 22, 'whole'),
            $config,
            new NullLogger(),
        );
    }

    private function realFixture(): string
    {
        return dirname(__DIR__, 2) . '/fixtures/goldensneakers-dev.json';
    }

    public function testSyncFromFixturePopulatesProductsWithPlanPrices(): void
    {
        $result = $this->serviceFor($this->realFixture())->run();

        self::assertSame('ok', $result['status']);
        self::assertSame(63, $result['rows_read']);
        self::assertSame(12, $result['products_created']);
        self::assertSame(0, $result['products_updated']);

        // prezzi del sample reale: offer 47, rounding whole → 75 / 72 / 69
        // (normalizzati a 2 decimali: SQLite non conserva gli zeri finali dei DECIMAL)
        $stmt = $this->pdo->query(
            "SELECT s.price_base, s.price_pro, s.price_max FROM product_sizes s
             JOIN products p ON p.id = s.product_id WHERE p.sku = 'JS3801' LIMIT 1"
        );
        $prices = $stmt === false ? [] : $stmt->fetch();
        self::assertSame(
            ['75.00', '72.00', '69.00'],
            array_values(array_map(static fn ($v): string => number_format((float) $v, 2, '.', ''), (array) $prices)),
        );

        // denormalizzazioni: total_quantity e min price
        $stmt = $this->pdo->query("SELECT total_quantity, min_price_base FROM products WHERE sku = 'JS3801'");
        $product = $stmt === false ? [] : $stmt->fetch();
        self::assertSame(3, (int) $product['total_quantity']);
        self::assertSame('75.00', number_format((float) $product['min_price_base'], 2, '.', ''));

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

    public function testRepriceRecalculatesFromStoredOfferPrice(): void
    {
        $this->serviceFor($this->realFixture())->run();

        // nuove percentuali: markup base 50, rounding none → 47 × 1,50 × 1,22 = 86,01
        $config = new Config([
            'ROOT_PATH' => $this->workDir,
            'FEED_SOURCE' => 'fixture',
            'FEED_FIXTURE_PATH' => $this->realFixture(),
        ]);
        $service = new FeedSyncService(
            $this->pdo,
            new GoldenSneakersAdapter($config, new NullLogger()),
            new ProductRepository($this->pdo),
            new SyncLogRepository($this->pdo),
            new PricingService(50, 25, 20, 22, 'none'),
            $config,
            new NullLogger(),
        );
        $result = $service->run(repriceOnly: true);

        self::assertSame('ok', $result['status']);
        $stmt = $this->pdo->query(
            "SELECT s.price_base FROM product_sizes s JOIN products p ON p.id = s.product_id WHERE p.sku = 'JS3801' LIMIT 1"
        );
        self::assertSame('86.01', number_format((float) ($stmt === false ? 0 : $stmt->fetchColumn()), 2, '.', ''));
    }

    /** @return list<array<string, mixed>> */
    private function dumpCatalog(): array
    {
        $stmt = $this->pdo->query(
            'SELECT p.sku, p.name, p.is_active, p.total_quantity, s.size_eu, s.quantity, s.price_base, s.price_pro, s.price_max
             FROM products p LEFT JOIN product_sizes s ON s.product_id = p.id
             ORDER BY p.sku, s.size_eu'
        );

        /** @var list<array<string, mixed>> */
        return $stmt === false ? [] : $stmt->fetchAll();
    }
}
