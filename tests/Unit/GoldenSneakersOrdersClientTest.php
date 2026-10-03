<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Adapter\DropshipException;
use App\Adapter\DropshipUncertainException;
use App\Adapter\GoldenSneakersOrdersClient;
use App\Support\Config;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Client dell'API ordini (/api/orders/) con transport HTTP stubbato. I
 * payload e le risposte sono quelli d'esempio forniti dal titolare il
 * 02/10/2026 (docs/09). Soldi veri in ballo sulla creazione: ogni esito va
 * classificato correttamente (certo/incerto) e la POST non va mai ripetuta.
 */
final class GoldenSneakersOrdersClientTest extends TestCase
{
    /** @var list<array{method: string, url: string, headers: list<string>, body: string|array<string, mixed>|null}> */
    private array $calls = [];

    /**
     * @param list<array{status: int, body: string, errno?: int, error?: string}> $responses
     * @param array<string, string> $configOverrides
     */
    private function client(array $responses, array $configOverrides = []): GoldenSneakersOrdersClient
    {
        $this->calls = [];
        $queue = $responses;
        $transport = function (string $method, string $url, array $headers, string|array|null $body, int $timeout) use (&$queue): array {
            $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
            $next = array_shift($queue);
            self::assertNotNull($next, 'chiamata HTTP inattesa: coda risposte esaurita');

            return ['status' => $next['status'], 'body' => $next['body'], 'errno' => $next['errno'] ?? 0, 'error' => $next['error'] ?? ''];
        };

        return new GoldenSneakersOrdersClient(new Config(array_merge([
            'DROPSHIP_MODE' => 'live',
            'FEED_BEARER_TOKEN' => 'tok-segreto',
            'FEED_BASE_URL' => 'https://www.goldensneakers.net',
        ], $configOverrides)), new NullLogger(), $transport);
    }

    /** @return array{currency: string, shipping_address: array<string, string>, items: list<array<string, int|string>>} */
    private function samplePayload(): array
    {
        return [
            'currency' => 'EUR',
            'shipping_address' => [
                'recipient_name' => 'Mario Rossi',
                'address_l1' => 'Via Roma 1',
                'address_l2' => '',
                'city' => 'Milano',
                'zip_code' => '20121',
                'country' => 'IT',
                'phone' => '+390401234567',
                'email' => 'info@sneakershop.it',
            ],
            'items' => [
                ['size_id' => 12280, 'quantity' => 6],
                ['sku' => 'B75806', 'size_us' => '4', 'quantity' => 4],
            ],
        ];
    }

    /** @return array<string, mixed> risposta d'esempio del dettaglio, integrale */
    private function sampleDetail(): array
    {
        return [
            'order_id' => 3120,
            'status' => 'TO_SHIP',
            'currency' => 'EUR',
            'total_amount' => 1240,
            'created_at' => '2025-05-02T14:20:00Z',
            'billing' => [
                'name' => 'Sneaker Shop SRL', 'vat_id' => '12345678901', 'full_vat_id' => 'IT12345678901',
                'address_l1' => 'Via Roma 1', 'address_l2' => null, 'city' => 'Milano', 'zip_code' => '20121',
                'country' => 'IT', 'email' => 'info@sneakershop.it', 'phone' => '+390401234567',
            ],
            'shipping_address' => [
                'recipient_name' => 'Marco Rossi', 'address_l1' => 'Via Verdi 8', 'address_l2' => null,
                'city' => 'Roma', 'zip_code' => '00100', 'country' => 'IT', 'email' => null, 'phone' => null,
            ],
            'items' => [[
                'size_id' => 2917, 'sku' => 'B75806', 'product_name' => 'adidas Samba OG Cloud White',
                'size_us' => '4', 'quantity' => 6, 'unit_price' => 55, 'total_price' => 330,
            ]],
            'proforma' => [
                'url' => 'https://www.goldensneakers.net/orders/protected-proforma/3120/',
                'symbol' => 'FS 1/2025',
                'uploaded_at' => '2025-05-02T15:00:00Z',
            ],
            'invoice' => null,
            'payment' => [
                'status' => 'unpaid', 'is_paid' => false, 'paid_amount' => 0, 'total_amount' => 1240,
                'currency' => 'EUR', 'due_date' => null,
            ],
        ];
    }

