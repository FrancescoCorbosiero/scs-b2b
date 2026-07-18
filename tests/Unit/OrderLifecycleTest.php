<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Adapter\GoldenSneakersDropshipClient;
use App\Repository\DropshipOrderRepository;
use App\Repository\OrderRequestRepository;
use App\Repository\ProductRepository;
use App\Service\CartService;
use App\Service\DropshipOrderService;
use App\Service\OrderMailer;
use App\Service\OrderService;
use App\Service\ReceiptService;
use App\Service\VatService;
use App\Repository\VatRateRepository;
use App\Support\Config;
use App\Support\Lang;
use App\Support\Session;
use App\Support\TwigExtension;
use App\Tests\Support\TestDb;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Ciclo di vita della richiesta (docs/06): pending → confirm (numero ricevuta
 * + email col PDF) / cancel. E auto-dropship alla richiesta con l'indirizzo
 * del cliente (AUTO_DROPSHIP_ON_REQUEST).
 */
final class OrderLifecycleTest extends TestCase
{
    private PDO $pdo;
    private OrderRequestRepository $orders;
    private OrderService $service;
    private DropshipOrderService $dropship;
    private DropshipOrderRepository $dropshipOrders;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->pdo = TestDb::create();
        $root = dirname(__DIR__, 2);
        // SMTP volutamente NON configurato: l'invio email fallisce ma il flusso
        // deve completarsi comunque (come in produzione con SMTP giù)
        $config = new Config([
            'ROOT_PATH' => $root,
            'DROPSHIP_ENABLED' => '1',
            'DROPSHIP_MODE' => 'simulation',
            'AUTO_DROPSHIP_ON_REQUEST' => '1',
        ]);
        $lang = new Lang($root);
        $twig = new Environment(new FilesystemLoader($root . '/templates'), ['autoescape' => 'html']);
        $twig->addExtension(new TwigExtension($lang));

        $products = new ProductRepository($this->pdo);
        $session = new Session($config);
        $this->orders = new OrderRequestRepository($this->pdo);
        $this->dropshipOrders = new DropshipOrderRepository($this->pdo);
        $receipts = new ReceiptService($this->pdo, $twig, $lang, $config);
        $this->dropship = new DropshipOrderService(
            $products,
            $this->dropshipOrders,
            new GoldenSneakersDropshipClient($config, new NullLogger()),
            $session,
            $config,
            $lang,
            new NullLogger(),
        );
        $this->service = new OrderService(
            new CartService($session, $products, $config),
            $products,
            $this->orders,
            new OrderMailer($config, $twig, $lang, $receipts),
            new VatService(new VatRateRepository($this->pdo)),
            $receipts,
            $this->dropship,
            $session,
            $config,
            $lang,
            new NullLogger(),
        );
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function seedPendingOrder(): int
    {
        return $this->orders->insert([
            'customer_name' => 'Mario Rossi',
            'company' => null,
            'email' => 'mario.rossi@example.it',
            'phone' => '+393401234567',
            'address_street' => 'Via Montenapoleone 12',
            'address_city' => 'Milano',
            'address_zip' => '20121',
            'notes' => null,
            'locale' => 'it',
            'country_code' => 'IT',
            'vat_number' => null,
            'vat_scheme' => 'domestic',
            'vat_rate' => '22.00',
            'vat_amount' => '134.20',
            'total_gross' => '744.20',
            'total_items' => 6,
            'total_amount' => '610.00',
            'cart_snapshot' => (string) json_encode(['lines' => [[
                'sku' => 'JS3801', 'name' => 'adidas Gazelle', 'brand' => 'Adidas',
                'size_eu' => '42', 'size_us' => '8.5', 'barcode' => 'BC42',
                'qty' => 6, 'unit_price' => '101.67', 'subtotal' => '610.00',
            ]]]),
            'ip_address' => '127.0.0.1',
            'user_agent' => null,
        ]);
    }

    public function testInsertStartsPendingWithoutReceipt(): void
    {
        $id = $this->seedPendingOrder();
        $order = $this->orders->find($id);
        self::assertNotNull($order);
        self::assertSame('pending', $order['status']);
        self::assertNull($order['receipt_number']);
    }

    public function testConfirmAssignsReceiptAndStatus(): void
    {
        $id = $this->seedPendingOrder();
        $result = $this->service->confirm($id);

        self::assertTrue($result['ok']);
        self::assertFalse($result['email_sent'], 'SMTP assente: la conferma resta valida, l\'email no');

        $order = $this->orders->find($id);
        self::assertSame('confirmed', $order['status']);
        self::assertNotNull($order['confirmed_at']);
        self::assertMatchesRegularExpression('/^PF-\d{4}-\d{4}$/', (string) $order['receipt_number']);
    }

