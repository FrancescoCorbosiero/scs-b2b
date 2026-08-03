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

    /** @var list<array{method: string, url: string, body: mixed}> richieste viste dal transport stub */
    private array $liveRequests = [];

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
            'address_street' => 'Via Montenapoleone 12',
            'address_city' => 'Milano',
            'address_zip' => '20121',
            'country_code' => 'IT',
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

    // ── Modalità live (transport HTTP stubbato) ──────────────────────

    /**
     * Service in modalità live con risposte HTTP predefinite; $calls conta
     * le richieste realmente "inviate".
     *
     * @param list<array{status: int, body: string, errno?: int, error?: string}> $responses
     * @param array<string, string> $configOverrides
     */
    private function liveService(array $responses, array $configOverrides, ?int &$calls): DropshipOrderService
    {
        $calls = 0;
        $queue = $responses;
        $this->liveRequests = [];
        $transport = function (string $method, string $url, array $headers, string|array|null $body, int $timeout) use (&$queue, &$calls): array {
            $calls++;
            $this->liveRequests[] = ['method' => $method, 'url' => $url, 'body' => $body];
            $next = array_shift($queue);
            self::assertNotNull($next, 'chiamata HTTP inattesa: coda risposte esaurita');

            return ['status' => $next['status'], 'body' => $next['body'], 'errno' => $next['errno'] ?? 0, 'error' => $next['error'] ?? ''];
        };
        $config = new Config(array_merge([
            'ROOT_PATH' => dirname(__DIR__, 2),
            'DROPSHIP_ENABLED' => '1',
            'DROPSHIP_MODE' => 'live',
            'FEED_BEARER_TOKEN' => 'tok-segreto',
        ], $configOverrides));

        return new DropshipOrderService(
            new ProductRepository($this->pdo),
            $this->dropshipOrders,
            new GoldenSneakersDropshipClient($config, new NullLogger(), $transport),
            new Session($config),
            $config,
            new Lang(dirname(__DIR__, 2)),
            new NullLogger(),
        );
    }

    /** Porta la bozza fino a pre-invio (step 1 + 2) e restituisce il token. */
    private function draftReadyToSend(DropshipOrderService $service, array $order): string
    {
        $result = $service->createDraft($order, $this->validInput());
        self::assertTrue($result['ok'], implode(' / ', $result['errors']));
        $draft = $service->draftFor(7);
        self::assertNotNull($draft);
        $token = (string) $draft['token'];
        $checks = $service->confirmChecks(7, ['_draft_token' => $token, 'check_address' => '1', 'check_items' => '1', 'check_irreversible' => '1']);
        self::assertTrue($checks['ok']);

        return $token;
    }

    public function testLiveSendStoresVendorOrderAndApiTotal(): void
    {
        $service = $this->liveService([[
            'status' => 201,
            'body' => (string) json_encode(['message' => 'ok', 'order_id' => 4242, 'total_price' => 512.30, 'dropship_package_id' => 777]),
        ]], [], $calls);
        $order = $this->seedOrderRequest();
        $token = $this->draftReadyToSend($service, $order);

        $result = $service->send(7, ['_draft_token' => $token, 'confirmation_phrase' => 'CONFERMA 7']);

        self::assertTrue($result['ok'], implode(' / ', $result['errors']));
        self::assertSame(1, $calls);
        $stored = $this->dropshipOrders->find((int) $result['dropship_id']);
        self::assertNotNull($stored);
        self::assertSame('live', $stored['mode']);
        self::assertSame('UNCONFIRMED', $stored['status']);
        self::assertSame(4242, (int) $stored['vendor_order_id']);
        self::assertSame(512.30, (float) $stored['total_price'], 'in live vale il totale calcolato dall\'API');
        $response = json_decode((string) $stored['response_payload'], true);
        self::assertIsArray($response);
        self::assertFalse($response['simulated']);
    }

    public function testUncertainOutcomeRecordsUnknownRowAndDiscardsDraft(): void
    {
        $service = $this->liveService([[
            'status' => 0, 'body' => '', 'errno' => CURLE_OPERATION_TIMEDOUT, 'error' => 'timeout',
        ]], [], $calls);
        $order = $this->seedOrderRequest();
        $token = $this->draftReadyToSend($service, $order);

        $result = $service->send(7, ['_draft_token' => $token, 'confirmation_phrase' => 'CONFERMA 7']);

        self::assertFalse($result['ok']);
        self::assertSame(1, $calls, 'mai retry sulla creazione');
        self::assertStringContainsString('NON ripetere', implode(' ', $result['errors']));
        $rows = $this->dropshipOrders->findByOrderRequest(7);
        self::assertCount(1, $rows, 'l\'esito incerto va registrato per l\'audit');
        self::assertSame('UNKNOWN', $rows[0]['status']);
        self::assertNull($rows[0]['vendor_order_id']);
        self::assertNull($service->draftFor(7), 'la bozza va scartata: ritentare richiede di rifare le 3 conferme');
    }

    public function testCapExceededRefusesBeforeAnyCall(): void
    {
        // costo stimato della bozza: (3+2)×50.00 = 250 € (offer_price default di TestDb)
        $service = $this->liveService([], ['DROPSHIP_MAX_ORDER_EUR' => '100'], $calls);
        $order = $this->seedOrderRequest();
        $token = $this->draftReadyToSend($service, $order);

        $result = $service->send(7, ['_draft_token' => $token, 'confirmation_phrase' => 'CONFERMA 7']);

        self::assertFalse($result['ok']);
        self::assertSame(0, $calls, 'oltre il tetto non deve partire nulla');
        self::assertStringContainsString('DROPSHIP_MAX_ORDER_EUR', implode(' ', $result['errors']));
        self::assertSame([], $this->dropshipOrders->findByOrderRequest(7));
    }

    public function testAutoDropshipLiveRefusedWithoutDedicatedFlag(): void
    {
        $service = $this->liveService([], [], $calls);

        $result = $service->autoCreateFromRequest($this->autoOrder());

        self::assertFalse($result['ok']);
        self::assertSame(0, $calls, 'senza AUTO_DROPSHIP_ALLOW_LIVE l\'auto non deve inviare in live');
        self::assertStringContainsString('AUTO_DROPSHIP_ALLOW_LIVE', (string) $result['message']);
    }

    public function testAutoDropshipLiveSendsWithDedicatedFlag(): void
    {
        $service = $this->liveService([[
            'status' => 201,
            'body' => (string) json_encode(['message' => 'ok', 'order_id' => 9001, 'total_price' => null, 'dropship_package_id' => 5]),
        ]], ['AUTO_DROPSHIP_ALLOW_LIVE' => '1'], $calls);

        $result = $service->autoCreateFromRequest($this->autoOrder());

        self::assertTrue($result['ok'], (string) $result['message']);
        self::assertSame(1, $calls);
        self::assertFalse($result['simulated']);
        $stored = $this->dropshipOrders->find((int) $result['dropship_id']);
        self::assertNotNull($stored);
        self::assertSame('live', $stored['mode']);
        self::assertSame(9001, (int) $stored['vendor_order_id']);
    }

    /** @return array<string, mixed> richiesta d'ordine completa di indirizzo per l'auto-dropship */
    private function autoOrder(): array
    {
        $order = $this->seedOrderRequest();

        return $order + [
            'address_street' => 'Via Montenapoleone 12',
            'address_city' => 'Milano',
            'address_zip' => '20121',
            'country_code' => 'IT',
        ];
    }

    public function testRefreshStatusStoresVendorSnapshotAndPackage(): void
    {
        $service = $this->liveService([
            ['status' => 200, 'body' => (string) json_encode([
                'order_id' => 123, 'status' => 'TO_SHIP', 'total_amount' => 299.99, 'currency' => 'EUR',
                'created_at' => '2024-01-15T10:30:00Z', 'dropship_package_id' => 456,
                'tracking_numbers' => ['1234567890123'],
                'items' => [['size_id' => 789, 'sku' => 'AIR-JORDAN-1-HIGH', 'size_us' => '9.5', 'product_name' => 'Air Jordan 1 High', 'quantity' => 1, 'unit_price' => 149.99, 'total_price' => 149.99]],
            ])],
            ['status' => 200, 'body' => (string) json_encode([
                'package_id' => 456, 'status' => 'READY_FOR_PROFORMA', 'total_order_count' => 3, 'orders' => [],
            ])],
        ], [], $calls);
        $id = $this->dropshipOrders->insert([
            'order_request_id' => null, 'mode' => 'live', 'status' => 'UNCONFIRMED',
            'vendor_order_id' => 123, 'dropship_package_id' => null, 'total_price' => '250.00',
            'currency' => 'EUR', 'request_payload' => '{}', 'lines_snapshot' => '[]', 'response_payload' => '{}',
        ]);
        $row = $this->dropshipOrders->find($id);
        self::assertNotNull($row);

        $result = $service->refreshStatus($row);

        self::assertTrue($result['ok'], $result['message']);
        self::assertSame(2, $calls, 'order-details + package-details');
        $updated = $this->dropshipOrders->find($id);
        self::assertNotNull($updated);
        self::assertSame('TO_SHIP', $updated['status']);
        self::assertSame(299.99, (float) $updated['total_price'], 'il totale reale del fornitore sostituisce la stima');
        self::assertSame(456, (int) $updated['dropship_package_id']);
        self::assertSame(['1234567890123'], json_decode((string) $updated['tracking_numbers'], true));
        $details = json_decode((string) $updated['details_payload'], true);
        self::assertIsArray($details);
        self::assertSame('TO_SHIP', $details['order']['status']);
        self::assertSame('READY_FOR_PROFORMA', $details['package']['status']);
    }

    public function testRefreshWithUnknownVendorStatusKeepsStored(): void
    {
        $service = $this->liveService([
            ['status' => 200, 'body' => (string) json_encode(['order_id' => 123, 'status' => 'QUALCOSA_DI_NUOVO'])],
        ], [], $calls);
        $id = $this->dropshipOrders->insert([
            'order_request_id' => null, 'mode' => 'live', 'status' => 'TO_SHIP',
            'vendor_order_id' => 123, 'dropship_package_id' => null, 'total_price' => null,
            'currency' => 'EUR', 'request_payload' => '{}', 'lines_snapshot' => '[]', 'response_payload' => '{}',
        ]);
        $row = $this->dropshipOrders->find($id);
        self::assertNotNull($row);

        $result = $service->refreshStatus($row);

        self::assertTrue($result['ok']);
        $updated = $this->dropshipOrders->find($id);
        self::assertNotNull($updated);
        self::assertSame('TO_SHIP', $updated['status'], 'stati non documentati non sovrascrivono quello salvato');
    }

    public function testUnknownModeDegradesToSimulation(): void
    {
        $config = new Config(['DROPSHIP_MODE' => 'produzione']);
        $client = new GoldenSneakersDropshipClient($config, new NullLogger());
        self::assertTrue($client->isSimulation(), 'valori sconosciuti non devono mai attivare il live');
    }

    // ── Dropshipping del rivenditore: destinatario finale ed etichetta ──

    public function testAutoDropshipUsesEndCustomerRecipient(): void
    {
        $service = $this->liveService([[
            'status' => 201,
            'body' => (string) json_encode(['message' => 'ok', 'order_id' => 9002, 'total_price' => null, 'dropship_package_id' => 6]),
        ]], ['AUTO_DROPSHIP_ALLOW_LIVE' => '1'], $calls);

        $order = $this->autoOrder() + [];
        $order['ship_to'] = 'customer';
        $order['recipient_name'] = 'Luca Bianchi';
        $order['recipient_street'] = 'Rue de Rivoli 10';
        $order['recipient_city'] = 'Paris';
        $order['recipient_zip'] = '75001';
        $order['recipient_country'] = 'FR';
        $order['recipient_phone'] = '+33123456789';
        $order['client_provides_label'] = 1;

        $result = $service->autoCreateFromRequest($order);

        self::assertTrue($result['ok'], (string) $result['message']);
        $payload = json_decode((string) $this->liveRequests[0]['body'], true);
        self::assertIsArray($payload);
        self::assertSame('Luca Bianchi', $payload['delivery_address']['name'], 'l\'ordine parte con il destinatario finale');
        self::assertSame('FR', $payload['delivery_address']['country_code']);
        self::assertSame('mario.rossi@example.it', $payload['delivery_address']['email'], 'l\'email resta quella del rivenditore');
        self::assertTrue($payload['client_provides_shipping_label'], 'la scelta etichetta del rivenditore arriva al fornitore');
    }

    public function testPrepareUsesRecipientAndLabelFlag(): void
    {
        $order = $this->seedOrderRequest();
        $order['ship_to'] = 'customer';
        $order['recipient_name'] = 'Luca Bianchi';
        $order['recipient_street'] = 'Rue de Rivoli 10';
        $order['recipient_city'] = 'Paris';
        $order['recipient_zip'] = '75001';
        $order['recipient_country'] = 'FR';
        $order['recipient_phone'] = '+33123456789';
        $order['client_provides_label'] = 1;

        $draft = $this->service->prepare($order);

        self::assertSame('Luca Bianchi', $draft['address']['name']);
        self::assertSame('FR', $draft['address']['country_code']);
        self::assertTrue($draft['client_provides_shipping_label']);
    }

    /** @return array{tmp_path: string, name: string, size: int} un PDF minimale reale */
    private function fakeLabelFile(string $name = 'etichetta.pdf', string $content = "%PDF-1.4\n%fake label\n"): array
    {
        $path = tempnam(sys_get_temp_dir(), 'lbl');
        self::assertIsString($path);
        file_put_contents($path, $content);

        return ['tmp_path' => $path, 'name' => $name, 'size' => (int) filesize($path)];
    }

    /** @return array<string, mixed> riga dropship in attesa di etichetta */
    private function seedLabelPendingOrder(): array
    {
        $id = $this->dropshipOrders->insert([
            'order_request_id' => null, 'mode' => 'simulation', 'status' => 'UNCONFIRMED',
            'vendor_order_id' => 123, 'dropship_package_id' => null, 'total_price' => null,
            'currency' => 'EUR',
            'request_payload' => (string) json_encode(['client_provides_shipping_label' => true, 'items' => []]),
            'lines_snapshot' => '[]', 'response_payload' => '{}',
        ]);
        $row = $this->dropshipOrders->find($id);
        self::assertNotNull($row);

        return $row;
    }

    public function testUploadLabelSimulatedStoresTrackingAndTimestamp(): void
    {
        $row = $this->seedLabelPendingOrder();
        self::assertTrue($this->service->labelPending($row));

        $result = $this->service->uploadLabel($row, $this->fakeLabelFile(), "ABC-123456\nXYZ7890123");

        self::assertTrue($result['ok'], $result['message']);
        $updated = $this->dropshipOrders->find((int) $row['id']);
        self::assertNotNull($updated);
        self::assertNotNull($updated['label_uploaded_at']);
        self::assertSame('etichetta.pdf', $updated['label_file_name']);
        self::assertSame(['ABC-123456', 'XYZ7890123'], json_decode((string) $updated['tracking_numbers'], true));
        self::assertFalse($this->service->labelPending($updated), 'upload monouso: non più in attesa');
    }

    public function testUploadLabelRefusedWhenNotRequestedOrBadInput(): void
    {
        // ordine SENZA client_provides_shipping_label: nessun upload
        $id = $this->dropshipOrders->insert([
            'order_request_id' => null, 'mode' => 'simulation', 'status' => 'UNCONFIRMED',
            'vendor_order_id' => 123, 'dropship_package_id' => null, 'total_price' => null,
            'currency' => 'EUR',
            'request_payload' => (string) json_encode(['client_provides_shipping_label' => false, 'items' => []]),
            'lines_snapshot' => '[]', 'response_payload' => '{}',
        ]);
        $row = $this->dropshipOrders->find($id);
        self::assertNotNull($row);
        self::assertFalse($this->service->labelPending($row));
        self::assertFalse($this->service->uploadLabel($row, $this->fakeLabelFile(), 'ABC-123456')['ok']);

        // in attesa, ma file con estensione non ammessa
        $pending = $this->seedLabelPendingOrder();
        $bad = $this->fakeLabelFile('etichetta.docx');
        self::assertFalse($this->service->uploadLabel($pending, $bad, 'ABC-123456')['ok']);

        // estensione pdf ma contenuto non-PDF (MIME sniffing)
        $fakePdf = $this->fakeLabelFile('etichetta.pdf', 'non sono un pdf');
        self::assertFalse($this->service->uploadLabel($pending, $fakePdf, 'ABC-123456')['ok']);

        // tracking mancante o malformato
        self::assertFalse($this->service->uploadLabel($pending, $this->fakeLabelFile(), '')['ok']);
        self::assertFalse($this->service->uploadLabel($pending, $this->fakeLabelFile(), 'tracking con spazi interni!!')['ok']);
    }
}
