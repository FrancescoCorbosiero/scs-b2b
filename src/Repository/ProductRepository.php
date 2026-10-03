<?php

declare(strict_types=1);

namespace App\Repository;

use App\Service\SizeCategory;
use PDO;

/**
 * Accesso a products e product_sizes.
 *
 * ATTENZIONE (Regola d'oro n.1): i metodi usati dalle pagine client
 * (search, sizesForProducts, findActiveBySku, sizesForSku) NON selezionano
 * mai offer_price. Gli unici metodi che lo leggono sono quelli marcati
 * "SOLO USO INTERNO" (sync e email admin).
 *
 * Due origini, mai mescolate (colonna `source`): i prodotti del feed
 * GoldenSneakers e i prodotti propri importati da /admin/prodotti-propri.
 * Lo SKU è unico su tutto il catalogo: ogni scrittura di un'origine lascia
 * intatti i prodotti dell'altra.
 */
final class ProductRepository
{
    /** Origine del prodotto (colonna `source`): feed GoldenSneakers o prodotto proprio importato da /admin. */
    public const SOURCE_FEED = 'feed';
    public const SOURCE_CUSTOM = 'custom';
    public const SOURCES = [self::SOURCE_FEED, self::SOURCE_CUSTOM];

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

    /** @return array{id: int, source: string}|null */
    public function findIdAndSourceBySku(string $sku): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, source FROM products WHERE sku = ?');
        $stmt->execute([$sku]);
        $row = $stmt->fetch();

