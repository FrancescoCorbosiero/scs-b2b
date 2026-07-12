<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Adapter\GoldenSneakersDropshipClient;
use App\Repository\DropshipOrderRepository;
use App\Repository\ProductRepository;
use App\Service\DropshipOrderService;
use App\Support\Config;
use App\Support\Lang;
use App\Support\Session;
use App\Tests\Support\TestDb;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class DropshipOrderServiceTest extends TestCase
{
    private \PDO $pdo;
    private DropshipOrderService $service;
    private DropshipOrderRepository $dropshipOrders;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->pdo = TestDb::create();
        $config = new Config([
            'ROOT_PATH' => dirname(__DIR__, 2),
            'DROPSHIP_ENABLED' => '1',
            'DROPSHIP_MODE' => 'simulation',
        ]);
        $this->dropshipOrders = new DropshipOrderRepository($this->pdo);
        $this->service = new DropshipOrderService(
            new ProductRepository($this->pdo),
            $this->dropshipOrders,
            new GoldenSneakersDropshipClient($config, new NullLogger()),
            new Session($config),
            $config,
            new Lang(dirname(__DIR__, 2)),
            new NullLogger(),
        );
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    /** @return array<string, mixed> una richiesta d'ordine con 2 righe (una con size_id, una senza) */
    private function seedOrderRequest(): array
    {
        $productId = TestDb::seedProduct($this->pdo, 'JS3801', 'adidas Gazelle', 'Adidas', [
            ['size_eu' => '42', 'size_us' => '8.5', 'quantity' => 5],
            ['size_eu' => '43', 'size_us' => '9', 'quantity' => 2],
        ]);
        // solo la 42 ha il size_id del fornitore
        $this->pdo->exec("UPDATE product_sizes SET supplier_size_id = 11769 WHERE product_id = {$productId} AND size_eu = '42'");

        $snapshot = json_encode(['plan' => 'base', 'lines' => [
            ['sku' => 'JS3801', 'name' => 'adidas Gazelle', 'size_eu' => '42', 'size_us' => '8.5', 'qty' => 3],
            ['sku' => 'JS3801', 'name' => 'adidas Gazelle', 'size_eu' => '43', 'size_us' => '9', 'qty' => 2],
        ]]);

        return [
            'id' => 7,
            'customer_name' => 'Mario Rossi',
            'email' => 'mario.rossi@example.it',
            'phone' => '+393401234567',
            'cart_snapshot' => $snapshot,
        ];
    }

    /** @return array<string, mixed> input valido per lo step 1 */
    private function validInput(): array
    {
        return [
            'name' => 'Mario Rossi',
            'street' => 'Via Montenapoleone 12',
            'city' => 'Milano',
            'zip_code' => '20121',
            'country_code' => 'it',
            'phone' => '+393401234567',
            'email' => 'mario.rossi@example.it',
            'client_provides_shipping_label' => '',
            'qty' => ['0' => 3, '1' => 2],
        ];
    }

    /** @return array<string, mixed> la bozza dopo uno step 1 valido */
    private function draftAfterStep1(array $orderRequest): array
    {
        $result = $this->service->createDraft($orderRequest, $this->validInput());
        self::assertTrue($result['ok'], implode(' / ', $result['errors']));
        $draft = $this->service->draftFor((int) $orderRequest['id']);
        self::assertNotNull($draft);

        return $draft;
    }

    public function testPrepareChecksStockAndSizeIds(): void
    {
        $order = $this->seedOrderRequest();
        $prepared = $this->service->prepare($order);

        self::assertSame('Mario Rossi', $prepared['address']['name']);
        self::assertSame('IT', $prepared['address']['country_code']);
        self::assertCount(2, $prepared['lines']);
        self::assertSame(11769, $prepared['lines'][0]['supplier_size_id']);
        self::assertNull($prepared['lines'][1]['supplier_size_id']);
        self::assertTrue($prepared['lines'][1]['orderable'], 'senza size_id ma con size_us resta ordinabile');
    }

    public function testPayloadPrefersSizeIdAndFallsBackToSkuSizeUs(): void
    {
        $order = $this->seedOrderRequest();
        $draft = $this->draftAfterStep1($order);

        self::assertSame([
            ['size_id' => 11769, 'quantity' => 3],
            ['sku' => 'JS3801', 'size_us' => '9', 'quantity' => 2],
        ], $draft['payload']['items']);
        self::assertSame('IT', $draft['payload']['delivery_address']['country_code']);
        self::assertFalse($draft['payload']['client_provides_shipping_label']);
        // 5 pezzi × offer_price di default 50.00 (TestDb)
        self::assertSame('250.00', $draft['wholesale_total']);
    }

    public function testQuantityAboveStockIsRejected(): void
    {
        $order = $this->seedOrderRequest();
        $input = $this->validInput();
        $input['qty'] = ['0' => 3, '1' => 99];

        $result = $this->service->createDraft($order, $input);
        self::assertFalse($result['ok']);
        self::assertNull($this->service->draftFor((int) $order['id']));
    }

    public function testInvalidAddressIsRejected(): void
    {
        $order = $this->seedOrderRequest();
        $input = $this->validInput();
        $input['street'] = '';
        $input['country_code'] = 'ITA';
        $input['email'] = 'non-una-email';

        $result = $this->service->createDraft($order, $input);
        self::assertFalse($result['ok']);
        self::assertCount(3, $result['errors']);
    }

    public function testSendRequiresChecksAndPhrase(): void
    {
        $order = $this->seedOrderRequest();
        $draft = $this->draftAfterStep1($order);
        $token = $draft['token'];

        // senza caselle di conferma: rifiutato
        $result = $this->service->send(7, ['_draft_token' => $token, 'confirmation_phrase' => 'CONFERMA 7']);
        self::assertFalse($result['ok']);

        // caselle ok ma frase sbagliata: rifiutato
        $checks = ['_draft_token' => $token, 'check_address' => '1', 'check_items' => '1', 'check_irreversible' => '1'];
        self::assertTrue($this->service->confirmChecks(7, $checks)['ok']);
        $result = $this->service->send(7, ['_draft_token' => $token, 'confirmation_phrase' => 'CONFERMA 8']);
        self::assertFalse($result['ok']);

        // token sbagliato: rifiutato
        $result = $this->service->send(7, ['_draft_token' => 'x', 'confirmation_phrase' => 'CONFERMA 7']);
        self::assertFalse($result['ok']);

        self::assertSame([], $this->dropshipOrders->findByOrderRequest(7), 'nessun ordine registrato finché le barriere non passano');
    }

    public function testSimulatedSendStoresOrderWithoutLeakingAnything(): void
    {
        $order = $this->seedOrderRequest();
        $draft = $this->draftAfterStep1($order);
        $token = $draft['token'];
        $this->service->confirmChecks(7, ['_draft_token' => $token, 'check_address' => '1', 'check_items' => '1', 'check_irreversible' => '1']);

        $result = $this->service->send(7, ['_draft_token' => $token, 'confirmation_phrase' => ' conferma 7 ']);
        self::assertTrue($result['ok'], implode(' / ', $result['errors']));
        self::assertIsInt($result['dropship_id']);

        $stored = $this->dropshipOrders->find($result['dropship_id']);
        self::assertNotNull($stored);
        self::assertSame('simulation', $stored['mode']);
        self::assertSame('UNCONFIRMED', $stored['status']);
        self::assertNotNull($stored['vendor_order_id']);
        $response = json_decode((string) $stored['response_payload'], true);
        self::assertIsArray($response);
        self::assertTrue($response['simulated']);
        // il payload API non deve contenere prezzi (né offer_price né listino)
        self::assertStringNotContainsString('price', (string) $stored['request_payload']);

        self::assertNull($this->service->draftFor(7), 'la bozza è monouso');
    }

    public function testLiveModeIsRefusedWithoutSending(): void
    {
        $config = new Config([
            'ROOT_PATH' => dirname(__DIR__, 2),
            'DROPSHIP_ENABLED' => '1',
            'DROPSHIP_MODE' => 'live',
        ]);
        $service = new DropshipOrderService(
            new ProductRepository($this->pdo),
            $this->dropshipOrders,
            new GoldenSneakersDropshipClient($config, new NullLogger()),
            new Session($config),
            $config,
            new Lang(dirname(__DIR__, 2)),
            new NullLogger(),
        );

        $order = $this->seedOrderRequest();
        $result = $service->createDraft($order, $this->validInput());
        self::assertTrue($result['ok']);
        $draft = $service->draftFor(7);
        self::assertNotNull($draft);
        $token = $draft['token'];
        $service->confirmChecks(7, ['_draft_token' => $token, 'check_address' => '1', 'check_items' => '1', 'check_irreversible' => '1']);

        $result = $service->send(7, ['_draft_token' => $token, 'confirmation_phrase' => 'CONFERMA 7']);
        self::assertFalse($result['ok'], 'la modalità live non implementata rifiuta l\'invio');
        self::assertSame([], $this->dropshipOrders->findByOrderRequest(7));
    }

    public function testUnknownModeDegradesToSimulation(): void
    {
        $config = new Config(['DROPSHIP_MODE' => 'produzione']);
        $client = new GoldenSneakersDropshipClient($config, new NullLogger());
        self::assertTrue($client->isSimulation(), 'valori sconosciuti non devono mai attivare il live');
    }
}