    public function testConfirmTwiceIsRejected(): void
    {
        $id = $this->seedPendingOrder();
        self::assertTrue($this->service->confirm($id)['ok']);
        self::assertFalse($this->service->confirm($id)['ok'], 'Una richiesta confermata non si riconferma');
    }

    public function testCancelOnlyWorksWhilePending(): void
    {
        $id = $this->seedPendingOrder();
        self::assertTrue($this->service->cancel($id)['ok']);

        $order = $this->orders->find($id);
        self::assertSame('cancelled', $order['status']);
        self::assertNotNull($order['cancelled_at']);

        self::assertFalse($this->service->cancel($id)['ok']);
        self::assertFalse($this->service->confirm($id)['ok'], 'Una richiesta annullata non è confermabile');
    }

    public function testAdminUpdateRecalculatesTotalsAndVat(): void
    {
        $id = $this->seedPendingOrder();

        // da 6 a 3 pezzi: prezzo unitario quotato invariato, totali e VAT ricalcolati
        $result = $this->service->adminUpdate($id, [0 => 3], renotify: false);

        self::assertTrue($result['ok']);
        self::assertNull($result['email_sent'], 'Senza rinotifica non parte alcuna email');

        $order = $this->orders->find($id);
        // normalizzati a 2 decimali: SQLite non conserva gli zeri finali dei DECIMAL
        $money = static fn (mixed $v): string => number_format((float) $v, 2, '.', '');
        self::assertSame(3, (int) $order['total_items']);
        self::assertSame('305.01', $money($order['total_amount']));
        self::assertSame('67.10', $money($order['vat_amount']));
        self::assertSame('372.11', $money($order['total_gross']));
        $snapshot = json_decode((string) $order['cart_snapshot'], true);
        self::assertSame(3, $snapshot['lines'][0]['qty']);
        self::assertSame('305.01', $snapshot['lines'][0]['subtotal']);
        self::assertSame('101.67', $snapshot['lines'][0]['unit_price'], 'Prezzo quotato invariato');
    }

    public function testAdminUpdateRejectsEmptyResult(): void
    {
        $id = $this->seedPendingOrder();
        $result = $this->service->adminUpdate($id, [0 => 0], renotify: false);

        self::assertFalse($result['ok'], 'Tutte le righe a 0 → errore, meglio annullare la richiesta');
        $order = $this->orders->find($id);
        self::assertSame(6, (int) $order['total_items'], 'Nessuna modifica applicata');
    }

    public function testAdminUpdateOnlyWorksWhilePending(): void
    {
        $id = $this->seedPendingOrder();
        $this->service->confirm($id);

        $result = $this->service->adminUpdate($id, [0 => 1], renotify: false);
        self::assertFalse($result['ok']);
    }

    public function testAutoDropshipUsesCustomerAddress(): void
    {
        $productId = TestDb::seedProduct($this->pdo, 'JS3801', 'adidas Gazelle', 'Adidas', [
            ['size_eu' => '42', 'size_us' => '8.5', 'quantity' => 10],
        ]);
        $this->pdo->exec("UPDATE product_sizes SET supplier_size_id = 11769 WHERE product_id = {$productId}");
        $id = $this->seedPendingOrder();
        $order = $this->orders->find($id);

        $result = $this->dropship->autoCreateFromRequest($order);

        self::assertTrue($result['ok'], (string) $result['message']);
        self::assertTrue($result['simulated'], 'DROPSHIP_MODE=simulation: nessuna chiamata reale');
        $summary = $this->dropshipOrders->findByOrderRequest($id)[0] ?? null;
        self::assertNotNull($summary);
        $record = $this->dropshipOrders->find((int) $summary['id']);
        self::assertNotNull($record);
        $payload = json_decode((string) $record['request_payload'], true);
        self::assertSame('Via Montenapoleone 12', $payload['delivery_address']['street']);
        self::assertSame('IT', $payload['delivery_address']['country_code']);
        self::assertSame([['size_id' => 11769, 'quantity' => 6]], $payload['items']);
    }

    public function testAutoDropshipFailsGracefullyWithoutAddress(): void
    {
        $id = $this->seedPendingOrder();
        $order = $this->orders->find($id);
        $order['address_street'] = '';

        $result = $this->dropship->autoCreateFromRequest($order);

        self::assertFalse($result['ok']);
        self::assertNotNull($result['message']);
        self::assertSame([], $this->dropshipOrders->findByOrderRequest($id));
    }
}
