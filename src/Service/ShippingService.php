<?php

declare(strict_types=1);

namespace App\Service;

use App\Support\Config;

/**
 * Costi di spedizione (docs/06): tariffa unica forfettaria, gratuita a partire
 * da una soglia di pezzi.
 *
 * - da `FREE_SHIPPING_MIN_ITEMS` pezzi in su (default 7) → spedizione gratuita
 * - sotto la soglia → `SHIPPING_FEE` (default 10,00 €)
 *
 * L'importo è NETTO (VAT esclusa) come tutti i prezzi del listino: la spesa di
 * spedizione è accessoria alla cessione dei beni e segue quindi lo stesso
 * regime VAT dell'ordine (entra nell'imponibile in `OrderService`).
 */
final class ShippingService
{
    private const DEFAULT_FREE_FROM_ITEMS = 7;
    private const DEFAULT_FEE = 10.0;

    public function __construct(private readonly Config $config)
    {
    }

    /** Pezzi a partire dai quali la spedizione è gratuita. */
    public function freeFromItems(): int
    {
        return max(1, $this->config->int('FREE_SHIPPING_MIN_ITEMS', self::DEFAULT_FREE_FROM_ITEMS));
    }

    /** Tariffa piena (sotto soglia), come stringa DECIMAL(10,2). */
    public function fee(): string
    {
        return CartService::money(max(0, (int) round($this->config->float('SHIPPING_FEE', self::DEFAULT_FEE) * 100)));
    }

    /** Spedizione dovuta per un carrello di N pezzi (carrello vuoto = 0,00). */
    public function amountFor(int $totalItems): string
    {
        if ($totalItems < 1 || $totalItems >= $this->freeFromItems()) {
            return '0.00';
        }

        return $this->fee();
    }

    public function isFree(int $totalItems): bool
    {
        return CartService::cents($this->amountFor($totalItems)) === 0;
    }

    /** Pezzi ancora mancanti alla spedizione gratuita (0 = già raggiunta). */
    public function itemsToFree(int $totalItems): int
    {
        return $this->isFree($totalItems) ? 0 : max(0, $this->freeFromItems() - $totalItems);
    }

    /**
     * Preventivo completo per la vista (carrello, checkout, JSON).
     *
     * @return array{amount: string, fee: string, free: bool, free_from: int, items_to_free: int}
     */
    public function quote(int $totalItems): array
    {
        return [
            'amount' => $this->amountFor($totalItems),
            'fee' => $this->fee(),
            'free' => $this->isFree($totalItems),
            'free_from' => $this->freeFromItems(),
            'items_to_free' => $this->itemsToFree($totalItems),
        ];
    }
}
