<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Controller\CatalogApiController;
use App\Middleware\RobotsMiddleware;
use App\Repository\ProductRepository;
use App\Repository\SyncLogRepository;
use App\Support\Config;
use App\Tests\Support\TestDb;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * API catalogo (docs/10): solo con il token, solo prodotti del feed, prezzo
 * netto di listino e MAI offer_price.
 */
final class CatalogApiTest extends TestCase
{
    private const TOKEN = 'test-token-0123456789abcdef';

    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = TestDb::create();
        // costi riconoscibili: non devono MAI uscire dall'API
        TestDb::seedProduct($this->pdo, 'NK1001', 'Nike Dunk Low', 'Nike', [
            ['size_eu' => '42', 'size_us' => '8.5', 'quantity' => 5, 'offer_price' => '61.11', 'price' => '123'],
            ['size_eu' => '36 2/3', 'size_us' => '4.5', 'quantity' => 0, 'offer_price' => '61.12', 'price' => '120'],
        ]);
        TestDb::seedProduct($this->pdo, 'LOCAL-01', 'Puma Suede in sede', 'Puma', [
            ['size_eu' => '44', 'size_us' => '10', 'quantity' => 2, 'offer_price' => '33.33', 'price' => '87.65'],
        ], source: 'custom');
        $gone = TestDb::seedProduct($this->pdo, 'OLD-99', 'Prodotto sparito', 'Nike', [
            ['size_eu' => '40', 'quantity' => 1, 'offer_price' => '44.44', 'price' => '99'],
        ]);
        $this->pdo->prepare('UPDATE products SET is_active = 0 WHERE id = ?')->execute([$gone]);
        $this->pdo->exec("INSERT INTO sync_logs (started_at, finished_at, status) VALUES ('2026-11-17 10:00:00', '2026-11-17 10:01:00', 'ok')");
    }

    private function call(string $configuredToken, ?string $auth, ?string $ifNoneMatch = null): ResponseInterface
    {
        $controller = new CatalogApiController(
            new ProductRepository($this->pdo),
            new SyncLogRepository($this->pdo),
            new Config(['CATALOG_API_TOKEN' => $configuredToken]),
        );
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/catalogo');
        if ($auth !== null) {
            $request = $request->withHeader('Authorization', $auth);
        }
        if ($ifNoneMatch !== null) {
            $request = $request->withHeader('If-None-Match', $ifNoneMatch);
        }

        return $controller->catalog($request, new Response());
    }

    public function testDisabledWithoutConfiguredToken(): void
    {
        self::assertSame(404, $this->call('', 'Bearer anything')->getStatusCode());
    }

    public function testRejectsMissingOrWrongToken(): void
    {
        foreach ([null, 'Bearer wrong', self::TOKEN, 'Basic ' . base64_encode('a:b')] as $auth) {
            $response = $this->call(self::TOKEN, $auth);
            self::assertSame(401, $response->getStatusCode());
            self::assertStringNotContainsString('Nike', (string) $response->getBody());
        }
    }

    public function testReturnsFeedProductsWithListPriceOnly(): void
    {
        $response = $this->call(self::TOKEN, 'Bearer ' . self::TOKEN);
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('noindex', $response->getHeaderLine('X-Robots-Tag'));

        /** @var array{count: int, vat_included: bool, synced_at: string, products: list<array<string, mixed>>} $data */
        $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $data['count'], 'solo il feed attivo: niente prodotti propri né disattivati');
        self::assertFalse($data['vat_included']);
        self::assertSame('2026-11-17T10:01:00+00:00', $data['synced_at']);

        $product = $data['products'][0];
        self::assertSame('NK1001', $product['sku']);
        self::assertSame('120.00', $product['price_from']);
        self::assertSame(5, $product['total_quantity']);
        self::assertSame(
            [
                ['size_eu' => '36 2/3', 'size_us' => '4.5', 'barcode' => 'BC36 2/3', 'quantity' => 0, 'price' => '120.00'],
                ['size_eu' => '42', 'size_us' => '8.5', 'barcode' => 'BC42', 'quantity' => 5, 'price' => '123.00'],
            ],
            $product['sizes'],
        );

        // Regola d'oro n.1: né il valore né la chiave del costo fornitore
        foreach (['61.11', '61.12', '33.33', '44.44', 'offer', 'supplier'] as $secret) {
            self::assertStringNotContainsString($secret, $body);
        }
        self::assertStringNotContainsString('LOCAL-01', $body);
        self::assertStringNotContainsString('OLD-99', $body);
    }

    public function testEtagGivesNotModifiedUntilTheListChanges(): void
    {
        $first = $this->call(self::TOKEN, 'Bearer ' . self::TOKEN);
        $etag = $first->getHeaderLine('ETag');
        self::assertNotSame('', $etag);

        $again = $this->call(self::TOKEN, 'Bearer ' . self::TOKEN, $etag);
        self::assertSame(304, $again->getStatusCode());
        self::assertSame('', (string) $again->getBody());

        // reprice (es. margine cambiato da /admin/margini): nuovo ETag, nuovi dati
        $this->pdo->exec("UPDATE product_sizes SET price = 130 WHERE size_eu = '42'");
        $changed = $this->call(self::TOKEN, 'Bearer ' . self::TOKEN, $etag);
        self::assertSame(200, $changed->getStatusCode());
        self::assertStringContainsString('"130.00"', (string) $changed->getBody());
    }

    public function testOnlyPublicPresentationPagesAreIndexable(): void
    {
        foreach (['/', '/spedizioni', '/come-ordinare', '/richiedi-accesso', '/spedizioni/'] as $path) {
            self::assertTrue(RobotsMiddleware::isIndexable($path), $path);
        }
        foreach (['/vetrina', '/catalogo', '/admin', '/api/v1/catalogo', '/login', '/carrello', '/export.xlsx'] as $path) {
            self::assertFalse(RobotsMiddleware::isIndexable($path), $path);
        }
    }
}
