<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PDO;

/**
 * Database SQLite in-memory con lo stesso schema logico di MySQL,
 * per testare i repository senza un server MySQL.
 */
final class TestDb
{
    public static function create(): PDO
    {
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $pdo->exec('CREATE TABLE products (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sku TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL,
            brand TEXT NOT NULL DEFAULT "",
            size_mapper TEXT NULL,
            image_url TEXT NULL,
            is_recommended INTEGER NOT NULL DEFAULT 0,
            is_active INTEGER NOT NULL DEFAULT 1,
            total_quantity INTEGER NOT NULL DEFAULT 0,
            min_price_base NUMERIC NULL,
            min_price_pro NUMERIC NULL,
            min_price_max NUMERIC NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            last_seen_at TEXT NULL
        )');

        $pdo->exec('CREATE TABLE product_sizes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER NOT NULL REFERENCES products (id) ON DELETE CASCADE,
            size_eu TEXT NOT NULL,
            size_us TEXT NULL,
            barcode TEXT NULL,
            quantity INTEGER NOT NULL DEFAULT 0,
            offer_price NUMERIC NOT NULL,
            price_base NUMERIC NOT NULL,
            price_pro NUMERIC NOT NULL,
            price_max NUMERIC NOT NULL,
            UNIQUE (product_id, size_eu)
        )');

        $pdo->exec('CREATE TABLE order_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            created_at TEXT NOT NULL,
            customer_name TEXT NOT NULL,
            company TEXT NULL,
            email TEXT NOT NULL,
            phone TEXT NOT NULL,
            notes TEXT NULL,
            plan TEXT NOT NULL,
            total_items INTEGER NOT NULL,
            total_amount NUMERIC NOT NULL,
            cart_snapshot TEXT NOT NULL,
            email_admin_sent INTEGER NOT NULL DEFAULT 0,
            email_customer_sent INTEGER NOT NULL DEFAULT 0,
            ip_address TEXT NOT NULL,
            user_agent TEXT NULL
        )');

        $pdo->exec('CREATE TABLE sync_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            started_at TEXT NOT NULL,
            finished_at TEXT NULL,
            status TEXT NOT NULL,
            rows_read INTEGER NOT NULL DEFAULT 0,
            products_created INTEGER NOT NULL DEFAULT 0,
            products_updated INTEGER NOT NULL DEFAULT 0,
            products_deactivated INTEGER NOT NULL DEFAULT 0,
            message TEXT NULL
        )');

        $pdo->exec('CREATE TABLE login_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ip_address TEXT NOT NULL,
            scope TEXT NOT NULL,
            attempted_at TEXT NOT NULL,
            success INTEGER NOT NULL DEFAULT 0
        )');

        return $pdo;
    }

    /**
     * Inserisce un prodotto con taglie. Prezzi già "per piano" precalcolati.
     *
     * @param list<array{size_eu: string, size_us?: string, quantity: int,
     *   offer_price?: string, price_base?: string, price_pro?: string, price_max?: string}> $sizes
     */
    public static function seedProduct(PDO $pdo, string $sku, string $name, string $brand, array $sizes, bool $recommended = false): int
    {
        $now = date('Y-m-d H:i:s');
        $total = 0;
        foreach ($sizes as $size) {
            $total += $size['quantity'];
        }
        $stmt = $pdo->prepare(
            'INSERT INTO products (sku, name, brand, is_recommended, is_active, total_quantity,
                min_price_base, min_price_pro, min_price_max, created_at, updated_at, last_seen_at)
             VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?)'
        );
        $minBase = null;
        foreach ($sizes as $size) {
            $base = (float) ($size['price_base'] ?? '100.00');
            $minBase = $minBase === null ? $base : min($minBase, $base);
        }
        $stmt->execute([$sku, $name, $brand, $recommended ? 1 : 0, $total, $minBase, $minBase, $minBase, $now, $now, $now]);
        $productId = (int) $pdo->lastInsertId();

        $sizeStmt = $pdo->prepare(
            'INSERT INTO product_sizes (product_id, size_eu, size_us, barcode, quantity, offer_price, price_base, price_pro, price_max)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($sizes as $size) {
            $sizeStmt->execute([
                $productId,
                $size['size_eu'],
                $size['size_us'] ?? '',
                'BC' . $size['size_eu'],
                $size['quantity'],
                $size['offer_price'] ?? '50.00',
                $size['price_base'] ?? '100.00',
                $size['price_pro'] ?? '95.00',
                $size['price_max'] ?? '90.00',
            ]);
        }

        return $productId;
    }
}
