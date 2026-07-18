#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Sync manuale/cron del feed GoldenSneakers. Idempotente e transazionale.
 *
 * Uso: php bin/sync-feed.php [--reprice]
 *   --reprice  ricalcola solo i prezzi dai valori offer_price già in DB
 *              (dopo una modifica alle regole margine in /admin/margini o
 *              a PRICE_ROUNDING in .env; /admin lo esegue già da sé al salvataggio)
 *
 * Exit code: 0 = ok, 1 = errore, 2 = saltato (altro sync in corso).
 */

use App\Adapter\GoldenSneakersAdapter;
use App\Repository\MarginRuleRepository;
use App\Repository\ProductRepository;
use App\Repository\SettingsRepository;
use App\Repository\SyncLogRepository;
use App\Service\FeedSyncService;
use App\Service\MarginResolver;
use App\Service\PricingService;
use App\Support\Config;
use App\Support\Db;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
if (is_file($root . '/.env')) {
    Dotenv\Dotenv::createImmutable($root)->safeLoad();
}
$config = Config::fromEnv($root);
date_default_timezone_set($config->str('APP_TIMEZONE', 'Europe/Rome'));

$logger = new Logger('sync');
$logger->pushHandler(new StreamHandler($root . '/logs/sync.log'));

$pdo = Db::connectWithRetry($config, 30);
$service = new FeedSyncService(
    $pdo,
    new GoldenSneakersAdapter($config, $logger),
    new ProductRepository($pdo),
    new SyncLogRepository($pdo),
    PricingService::fromConfig($config),
    new MarginResolver(new MarginRuleRepository($pdo), new SettingsRepository($pdo)),
    $config,
    $logger,
);

$reprice = in_array('--reprice', $argv, true);
$result = $service->run($reprice);

printf(
    "[%s] status=%s righe=%d creati=%d aggiornati=%d disattivati=%d%s\n",
    date('Y-m-d H:i:s'),
    $result['status'],
    $result['rows_read'],
    $result['products_created'],
    $result['products_updated'],
    $result['products_deactivated'],
    $result['message'] !== null ? ' — ' . $result['message'] : '',
);

exit(match ($result['status']) {
    'ok' => 0,
    'skipped' => 2,
    default => 1,
});
