<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Adapter\FeedException;
use App\Adapter\GoldenSneakersAdapter;
use App\Support\Config;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Feed in modalità live con transport HTTP stubbato: formati accettati,
 * paginazione, JSON riparato e soprattutto la diagnostica delle risposte che
 * NON sono il feed (pagina di login, JSON d'errore, JSON malformato): il
 * messaggio finisce in /admin/sync e deve dire cosa ha risposto il fornitore,
 * mai il token.
 */
final class GoldenSneakersAdapterLiveTest extends TestCase
{
    private const TOKEN = 'tok-segreto-feed';

    /** @var list<array{url: string, headers: list<string>}> */
    private array $calls = [];

    /** @param list<array<string, mixed>> $responses */
    private function adapter(array $responses): GoldenSneakersAdapter
    {
        $this->calls = [];
        $queue = $responses;
        $transport = function (string $url, array $headers, int $timeout) use (&$queue): array {
            $this->calls[] = ['url' => $url, 'headers' => $headers];
            $next = array_shift($queue);
            self::assertNotNull($next, 'chiamata HTTP inattesa: coda risposte esaurita');

            return $next;
        };

        return new GoldenSneakersAdapter(new Config([
            'ROOT_PATH' => dirname(__DIR__, 2),
            'FEED_SOURCE' => 'live',
            'FEED_BEARER_TOKEN' => self::TOKEN,
            'FEED_BASE_URL' => 'https://www.goldensneakers.net',
            'FEED_ENDPOINT' => '/api/assortment-flat/',
        ]), new NullLogger(), $transport);
    }

    /** @return array<string, mixed> riga nel formato reale di assortment-flat */
    private static function row(int $id, string $sku = 'JS3801', string $sizeEu = '42'): array
    {
        return [
            'id' => $id,
            'sku' => $sku,
            'product_name' => "Prodotto {$sku}",
            'brand_name' => 'Adidas',
            'size_mapper_name' => 'Adidas MENS',
            'barcode' => '406790763' . $id,
            'size_us' => '8.5',
            'size_eu' => $sizeEu,
            'offer_price' => 47,
            'presented_price' => 74.54,
            'available_quantity' => 2,
            'image' => '/images/X/main/',
            'image_full_url' => 'https://www.goldensneakers.net/images/X/main/',
            'image_name' => 'x.png',
        ];
    }

    /** @return list<array<string, mixed>> righe valide, SKU+taglia distinti */
    private static function rows(int $count): array
    {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = self::row($i, 'SKU' . intdiv($i, 5), (string) (36 + $i % 5));
        }

        return $rows;
    }

    /**
     * @param mixed $payload
     * @return array<string, mixed>
     */
    private static function jsonResponse(mixed $payload, int $status = 200): array
    {
        return ['status' => $status, 'body' => (string) json_encode($payload), 'content_type' => 'application/json'];
    }

    private function feedError(GoldenSneakersAdapter $adapter): string
    {
        try {
            $adapter->fetch();
        } catch (FeedException $e) {
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage(), 'Il token non deve mai finire nei messaggi');

            return $e->getMessage();
        }
        self::fail('FeedException attesa');
    }

    public function testFlatListIsReadWithBearerToken(): void
    {
        $feed = $this->adapter([self::jsonResponse([self::row(1), self::row(2, 'JS3801', '43')])])->fetch();

        self::assertCount(2, $feed['rows']);
        self::assertSame([], $feed['warnings']);
        self::assertSame('https://www.goldensneakers.net/api/assortment-flat/', $this->calls[0]['url']);
        self::assertContains('Authorization: Bearer ' . self::TOKEN, $this->calls[0]['headers']);
    }

    public function testDrfPaginationStaysOnTheFeedHostOverHttps(): void
    {
        $adapter = $this->adapter([
            // DRF dietro proxy genera spesso link http:// anche su una chiamata https
            self::jsonResponse(['count' => 3, 'next' => 'http://www.goldensneakers.net/api/assortment-flat/?page=2',
                'previous' => null, 'results' => [self::row(1), self::row(2, 'JS3801', '43')]]),
            self::jsonResponse(['count' => 3, 'next' => '/api/assortment-flat/?page=3', 'results' => [self::row(3, 'JS3801', '44')]]),
            self::jsonResponse(['count' => 3, 'next' => null, 'results' => []]),
        ]);

        self::assertCount(3, $adapter->fetch()['rows']);
        self::assertSame('https://www.goldensneakers.net/api/assortment-flat/?page=2', $this->calls[1]['url']);
        self::assertSame('https://www.goldensneakers.net/api/assortment-flat/?page=3', $this->calls[2]['url']);
    }

    public function testPaginationTowardsAnotherHostIsRefused(): void
    {
        $message = $this->feedError($this->adapter([
            self::jsonResponse(['next' => 'https://evil.example.com/api/assortment-flat/?page=2', 'results' => [self::row(1)]]),
        ]));

        self::assertStringContainsString('host diverso', $message);
        self::assertCount(1, $this->calls, 'Il token non deve partire verso un host diverso da FEED_BASE_URL');
    }

    public function testLoginPageAfterRedirectPointsToTheToken(): void
    {
        $message = $this->feedError($this->adapter([[
            'status' => 200,
            'content_type' => 'text/html; charset=utf-8',
            'url' => 'https://www.goldensneakers.net/accounts/login/?next=/api/assortment-flat/',
            'redirects' => 1,
            'body' => "<!DOCTYPE html>\n<html><head><title>Log in | Golden Sneakers</title></head><body><form></form></body></html>",
        ]]));

        self::assertStringContainsString('pagina HTML', $message);
        self::assertStringContainsString('«Log in | Golden Sneakers»', $message);
        self::assertStringContainsString('dopo 1 redirect verso https://www.goldensneakers.net/accounts/login/', $message);
        self::assertStringContainsString('FEED_BEARER_TOKEN', $message);
        self::assertStringNotContainsString('?next=', $message, 'Dell\'URL finale solo host e percorso');
    }

    public function testJsonObjectWithoutRowsShowsKeysAndDetail(): void
    {
        $message = $this->feedError($this->adapter([
            self::jsonResponse(['detail' => 'Account disabled.', 'code' => 'inactive']),
        ]));

        self::assertStringContainsString('HTTP 200', $message);
        self::assertStringContainsString('chiavi: detail, code', $message);
        self::assertStringContainsString('«Account disabled.»', $message);
    }

    public function testTruncatedJsonShowsTheErrorAndBothEnds(): void
    {
        $body = substr((string) json_encode(self::rows(3)), 0, -1);
        $message = $this->feedError($this->adapter([['status' => 200, 'content_type' => 'application/json', 'body' => $body]]));

        self::assertStringContainsString('non è JSON valido (Syntax error)', $message);
        self::assertStringContainsString('inizia con «[{"id":1,"sku":"SKU0"', $message);
        self::assertStringContainsString('"image_name":"x.png"}»', $message, 'La coda mostra dove il feed è stato troncato');
    }

    public function testShortGarbageIsShownWhole(): void
    {
        $message = $this->feedError($this->adapter([['status' => 200, 'content_type' => 'text/plain', 'body' => "Service temporarily\n unavailable"]]));

        self::assertStringContainsString('non è JSON valido (', $message);
        self::assertStringContainsString('contenuto «Service temporarily unavailable»', $message);
    }

    public function testEmptyBodyIsReported(): void
    {
        $message = $this->feedError($this->adapter([['status' => 200, 'body' => '']]));

        self::assertStringContainsString('senza contenuto', $message);
    }

    public function testUnauthorizedIsNotRetriedAndPointsToTheToken(): void
    {
        $message = $this->feedError($this->adapter([
            self::jsonResponse(['detail' => 'Invalid token.'], 401),
        ]));

        self::assertStringContainsString('Download del feed fallito: HTTP 401 «Invalid token.»', $message);
        self::assertStringContainsString('FEED_BEARER_TOKEN', $message);
        self::assertCount(1, $this->calls, 'Un 401 non cambia ritentando');
    }

    public function testNonStandardJsonIsRepairedAndIncompleteRowsSkipped(): void
    {
        $rows = self::rows(40);
        $rows[] = ['barcode' => '__NAN__', 'product_name' => 'NaN Runner'] + self::row(41, 'NAN1');
        $rows[] = ['offer_price' => '__NAN__'] + self::row(42, 'NAN2');
        // NaN/Infinity non sono JSON: li emette json.dumps di Python per i valori mancanti
        $body = "\xEF\xBB\xBF" . str_replace('"__NAN__"', 'NaN', (string) json_encode($rows));

        $feed = $this->adapter([['status' => 200, 'body' => $body, 'content_type' => 'application/json']])->fetch();

        self::assertCount(41, $feed['rows'], 'Solo la riga senza prezzo viene scartata');
        $nanRunner = array_values(array_filter($feed['rows'], static fn (array $r): bool => $r['sku'] === 'NAN1'))[0];
        self::assertSame('', $nanRunner['barcode']);
        self::assertSame('NaN Runner', $nanRunner['name'], 'Le stringhe che contengono NaN restano intatte');
        self::assertCount(2, $feed['warnings']);
        self::assertStringContainsString('JSON non standard', $feed['warnings'][0]);
        self::assertStringContainsString('(1 su 42)', $feed['warnings'][1]);
        self::assertStringContainsString('NAN2', $feed['warnings'][1]);
    }
}
