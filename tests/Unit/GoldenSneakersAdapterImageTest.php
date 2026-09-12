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

    /** @param array<string, string> $extraRow */
    private function imageUrlFor(?string $base, ?string $file, array $extraRow = [], ?string $feedBaseUrl = null): ?string
    {
        $row = ['sku' => 'KJ8969', 'product_name' => 'Climacool 4D', 'brand_name' => 'Adidas',
            'size_eu' => '42', 'offer_price' => 50, 'available_quantity' => 3];
        if ($base !== null) {
            $row['image_full_url'] = $base;
        }
        if ($file !== null) {
            $row['image_name'] = $file;
        }
        $row = array_merge($row, $extraRow);
        file_put_contents($this->fixturePath, json_encode([$row]));
        $env = [
            'ROOT_PATH' => sys_get_temp_dir(),
            'FEED_SOURCE' => 'fixture',
            'FEED_FIXTURE_PATH' => $this->fixturePath,
        ];
        if ($feedBaseUrl !== null) {
            $env['FEED_BASE_URL'] = $feedBaseUrl;
        }
        $config = new Config($env);
        $rows = (new GoldenSneakersAdapter($config, new NullLogger()))->fetch();

        return $rows[0]['image_url'];
    }

    public function testNewFormatFullUrlIsNotDuplicated(): void
    {
        // formato reale post-cambio del fornitore (08/2026): image_full_url
        // è GIÀ l'URL completo del file; image_name è solo il nome. Il join
        // cieco produceva ".../x.png/x.png" → 404 su tutto il catalogo.
        self::assertSame(
            'https://media.goldensneakers.net/products/images/2913_KJ8969/raw/c67b5534062a.png',
            $this->imageUrlFor('https://media.goldensneakers.net/products/images/2913_KJ8969/raw/c67b5534062a.png', 'c67b5534062a.png'),
        );
    }

    public function testNewMediaSubdomainWithBaseFolderStillJoins(): void
    {
        // variante "cartella base" su nuovo host: il join resta corretto
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

    public function testRelativePathIsResolvedAgainstFeedBaseUrl(): void
    {
        // il fornitore manda, su una parte delle righe, un percorso relativo
        // senza host: senza risoluzione l'immagine spariva dal catalogo
        self::assertSame(
            'https://www.goldensneakers.net/images/IH6001/main/Screenshot_2026-08-24_at_12.25.46.png',
            $this->imageUrlFor('/images/IH6001/main/', 'Screenshot_2026-08-24_at_12.25.46.png'),
        );
    }

    public function testRelativeFullFilePathIsNotDuplicatedAfterResolution(): void
    {
        self::assertSame(
            'https://www.goldensneakers.net/products/images/1520_JI2626/raw/b086c2487cf4.png',
            $this->imageUrlFor('/products/images/1520_JI2626/raw/b086c2487cf4.png', 'b086c2487cf4.png'),
        );
    }

    public function testRelativePathWithoutLeadingSlashIsResolved(): void
    {
        self::assertSame(
            'https://www.goldensneakers.net/images/IH6001/main/foto.png',
            $this->imageUrlFor('images/IH6001/main/', 'foto.png'),
        );
    }

    public function testRelativePathUsesConfiguredFeedBaseUrl(): void
    {
        self::assertSame(
            'https://media.goldensneakers.net/images/IH6001/main/foto.png',
            $this->imageUrlFor('/images/IH6001/main/', 'foto.png', [], 'https://media.goldensneakers.net/'),
        );
    }

    public function testProtocolRelativeUrlGetsHttpsScheme(): void
    {
        self::assertSame(
            'https://media.goldensneakers.net/images/IH6001/main/foto.png',
            $this->imageUrlFor('//media.goldensneakers.net/images/IH6001/main/', 'foto.png'),
        );
    }

    public function testFallsBackToImageFieldWhenFullUrlIsEmpty(): void
    {
        self::assertSame(
            'https://www.goldensneakers.net/images/IH6001/main/foto.png',
            $this->imageUrlFor('', 'foto.png', ['image' => '/images/IH6001/main/']),
        );
    }

    public function testResolvedRelativePathStillHonoursDomainWhitelist(): void
    {
        // se FEED_BASE_URL puntasse altrove, la whitelist a valle deve reggere
        self::assertNull(
            $this->imageUrlFor('/images/IH6001/main/', 'foto.png', [], 'https://cdn.example.com'),
        );
    }

    public function testMissingFieldsGiveNull(): void
    {
        self::assertNull($this->imageUrlFor(null, null));
    }
}
