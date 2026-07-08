<?php

declare(strict_types=1);

/** @var Slim\App<Psr\Container\ContainerInterface|null> $app */
$app = require dirname(__DIR__) . '/config/bootstrap.php';
$app->run();
