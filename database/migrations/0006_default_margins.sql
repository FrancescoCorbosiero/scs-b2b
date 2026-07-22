-- Margini di listino indicati dal titolare (19/07/2026), gestibili poi da
-- /admin/margini: default 5% per i brand nuovi/non elencati, regole fisse
-- in EUR per i brand noti ("Soucony" nel messaggio = Saucony; "In" lasciato
-- letterale: match per uguaglianza, correggibile dall'admin se il brand del
-- feed ha un nome diverso).
-- ⚠ Dopo il deploy serve un reprice: php bin/sync-feed.php --reprice
--   (o un salvataggio qualsiasi in /admin/margini).

UPDATE settings SET setting_value = '5', updated_at = NOW() WHERE setting_key = 'default_margin_value';
UPDATE settings SET setting_value = 'percent', updated_at = NOW() WHERE setting_key = 'default_margin_type';

INSERT INTO margin_rules (priority, match_type, match_value, margin_type, margin_value, is_active, created_at, updated_at) VALUES
    (100, 'brand', 'Adidas',      'percent', 5.00, 1, NOW(), NOW()),
    (100, 'brand', 'Autry',       'fixed',   2.00, 1, NOW(), NOW()),
    (100, 'brand', 'Asics',       'fixed',   2.00, 1, NOW(), NOW()),
    (100, 'brand', 'Jordan',      'fixed',   3.00, 1, NOW(), NOW()),
    (100, 'brand', 'Nike',        'fixed',   3.00, 1, NOW(), NOW()),
    (100, 'brand', 'Puma',        'fixed',   2.00, 1, NOW(), NOW()),
    (100, 'brand', 'Timberland',  'fixed',   3.00, 1, NOW(), NOW()),
    (100, 'brand', 'Ugg',         'fixed',   3.00, 1, NOW(), NOW()),
    (100, 'brand', 'Vans',        'fixed',   2.00, 1, NOW(), NOW()),
    (100, 'brand', 'Yeezy',       'fixed',   3.00, 1, NOW(), NOW()),
    (100, 'brand', 'Birkenstock', 'fixed',   2.00, 1, NOW(), NOW()),
    (100, 'brand', 'New Balance', 'fixed',   2.00, 1, NOW(), NOW()),
    (100, 'brand', 'In',          'fixed',   2.00, 1, NOW(), NOW()),
    (100, 'brand', 'Saucony',     'fixed',   2.00, 1, NOW(), NOW());
