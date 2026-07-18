<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Token monouso per invito e reset password. A DB vive SOLO l'hash sha256:
 * il token in chiaro esiste esclusivamente nel link inviato via email.
 */
final class UserTokenRepository
{
    public const PURPOSES = ['invite', 'reset'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** Genera un token nuovo (invalidando i precedenti stesso scopo) e ritorna il CHIARO. */
    public function issue(int $userId, string $purpose, int $ttlHours): string
    {
        // i token precedenti per lo stesso scopo muoiono: vale solo l'ultimo link
        $void = $this->pdo->prepare(
            'UPDATE user_tokens SET used_at = ? WHERE user_id = ? AND purpose = ? AND used_at IS NULL'
        );
        $void->execute([date('Y-m-d H:i:s'), $userId, $purpose]);

        $plain = bin2hex(random_bytes(32));
        $stmt = $this->pdo->prepare(
            'INSERT INTO user_tokens (user_id, token_hash, purpose, expires_at, used_at, created_at)
             VALUES (?, ?, ?, ?, NULL, ?)'
        );
        $stmt->execute([
            $userId,
            hash('sha256', $plain),
            $purpose,
            date('Y-m-d H:i:s', time() + $ttlHours * 3600),
            date('Y-m-d H:i:s'),
        ]);

        return $plain;
    }

    /**
     * Consuma un token valido (esistente, non usato, non scaduto) e ritorna
     * user_id e scopo; null se non valido. Il purpose NON è un input: lo
     * decide il token stesso (il form di set-password serve entrambi i flussi).
     *
     * @return array{user_id: int, purpose: string}|null
     */
    public function consume(string $plainToken): ?array
    {
        if ($plainToken === '' || strlen($plainToken) > 128) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, purpose FROM user_tokens
             WHERE token_hash = ? AND used_at IS NULL AND expires_at > ?'
        );
        $stmt->execute([hash('sha256', $plainToken), date('Y-m-d H:i:s')]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        $mark = $this->pdo->prepare('UPDATE user_tokens SET used_at = ? WHERE id = ? AND used_at IS NULL');
        $mark->execute([date('Y-m-d H:i:s'), (int) $row['id']]);
        if ($mark->rowCount() === 0) {
            // corsa: qualcun altro l'ha appena consumato
            return null;
        }

        return ['user_id' => (int) $row['user_id'], 'purpose' => (string) $row['purpose']];
    }

    /** Sguardo senza consumo (per mostrare il form solo su token plausibile). */
    public function isValid(string $plainToken): bool
    {
        if ($plainToken === '' || strlen($plainToken) > 128) {
            return false;
        }
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM user_tokens WHERE token_hash = ? AND used_at IS NULL AND expires_at > ?'
        );
        $stmt->execute([hash('sha256', $plainToken), date('Y-m-d H:i:s')]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /** Antiabuso reset: token emessi di recente per un utente. */
    public function recentCount(int $userId, string $purpose, int $windowMinutes): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM user_tokens WHERE user_id = ? AND purpose = ? AND created_at >= ?'
        );
        $stmt->execute([$userId, $purpose, date('Y-m-d H:i:s', time() - $windowMinutes * 60)]);

        return (int) $stmt->fetchColumn();
    }
}
