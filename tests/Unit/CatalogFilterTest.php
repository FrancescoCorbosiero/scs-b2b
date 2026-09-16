<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Repository\ProductRepository;
use App\Tests\Support\TestDb;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Filtri di esplorazione del catalogo (docs/06 § Catalogo): taglia, solo
 * disponibili e faccette taglia che alimentano il pannello filtri.
 */
final class CatalogFilterTest extends TestCase
{
    private const FILTERS = [
        'q' => '', 'brand' => '', 'availability' => '', 'recommended' => false,
        'price_min' => null, 'price_max' => null, 'sort' => 'rilevanza',
        'sizes' => [], 'in_stock' => false, 'size_categories' => [],
    ];

    private PDO $pdo;
    private ProductRepository $products;

    protected function setUp(): void
    {
        $this->pdo = TestDb::create();
        TestDb::seedProduct($this->pdo, 'NK1001', 'Nike Dunk Low', 'Nike', [
            ['size_eu' => '42', 'quantity' => 5, 'price' => '100.00'],
            ['size_eu' => '43', 'quantity' => 2, 'price' => '100.00'],
        ]);
        TestDb::seedProduct($this->pdo, 'AD2001', 'adidas Samba', 'Adidas', [
            ['size_eu' => '43', 'quantity' => 0, 'price' => '90.00'],
            ['size_eu' => '44', 'quantity' => 7, 'price' => '90.00'],
        ]);
        TestDb::seedProduct($this->pdo, 'PM5001', 'Puma Suede', 'Puma', [
            ['size_eu' => '42', 'quantity' => 0, 'price' => '80.00'],
        ]);
        $this->products = new ProductRepository($this->pdo);
    }

    /** @param array<string, mixed> $overrides */
    private function skus(array $overrides): array
    {
        $result = $this->products->search($overrides + self::FILTERS, 1, 24, 60, 20);

        return array_map(static fn (array $i): string => (string) $i['sku'], $result['items']);
    }

    public function testSizeFilterKeepsOnlyProductsWithStockInThatSize(): void
    {
        // PM5001 ha la 42 ma esaurita: non deve comparire
        self::assertSame(['NK1001'], $this->skus(['sizes' => ['42']]));
        self::assertSame(['AD2001'], $this->skus(['sizes' => ['44']]));
    }

    public function testSizeFilterIsAnOrAcrossSizes(): void
    {
        $skus = $this->skus(['sizes' => ['42', '44']]);
        sort($skus);

        self::assertSame(['AD2001', 'NK1001'], $skus, 'Basta lo stock in una delle taglie scelte');
    }

    public function testSizeFilterIgnoresOutOfStockSizes(): void
    {
        // la 43 è in stock solo su NK1001 (AD2001 ce l'ha a 0)
        self::assertSame(['NK1001'], $this->skus(['sizes' => ['43']]));
    }

    public function testInStockFilterDropsProductsWithoutStock(): void
    {
        self::assertContains('PM5001', $this->skus([]), 'Senza filtro il prodotto esaurito resta visibile');
        self::assertNotContains('PM5001', $this->skus(['in_stock' => true]));
    }

    public function testSizeFacetsCountProductsAndSortNumerically(): void
    {
        $facets = $this->products->activeSizesWithCounts();
        $labels = array_map(static fn (array $f): string => $f['size_eu'], $facets);

        self::assertSame(['42', '43', '44'], $labels, 'Solo taglie con stock, in ordine numerico');
        $counts = array_combine($labels, array_map(static fn (array $f): int => $f['products'], $facets));
        self::assertSame(1, $counts['42'], 'PM5001 ha la 42 esaurita: non si conta');
        self::assertSame(1, $counts['43']);
        self::assertSame(1, $counts['44']);
    }

    public function testSizeFacetsSortHalfSizesCorrectly(): void
    {
        TestDb::seedProduct($this->pdo, 'NB3001', 'New Balance 550', 'New Balance', [
            ['size_eu' => '40.5', 'quantity' => 3, 'price' => '110.00'],
            ['size_eu' => '9', 'quantity' => 1, 'price' => '110.00'],
        ]);

        $labels = array_map(
            static fn (array $f): string => $f['size_eu'],
            (new ProductRepository($this->pdo))->activeSizesWithCounts(),
        );

        self::assertSame(['9', '40.5', '42', '43', '44'], $labels, 'Ordine numerico, non alfabetico');
    }

    public function testSizeFilterCombinesWithOtherFilters(): void
    {
        self::assertSame([], $this->skus(['sizes' => ['42'], 'brand' => 'Adidas']));
        self::assertSame(['NK1001'], $this->skus(['sizes' => ['42'], 'brand' => 'Nike']));
    }

    // ── Categoria di taglia (normali / GS / PS) ──────────────────────

    private function seedKidsProducts(): void
    {
        TestDb::seedProduct($this->pdo, 'NK9001', "Nike Dunk Low 'Panda' (GS)", 'Nike', [
            ['size_eu' => '37.5', 'quantity' => 4, 'price' => '70.00'],
        ]);
        TestDb::seedProduct($this->pdo, 'NK9002', "Nike Dunk Low 'Panda' (PS)", 'Nike', [
            ['size_eu' => '31', 'quantity' => 2, 'price' => '55.00'],
        ]);
    }

    public function testSizeCategoryFilterKeepsOnlyTheChosenCategories(): void
    {
        $this->seedKidsProducts();

        self::assertSame(['NK9001'], $this->skus(['size_categories' => ['gs']]));
        self::assertSame(['NK9002'], $this->skus(['size_categories' => ['ps']]));

        $kids = $this->skus(['size_categories' => ['gs', 'ps']]);
        sort($kids);
        self::assertSame(['NK9001', 'NK9002'], $kids, 'Più categorie = OR');

        $adults = $this->skus(['size_categories' => ['adult']]);
        sort($adults);
        self::assertSame(['AD2001', 'NK1001', 'PM5001'], $adults);
    }

    public function testSizeCategoryFilterCombinesWithOtherFilters(): void
    {
        $this->seedKidsProducts();

        self::assertSame(['NK9001'], $this->skus(['size_categories' => ['gs'], 'brand' => 'Nike']));
        self::assertSame([], $this->skus(['size_categories' => ['gs'], 'brand' => 'Adidas']));
        self::assertSame([], $this->skus(['size_categories' => ['ps'], 'sizes' => ['42']]));
    }

    public function testWithoutCategoryFilterNothingIsHidden(): void
    {
        $this->seedKidsProducts();

        self::assertCount(5, $this->skus([]));
        self::assertCount(5, $this->skus(['size_categories' => ['adult', 'gs', 'ps']]), 'Tutte selezionate = nessun filtro');
    }

    public function testCategoryFacetsCountActiveProducts(): void
    {
        $this->seedKidsProducts();

        self::assertSame(
            ['adult' => 3, 'gs' => 1, 'ps' => 1],
            $this->products->activeSizeCategoryCounts(),
        );
    }
}
