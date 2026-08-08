<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Adapter\GoldenSneakersAdapter;
use App\Support\Config;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Whitelist immagini del feed: dominio del fornitore e QUALSIASI suo
 * sottodominio (il fornitore ha già spostato le immagini www → media),
 * mai domini terzi o lookalike.
 */
final class GoldenSneakersAdapterImageTest extends TestCase
{
    private string $fixturePath;

    protected function setUp(): void
    {
        $this->fixturePath = tempnam(sys_get_temp_dir(), 'feed');
    }

    protected function tearDown(): void
    {
        @unlink($this->fixturePath);
    }

    private function imageUrlFor(?string $base, ?string $file): ?string
    {
        $row = ['sku' => 'KJ8969', 'product_name' => 'Climacool 4D', 'brand_name' => 'Adidas',
            'size_eu' => '42', 'offer_price' => 50, 'available_quantity' => 3];
        if ($base !== null) {
            $row['image_full_url'] = $base;
        }
        if ($file !== null) {
            $row['image_name'] = $file;
        }
        file_put_contents($this->fixturePath, json_encode([$row]));
        $config = new Config([
            'ROOT_PATH' => sys_get_temp_dir(),
            'FEED_SOURCE' => 'fixture',
            'FEED_FIXTURE_PATH' => $this->fixturePath,
        ]);
        $rows = (new GoldenSneakersAdapter($config, new NullLogger()))->fetch();

        return $rows[0]['image_url'];
    }

    public function testNewMediaSubdomainIsAccepted(): void
    {
        // formato reale post-cambio del fornitore (08/2026)
        self::assertSame(
            'https://media.goldensneakers.net/products/images/2913_KJ8969/raw/c67b5534062a.png',
            $this->imageUrlFor('https://media.goldensneakers.net/products/images/2913_KJ8969/raw/', 'c67b5534062a.png'),
        );
    }

    public function testLegacyWwwHostStillAccepted(): void
    {
        self::assertSame(
            'https://www.goldensneakers.net/images/KJ8969/main/foto.png',
            $this->imageUrlFor('https://www.goldensneakers.net/images/KJ8969/main/', 'foto.png'),
        );
    }

    public function testApexDomainAccepted(): void
    {
        self::assertNotNull($this->imageUrlFor('https://goldensneakers.net/img/', 'foto.png'));
    }

    public function testThirdPartyAndLookalikeHostsRejected(): void
    {
        self::assertNull($this->imageUrlFor('https://cdn.example.com/img/', 'foto.png'));
        // lookalike: finisce in "goldensneakers.net" ma NON è un sottodominio
        self::assertNull($this->imageUrlFor('https://evilgoldensneakers.net/img/', 'foto.png'));
    }

    public function testPlainHttpRejected(): void
    {
        self::assertNull($this->imageUrlFor('http://media.goldensneakers.net/img/', 'foto.png'));
    }

    public function testMissingFieldsGiveNull(): void
    {
        self::assertNull($this->imageUrlFor(null, null));
    }
}
