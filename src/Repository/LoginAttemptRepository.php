<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class LoginAttemptRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function record(string $ip, string $scope, bool $success): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO login_attempts (ip_address, scope, attempted_at, success) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$ip, $scope, date('Y-m-d H:i:s'), $success ? 1 : 0]);

        // pulizia opportunistica delle righe più vecchie di 7 giorni
        if (random_int(1, 50) === 1) {
            $cleanup = $this->pdo->prepare('DELETE FROM login_attempts WHERE attempted_at < ?');
            $cleanup->execute([date('Y-m-d H:i:s', time() - 7 * 86400)]);
        }
    }

    public function recentFailures(string $ip, string $scope, int $windowMinutes): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE ip_address = ? AND scope = ? AND success = 0 AND attempted_at >= ?'
        );
        $stmt->execute([$ip, $scope, date('Y-m-d H:i:s', time() - $windowMinutes * 60)]);

        return (int) $stmt->fetchColumn();
    }
}
