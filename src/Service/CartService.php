<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\ProductRepository;
use App\Support\Config;
use App\Support\Session;

/**
 * Carrello in sessione server-side: sku => [size_eu => qty].
 * Le quantità sono sempre validate (clamp) contro lo stock corrente;
 * lo stock può cambiare a ogni sync, quindi revalidate() va richiamata
 * alla submission dell'ordine.
 */
final class CartService
{
    public function __construct(
        private readonly Session $session,
        private readonly ProductRepository $products,
        private readonly Config $config,
    ) {
    }

    public function addProduct(string $sku): bool
    {
        if ($this->products->findActiveBySku($sku) === null) {
            return false;
        }
        $cart = $this->session->cart();
        if (!isset($cart[$sku])) {
            $cart[$sku] = [];
            $this->session->setCart($cart);
        }

        return true;
    }

    /** Imposta la quantità di una taglia, clampata a [0, stock]. Ritorna la quantità applicata. */
    public function setQuantity(string $sku, string $sizeEu, int $qty): int
    {
        $stock = $this->stockFor($sku);
        if (!array_key_exists($sizeEu, $stock)) {
            return 0;
        }
        $applied = max(0, min($qty, $stock[$sizeEu]));
        $cart = $this->session->cart();
        if (!isset($cart[$sku])) {
            $cart[$sku] = [];
        }
        if ($applied > 0) {
            $cart[$sku][$sizeEu] = $applied;
        } else {
            unset($cart[$sku][$sizeEu]);
        }
        $this->session->setCart($cart);

        return $applied;
    }

    /** "Prendi tutto": quantità = stock su ogni taglia disponibile. */
    public function takeAll(string $sku): void
    {
        $cart = $this->session->cart();
        $cart[$sku] = [];
        foreach ($this->stockFor($sku) as $sizeEu => $stock) {
            if ($stock > 0) {
                $cart[$sku][$sizeEu] = $stock;
            }
        }
        $this->session->setCart($cart);
    }

    /** "Svuota": azzera le quantità del prodotto mantenendolo nel carrello. */
    public function clearProduct(string $sku): void
    {
        $cart = $this->session->cart();
        if (isset($cart[$sku])) {
            $cart[$sku] = [];
            $this->session->setCart($cart);
        }
    }

    public function removeProduct(string $sku): void
    {
        $cart = $this->session->cart();
        unset($cart[$sku]);
        $this->session->setCart($cart);
    }

    public function clearAll(): void
    {
        $this->session->setCart([]);
    }

    public function totalItems(): int
    {
        return $this->session->cartItemCount();
    }

    public function minOrderItems(): int
    {
        return max(1, $this->config->int('MIN_ORDER_ITEMS', 5));
    }

    public function meetsMinimum(): bool
    {
        return $this->totalItems() >= $this->minOrderItems();
    }

    /**
     * Vista completa del carrello a prezzi netti (VAT esclusa). MAI offer_price qui.
     *
     * @return array{products: list<array{sku: string, name: string, brand: string,
     *   image_url: string|null, sizes: list<array{size_eu: string, size_us: string,
     *   quantity_stock: int, price: string, qty: int, row_total: string}>,
     *   product_items: int, product_total: string}>, total_items: int, total_amount: string}
     */
    public function detail(): array
    {
        $cart = $this->session->cart();
        $productsOut = [];
        $totalCents = 0;
        $totalItems = 0;

        foreach ($cart as $sku => $quantities) {
            $sku = (string) $sku; // chiavi array numeriche → int in PHP
            $product = $this->products->findActiveBySku($sku);
            if ($product === null) {
                // prodotto sparito dal catalogo: rimosso dal carrello
                unset($cart[$sku]);
                continue;
            }
            $sizes = $this->products->sizesForSku($sku);
            $sizesOut = [];
            $productCents = 0;
            $productItems = 0;
            foreach ($sizes as $size) {
                $price = $size['price'];
                $qty = $quantities[$size['size_eu']] ?? 0;
                $qty = min($qty, $size['quantity']);
                $rowCents = self::cents($price) * $qty;
                $sizesOut[] = [
                    'size_eu' => $size['size_eu'],
                    'size_us' => $size['size_us'],
                    'quantity_stock' => $size['quantity'],
                    'price' => $price,
                    'qty' => $qty,
                    'row_total' => self::money($rowCents),
                ];
                $productCents += $rowCents;
                $productItems += $qty;
            }
            $productsOut[] = [
                'sku' => $sku,
                'name' => (string) $product['name'],
                'brand' => (string) $product['brand'],
                'image_url' => is_string($product['image_url'] ?? null) ? $product['image_url'] : null,
                'sizes' => $sizesOut,
                'product_items' => $productItems,
                'product_total' => self::money($productCents),
            ];
            $totalCents += $productCents;
            $totalItems += $productItems;
        }
        $this->session->setCart($cart);

        return [
            'products' => $productsOut,
            'total_items' => $totalItems,
            'total_amount' => self::money($totalCents),
        ];
    }

    /**
     * Riallinea il carrello allo stock corrente (può essere cambiato da un sync).
     *
     * @return list<array{sku: string, size_eu: string, requested: int, available: int}>
     *   le righe che sono state ridotte o rimosse
     */
    public function revalidate(): array
    {
        $adjustments = [];
        $cart = $this->session->cart();
        foreach ($cart as $sku => $quantities) {
            $sku = (string) $sku; // chiavi array numeriche → int in PHP
            $stock = $this->stockFor($sku);
            foreach ($quantities as $sizeEu => $qty) {
                $available = $stock[$sizeEu] ?? 0;
                if ($qty > $available) {
                    $adjustments[] = [
                        'sku' => $sku,
                        // le chiavi array numeriche diventano int in PHP: si ricasta
                        'size_eu' => (string) $sizeEu,
                        'requested' => $qty,
                        'available' => $available,
                    ];
                    if ($available > 0) {
                        $cart[$sku][$sizeEu] = $available;
                    } else {
                        unset($cart[$sku][$sizeEu]);
                    }
                }
            }
        }
        $this->session->setCart($cart);

        return $adjustments;
    }

    /** @return array<string, int> size_eu => stock disponibile */
    private function stockFor(string $sku): array
    {
        $stock = [];
        foreach ($this->products->sizesForSku($sku) as $size) {
            $stock[$size['size_eu']] = $size['quantity'];
        }

        return $stock;
    }

    public static function cents(string $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    public static function money(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), abs($cents) % 100);
    }
}
