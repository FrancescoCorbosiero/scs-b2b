<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Adapter\DropshipException;
use App\Adapter\DropshipUncertainException;
use App\Adapter\GoldenSneakersDropshipClient;
use App\Support\Config;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Client dropship in modalità live, con transport HTTP stubbato: qui si
 * verifica la classificazione degli esiti (certo/incerto), l'assenza di
 * retry sulla POST e la validazione delle risposte. Soldi veri in ballo:
 * ogni ramo va coperto.
 */
final class GoldenSneakersDropshipClientTest extends TestCase
{
    /** @var list<array{method: string, url: string, headers: list<string>, body: string|array<string, mixed>|null}> */
    private array $calls = [];

    /** @param list<array{status: int, body: string, errno?: int, error?: string}> $responses */
    private function client(array $responses, array $configOverrides = []): GoldenSneakersDropshipClient
    {
        $this->calls = [];
        $queue = $responses;
        $transport = function (string $method, string $url, array $headers, string|array|null $body, int $timeout) use (&$queue): array {
            $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
            $next = array_shift($queue);
            self::assertNotNull($next, 'chiamata HTTP inattesa: coda risposte esaurita');

            return ['status' => $next['status'], 'body' => $next['body'], 'errno' => $next['errno'] ?? 0, 'error' => $next['error'] ?? ''];
        };

        return new GoldenSneakersDropshipClient(new Config(array_merge([
            'DROPSHIP_MODE' => 'live',
            'FEED_BEARER_TOKEN' => 'tok-segreto',
            'FEED_BASE_URL' => 'https://www.goldensneakers.net',
        ], $configOverrides)), new NullLogger(), $transport);
    }

    /** @return array{delivery_address: array<string, string>, client_provides_shipping_label: bool, items: list<array<string, int|string>>} */
    private function payload(): array
    {
        return [
            'delivery_address' => [
                'name' => 'Mario Rossi', 'city' => 'Milano', 'zip_code' => '20121',
                'street' => 'Via Montenapoleone 12', 'country_code' => 'IT',
                'phone' => '+393401234567', 'email' => 'mario.rossi@example.it',
            ],
            'client_provides_shipping_label' => false,
            'items' => [['size_id' => 11769, 'quantity' => 2]],
        ];
    }

    public function testLiveCreateSuccessParsesResponseAndSendsExactPayload(): void
    {
        $client = $this->client([[
            'status' => 201,
            'body' => (string) json_encode([
                'message' => 'Dropship order created successfully',
                'order_id' => 4242,
                'total_price' => '199.90',
                'dropship_package_id' => 777,
            ]),
        ]]);

        $result = $client->createOrder($this->payload());

        self::assertFalse($result['simulated']);
        self::assertSame(4242, $result['order_id']);
        self::assertSame(199.90, $result['total_price']);
        self::assertSame(777, $result['dropship_package_id']);

        self::assertCount(1, $this->calls);
        $call = $this->calls[0];
        self::assertSame('POST', $call['method']);
        self::assertSame('https://www.goldensneakers.net/api/orders-dropship/create-order/', $call['url']);
        self::assertContains('Authorization: Bearer tok-segreto', $call['headers']);
        self::assertContains('Content-Type: application/json', $call['headers']);
        self::assertSame($this->payload(), json_decode((string) $call['body'], true));
    }

    public function testRejected4xxIsCertainFailureWithSupplierMessage(): void
    {
        $client = $this->client([[
            'status' => 400,
            'body' => (string) json_encode(['detail' => 'size_id 11769 out of stock']),
        ]]);

        try {
            $client->createOrder($this->payload());
            self::fail('attesa DropshipException');
        } catch (DropshipUncertainException) {
            self::fail('un 4xx è un rifiuto certo, non un esito incerto');
        } catch (DropshipException $e) {
            self::assertStringContainsString('size_id 11769 out of stock', $e->getMessage());
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
            $client->createOrder($this->payload());
        } finally {
            self::assertCount(1, $this->calls, 'mai retry: rischio ordine doppio');
        }
    }

    public function testConnectFailureIsCertainFailure(): void
    {
        $client = $this->client([[
            'status' => 0, 'body' => '', 'errno' => CURLE_COULDNT_CONNECT, 'error' => 'connection refused',
        ]]);

        try {
            $client->createOrder($this->payload());
            self::fail('attesa DropshipException');
        } catch (DropshipUncertainException) {
            self::fail('connect fallito = nulla è partito: fallimento certo');
        } catch (DropshipException $e) {
            self::assertStringContainsString('nessun ordine', $e->getMessage());
        }
    }

