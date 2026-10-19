<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Adapter\FeedException;
use App\Adapter\GoldenSneakersAdapter;
use App\Support\Config;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class GoldenSneakersAdapterTest extends TestCase
{
    private function adapterFor(string $fixturePath): GoldenSneakersAdapter
    {
        $config = new Config([
            'ROOT_PATH' => dirname(__DIR__, 2),
            'FEED_SOURCE' => 'fixture',
            'FEED_FIXTURE_PATH' => $fixturePath,
        ]);

        return new GoldenSneakersAdapter($config, new NullLogger());
    }

    public function testParsesRealSampleFixture(): void
    {
        $rows = $this->adapterFor('fixtures/goldensneakers-sample.json')->fetch()['rows'];

        self::assertCount(4, $rows);

        $skus = array_unique(array_column($rows, 'sku'));
        self::assertSame(['JS3801', 'JP8763'], array_values($skus));

        $first = $rows[0];
        self::assertSame('JS3801', $first['sku']);
        self::assertSame("adidas Gazelle Indoor J 'Better Scarlet'", $first['name']);
        self::assertSame('Adidas', $first['brand']);
        self::assertSame('35.5', $first['size_eu']);
        self::assertSame('47.00', $first['offer_price']);
        self::assertSame(1, $first['quantity']);
        self::assertSame(
            'https://www.goldensneakers.net/images/JS3801/main/Screenshot_2025-10-16_at_17.43.27.png',
            $first['image_url'],
        );
    }

    public function testFractionalSizesStayStrings(): void
    {
        $rows = $this->adapterFor('fixtures/goldensneakers-sample.json')->fetch()['rows'];
        $fractional = array_values(array_filter($rows, static fn (array $r): bool => $r['size_eu'] === '36 2/3'));

        self::assertCount(1, $fractional, 'La taglia "36 2/3" deve restare una stringa intatta');
    }

    public function testDevFixtureIsValidAndRicher(): void
    {
        $rows = $this->adapterFor('fixtures/goldensneakers-dev.json')->fetch()['rows'];
        $skus = array_unique(array_column($rows, 'sku'));
        $brands = array_unique(array_column($rows, 'brand'));

        self::assertGreaterThanOrEqual(10, count($skus), 'La fixture dev deve avere almeno 10 SKU');
        self::assertGreaterThanOrEqual(4, count($brands), 'Servono più brand per testare i filtri');
    }

    public function testMissingFixtureThrows(): void
    {
        $this->expectException(FeedException::class);
        $this->adapterFor('fixtures/does-not-exist.json')->fetch();
    }

    public function testInvalidRowAbortsWholeFetch(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'feed') . '.json';
        file_put_contents($path, json_encode([
            ['sku' => 'OK001', 'product_name' => 'Valido', 'size_eu' => '42', 'offer_price' => 50, 'available_quantity' => 1],
            ['sku' => 'KO001', 'product_name' => 'Senza prezzo', 'size_eu' => '43', 'available_quantity' => 1],
        ]));

        try {
            $this->expectException(FeedException::class);
            $this->adapterFor($path)->fetch();
        } finally {
            @unlink($path);
        }
    }

    /** @param list<array<string, mixed>> $rows */
    private function fixtureWith(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'feed') . '.json';
        file_put_contents($path, json_encode($rows));

        return $path;
    }

    /** @return list<array<string, mixed>> */
    private static function validRows(int $count): array
    {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = ['sku' => 'OK' . $i, 'product_name' => "Valido {$i}", 'size_eu' => '42', 'offer_price' => 50, 'available_quantity' => 1];
        }

        return $rows;
    }

    public function testFewInvalidRowsAreSkippedAndReported(): void
    {
        // un prodotto sporco presso il fornitore non deve bloccare tutto il catalogo
        $rows = self::validRows(30);
        $rows[] = ['sku' => 'KO001', 'product_name' => 'Senza prezzo', 'size_eu' => '43', 'available_quantity' => 1];
        $path = $this->fixtureWith($rows);

        try {
            $feed = $this->adapterFor($path)->fetch();
        } finally {
            @unlink($path);
        }

        self::assertCount(30, $feed['rows']);
        self::assertNotContains('KO001', array_column($feed['rows'], 'sku'));
        self::assertCount(1, $feed['warnings']);
        self::assertStringContainsString('scartate perché non valide (1 su 31)', $feed['warnings'][0]);
        self::assertStringContainsString('sku KO001', $feed['warnings'][0]);
    }

    public function testTooManyInvalidRowsAbortWholeFetch(): void
    {
        // oltre il 5% di righe invalide il formato è cambiato: meglio non toccare nulla
        $rows = self::validRows(10);
        $rows[] = ['sku' => 'KO001', 'product_name' => 'Senza prezzo', 'size_eu' => '43', 'available_quantity' => 1];
        $path = $this->fixtureWith($rows);

        try {
            $this->expectException(FeedException::class);
            $this->expectExceptionMessage('1 su 11');
            $this->adapterFor($path)->fetch();
        } finally {
            @unlink($path);
        }
    }

    public function testIntegralDecimalQuantityIsAccepted(): void
    {
        $adapter = $this->adapterFor('fixtures/goldensneakers-sample.json');
        $base = ['sku' => 'Q1', 'product_name' => 'Quantità decimale', 'size_eu' => '42', 'offer_price' => 50];

        self::assertSame(3, $adapter->normalizeRow($base + ['available_quantity' => 3.0], 0)['quantity']);
        self::assertSame(4, $adapter->normalizeRow($base + ['available_quantity' => '4.0'], 1)['quantity']);

        $this->expectException(FeedException::class);
        $adapter->normalizeRow($base + ['available_quantity' => 2.5], 2);
    }

    public function testImageUrlOutsideWhitelistIsDropped(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'feed') . '.json';
        file_put_contents($path, json_encode([[
            'sku' => 'X1', 'product_name' => 'Prodotto', 'size_eu' => '42',
            'offer_price' => 50, 'available_quantity' => 1,
            'image_full_url' => 'https://evil.example.com/images/X1/',
            'image_name' => 'x.png',
        ]]));

        try {
            $rows = $this->adapterFor($path)->fetch()['rows'];
            self::assertNull($rows[0]['image_url'], 'Host non in whitelist → immagine scartata');
        } finally {
            @unlink($path);
        }
    }
}
