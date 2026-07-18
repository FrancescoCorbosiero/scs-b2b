<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Accesso a products e product_sizes.
 *
 * ATTENZIONE (Regola d'oro n.1): i metodi usati dalle pagine client
 * (search, sizesForProducts, findActiveBySku, sizesForSku) NON selezionano
 * mai offer_price. Gli unici metodi che lo leggono sono quelli marcati
 * "SOLO USO INTERNO" (sync e email admin).
 */
final class ProductRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    // ── Sync (SOLO USO INTERNO) ──────────────────────────────────────

    public function findIdBySku(string $sku): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM products WHERE sku = ?');
        $stmt->execute([$sku]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * @param array{sku: string, name: string, brand: string, size_mapper: string,
     *   image_url: string|null, total_quantity: int, min_price: string|null} $data
     * @return array{id: int, created: bool}
     */
    public function upsertProduct(array $data, string $seenAt): array
    {
        $id = $this->findIdBySku($data['sku']);
        if ($id === null) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO products (sku, name, brand, size_mapper, image_url, is_active, total_quantity,
                    min_price, created_at, updated_at, last_seen_at)
                 VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $data['sku'], $data['name'], $data['brand'], $data['size_mapper'], $data['image_url'],
                $data['total_quantity'], $data['min_price'],
                $seenAt, $seenAt, $seenAt,
            ]);

            return ['id' => (int) $this->pdo->lastInsertId(), 'created' => true];
        }

        $stmt = $this->pdo->prepare(
            'UPDATE products SET name = ?, brand = ?, size_mapper = ?, image_url = ?, is_active = 1,
                total_quantity = ?, min_price = ?, updated_at = ?, last_seen_at = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $data['name'], $data['brand'], $data['size_mapper'], $data['image_url'],
            $data['total_quantity'], $data['min_price'],
            $seenAt, $seenAt, $id,
        ]);

        return ['id' => $id, 'created' => false];
    }

    /**
     * Sostituisce integralmente le taglie di un prodotto (il feed è la fonte di verità).
     *
     * @param list<array{size_eu: string, size_us: string, barcode: string, quantity: int,
     *   offer_price: string, price: string, supplier_size_id?: int|null}> $sizes
     */
    public function replaceSizes(int $productId, array $sizes): void
    {
        $delete = $this->pdo->prepare('DELETE FROM product_sizes WHERE product_id = ?');
        $delete->execute([$productId]);

        $insert = $this->pdo->prepare(
            'INSERT INTO product_sizes (product_id, size_eu, size_us, barcode, quantity, offer_price,
                price, supplier_size_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($sizes as $size) {
            $insert->execute([
                $productId, $size['size_eu'], $size['size_us'], $size['barcode'], $size['quantity'],
                $size['offer_price'], $size['price'],
                $size['supplier_size_id'] ?? null,
            ]);
        }
    }

    /**
     * Disattiva i prodotti spariti dal feed (mai cancellarli: gli ordini
     * passati li referenziano). Il confronto è sul set di id visti nel run
     * corrente, non su un timestamp: due sync nello stesso secondo non
     * devono confondersi.
     *
     * @param list<int> $seenIds
     */
    public function deactivateExcept(array $seenIds, string $updatedAt): int
    {
        $stmt = $this->pdo->query('SELECT id FROM products WHERE is_active = 1');
        $activeIds = [];
        foreach ($stmt === false ? [] : $stmt->fetchAll() as $row) {
            $activeIds[] = (int) $row['id'];
        }
        $toDeactivate = array_values(array_diff($activeIds, $seenIds));

        foreach (array_chunk($toDeactivate, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $update = $this->pdo->prepare(
                "UPDATE products SET is_active = 0, updated_at = ? WHERE id IN ({$placeholders})"
            );
            $update->execute([$updatedAt, ...$chunk]);
        }

        return count($toDeactivate);
    }

    /**
     * Tutte le taglie con offer_price + brand/nome del prodotto, per il
     * ricalcolo prezzi (--reprice) con le regole margine. SOLO USO INTERNO.
     *
     * @return list<array{id: int, product_id: int, offer_price: string, brand: string, name: string}>
     */
    public function allSizesWithCost(): array
    {
        $stmt = $this->pdo->query(
            'SELECT s.id, s.product_id, s.offer_price, p.brand, p.name
             FROM product_sizes s INNER JOIN products p ON p.id = s.product_id
             ORDER BY s.product_id, s.id'
        );
        $rows = [];
        foreach ($stmt === false ? [] : $stmt->fetchAll() as $row) {
            $rows[] = [
                'id' => (int) $row['id'],
                'product_id' => (int) $row['product_id'],
                'offer_price' => (string) $row['offer_price'],
                'brand' => (string) $row['brand'],
                'name' => (string) $row['name'],
            ];
        }

        return $rows;
    }

    public function updateSizePrice(int $sizeId, string $price): void
    {
        $stmt = $this->pdo->prepare('UPDATE product_sizes SET price = ? WHERE id = ?');
        $stmt->execute([$price, $sizeId]);
    }

    /** Ricalcola il minimo denormalizzato sui prodotti (dopo un reprice). */
    public function refreshMinPrices(): void
    {
        // sintassi portabile MySQL/SQLite: niente alias sulla tabella target
        $this->pdo->exec(
            'UPDATE products SET
                min_price = (SELECT MIN(price) FROM product_sizes WHERE product_sizes.product_id = products.id)'
        );
    }

    /**
     * Righe taglia CON offer_price per l'email admin. SOLO USO INTERNO: mai verso il client.
     *
     * @param list<string> $skus
     * @return array<string, array<string, array{offer_price: string}>> sku => size_eu => dati
     */
    public function costBySkuSize(array $skus): array
    {
        if ($skus === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($skus), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT p.sku, s.size_eu, s.offer_price
             FROM product_sizes s INNER JOIN products p ON p.id = s.product_id
             WHERE p.sku IN ({$placeholders})"
        );
        $stmt->execute(array_values($skus));
        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(string) $row['sku']][(string) $row['size_eu']] = ['offer_price' => (string) $row['offer_price']];
        }

        return $map;
    }

    /**
     * Dati per costruire gli item dell'ordine dropship (docs/09). SOLO USO
     * INTERNO: include offer_price ed è richiamato esclusivamente da /admin.
     *
     * @param list<string> $skus
     * @return array<string, array<string, array{supplier_size_id: int|null, size_us: string,
     *   quantity: int, offer_price: string}>> sku => size_eu => dati
     */
    public function dropshipDataForSkuSizes(array $skus): array
    {
        if ($skus === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($skus), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT p.sku, s.size_eu, s.size_us, s.quantity, s.offer_price, s.supplier_size_id
             FROM product_sizes s INNER JOIN products p ON p.id = s.product_id
             WHERE p.is_active = 1 AND p.sku IN ({$placeholders})"
        );
        $stmt->execute(array_values($skus));
        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(string) $row['sku']][(string) $row['size_eu']] = [
                'supplier_size_id' => $row['supplier_size_id'] !== null ? (int) $row['supplier_size_id'] : null,
                'size_us' => (string) $row['size_us'],
                'quantity' => (int) $row['quantity'],
                'offer_price' => (string) $row['offer_price'],
            ];
        }

        return $map;
    }

    // ── Catalogo (lato client: MAI offer_price) ──────────────────────

    /**
     * @param array{q: string, brand: string, availability: string, recommended: bool,
     *   price_min: float|null, price_max: float|null, sort: string} $filters
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function search(array $filters, int $page, int $perPage, int $highMin, int $lowMax): array
    {
        $where = ['p.is_active = 1'];
        $params = [];

        if ($filters['q'] !== '') {
            $where[] = '(p.name LIKE ? OR p.sku LIKE ?)';
            $like = '%' . addcslashes($filters['q'], '%_\\') . '%';
            $params[] = $like;
            $params[] = $like;
        }
        if ($filters['brand'] !== '') {
            $where[] = 'p.brand = ?';
            $params[] = $filters['brand'];
        }
        if ($filters['recommended']) {
            $where[] = 'p.is_recommended = 1';
        }
        switch ($filters['availability']) {
            case 'alta':
                $where[] = 'p.total_quantity >= ?';
                $params[] = $highMin;
                break;
            case 'media':
                $where[] = 'p.total_quantity > ? AND p.total_quantity < ?';
                $params[] = $lowMax;
                $params[] = $highMin;
                break;
            case 'bassa':
                $where[] = 'p.total_quantity <= ?';
                $params[] = $lowMax;
                break;
        }
        if ($filters['price_min'] !== null) {
            $where[] = 'p.min_price >= ?';
            $params[] = $filters['price_min'];
        }
        if ($filters['price_max'] !== null) {
            $where[] = 'p.min_price <= ?';
            $params[] = $filters['price_max'];
        }

        $whereSql = implode(' AND ', $where);
        $orderSql = match ($filters['sort']) {
            'nome' => 'p.name ASC',
            'prezzo_asc' => 'p.min_price ASC, p.name ASC',
            'prezzo_desc' => 'p.min_price DESC, p.name ASC',
            'disponibilita' => 'p.total_quantity DESC, p.name ASC',
            default => 'p.is_recommended DESC, p.name ASC',
        };

        $count = $this->pdo->prepare("SELECT COUNT(*) FROM products p WHERE {$whereSql}");
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $offset = max(0, ($page - 1) * $perPage);
        $stmt = $this->pdo->prepare(
            "SELECT p.id, p.sku, p.name, p.brand, p.size_mapper, p.image_url, p.is_recommended,
                    p.total_quantity, p.min_price AS price_from
             FROM products p WHERE {$whereSql} ORDER BY {$orderSql} LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);
        /** @var list<array<string, mixed>> $items */
        $items = $stmt->fetchAll();

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Taglie per una lista di prodotti, senza offer_price, col prezzo di listino netto.
     *
     * @param list<int> $productIds
     * @return array<int, list<array{size_eu: string, size_us: string, barcode: string, quantity: int, price: string}>>
     */
    public function sizesForProducts(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT product_id, size_eu, size_us, barcode, quantity, price
             FROM product_sizes WHERE product_id IN ({$placeholders})
             ORDER BY product_id, CAST(size_eu AS DECIMAL(6,2)), size_eu"
        );
        $stmt->execute(array_values($productIds));
        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(int) $row['product_id']][] = [
                'size_eu' => (string) $row['size_eu'],
                'size_us' => (string) $row['size_us'],
                'barcode' => (string) $row['barcode'],
                'quantity' => (int) $row['quantity'],
                'price' => (string) $row['price'],
            ];
        }

        return $map;
    }

    /** @return list<string> */
    public function activeBrands(): array
    {
        $stmt = $this->pdo->query("SELECT DISTINCT brand FROM products WHERE is_active = 1 AND brand <> '' ORDER BY brand");
        $brands = [];
        foreach ($stmt === false ? [] : $stmt->fetchAll() as $row) {
            $brands[] = (string) $row['brand'];
        }

        return $brands;
    }

    /** @return array<string, mixed>|null */
    public function findActiveBySku(string $sku): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, sku, name, brand, size_mapper, image_url, is_recommended, total_quantity
             FROM products WHERE sku = ? AND is_active = 1'
        );
        $stmt->execute([$sku]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Taglie di un prodotto col prezzo di listino (pubblico) ma senza offer_price.
     *
     * @return list<array{size_eu: string, size_us: string, barcode: string, quantity: int, price: string}>
     */
    public function sizesForSku(string $sku): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.size_eu, s.size_us, s.barcode, s.quantity, s.price
             FROM product_sizes s INNER JOIN products p ON p.id = s.product_id
             WHERE p.sku = ? AND p.is_active = 1
             ORDER BY CAST(s.size_eu AS DECIMAL(6,2)), s.size_eu'
        );
        $stmt->execute([$sku]);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = [
                'size_eu' => (string) $row['size_eu'],
                'size_us' => (string) $row['size_us'],
                'barcode' => (string) $row['barcode'],
                'quantity' => (int) $row['quantity'],
                'price' => (string) $row['price'],
            ];
        }

        return $rows;
    }

    public function setRecommended(string $sku, bool $recommended): bool
    {
        $stmt = $this->pdo->prepare('UPDATE products SET is_recommended = ?, updated_at = ? WHERE sku = ?');
        $stmt->execute([$recommended ? 1 : 0, date('Y-m-d H:i:s'), $sku]);

        return $stmt->rowCount() > 0;
    }
}
