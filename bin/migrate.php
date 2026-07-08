#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Applica le migrazioni SQL non ancora eseguite (database/migrations/*.sql,
 * in ordine alfabetico). Idempotente: il tracking è nella tabella migrations.
 *
 * Uso: php bin/migrate.php [--wait=60]
 *   --wait=N  attende fino a N secondi che MySQL sia raggiungibile
 *             (utile al primo avvio in Docker Compose)
 */

use App\Support\Config;
use App\Support\Db;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
if (is_file($root . '/.env')) {
    Dotenv\Dotenv::createImmutable($root)->safeLoad();
}
$config = Config::fromEnv($root);

$wait = 0;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--wait=(\d+)$/', $arg, $m) === 1) {
        $wait = (int) $m[1];
    }
}

$pdo = Db::connectWithRetry($config, $wait);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS migrations (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        filename VARCHAR(255) NOT NULL UNIQUE,
        applied_at DATETIME NOT NULL
    )'
);

$appliedStmt = $pdo->query('SELECT filename FROM migrations');
$applied = $appliedStmt === false ? [] : array_column($appliedStmt->fetchAll(), 'filename');

$files = glob($root . '/database/migrations/*.sql') ?: [];
sort($files);

$ran = 0;
foreach ($files as $file) {
    $name = basename($file);
    if (in_array($name, $applied, true)) {
        continue;
    }
    echo "Applico {$name}... ";
    $sql = (string) file_get_contents($file);
    // split naive sugli ";" a fine riga: sufficiente per i nostri DDL
    $statements = preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [];
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if ($statement !== '' && !str_starts_with($statement, '--')) {
            $pdo->exec($statement);
        }
    }
    $record = $pdo->prepare('INSERT INTO migrations (filename, applied_at) VALUES (?, ?)');
    $record->execute([$name, date('Y-m-d H:i:s')]);
    echo "ok\n";
    $ran++;
}

echo $ran === 0 ? "Nessuna migrazione da applicare.\n" : "Applicate {$ran} migrazioni.\n";
