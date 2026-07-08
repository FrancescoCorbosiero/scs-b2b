<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Repository\ProductRepository;
use App\Service\CartService;
use App\Support\Config;
use App\Support\Session;
use App\Tests\Support\TestDb;
use PHPUnit\Framework\TestCase;

final class CartServiceTest extends TestCase
{
    private CartService $cart;

    protected function setUp(): void
    {
        $_SESSION = [];
        $pdo = TestDb::create();
        TestDb::seedProduct($pdo, 'NK1001', 'Nike Dunk Low', 'Nike', [
            ['size_eu' => '42', 'quantity' => 5, 'price_base' => '100.00', 'price_pro' => '95.00', 'price_max' => '90.00'],
            ['size_eu' => '43', 'quantity' => 0, 'price_base' => '100.00', 'price_pro' => '95.00', 'price_max' => '90.00'],
            ['size_eu' => '44', 'quantity' => 2, 'price_base' => '100.00', 'price_pro' => '95.00', 'price_max' => '90.00'],
        ]);
        $config = new Config(['MIN_ORDER_ITEMS' => '5']);
        $this->cart = new CartService(new Session($config), new ProductRepository($pdo), $config);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testQuantityIsClampedToStock(): void
    {
        $this->cart->addProduct('NK1001');
        self::assertSame(5, $this->cart->setQuantity('NK1001', '42', 99), 'Sopra lo stock → clamp a 5');
        self::assertSame(0, $this->cart->setQuantity('NK1001', '43', 3), 'Taglia esaurita → 0');
        self::assertSame(0, $this->cart->setQuantity('NK1001', '42', -4), 'Quantità negativa → 0');
        self::assertSame(0, $this->cart->setQuantity('NK1001', '99', 1), 'Taglia inesistente → 0');
    }

    public function testUnknownSkuIsRejected(): void
    {
        self::assertFalse($this->cart->addProduct('BOGUS'));
    }

    public function testMinimumOrderRule(): void
    {
        $this->cart->addProduct('NK1001');
        $this->cart->setQuantity('NK1001', '42', 4);
        self::assertFalse($this->cart->meetsMinimum(), '4 pezzi < minimo 5');

        $this->cart->setQuantity('NK1001', '44', 1);
        self::assertTrue($this->cart->meetsMinimum(), '5 pezzi = minimo raggiunto');
    }

    public function testTakeAllAndTotalsPerPlan(): void
    {
        $this->cart->takeAll('NK1001');
        self::assertSame(7, $this->cart->totalItems(), '5 + 0 + 2 pezzi');

        $base = $this->cart->detail('base');
        self::assertSame('700.00', $base['total_amount']);

        $max = $this->cart->detail('max');
        self::assertSame('630.00', $max['total_amount'], 'Cambio piano ricalcola i prezzi, non le quantità');
        self::assertSame(7, $max['total_items']);
    }

    public function testRevalidateShrinksCartWhenStockDrops(): void
    {
        $this->cart->addProduct('NK1001');
        $this->cart->setQuantity('NK1001', '42', 5);

        // il sync riduce lo stock della 42 a 1
        $pdo = TestDb::create();
        TestDb::seedProduct($pdo, 'NK1001', 'Nike Dunk Low', 'Nike', [
            ['size_eu' => '42', 'quantity' => 1],
        ]);
        $cart = new CartService(new Session(new Config([])), new ProductRepository($pdo), new Config([]));

        $adjustments = $cart->revalidate();
        self::assertCount(1, $adjustments);
        self::assertSame('42', $adjustments[0]['size_eu']);
        self::assertSame(5, $adjustments[0]['requested']);
        self::assertSame(1, $adjustments[0]['available']);
        self::assertSame(1, $cart->totalItems(), 'Il carrello viene ridotto allo stock reale');
    }

    public function testDetailNeverExposesOfferPrice(): void
    {
        $this->cart->takeAll('NK1001');
        $detail = $this->cart->detail('base');

        $json = json_encode($detail, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('offer_price', $json, 'Regola d\'oro n.1: mai offer_price verso il client');
    }
}