    // ── Creazione ────────────────────────────────────────────────────

    public function testLiveCreateSendsExactPayloadAndParsesResponse(): void
    {
        $client = $this->client([[
            'status' => 201,
            'body' => (string) json_encode([
                'order_id' => 3125, 'status' => 'UNCONFIRMED', 'currency' => 'EUR', 'total_amount' => 585,
                'created_at' => '2025-05-04T09:00:00Z', 'shipping_cost' => 15, 'free_shipping' => false,
                'payment_status' => 'unpaid',
            ]),
        ]]);

        $result = $client->createOrder($this->samplePayload());

        self::assertFalse($result['simulated']);
        self::assertSame(3125, $result['order_id']);
        self::assertSame('UNCONFIRMED', $result['status']);
        self::assertSame('EUR', $result['currency']);
        self::assertSame(585.0, $result['total_amount']);
        self::assertSame(15.0, $result['shipping_cost']);
        self::assertFalse($result['free_shipping']);
        self::assertSame('unpaid', $result['payment_status']);
        self::assertSame('2025-05-04T09:00:00Z', $result['created_at']);

        self::assertCount(1, $this->calls);
        $call = $this->calls[0];
        self::assertSame('POST', $call['method']);
        self::assertSame('https://www.goldensneakers.net/api/orders/create/', $call['url']);
        self::assertContains('Authorization: Bearer tok-segreto', $call['headers']);
        self::assertContains('Content-Type: application/json', $call['headers']);
        self::assertSame($this->samplePayload(), json_decode((string) $call['body'], true));
    }

    public function testRejected4xxIsCertainFailureWithSupplierMessage(): void
    {
        $client = $this->client([[
            'status' => 400,
            'body' => (string) json_encode(['detail' => 'size_id 12280: insufficient stock']),
        ]]);

        try {
            $client->createOrder($this->samplePayload());
            self::fail('attesa DropshipException');
        } catch (DropshipUncertainException) {
            self::fail('un 4xx è un rifiuto certo, non un esito incerto');
        } catch (DropshipException $e) {
            self::assertStringContainsString('insufficient stock', $e->getMessage());
            self::assertStringContainsString('HTTP 400', $e->getMessage());
        }
        self::assertCount(1, $this->calls, 'nessun retry sulla POST');
    }

    public function testTimeoutAfterSendIsUncertainAndNeverRetried(): void
    {
        $client = $this->client([[
            'status' => 0, 'body' => '', 'errno' => CURLE_OPERATION_TIMEDOUT, 'error' => 'timeout',
        ]]);

        $this->expectException(DropshipUncertainException::class);
        try {
            $client->createOrder($this->samplePayload());
        } finally {
            self::assertCount(1, $this->calls, 'mai retry: rischio ordine doppio');
        }
    }

    public function testServerErrorIsUncertain(): void
    {
        $client = $this->client([['status' => 503, 'body' => 'Service Unavailable']]);

        $this->expectException(DropshipUncertainException::class);
        $client->createOrder($this->samplePayload());
    }

    public function testOkWithoutOrderIdIsUncertain(): void
    {
        $client = $this->client([['status' => 201, 'body' => (string) json_encode(['status' => 'UNCONFIRMED'])]]);

        $this->expectException(DropshipUncertainException::class);
        $this->expectExceptionMessageMatches('/potrebbe essere stato creato/');
        $client->createOrder($this->samplePayload());
    }

    public function testRedirectIsCertainFailureAndNotFollowed(): void
    {
        $client = $this->client([['status' => 302, 'body' => '']]);

        try {
            $client->createOrder($this->samplePayload());
            self::fail('attesa DropshipException');
        } catch (DropshipUncertainException) {
            self::fail('un redirect non processa la POST: fallimento certo');
        } catch (DropshipException $e) {
            self::assertStringContainsString('Nessun ordine creato', $e->getMessage());
        }
        self::assertCount(1, $this->calls);
    }

