<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/** Impostazioni chiave/valore modificabili da /admin (es. margine di default). */
final class SettingsRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function get(string $key, string $default = ''): string
    {
        $stmt = $this->pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();

        return $value === false ? $default : (string) $value;
    }

    public function set(string $key, string $value): void
    {
        // upsert portabile MySQL/SQLite: UPDATE, poi INSERT se non esisteva
        $update = $this->pdo->prepare('UPDATE settings SET setting_value = ?, updated_at = ? WHERE setting_key = ?');
        $update->execute([$value, date('Y-m-d H:i:s'), $key]);
        if ($update->rowCount() === 0) {
            $insert = $this->pdo->prepare('INSERT INTO settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?)');
            try {
                $insert->execute([$key, $value, date('Y-m-d H:i:s')]);
            } catch (\PDOException) {
                // corsa con un altro writer: la riga ora esiste, riprova l'update
                $update->execute([$value, date('Y-m-d H:i:s'), $key]);
            }
        }
    }
}
