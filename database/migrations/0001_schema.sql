-- Schema iniziale (vedi docs/05-data-model.md)

CREATE TABLE IF NOT EXISTS products (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sku VARCHAR(64) NOT NULL,
    name VARCHAR(255) NOT NULL,
    brand VARCHAR(128) NOT NULL DEFAULT '',
    size_mapper VARCHAR(128) NULL,
    image_url VARCHAR(512) NULL,
    is_recommended TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    total_quantity INT NOT NULL DEFAULT 0,
    min_price_base DECIMAL(10,2) NULL,
    min_price_pro DECIMAL(10,2) NULL,
    min_price_max DECIMAL(10,2) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    last_seen_at DATETIME NULL,
    UNIQUE KEY uq_products_sku (sku),
    KEY idx_products_brand (brand),
    KEY idx_products_active (is_active),
    KEY idx_products_quantity (total_quantity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_sizes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    size_eu VARCHAR(10) NOT NULL,
    size_us VARCHAR(10) NULL,
    barcode VARCHAR(32) NULL,
    quantity INT NOT NULL DEFAULT 0,
    offer_price DECIMAL(10,2) NOT NULL,
    price_base DECIMAL(10,2) NOT NULL,
    price_pro DECIMAL(10,2) NOT NULL,
    price_max DECIMAL(10,2) NOT NULL,
    UNIQUE KEY uq_sizes_product_size (product_id, size_eu),
    CONSTRAINT fk_sizes_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    created_at DATETIME NOT NULL,
    customer_name VARCHAR(128) NOT NULL,
    company VARCHAR(128) NULL,
    email VARCHAR(255) NOT NULL,
    phone VARCHAR(32) NOT NULL,
    notes TEXT NULL,
    plan ENUM('base','pro','max') NOT NULL,
    total_items INT NOT NULL,
    total_amount DECIMAL(10,2) NOT NULL,
    cart_snapshot JSON NOT NULL,
    email_admin_sent TINYINT(1) NOT NULL DEFAULT 0,
    email_customer_sent TINYINT(1) NOT NULL DEFAULT 0,
    ip_address VARCHAR(45) NOT NULL,
    user_agent VARCHAR(255) NULL,
    KEY idx_orders_created (created_at),
    KEY idx_orders_ip (ip_address, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sync_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    started_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    status ENUM('ok','error') NOT NULL,
    rows_read INT NOT NULL DEFAULT 0,
    products_created INT NOT NULL DEFAULT 0,
    products_updated INT NOT NULL DEFAULT 0,
    products_deactivated INT NOT NULL DEFAULT 0,
    message TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    scope ENUM('catalog','admin') NOT NULL,
    attempted_at DATETIME NOT NULL,
    success TINYINT(1) NOT NULL DEFAULT 0,
    KEY idx_attempts_lookup (ip_address, scope, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
