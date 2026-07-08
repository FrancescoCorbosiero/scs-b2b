<?php

declare(strict_types=1);

use App\Support\Config;
use DI\ContainerBuilder;
use Slim\App;
use Slim\Factory\AppFactory;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
if (is_file($root . '/.env')) {
    Dotenv\Dotenv::createImmutable($root)->safeLoad();
}

$builder = new ContainerBuilder();
$builder->addDefinitions(require $root . '/config/container.php');
$container = $builder->build();

date_default_timezone_set($container->get(Config::class)->str('APP_TIMEZONE', 'Europe/Rome'));

AppFactory::setContainer($container);
$app = AppFactory::create();

(require $root . '/config/middleware.php')($app);
(require $root . '/config/routes.php')($app);

return $app;
