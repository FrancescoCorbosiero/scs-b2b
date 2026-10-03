<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Controller\CatalogController;
use App\Repository\AccountRequestRepository;
use App\Repository\ProductRepository;
use App\Repository\UserRepository;
use App\Repository\VatRateRepository;
use App\Support\Config;
use App\Support\Images;
use App\Support\Lang;
use App\Support\Session;
use App\Support\TwigExtension;
use App\Support\View;
use App\Support\XlsxWriter;
use App\Tests\Support\TestDb;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Vetrina pubblica /vetrina (docs/06): lo stesso catalogo SENZA prezzi e
 * senza login. Si renderizzano i template veri: nessun prezzo deve arrivare
 * al client, né nell'HTML né nei dati della scheda rapida né nei frammenti
 * "Carica altri", e filtri/ordinamenti per prezzo vanno ignorati lato server.
 */
final class PublicCatalogTest extends TestCase
{
    private PDO $pdo;
    private CatalogController $controller;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->pdo = TestDb::create();
        // prezzi riconoscibili: non devono MAI comparire nella vetrina
        TestDb::seedProduct($this->pdo, 'NK1001', 'Nike Dunk Low', 'Nike', [
            ['size_eu' => '42', 'size_us' => '8.5', 'quantity' => 5, 'offer_price' => '61.11', 'price' => '123.45'],
        ]);
        TestDb::seedProduct($this->pdo, 'AD2002', 'adidas Samba', 'Adidas', [
            ['size_eu' => '43', 'size_us' => '9.5', 'quantity' => 3, 'offer_price' => '72.22', 'price' => '234.56'],
        ]);
        TestDb::seedProduct($this->pdo, 'LOCAL-01', 'Puma Suede in sede', 'Puma', [
            ['size_eu' => '44', 'size_us' => '10', 'quantity' => 2, 'offer_price' => '33.33', 'price' => '87.65'],
        ], source: 'custom');

        $root = dirname(__DIR__, 2);
        $config = new Config(['ROOT_PATH' => $root, 'PRODUCTS_PER_PAGE' => '24']);
        $lang = new Lang($root);
        $twig = new Environment(new FilesystemLoader($root . '/templates'), ['autoescape' => 'html', 'strict_variables' => false]);
        $twig->addExtension(new TwigExtension($lang));
        $session = new Session($config);
        $view = new View(
            $twig,
            $session,
            $config,
            $lang,
            new VatRateRepository($this->pdo),
            new UserRepository($this->pdo),
            new AccountRequestRepository($this->pdo),
            new Images($config),
        );
        $this->controller = new CatalogController($view, new ProductRepository($this->pdo), $config, new XlsxWriter(), $lang, $session);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        unset($_SERVER['REQUEST_URI']);
    }

    /** @param array<string, mixed> $query */
    private function showcase(array $query = []): ResponseInterface
    {
        $_SERVER['REQUEST_URI'] = '/vetrina';
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/vetrina')->withQueryParams($query);

        return $this->controller->showcase($request, new Response());
    }

    /** @param array<string, mixed> $query */
    private function reserved(array $query = []): ResponseInterface
    {
        $_SERVER['REQUEST_URI'] = '/catalogo';
        $_SESSION['auth_catalog'] = true;
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/catalogo')->withQueryParams($query);

        return $this->controller->index($request, new Response());
    }

    private static function assertNoPrice(string $html): void
    {
        foreach (['123,45', '123.45', '234,56', '234.56', '87,65', '87.65', '61.11', '72.22', '33.33'] as $amount) {
            self::assertStringNotContainsString($amount, $html, "prezzo {$amount} esposto nella vetrina");
        }
        self::assertStringNotContainsString('€', $html);
        self::assertStringNotContainsString('price', $html, 'nemmeno le chiavi dei prezzi nel JSON della scheda rapida');
    }

    public function testShowcaseShowsTheCatalogWithoutAnyPrice(): void
    {
        $response = $this->showcase();
        $html = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Nike Dunk Low', $html);
        self::assertStringContainsString('adidas Samba', $html);
        self::assertStringContainsString('noindex', $html, 'pubblica ma non indicizzabile');
        self::assertStringNotContainsString('/export.xlsx', $html);
        self::assertStringNotContainsString('/carrello/aggiungi', $html);
        self::assertStringNotContainsString('name="prezzo_min"', $html, 'niente filtro prezzo');
        self::assertNoPrice($html);
    }

    public function testLoadMoreFragmentsHaveNoPricesEither(): void
    {
        $html = (string) $this->showcase(['fragment' => '1', 'page' => '1'])->getBody();

        self::assertStringContainsString('Nike Dunk Low', $html);
        self::assertNoPrice($html);
    }

    public function testPriceFiltersAndSortsAreIgnoredServerSide(): void
    {
        // nel catalogo riservato il filtro funziona…
        $reserved = (string) $this->reserved(['prezzo_min' => '200'])->getBody();
        self::assertStringNotContainsString('Nike Dunk Low', $reserved);
        self::assertStringContainsString('adidas Samba', $reserved);

        // …nella vetrina no: per bisezione si risalirebbe ai prezzi
        $_SESSION = [];
        $showcase = (string) $this->showcase(['prezzo_min' => '200', 'prezzo_max' => '300'])->getBody();
        self::assertStringContainsString('Nike Dunk Low', $showcase);
        self::assertStringContainsString('adidas Samba', $showcase);

        // ordinamento per prezzo ignorato: resta quello di default (il
        // confronto è col catalogo riservato, così non dipende dalla
        // collation del DB)
        $nikeFirst = static fn (string $html): bool => strpos($html, 'Nike Dunk Low') < strpos($html, 'adidas Samba');
        $default = $nikeFirst((string) $this->reserved()->getBody());
        self::assertNotSame($default, $nikeFirst((string) $this->reserved(['ordina' => 'prezzo_desc'])->getBody()), 'precondizione: i due ordinamenti differiscono');
        $_SESSION = [];
        $sorted = (string) $this->showcase(['ordina' => 'prezzo_desc'])->getBody();
        self::assertSame($default, $nikeFirst($sorted));
        self::assertStringNotContainsString('value="prezzo_desc"', $sorted);
    }

    public function testVisitorsWithCatalogAccessGetThePricedCatalog(): void
    {
        $_SESSION['auth_catalog'] = true;

        $response = $this->showcase(['brand' => 'Nike']);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/catalogo?brand=Nike', $response->getHeaderLine('Location'));
    }

    public function testReservedCatalogStillShowsNetPrices(): void
    {
        $html = (string) $this->reserved()->getBody();

        self::assertStringContainsString('123,45 €', $html);
        self::assertStringContainsString('/export.xlsx', $html);
    }

    public function testOwnProductsLiveInTheirOwnSection(): void
    {
        $main = (string) $this->showcase()->getBody();
        self::assertStringNotContainsString('Puma Suede in sede', $main, 'mai mescolati ai prodotti del feed');
        self::assertStringContainsString('?sezione=sede', $main, 'la scheda della sezione c\'è');

        $own = (string) $this->showcase(['sezione' => 'sede'])->getBody();
        self::assertStringContainsString('Puma Suede in sede', $own);
        self::assertStringNotContainsString('Nike Dunk Low', $own);
        self::assertStringContainsString('name="sezione" value="sede"', $own, 'i filtri restano nella sezione');
        self::assertNoPrice($own);
    }
}