    public function testServerErrorIsUncertain(): void
    {
        $client = $this->client([['status' => 502, 'body' => 'Bad Gateway']]);

        $this->expectException(DropshipUncertainException::class);
        $client->createOrder($this->payload());
    }

    public function testOkWithUnreadableBodyIsUncertain(): void
    {
        $client = $this->client([['status' => 200, 'body' => '<html>boh</html>']]);

        $this->expectException(DropshipUncertainException::class);
        $this->expectExceptionMessageMatches('/potrebbe essere stato creato/');
        $client->createOrder($this->payload());
    }

    public function testRedirectMeansMisconfiguredEndpointCertainFailure(): void
    {
        $client = $this->client([['status' => 301, 'body' => '']]);

        try {
            $client->createOrder($this->payload());
            self::fail('attesa DropshipException');
        } catch (DropshipUncertainException) {
            self::fail('un redirect non processa la POST: fallimento certo');
        } catch (DropshipException $e) {
            self::assertStringContainsString('DROPSHIP_CREATE_ENDPOINT', $e->getMessage());
        }
    }

    public function testMissingTokenRefusesBeforeAnyCall(): void
    {
        $client = $this->client([], ['FEED_BEARER_TOKEN' => '']);

        try {
            $client->createOrder($this->payload());
            self::fail('attesa DropshipException');
        } catch (DropshipException $e) {
            self::assertStringContainsString('FEED_BEARER_TOKEN', $e->getMessage());
        }
        self::assertCount(0, $this->calls, 'senza token non deve partire nulla');
    }

    public function testInvalidEndpointRefusesBeforeAnyCall(): void
    {
        $client = $this->client([], ['DROPSHIP_CREATE_ENDPOINT' => 'senza-slash-iniziale']);

        $this->expectException(DropshipException::class);
        try {
            $client->createOrder($this->payload());
        } finally {
            self::assertCount(0, $this->calls);
        }
    }

    public function testOrderDetailsParsesFullDocumentedResponse(): void
    {
        // risposta d'esempio della doc Swagger, integrale
        $body = [
            'order_id' => 123,
            'status' => 'TO_SHIP',
            'total_amount' => 299.99,
            'currency' => 'EUR',
            'created_at' => '2024-01-15T10:30:00Z',
            'dropship_package_id' => 456,
            'tracking_numbers' => ['1234567890123', '1234567890124'],
            'items' => [
                ['size_id' => 789, 'sku' => 'AIR-JORDAN-1-HIGH', 'size_us' => '9.5', 'product_name' => 'Air Jordan 1 High', 'quantity' => 1, 'unit_price' => 149.99, 'total_price' => 149.99],
                ['size_id' => 790, 'sku' => 'NIKE-DUNK-LOW', 'size_us' => '10', 'product_name' => 'Nike Dunk Low', 'quantity' => 1, 'unit_price' => 150, 'total_price' => 150],
            ],
        ];
        $client = $this->client([['status' => 200, 'body' => (string) json_encode($body)]]);

        $details = $client->orderDetails(123);

        self::assertFalse($details['simulated']);
        self::assertSame('TO_SHIP', $details['status']);
        self::assertSame(['1234567890123', '1234567890124'], $details['tracking_numbers']);
        self::assertSame(299.99, $details['total_amount']);
        self::assertSame('EUR', $details['currency']);
        self::assertSame('2024-01-15T10:30:00Z', $details['created_at']);
        self::assertSame(456, $details['dropship_package_id']);
        self::assertCount(2, $details['items']);
        self::assertSame('Air Jordan 1 High', $details['items'][0]['product_name']);
        self::assertSame(149.99, $details['items'][0]['unit_price']);
        self::assertSame(150.0, $details['items'][1]['total_price']);
        self::assertSame($body, $details['raw'], 'raw è lo snapshot integrale per il DB');
        self::assertSame('GET', $this->calls[0]['method']);
        self::assertSame('https://www.goldensneakers.net/api/orders-dropship/order-details/123/', $this->calls[0]['url']);
    }

