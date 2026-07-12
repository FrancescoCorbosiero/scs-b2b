-- Ordini dropship GoldenSneakers (vedi docs/09-order-dropship.md).
-- supplier_size_id = campo "id" del feed assortment-flat: identifica la riga
-- SKU+taglia presso il fornitore ed è il "size_id" accettato dall'API dropship.

ALTER TABLE product_sizes ADD COLUMN supplier_size_id INT UNSIGNED NULL;

CREATE TABLE IF NOT EXISTS dropship_orders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_request_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    mode VARCHAR(16) NOT NULL,
    status VARCHAR(32) NOT NULL,
    vendor_order_id INT UNSIGNED NULL,
    dropship_package_id INT UNSIGNED NULL,
    total_price DECIMAL(10,2) NULL,
    currency VARCHAR(8) NOT NULL DEFAULT 'EUR',
    request_payload TEXT NOT NULL,
    lines_snapshot TEXT NULL,
    response_payload TEXT NULL,
    tracking_numbers TEXT NULL,
    KEY idx_dropship_request (order_request_id),
    CONSTRAINT fk_dropship_order_request FOREIGN KEY (order_request_id)
        REFERENCES order_requests (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
