<?php

declare(strict_types=1);

use App\Service\PricingService;
use App\Support\Config;
use App\Support\Db;
use App\Support\Lang;
use App\Support\Session;
use App\Support\TwigExtension;
use App\Support\View;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

return [
    Config::class => static fn (): Config => Config::fromEnv(dirname(__DIR__)),

    PDO::class => static fn (ContainerInterface $c): PDO => Db::connect($c->get(Config::class)),

    LoggerInterface::class => static function (ContainerInterface $c): LoggerInterface {
        $config = $c->get(Config::class);
        $logger = new Logger('app');
        $level = $config->isProduction() ? Level::Info : Level::Debug;
        // se logs/ non è scrivibile (permessi del bind mount) si logga su
        // stderr: finisce in `docker compose logs php` invece di rompere l'app
        $logsDir = $config->rootPath() . '/logs';
        $target = is_dir($logsDir) && is_writable($logsDir) ? $logsDir . '/app.log' : 'php://stderr';
        $logger->pushHandler(new StreamHandler($target, $level));

        return $logger;
    },

    Lang::class => static fn (ContainerInterface $c): Lang => new Lang($c->get(Config::class)->rootPath()),

    PricingService::class => static fn (ContainerInterface $c): PricingService => PricingService::fromConfig($c->get(Config::class)),

    Environment::class => static function (ContainerInterface $c): Environment {
        $config = $c->get(Config::class);
        $loader = new FilesystemLoader($config->rootPath() . '/templates');
        // cache solo se la directory è creabile/scrivibile: un bind mount con
        // permessi sbagliati non deve buttare giù il sito con un 500
        $cache = false;
        if ($config->isProduction()) {
            $cacheDir = $config->rootPath() . '/var/cache/twig';
            if (!is_dir($cacheDir)) {
                @mkdir($cacheDir, 0775, true);
            }
            if (is_dir($cacheDir) && is_writable($cacheDir)) {
                $cache = $cacheDir;
            }
        }
        $twig = new Environment($loader, [
            'autoescape' => 'html',
            'strict_variables' => false,
            'cache' => $cache,
        ]);
        $twig->addExtension(new TwigExtension($c->get(Lang::class)));

        return $twig;
    },

    View::class => static fn (ContainerInterface $c): View => new View(
        $c->get(Environment::class),
        $c->get(Session::class),
        $c->get(Config::class),
    ),
];