        return $row === false ? null : ['id' => (int) $row['id'], 'source' => (string) $row['source']];
    }

    /**
     * Origine degli SKU indicati già presenti a catalogo (attivi o no).
     *
     * @param list<string> $skus
     * @return array<string, string> sku => source
     */
    public function sourcesBySku(array $skus): array
    {
        $map = [];
        foreach (array_chunk(array_values(array_unique($skus)), 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->pdo->prepare("SELECT sku, source FROM products WHERE sku IN ({$placeholders})");
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll() as $row) {
                $map[(string) $row['sku']] = (string) $row['source'];
            }
        }

        return $map;
    }

    /**
     * Crea o aggiorna un prodotto dell'origine indicata (default: feed).
     * Uno SKU che appartiene all'ALTRA origine non viene toccato
     * (skipped = true): feed e prodotti propri non si sovrascrivono mai.
     *
     * @param array{sku: string, name: string, brand: string, size_mapper: string,
     *   size_category: string, image_url: string|null, total_quantity: int,
     *   min_price: string|null, source?: string} $data
     * @return array{id: int, created: bool, skipped: bool}
     */
    public function upsertProduct(array $data, string $seenAt): array
    {
        $source = $data['source'] ?? self::SOURCE_FEED;
        $existing = $this->findIdAndSourceBySku($data['sku']);
        if ($existing === null) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO products (sku, source, name, brand, size_mapper, size_category, image_url, is_active,
                    total_quantity, min_price, created_at, updated_at, last_seen_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $data['sku'], $source, $data['name'], $data['brand'], $data['size_mapper'], $data['size_category'],
                $data['image_url'], $data['total_quantity'], $data['min_price'],
                $seenAt, $seenAt, $seenAt,
            ]);

            return ['id' => (int) $this->pdo->lastInsertId(), 'created' => true, 'skipped' => false];
        }
        if ($existing['source'] !== $source) {
            return ['id' => $existing['id'], 'created' => false, 'skipped' => true];
        }
        $id = $existing['id'];

        // image_url con COALESCE: un sync in cui il feed arriva SENZA campi
        // immagine (glitch del fornitore) non deve cancellare le immagini
        // già note — meglio un'immagine vecchia che nessuna immagine
        $stmt = $this->pdo->prepare(
            'UPDATE products SET name = ?, brand = ?, size_mapper = ?, size_category = ?,
                image_url = COALESCE(?, image_url), is_active = 1,
                total_quantity = ?, min_price = ?, updated_at = ?, last_seen_at = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $data['name'], $data['brand'], $data['size_mapper'], $data['size_category'], $data['image_url'],
            $data['total_quantity'], $data['min_price'],
            $seenAt, $seenAt, $id,
        ]);

        return ['id' => $id, 'created' => false, 'skipped' => false];
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
     * devono confondersi. Riguarda SOLO l'origine indicata: il sync del feed
     * non disattiva mai un prodotto proprio, e viceversa.
     *
     * @param list<int> $seenIds
     */
    public function deactivateExcept(array $seenIds, string $updatedAt, string $source = self::SOURCE_FEED): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM products WHERE is_active = 1 AND source = ?');
        $stmt->execute([$source]);
        $activeIds = [];
        foreach ($stmt->fetchAll() as $row) {
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
     * Tutte le taglie con offer_price + i dati del prodotto che servono a
     * risolvere il margine (brand/nome/SKU/categoria) e a ricalcolare la
     * categoria di taglia (size_mapper + taglia EU). Usata dal reprice
     * (--reprice). SOLO USO INTERNO.
     *
     * @return list<array{id: int, product_id: int, offer_price: string, brand: string, name: string,
     *   sku: string, size_mapper: string, size_category: string, size_eu: string}>
     */
    public function allSizesWithCost(): array
    {
        $stmt = $this->pdo->query(
            'SELECT s.id, s.product_id, s.offer_price, s.size_eu, p.brand, p.name, p.sku,
                    p.size_mapper, p.size_category
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
                'sku' => (string) $row['sku'],
                'size_mapper' => (string) ($row['size_mapper'] ?? ''),
                'size_category' => (string) ($row['size_category'] ?? ''),
                'size_eu' => (string) $row['size_eu'],
            ];
        }

        return $rows;
    }

    public function updateSizePrice(int $sizeId, string $price): void
    {
        $stmt = $this->pdo->prepare('UPDATE product_sizes SET price = ? WHERE id = ?');
        $stmt->execute([$price, $sizeId]);
    }

    /** Riallinea la categoria di taglia dedotta (sync/reprice). */
    public function updateSizeCategory(int $productId, string $category): void
    {
        $stmt = $this->pdo->prepare('UPDATE products SET size_category = ? WHERE id = ?');
        $stmt->execute([$category, $productId]);
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
     * Dati per costruire gli item dell'ordine presso GoldenSneakers (docs/09).
     * SOLO USO INTERNO: include offer_price ed è richiamato esclusivamente da
     * /admin e dall'invio automatico. `source` serve a escludere i prodotti
     * propri, che non vanno mai ordinati al fornitore.
     *
     * @param list<string> $skus
     * @return array<string, array<string, array{supplier_size_id: int|null, size_us: string,
     *   quantity: int, offer_price: string, source: string}>> sku => size_eu => dati
     */
    public function dropshipDataForSkuSizes(array $skus): array
    {
        if ($skus === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($skus), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT p.sku, p.source, s.size_eu, s.size_us, s.quantity, s.offer_price, s.supplier_size_id
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
                'source' => (string) ($row['source'] ?? self::SOURCE_FEED),
            ];
        }

        return $map;
    }

    // ── Catalogo (lato client: MAI offer_price) ──────────────────────

    /**
     * `source` (facoltativo) limita la ricerca a una sezione del catalogo:
     * prodotti del feed o prodotti propri, che non si mescolano.
     *
     * @param array{q: string, brand: string, availability: string, recommended: bool,
     *   price_min: float|null, price_max: float|null, sort: string,
     *   sizes?: list<string>, in_stock?: bool, size_categories?: list<string>, source?: string|null} $filters
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function search(array $filters, int $page, int $perPage, int $highMin, int $lowMax): array
    {
        $where = ['p.is_active = 1'];
        $params = [];
        $source = $filters['source'] ?? null;
        if (is_string($source) && in_array($source, self::SOURCES, true)) {
            $where[] = 'p.source = ?';
            $params[] = $source;
        }

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
        // taglie: il prodotto passa se ha stock in ALMENO una delle taglie scelte
        $sizes = array_values(array_filter($filters['sizes'] ?? [], static fn (string $s): bool => $s !== ''));
        if ($sizes !== []) {
            $placeholders = implode(',', array_fill(0, count($sizes), '?'));
            $where[] = "EXISTS (SELECT 1 FROM product_sizes ps
                                WHERE ps.product_id = p.id AND ps.quantity > 0 AND ps.size_eu IN ({$placeholders}))";
            foreach ($sizes as $size) {
                $params[] = $size;
            }
        }
        if ($filters['in_stock'] ?? false) {
            $where[] = 'p.total_quantity > 0';
        }
        // categoria di taglia: normali / GS / PS (OR fra quelle scelte)
        $categories = array_values(array_filter(
            $filters['size_categories'] ?? [],
            static fn (string $c): bool => in_array($c, SizeCategory::ALL, true),
        ));
        if ($categories !== [] && count($categories) < count(SizeCategory::ALL)) {
            $placeholders = implode(',', array_fill(0, count($categories), '?'));
            $where[] = "p.size_category IN ({$placeholders})";
            foreach ($categories as $category) {
                $params[] = $category;
            }
        }

        $whereSql = implode(' AND ', $where);
        $orderSql = match ($filters['sort']) {
            'nome' => 'p.name ASC',
            'prezzo_asc' => 'p.min_price ASC, p.name ASC',
            'prezzo_desc' => 'p.min_price DESC, p.name ASC',
            'disponibilita' => 'p.total_quantity DESC, p.name ASC',
            default => 'p.is_recommended DESC, p.name ASC',
        };
        // Gli esauriti restano a catalogo (il fornitore li tiene nel feed a
        // quantità 0) ma vanno SEMPRE in fondo, qualunque sia l'ordinamento:
        // il rivenditore vede prima ciò che può ordinare davvero.
        $orderSql = 'CASE WHEN p.total_quantity > 0 THEN 0 ELSE 1 END ASC, ' . $orderSql;

        $count = $this->pdo->prepare("SELECT COUNT(*) FROM products p WHERE {$whereSql}");
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $offset = max(0, ($page - 1) * $perPage);
        $stmt = $this->pdo->prepare(
            "SELECT p.id, p.sku, p.name, p.brand, p.size_mapper, p.size_category, p.image_url, p.is_recommended,
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

    /**
     * Brand attivi con numero di prodotti (per la navigazione brand del
     * catalogo), eventualmente per una sola sezione (source).
     *
     * @return list<array{brand: string, products: int}>
     */
    public function activeBrandsWithCounts(?string $source = null): array
    {
        [$sourceSql, $params] = self::sourceClause($source, '');
        $stmt = $this->pdo->prepare(
            "SELECT brand, COUNT(*) AS products FROM products
             WHERE is_active = 1 AND brand <> ''{$sourceSql} GROUP BY brand ORDER BY brand"
        );
        $stmt->execute($params);
        $brands = [];
        foreach ($stmt->fetchAll() as $row) {
            $brands[] = ['brand' => (string) $row['brand'], 'products' => (int) $row['products']];
        }

        return $brands;
    }

    /**
     * Taglie disponibili (stock > 0) con quanti prodotti le hanno: alimenta il
     * filtro taglia del catalogo. Ordinamento numerico (39 < 40 < 40.5).
     *
     * @return list<array{size_eu: string, size_us: string, products: int}>
     */
    public function activeSizesWithCounts(?string $source = null): array
    {
        [$sourceSql, $params] = self::sourceClause($source, 'p.');
        $stmt = $this->pdo->prepare(
            "SELECT s.size_eu, MAX(s.size_us) AS size_us, COUNT(DISTINCT s.product_id) AS products
             FROM product_sizes s INNER JOIN products p ON p.id = s.product_id
             WHERE p.is_active = 1 AND s.quantity > 0 AND s.size_eu <> ''{$sourceSql}
             GROUP BY s.size_eu
             ORDER BY CAST(s.size_eu AS DECIMAL(6,2)), s.size_eu"
        );
        $stmt->execute($params);
        $sizes = [];
        foreach ($stmt->fetchAll() as $row) {
            $sizes[] = [
                'size_eu' => (string) $row['size_eu'],
                'size_us' => (string) ($row['size_us'] ?? ''),
                'products' => (int) $row['products'],
            ];
        }

        return $sizes;
    }

    /**
     * Prodotti attivi per categoria di taglia (normali/GS/PS): alimenta il
     * filtro categoria del catalogo. Le categorie senza prodotti restano a 0,
     * così il filtro è stabile anche quando il feed cambia assortimento.
     *
     * @return array<string, int> categoria => numero di prodotti
     */
    public function activeSizeCategoryCounts(?string $source = null): array
    {
        $counts = array_fill_keys(SizeCategory::ALL, 0);
        [$sourceSql, $params] = self::sourceClause($source, '');
        $stmt = $this->pdo->prepare(
            "SELECT size_category, COUNT(*) AS products FROM products
             WHERE is_active = 1{$sourceSql} GROUP BY size_category"
        );
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $row) {
            $category = SizeCategory::normalize((string) $row['size_category']);
            $counts[$category] += (int) $row['products'];
        }

        return $counts;
    }

    /** @return array<string, mixed>|null */
    public function findActiveBySku(string $sku): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, sku, source, name, brand, size_mapper, size_category, image_url, is_recommended, total_quantity
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

    /**
     * Prodotti attivi per sezione del catalogo: le schede "Catalogo" /
     * "Disponibili in sede" compaiono solo se la seconda ha prodotti.
     *
     * @return array<string, int> source => prodotti attivi
     */
    public function activeCountsBySource(): array
    {
        $counts = array_fill_keys(self::SOURCES, 0);
        $stmt = $this->pdo->query('SELECT source, COUNT(*) AS products FROM products WHERE is_active = 1 GROUP BY source');
        foreach ($stmt === false ? [] : $stmt->fetchAll() as $row) {
            if (isset($counts[(string) $row['source']])) {
                $counts[(string) $row['source']] = (int) $row['products'];
            }
        }

        return $counts;
    }

    // ── Prodotti propri (/admin/prodotti-propri) ─────────────────────

    /**
     * Tutti i prodotti propri, attivi e no, con numero di taglie e stock.
     * Pagina admin: niente offer_price qui comunque, non serve.
     *
     * @return list<array{id: int, sku: string, name: string, brand: string, size_category: string,
     *   image_url: string|null, is_active: bool, total_quantity: int, min_price: string|null,
     *   sizes: int, updated_at: string}>
     */
    public function customProducts(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.id, p.sku, p.name, p.brand, p.size_category, p.image_url, p.is_active, p.total_quantity,
                    p.min_price, p.updated_at, COUNT(s.id) AS sizes
             FROM products p LEFT JOIN product_sizes s ON s.product_id = p.id
             WHERE p.source = ?
             GROUP BY p.id, p.sku, p.name, p.brand, p.size_category, p.image_url, p.is_active,
                      p.total_quantity, p.min_price, p.updated_at
             ORDER BY p.is_active DESC, p.name ASC'
        );
        $stmt->execute([self::SOURCE_CUSTOM]);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = [
                'id' => (int) $row['id'],
                'sku' => (string) $row['sku'],
                'name' => (string) $row['name'],
                'brand' => (string) $row['brand'],
                'size_category' => (string) $row['size_category'],
                'image_url' => is_string($row['image_url'] ?? null) ? $row['image_url'] : null,
                'is_active' => (int) $row['is_active'] === 1,
                'total_quantity' => (int) $row['total_quantity'],
                'min_price' => $row['min_price'] !== null ? (string) $row['min_price'] : null,
                'sizes' => (int) $row['sizes'],
                'updated_at' => (string) $row['updated_at'],
            ];
        }

        return $rows;
    }

    /** Attiva/disattiva un prodotto proprio (mai uno del feed). */
    public function setCustomActive(int $id, bool $active): bool
    {
        $stmt = $this->pdo->prepare('UPDATE products SET is_active = ?, updated_at = ? WHERE id = ? AND source = ?');
        $stmt->execute([$active ? 1 : 0, date('Y-m-d H:i:s'), $id, self::SOURCE_CUSTOM]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Elimina un prodotto proprio con le sue taglie (mai uno del feed). Le
     * richieste d'ordine passate non ne risentono: hanno lo snapshot.
     */
    public function deleteCustom(int $id): bool
    {
        $owned = $this->pdo->prepare('SELECT id FROM products WHERE id = ? AND source = ?');
        $owned->execute([$id, self::SOURCE_CUSTOM]);
        if ($owned->fetchColumn() === false) {
            return false;
        }
        // taglie esplicite: non si conta sul CASCADE (spento su SQLite)
        $this->pdo->prepare('DELETE FROM product_sizes WHERE product_id = ?')->execute([$id]);
        $stmt = $this->pdo->prepare('DELETE FROM products WHERE id = ? AND source = ?');
        $stmt->execute([$id, self::SOURCE_CUSTOM]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Frammento SQL che limita a una sezione (source); vuoto se null.
     *
     * @return array{0: string, 1: list<string>}
     */
    private static function sourceClause(?string $source, string $alias): array
    {
        if ($source === null || !in_array($source, self::SOURCES, true)) {
            return ['', []];
        }

        return [" AND {$alias}source = ?", [$source]];
    }

    public function setRecommended(string $sku, bool $recommended): bool
    {
        $stmt = $this->pdo->prepare('UPDATE products SET is_recommended = ?, updated_at = ? WHERE sku = ?');
        $stmt->execute([$recommended ? 1 : 0, date('Y-m-d H:i:s'), $sku]);

        return $stmt->rowCount() > 0;
    }
}