    public function testPackageDetailsParsesDocumentedResponse(): void
    {
        $body = [
            'package_id' => 456,
            'status' => 'READY_FOR_PROFORMA',
            'creation_date' => '2024-01-15T09:00:00Z',
            'last_update_date' => '2024-01-15T12:30:00Z',
            'total_order_count' => 3,
            'total_order_price' => 599.97,
            'orders' => [
                ['order_id' => 123, 'status' => 'TO_SHIP', 'created_at' => '2024-01-15T10:30:00Z', 'total_price' => 299.99],
                ['order_id' => 124, 'status' => 'TO_SHIP', 'created_at' => '2024-01-15T11:00:00Z', 'total_price' => 199.99],
            ],
        ];
        $client = $this->client([['status' => 200, 'body' => (string) json_encode($body)]]);

        $package = $client->packageDetails(456);

        self::assertFalse($package['simulated']);
        self::assertSame('READY_FOR_PROFORMA', $package['status']);
        self::assertSame(3, $package['total_order_count']);
        self::assertSame(599.97, $package['total_order_price']);
        self::assertCount(2, $package['orders']);
        self::assertSame(124, $package['orders'][1]['order_id']);
        self::assertSame('https://www.goldensneakers.net/api/orders-dropship/package-details/456/', $this->calls[0]['url']);
    }

    public function testOrderDetailsRetriesOnceBeingIdempotent(): void
    {
        $client = $this->client([
            ['status' => 500, 'body' => 'boom'],
            ['status' => 200, 'body' => (string) json_encode(['order_id' => 4242, 'status' => 'ENDED', 'tracking_numbers' => []])],
        ]);

        $details = $client->orderDetails(4242);

        self::assertSame('ENDED', $details['status']);
        self::assertCount(2, $this->calls, 'la GET è idempotente: un retry è ammesso');
    }

    public function testSimulationNeverTouchesTransport(): void
    {
        $client = $this->client([], ['DROPSHIP_MODE' => 'simulation']);

        $created = $client->createOrder($this->payload());
        $details = $client->orderDetails(123);
        $package = $client->packageDetails(456);
        $upload = $client->uploadShippingLabel(123, __FILE__, 'label.pdf', 'application/pdf', ['ABC-1234']);

        self::assertTrue($created['simulated']);
        self::assertTrue($details['simulated']);
        self::assertTrue($package['simulated']);
        self::assertTrue($upload['simulated']);
        self::assertCount(0, $this->calls, 'in simulazione nessuna chiamata HTTP, mai');
    }

    public function testUploadLabelLiveSendsMultipartAndParsesResponse(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'lbl');
        self::assertIsString($tmp);
        file_put_contents($tmp, "%PDF-1.4\n");

        $client = $this->client([[
            'status' => 201,
            'body' => (string) json_encode([
                'message' => 'Shipping label uploaded successfully',
                'order_id' => 123, 'file_id' => 456,
                'tracking_numbers' => ['1234567890', '0987654321'],
            ]),
        ]]);

        $result = $client->uploadShippingLabel(123, $tmp, 'etichetta.pdf', 'application/pdf', ['1234567890', '0987654321']);

        self::assertFalse($result['simulated']);
        self::assertSame(456, $result['file_id']);
        self::assertSame(['1234567890', '0987654321'], $result['tracking_numbers']);

        $call = $this->calls[0];
        self::assertSame('POST', $call['method']);
        self::assertSame('https://www.goldensneakers.net/api/orders-dropship/upload-shipping-label/123/', $call['url']);
        self::assertIsArray($call['body'], 'multipart: i campi viaggiano come array, non JSON');
        self::assertInstanceOf(\CURLFile::class, $call['body']['shipping_label']);
        self::assertSame('etichetta.pdf', $call['body']['shipping_label']->getPostFilename());
        self::assertSame('["1234567890","0987654321"]', $call['body']['tracking_numbers']);
        // multipart: niente Content-Type manuale, il boundary lo mette cURL
        self::assertNotContains('Content-Type: application/json', $call['headers']);
    }

    public function testUploadLabelRejectedBySupplier(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'lbl');
        self::assertIsString($tmp);
        file_put_contents($tmp, "%PDF-1.4\n");

        $client = $this->client([[
            'status' => 400,
            'body' => (string) json_encode(['detail' => 'Order already has shipping labels uploaded']),
        ]]);

        try {
            $client->uploadShippingLabel(123, $tmp, 'etichetta.pdf', 'application/pdf', ['1234567890']);
            self::fail('attesa DropshipException');
        } catch (DropshipException $e) {
            self::assertStringContainsString('already has shipping labels', $e->getMessage());
        }
        self::assertCount(1, $this->calls, 'nessun retry automatico');
    }
}
