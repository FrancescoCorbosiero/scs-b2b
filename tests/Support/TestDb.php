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
            min_price NUMERIC NULL,
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
            price NUMERIC NOT NULL DEFAULT 0,
            supplier_size_id INTEGER NULL,
            UNIQUE (product_id, size_eu)
        )');

        $pdo->exec('CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL UNIQUE,
            password_hash TEXT NULL,
            name TEXT NOT NULL,
            company TEXT NULL,
            phone TEXT NULL,
            vat_number TEXT NULL,
            address_street TEXT NULL,
            address_city TEXT NULL,
            address_zip TEXT NULL,
            country_code TEXT NOT NULL DEFAULT "IT",
            locale TEXT NOT NULL DEFAULT "it",
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            last_login_at TEXT NULL
        )');

        $pdo->exec('CREATE TABLE user_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
            token_hash TEXT NOT NULL UNIQUE,
            purpose TEXT NOT NULL,
            expires_at TEXT NOT NULL,
            used_at TEXT NULL,
            created_at TEXT NOT NULL
        )');

        $pdo->exec('CREATE TABLE order_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NULL,
            created_at TEXT NOT NULL,
            customer_name TEXT NOT NULL,
            company TEXT NULL,
            email TEXT NOT NULL,
            phone TEXT NOT NULL,
            address_street TEXT NULL,
            address_city TEXT NULL,
            address_zip TEXT NULL,
            notes TEXT NULL,
            status TEXT NOT NULL DEFAULT "pending",
            confirmed_at TEXT NULL,
            cancelled_at TEXT NULL,
            plan TEXT NULL,
            locale TEXT NOT NULL DEFAULT "it",
            country_code TEXT NOT NULL DEFAULT "IT",
            vat_number TEXT NULL,
            vat_scheme TEXT NOT NULL DEFAULT "domestic",
            vat_rate NUMERIC NOT NULL DEFAULT 0,
            vat_amount NUMERIC NOT NULL DEFAULT 0,
            total_gross NUMERIC NOT NULL DEFAULT 0,
            receipt_number TEXT NULL,
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

        $pdo->exec('CREATE TABLE dropship_orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_request_id INTEGER NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            mode TEXT NOT NULL,
            status TEXT NOT NULL,
            vendor_order_id INTEGER NULL,
            dropship_package_id INTEGER NULL,
            total_price NUMERIC NULL,
            currency TEXT NOT NULL DEFAULT "EUR",
            request_payload TEXT NOT NULL,
            lines_snapshot TEXT NULL,
            response_payload TEXT NULL,
            tracking_numbers TEXT NULL
        )');

        $pdo->exec('CREATE TABLE margin_rules (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            priority INTEGER NOT NULL DEFAULT 100,
            match_type TEXT NOT NULL,
            match_value TEXT NOT NULL,
            margin_type TEXT NOT NULL,
            margin_value NUMERIC NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )');

        $pdo->exec('CREATE TABLE settings (
            setting_key TEXT PRIMARY KEY,
            setting_value TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )');

        $pdo->exec('CREATE TABLE vat_rates (
            country_code TEXT PRIMARY KEY,
            vat_rate NUMERIC NOT NULL,
            is_eu INTEGER NOT NULL DEFAULT 1,
            sort_order INTEGER NOT NULL DEFAULT 100,
            updated_at TEXT NOT NULL
        )');

        $pdo->exec('CREATE TABLE receipt_counters (
            year INTEGER PRIMARY KEY,
            last_number INTEGER NOT NULL DEFAULT 0
        )');

        self::seedDefaults($pdo);

        return $pdo;
    }

    /** Margine di default e aliquote VAT minime (le stesse chiavi della migrazione 0003). */
    private static function seedDefaults(PDO $pdo): void
    {
        $now = date('Y-m-d H:i:s');
        $settings = $pdo->prepare('INSERT INTO settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?)');
        $settings->execute(['default_margin_type', 'percent', $now]);
        $settings->execute(['default_margin_value', '30', $now]);

        $rates = $pdo->prepare('INSERT INTO vat_rates (country_code, vat_rate, is_eu, sort_order, updated_at) VALUES (?, ?, ?, ?, ?)');
        foreach ([
            ['IT', '22.00', 1, 1], ['AT', '20.00', 1, 100], ['BE', '21.00', 1, 100], ['BG', '20.00', 1, 100],
            ['CY', '19.00', 1, 100], ['CZ', '21.00', 1, 100], ['DE', '19.00', 1, 100], ['DK', '25.00', 1, 100],
            ['EE', '24.00', 1, 100], ['ES', '21.00', 1, 100], ['FI', '25.50', 1, 100], ['FR', '20.00', 1, 100],
            ['GR', '24.00', 1, 100], ['HR', '25.00', 1, 100], ['HU', '27.00', 1, 100], ['IE', '23.00', 1, 100],
            ['LT', '21.00', 1, 100], ['LU', '17.00', 1, 100], ['LV', '21.00', 1, 100], ['MT', '18.00', 1, 100],
            ['NL', '21.00', 1, 100], ['PL', '23.00', 1, 100], ['PT', '23.00', 1, 100], ['RO', '21.00', 1, 100],
            ['SE', '25.00', 1, 100], ['SI', '22.00', 1, 100], ['SK', '23.00', 1, 100],
            ['GB', '20.00', 0, 200], ['CH', '8.10', 0, 200],
        ] as [$code, $rate, $isEu, $sort]) {
            $rates->execute([$code, $rate, $isEu, $sort, $now]);
        }
    }

    /**
     * Inserisce un prodotto con taglie (prezzo netto già calcolato).
     *
     * @param list<array{size_eu: string, size_us?: string, quantity: int,
     *   offer_price?: string, price?: string}> $sizes
     */
    public static function seedProduct(PDO $pdo, string $sku, string $name, string $brand, array $sizes, bool $recommended = false): int
    {
        $now = date('Y-m-d H:i:s');
        $total = 0;
        $minPrice = null;
        foreach ($sizes as $size) {
            $total += $size['quantity'];
            $price = (float) ($size['price'] ?? '100.00');
            $minPrice = $minPrice === null ? $price : min($minPrice, $price);
        }
        $stmt = $pdo->prepare(
            'INSERT INTO products (sku, name, brand, is_recommended, is_active, total_quantity,
                min_price, created_at, updated_at, last_seen_at)
             VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$sku, $name, $brand, $recommended ? 1 : 0, $total, $minPrice, $now, $now, $now]);
        $productId = (int) $pdo->lastInsertId();

        $sizeStmt = $pdo->prepare(
            'INSERT INTO product_sizes (product_id, size_eu, size_us, barcode, quantity, offer_price, price)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($sizes as $size) {
            $sizeStmt->execute([
                $productId,
                $size['size_eu'],
                $size['size_us'] ?? '',
                'BC' . $size['size_eu'],
                $size['quantity'],
                $size['offer_price'] ?? '50.00',
                $size['price'] ?? '100.00',
            ]);
        }

        return $productId;
    }
}
