<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\ShippingService;
use App\Support\Config;
use PHPUnit\Framework\TestCase;

/**
 * Regola spedizione (docs/06): gratuita da FREE_SHIPPING_MIN_ITEMS paia in su,
 * forfait SHIPPING_FEE sotto soglia.
 */
final class ShippingServiceTest extends TestCase
{
    private ShippingService $shipping;

    protected function setUp(): void
    {
        $this->shipping = new ShippingService(new Config([]));
    }

    public function testDefaultsAreSevenPairsAndTenEuro(): void
    {
        self::assertSame(7, $this->shipping->freeFromItems());
        self::assertSame('10.00', $this->shipping->fee());
    }

    public function testFreeFromThresholdUp(): void
    {
        self::assertSame('0.00', $this->shipping->amountFor(7), '7 paia = soglia raggiunta');
        self::assertSame('0.00', $this->shipping->amountFor(20));
        self::assertTrue($this->shipping->isFree(7));
    }

    public function testFeeBelowThreshold(): void
    {
        self::assertSame('10.00', $this->shipping->amountFor(1));
        self::assertSame('10.00', $this->shipping->amountFor(6));
        self::assertFalse($this->shipping->isFree(6));
        self::assertSame(1, $this->shipping->itemsToFree(6));
        self::assertSame(0, $this->shipping->itemsToFree(7));
    }

    public function testEmptyCartHasNoShipping(): void
    {
        self::assertSame('0.00', $this->shipping->amountFor(0), 'Carrello vuoto: nessun costo da mostrare');
    }

    public function testThresholdAndFeeAreConfigurable(): void
    {
        $shipping = new ShippingService(new Config(['FREE_SHIPPING_MIN_ITEMS' => '10', 'SHIPPING_FEE' => '14.9']));

        self::assertSame(10, $shipping->freeFromItems());
        self::assertSame('14.90', $shipping->amountFor(9));
        self::assertSame('0.00', $shipping->amountFor(10));
    }

    public function testQuoteShape(): void
    {
        self::assertSame([
            'amount' => '10.00',
            'fee' => '10.00',
            'free' => false,
            'free_from' => 7,
            'items_to_free' => 3,
        ], $this->shipping->quote(4));
    }
}
