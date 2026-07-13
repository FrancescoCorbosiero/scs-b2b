#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Diagnostica dei login (catalogo e admin) SENZA rivelare i segreti.
 *
 * Controlla che CATALOG_PASSWORD_HASH e ADMIN_PASSWORD_HASH arrivino a PHP
 * integri (il caso tipico di rottura: hash in .env senza apici singoli →
 * Docker Compose interpola i segmenti "$..." e l'hash arriva mutilato) e
 * mostra lo stato del rate limiting (5 tentativi falliti in 15 minuti per
 * coppia IP+scope bloccano anche la password GIUSTA).
 *
 * Uso:
 *   php bin/check-auth.php                     # controlla formato hash + lockout
 *   php bin/check-auth.php admin "password"    # verifica anche una password
 *   php bin/check-auth.php catalog "password"
 *
 * In Docker: docker compose exec php php bin/check-auth.php
 */

use App\Support\Config;
use App\Support\Db;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
if (is_file($root . '/.env')) {
    Dotenv\Dotenv::createImmutable($root)->safeLoad();
}
$config = Config::fromEnv($root);

$scopeArg = $argv[1] ?? null;
$passwordArg = $argv[2] ?? null;
if ($scopeArg !== null && !in_array($scopeArg, ['catalog', 'admin'], true)) {
    fwrite(STDERR, "Scope sconosciuto \"{$scopeArg}\": usare catalog oppure admin.\n");
    exit(1);
}

$exitCode = 0;

foreach (['catalog' => 'CATALOG_PASSWORD_HASH', 'admin' => 'ADMIN_PASSWORD_HASH'] as $scope => $key) {
    echo "── {$key} (login {$scope}) ", str_repeat('─', max(0, 30 - strlen($scope))), "\n";
    $hash = $config->str($key);

    if ($hash === '') {
        echo "  ✗ NON impostato: ogni login {$scope} fallisce.\n";
        echo "    Genera l'hash: php bin/hash-password.php \"la-password\"\n";
        echo "    e in .env racchiudilo tra APICI SINGOLI: {$key}='\$argon2id\$...'\n";
        $exitCode = 1;
        continue;
    }

    $algo = password_get_info($hash)['algo'];
    if ($algo === null) {
        // il valore visto da PHP non è un hash valido: quasi sempre è stato
        // mutilato dall'interpolazione "$" di Docker Compose (env_file)
        $preview = substr($hash, 0, 12);
        echo "  ✗ MALFORMATO: PHP vede un valore che non è un hash riconosciuto.\n";
        echo "    Inizio del valore visto da PHP: \"{$preview}…\" (un hash valido inizia con \"\$argon2id\$\" o \"\$2y\$\")\n";
        echo "    Causa tipica: hash in .env senza apici singoli → Docker Compose\n";
        echo "    interpola i segmenti \"\$...\" come variabili e lo mutila.\n";
        echo "    Fix: {$key}='\$argon2id\$v=19\$...' (apici singoli), poi\n";
        echo "    docker compose up -d --force-recreate php cron\n";
        $exitCode = 1;
        continue;
    }

    echo "  ✓ Hash presente e ben formato (algoritmo: {$algo}).\n";

    if ($scopeArg === $scope && is_string($passwordArg) && $passwordArg !== '') {
        if (password_verify($passwordArg, $hash)) {
            echo "  ✓ La password fornita CORRISPONDE all'hash.\n";
        } else {
            echo "  ✗ La password fornita NON corrisponde all'hash.\n";
            $exitCode = 1;
        }
    }
}

// ── Rate limiting: 5 tentativi falliti in 15 minuti bloccano IP+scope ──
echo "── Rate limiting (login_attempts) ─────────────\n";
try {
    $pdo = Db::connect($config);
    $stmt = $pdo->prepare(
        'SELECT scope, ip_address, COUNT(*) AS failures, MAX(attempted_at) AS last_attempt
         FROM login_attempts
         WHERE success = 0 AND attempted_at >= ?
         GROUP BY scope, ip_address
         ORDER BY failures DESC'
    );
    $stmt->execute([date('Y-m-d H:i:s', time() - 15 * 60)]);
    $rows = $stmt->fetchAll();
    if ($rows === []) {
        echo "  ✓ Nessun tentativo fallito negli ultimi 15 minuti: nessun blocco attivo.\n";
    }
    foreach ($rows as $row) {
        $failures = (int) $row['failures'];
        $locked = $failures >= 5;
        printf(
            "  %s scope=%s ip=%s falliti=%d ultimo=%s%s\n",
            $locked ? '✗' : '·',
            (string) $row['scope'],
            (string) $row['ip_address'],
            $failures,
            (string) $row['last_attempt'],
            $locked ? ' → BLOCCATO: anche la password giusta viene rifiutata finché la finestra di 15 minuti non scade' : ''
        );
        if ($locked) {
            $exitCode = 1;
        }
    }
} catch (Throwable $e) {
    echo "  · Database non raggiungibile ({$e->getMessage()}): controllo lockout saltato.\n";
}

exit($exitCode);
