<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

final class Db
{
    public static function connect(Config $config): PDO
    {
        // DB_DRIVER=sqlite: solo per sviluppo/test senza Docker (lo schema
        // di produzione resta MySQL, vedi database/migrations)
        if ($config->str('DB_DRIVER', 'mysql') === 'sqlite') {
            $path = $config->str('DB_NAME', ':memory:');
            if ($path !== ':memory:' && !str_starts_with($path, '/')) {
                $path = $config->rootPath() . '/' . $path;
            }
            $pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $pdo->exec('PRAGMA foreign_keys = ON');

            return $pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config->str('DB_HOST', 'mysql'),
            $config->int('DB_PORT', 3306),
            $config->str('DB_NAME', 'b2b_catalog'),
        );

        return new PDO($dsn, $config->str('DB_USER'), $config->str('DB_PASSWORD'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    /**
     * Attende che MySQL sia raggiungibile (primo avvio in Docker Compose).
     */
    public static function connectWithRetry(Config $config, int $timeoutSeconds = 0): PDO
    {
        $deadline = time() + $timeoutSeconds;
        while (true) {
            try {
                return self::connect($config);
            } catch (\PDOException $e) {
                if (time() >= $deadline) {
                    throw $e;
                }
                sleep(2);
            }
        }
    }
}
