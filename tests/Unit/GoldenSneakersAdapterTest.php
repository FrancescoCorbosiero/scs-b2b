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
        $rows = $this->adapterFor('fixtures/goldensneakers-sample.json')->fetch();

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
        $rows = $this->adapterFor('fixtures/goldensneakers-sample.json')->fetch();
        $fractional = array_values(array_filter($rows, static fn (array $r): bool => $r['size_eu'] === '36 2/3'));

        self::assertCount(1, $fractional, 'La taglia "36 2/3" deve restare una stringa intatta');
    }

    public function testDevFixtureIsValidAndRicher(): void
    {
        $rows = $this->adapterFor('fixtures/goldensneakers-dev.json')->fetch();
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
            $rows = $this->adapterFor($path)->fetch();
            self::assertNull($rows[0]['image_url'], 'Host non in whitelist → immagine scartata');
        } finally {
            @unlink($path);
        }
    }
}