    public function testConnectFailureIsCertainFailure(): void
    {
        $client = $this->client([[
            'status' => 0, 'body' => '', 'errno' => CURLE_COULDNT_RESOLVE_HOST, 'error' => 'could not resolve host',
        ]]);

        try {
            $client->createOrder($this->samplePayload());
            self::fail('attesa DropshipException');
        } catch (DropshipUncertainException) {
            self::fail('DNS fallito = nulla è partito: fallimento certo');
        } catch (DropshipException $e) {
            self::assertStringContainsString('nessun ordine', $e->getMessage());
        }
    }

    public function testMissingTokenRefusesBeforeAnyCall(): void
    {
        $client = $this->client([], ['FEED_BEARER_TOKEN' => '']);

        try {
            $client->createOrder($this->samplePayload());
            self::fail('attesa DropshipException');
        } catch (DropshipException $e) {
            self::assertStringContainsString('FEED_BEARER_TOKEN', $e->getMessage());
        }
        self::assertCount(0, $this->calls, 'senza token non deve partire nulla');
    }

    public function testSimulationNeverSendsTheCreation(): void
    {
        $client = $this->client([], ['DROPSHIP_MODE' => 'simulation']);

        $result = $client->createOrder($this->samplePayload());

        self::assertTrue($result['simulated']);
        self::assertSame('UNCONFIRMED', $result['status']);
        self::assertGreaterThan(0, $result['order_id']);
        self::assertCount(0, $this->calls, 'in simulazione la creazione non parte mai');
    }

    public function testUnknownModeDegradesToSimulation(): void
    {
        $client = $this->client([], ['DROPSHIP_MODE' => 'LIVE ']);
        self::assertTrue($client->isSimulation(), 'solo "live" esatto attiva gli ordini reali');
    }

    // ── Elenco ───────────────────────────────────────────────────────

    public function testListParsesDocumentedSample(): void
    {
        $client = $this->client([[
            'status' => 200,
            'body' => '[{"order_id":3120,"status":"TO_SHIP","currency":"EUR","total_amount":1240,"created_at":"2025-05-02T14:20:00Z","payment_status":"unpaid","is_paid":false,"has_proforma":true,"has_invoice":false}]',
        ]]);

        $orders = $client->listOrders();

        self::assertSame([[
            'order_id' => 3120,
            'status' => 'TO_SHIP',
            'currency' => 'EUR',
            'total_amount' => 1240.0,
            'created_at' => '2025-05-02T14:20:00Z',
            'payment_status' => 'unpaid',
            'is_paid' => false,
            'has_proforma' => true,
            'has_invoice' => false,
        ]], $orders);
        self::assertSame('GET', $this->calls[0]['method']);
        self::assertSame('https://www.goldensneakers.net/api/orders/', $this->calls[0]['url']);
        self::assertContains('Authorization: Bearer tok-segreto', $this->calls[0]['headers']);
    }

    public function testListFollowsPaginationOnTheSameHost(): void
    {
        $client = $this->client([
            ['status' => 200, 'body' => (string) json_encode([
                'count' => 2, 'next' => 'https://www.goldensneakers.net/api/orders/?page=2',
                'results' => [['order_id' => 2, 'status' => 'ENDED']],
            ])],
            ['status' => 200, 'body' => (string) json_encode([
                'count' => 2, 'next' => null,
                'results' => [['order_id' => 1, 'status' => 'CANCELED'], ['status' => 'senza id']],
            ])],
        ]);

        $orders = $client->listOrders();

        self::assertSame([2, 1], array_column($orders, 'order_id'), 'le righe senza order_id vengono scartate');
        self::assertSame('https://www.goldensneakers.net/api/orders/?page=2', $this->calls[1]['url']);
    }

    public function testListNeverSendsTheTokenToAnotherHost(): void
    {
        $client = $this->client([
            ['status' => 200, 'body' => (string) json_encode([
                'next' => 'https://evil.example.com/api/orders/?page=2',
                'results' => [['order_id' => 2]],
            ])],
        ]);

        try {
            $client->listOrders();
            self::fail('attesa DropshipException');
        } catch (DropshipException $e) {
            self::assertStringContainsString('host diverso', $e->getMessage());
        }
        self::assertCount(1, $this->calls, 'la pagina su un altro host non va mai richiesta');
    }

    public function testReadsWorkInSimulationWhenTheTokenIsConfigured(): void
    {
        $client = $this->client([['status' => 200, 'body' => '[]']], ['DROPSHIP_MODE' => 'simulation']);

        self::assertSame([], $client->listOrders());
        self::assertCount(1, $this->calls, 'le letture non hanno effetti: partono anche in simulazione');
    }

