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
    /** @var list<array{method: string, url: string, headers: list<string>, body: ?string}> */
    private array $calls = [];

    /** @param list<array{status: int, body: string, errno?: int, error?: string}> $responses */
    private function client(array $responses, array $configOverrides = []): GoldenSneakersDropshipClient
    {
        $this->calls = [];
        $queue = $responses;
        $transport = function (string $method, string $url, array $headers, ?string $body, int $timeout) use (&$queue): array {
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
        self::assertSame('https://www.goldensneakers.net/api/orders-dropship/create/', $call['url']);
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

    public function testOrderDetailsParsesStatusAndTracking(): void
    {
        $client = $this->client([[
            'status' => 200,
            'body' => (string) json_encode([
                'order_id' => 4242, 'status' => 'TO_SHIP', 'tracking_numbers' => ['XY123', 456],
            ]),
        ]]);

        $details = $client->orderDetails(4242);

        self::assertFalse($details['simulated']);
        self::assertSame('TO_SHIP', $details['status']);
        self::assertSame(['XY123', '456'], $details['tracking_numbers']);
        self::assertSame('GET', $this->calls[0]['method']);
        self::assertSame('https://www.goldensneakers.net/api/orders-dropship/order-details/4242/', $this->calls[0]['url']);
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

        self::assertTrue($created['simulated']);
        self::assertTrue($details['simulated']);
        self::assertCount(0, $this->calls, 'in simulazione nessuna chiamata HTTP, mai');
    }
}
