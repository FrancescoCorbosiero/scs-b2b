<?php

declare(strict_types=1);

namespace App\Service;

use App\Adapter\FeedException;
use App\Adapter\GoldenSneakersAdapter;
use App\Repository\ProductRepository;
use App\Repository\SyncLogRepository;
use App\Support\Config;
use PDO;
use Psr\Log\LoggerInterface;

/**
 * Sync del catalogo dal feed GoldenSneakers.
 *
 * - Idempotente: due run consecutivi sullo stesso feed producono lo stesso stato.
 * - Transazionale: il payload viene scaricato e validato TUTTO prima di toccare
 *   il DB; qualsiasi errore → rollback, il catalogo resta com'era.
 * - I prodotti spariti dal feed vengono disattivati (mai cancellati).
 * - Il prezzo netto (VAT esclusa) è precalcolato qui, a livello taglia, con
 *   il margine risolto dalle regole admin (MarginResolver).
 * - Lock su file per evitare run concorrenti (cron + "Sincronizza ora").
 */
final class FeedSyncService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly GoldenSneakersAdapter $adapter,
        private readonly ProductRepository $products,
        private readonly SyncLogRepository $syncLogs,
        private readonly PricingService $pricing,
        private readonly MarginResolver $margins,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{status: string, rows_read: int, products_created: int,
     *   products_updated: int, products_deactivated: int, message: string|null}
     */
    public function run(bool $repriceOnly = false): array
    {
        $lock = $this->acquireLock();
        if ($lock === null) {
            return [
                'status' => 'skipped', 'rows_read' => 0, 'products_created' => 0,
                'products_updated' => 0, 'products_deactivated' => 0,
                'message' => 'Un altro sync è già in corso',
            ];
        }

        try {
            return $repriceOnly ? $this->reprice() : $this->syncFromFeed();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @return array{status: string, rows_read: int, products_created: int,
     *   products_updated: int, products_deactivated: int, message: string|null}
     */
    private function syncFromFeed(): array
    {
        $logId = $this->syncLogs->start();
        $counters = ['rows_read' => 0, 'products_created' => 0, 'products_updated' => 0, 'products_deactivated' => 0];

        try {
            // 1. Scarica e valida TUTTO prima di toccare il DB
            $rows = $this->adapter->fetch();
            $counters['rows_read'] = count($rows);
            if ($rows === []) {
                throw new FeedException('Feed vuoto: sync abortito per sicurezza (il catalogo resta invariato)');
            }
            $grouped = $this->groupBySku($rows);

            // 2. Applica in transazione
            $seenAt = date('Y-m-d H:i:s');
            $seenIds = [];
            $this->pdo->beginTransaction();
            foreach ($grouped as $sku => $product) {
                // gli SKU numerici diventano chiavi int in PHP: si ricasta
                $sku = (string) $sku;
                $margin = $this->margins->resolve($product['brand'], $product['name']);
                $sizes = [];
                $totalQuantity = 0;
                $min = null;
                foreach ($product['sizes'] as $size) {
                    $price = $this->pricing->netPrice($size['offer_price'], $margin['margin_type'], $margin['margin_value']);
                    $sizes[] = [
                        'size_eu' => $size['size_eu'],
                        'size_us' => $size['size_us'],
                        'barcode' => $size['barcode'],
                        'quantity' => $size['quantity'],
                        'offer_price' => $size['offer_price'],
                        'price' => $price,
                        'supplier_size_id' => $size['supplier_size_id'],
                    ];
                    $totalQuantity += $size['quantity'];
                    if ($min === null || (float) $price < (float) $min) {
                        $min = $price;
                    }
                }

                $result = $this->products->upsertProduct([
                    'sku' => $sku,
                    'name' => $product['name'],
                    'brand' => $product['brand'],
                    'size_mapper' => $product['size_mapper'],
                    'image_url' => $product['image_url'],
                    'total_quantity' => $totalQuantity,
                    'min_price' => $min,
                ], $seenAt);
                $this->products->replaceSizes($result['id'], $sizes);
                $seenIds[] = $result['id'];
                $counters[$result['created'] ? 'products_created' : 'products_updated']++;
            }
            $counters['products_deactivated'] = $this->products->deactivateExcept($seenIds, $seenAt);
            $this->pdo->commit();

            $this->syncLogs->finish($logId, 'ok', $counters);
            $this->logger->info('Sync feed completato', $counters);

            return $counters + ['status' => 'ok', 'message' => null];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->syncLogs->finish($logId, 'error', $counters, $e->getMessage());
            $this->logger->error('Sync feed fallito', ['error' => $e->getMessage()]);

            return $counters + ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Ricalcola i prezzi da offer_price già in DB (dopo una modifica alle
     * regole margine da /admin/margini, senza riscaricare il feed).
     *
     * @return array{status: string, rows_read: int, products_created: int,
     *   products_updated: int, products_deactivated: int, message: string|null}
     */
    private function reprice(): array
    {
        $logId = $this->syncLogs->start();
        $counters = ['rows_read' => 0, 'products_created' => 0, 'products_updated' => 0, 'products_deactivated' => 0];

        try {
            $this->margins->clearCache();
            $this->pdo->beginTransaction();
            $marginByProduct = [];
            foreach ($this->products->allSizesWithCost() as $size) {
                // il margine dipende solo da brand/nome: si risolve una volta per prodotto
                $margin = $marginByProduct[$size['product_id']]
                    ??= $this->margins->resolve($size['brand'], $size['name']);
                $this->products->updateSizePrice(
                    $size['id'],
                    $this->pricing->netPrice($size['offer_price'], $margin['margin_type'], $margin['margin_value']),
                );
                $counters['rows_read']++;
            }
            $this->products->refreshMinPrices();
            $this->pdo->commit();

            $this->syncLogs->finish($logId, 'ok', $counters, 'Reprice (nessun download feed)');
            $this->logger->info('Reprice completato', $counters);

            return $counters + ['status' => 'ok', 'message' => 'Reprice (nessun download feed)'];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->syncLogs->finish($logId, 'error', $counters, $e->getMessage());
            $this->logger->error('Reprice fallito', ['error' => $e->getMessage()]);

            return $counters + ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Raggruppa le righe flat per SKU. offer_price resta a livello taglia
     * (può variare tra taglie dello stesso SKU, vedi docs/03).
     *
     * @param list<array{sku: string, name: string, brand: string, size_mapper: string,
     *   size_eu: string, size_us: string, barcode: string, offer_price: string,
     *   quantity: int, image_url: string|null, supplier_size_id: int|null}> $rows
     * @return array<string, array{name: string, brand: string, size_mapper: string,
     *   image_url: string|null, sizes: list<array{size_eu: string, size_us: string,
     *   barcode: string, offer_price: string, quantity: int, supplier_size_id: int|null}>}>
     */
    private function groupBySku(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $sku = $row['sku'];
            if (!isset($grouped[$sku])) {
                $grouped[$sku] = [
                    'name' => $row['name'],
                    'brand' => $row['brand'],
                    'size_mapper' => $row['size_mapper'],
                    'image_url' => $row['image_url'],
                    'sizes' => [],
                ];
            }
            if ($grouped[$sku]['image_url'] === null && $row['image_url'] !== null) {
                $grouped[$sku]['image_url'] = $row['image_url'];
            }
            // taglia duplicata per lo stesso SKU: tiene l'ultima riga (feed sporco)
            $grouped[$sku]['sizes'][$row['size_eu']] = [
                'size_eu' => $row['size_eu'],
                'size_us' => $row['size_us'],
                'barcode' => $row['barcode'],
                'offer_price' => $row['offer_price'],
                'quantity' => $row['quantity'],
                'supplier_size_id' => $row['supplier_size_id'],
            ];
        }

        foreach ($grouped as $sku => $product) {
            $grouped[$sku]['sizes'] = array_values($product['sizes']);
        }

        /** @var array<string, array{name: string, brand: string, size_mapper: string,
         *   image_url: string|null, sizes: list<array{size_eu: string, size_us: string,
         *   barcode: string, offer_price: string, quantity: int, supplier_size_id: int|null}>}> $grouped */
        return $grouped;
    }

    /** @return resource|null */
    private function acquireLock()
    {
        $dir = $this->config->rootPath() . '/logs';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $handle = fopen($dir . '/sync.lock', 'c');
        if ($handle === false) {
            return null;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }

        return $handle;
    }
}