    public function testReadsWithoutTokenFailBeforeAnyCall(): void
    {
        $client = $this->client([], ['FEED_BEARER_TOKEN' => '']);

        $this->expectException(DropshipException::class);
        $this->expectExceptionMessageMatches('/FEED_BEARER_TOKEN/');
        try {
            $client->listOrders();
        } finally {
            self::assertCount(0, $this->calls);
        }
    }

    // ── Dettaglio ────────────────────────────────────────────────────

    public function testDetailParsesDocumentedSample(): void
    {
        $client = $this->client([['status' => 200, 'body' => (string) json_encode($this->sampleDetail())]]);

        $detail = $client->orderDetail(3120);

        self::assertSame('https://www.goldensneakers.net/api/orders/3120/', $this->calls[0]['url']);
        self::assertSame(3120, $detail['order_id']);
        self::assertSame('TO_SHIP', $detail['status']);
        self::assertSame(1240.0, $detail['total_amount']);
        self::assertSame('Sneaker Shop SRL', $detail['billing']['name']);
        self::assertSame('IT12345678901', $detail['billing']['full_vat_id']);
        self::assertNull($detail['billing']['address_l2']);
        self::assertSame('Marco Rossi', $detail['shipping_address']['recipient_name']);
        self::assertNull($detail['shipping_address']['email']);
        self::assertSame([[
            'size_id' => 2917, 'sku' => 'B75806', 'product_name' => 'adidas Samba OG Cloud White',
            'size_us' => '4', 'quantity' => 6, 'unit_price' => 55.0, 'total_price' => 330.0,
        ]], $detail['items']);
        self::assertSame([
            'url' => 'https://www.goldensneakers.net/orders/protected-proforma/3120/',
            'symbol' => 'FS 1/2025',
            'uploaded_at' => '2025-05-02T15:00:00Z',
        ], $detail['proforma']);
        self::assertNull($detail['invoice']);
        self::assertSame([
            'status' => 'unpaid', 'is_paid' => false, 'paid_amount' => 0.0, 'total_amount' => 1240.0,
            'currency' => 'EUR', 'due_date' => null,
        ], $detail['payment']);
        self::assertSame([], $detail['tracking_numbers']);
        self::assertSame($this->sampleDetail(), $detail['raw'], 'raw è lo snapshot integrale per il DB');
    }

    public function testDocumentLinksOnlyOnTheSupplierDomain(): void
    {
        $body = $this->sampleDetail();
        $body['proforma'] = ['url' => 'https://phishing.example.com/proforma.pdf', 'symbol' => 'FS 2/2025', 'uploaded_at' => null];
        $body['invoice'] = ['url' => '/orders/protected-invoice/3120/', 'symbol' => 'FV 9/2025', 'uploaded_at' => null];
        $client = $this->client([['status' => 200, 'body' => (string) json_encode($body)]]);

        $detail = $client->orderDetail(3120);

        self::assertNotNull($detail['proforma']);
        self::assertNull($detail['proforma']['url'], 'mai un link verso domini terzi');
        self::assertSame('FS 2/2025', $detail['proforma']['symbol']);
        self::assertNotNull($detail['invoice']);
        self::assertSame('https://www.goldensneakers.net/orders/protected-invoice/3120/', $detail['invoice']['url'], 'i relativi si risolvono contro FEED_BASE_URL');
    }

    public function testDetailRetriesOnceBeingIdempotent(): void
    {
        $client = $this->client([
            ['status' => 502, 'body' => 'Bad Gateway'],
            ['status' => 200, 'body' => (string) json_encode(['order_id' => 3120, 'status' => 'ENDED'])],
        ]);

        $detail = $client->orderDetail(3120);

        self::assertSame('ENDED', $detail['status']);
        self::assertCount(2, $this->calls, 'la GET è idempotente: un retry è ammesso');
    }

    public function testDetailWithoutOrderIdIsRejected(): void
    {
        $client = $this->client([['status' => 200, 'body' => (string) json_encode(['detail' => 'Not found.'])]]);

        $this->expectException(DropshipException::class);
        $client->orderDetail(3120);
    }
}
