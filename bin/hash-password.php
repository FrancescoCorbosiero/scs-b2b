#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Genera l'hash Argon2id da mettere in .env (CATALOG_PASSWORD_HASH /
 * ADMIN_PASSWORD_HASH). Uso: php bin/hash-password.php "la-password"
 */
if ($argc < 2 || $argv[1] === '') {
    fwrite(STDERR, "Uso: php bin/hash-password.php \"la-password\"\n");
    exit(1);
}

echo password_hash($argv[1], PASSWORD_ARGON2ID), PHP_EOL;
