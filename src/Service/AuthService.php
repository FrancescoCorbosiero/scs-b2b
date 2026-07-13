<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\LoginAttemptRepository;
use App\Support\Config;
use App\Support\Session;
use Psr\Log\LoggerInterface;

/**
 * Login a password condivisa (catalogo) e password dedicata (admin),
 * con lockout dopo 5 tentativi falliti in 15 minuti per (IP, scope).
 * La risposta verso l'utente è sempre generica: non riveliamo se il
 * blocco dipende dalla password errata o dal rate limiting.
 */
final class AuthService
{
    public const SCOPE_CATALOG = 'catalog';
    public const SCOPE_ADMIN = 'admin';

    private const MAX_FAILURES = 5;
    private const WINDOW_MINUTES = 15;

    public function __construct(
        private readonly Config $config,
        private readonly Session $session,
        private readonly LoginAttemptRepository $attempts,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function attempt(string $scope, string $password, string $ip, bool $remember = false): bool
    {
        if ($this->attempts->recentFailures($ip, $scope, self::WINDOW_MINUTES) >= self::MAX_FAILURES) {
            $this->logger->warning('Login bloccato dal rate limiting', ['scope' => $scope, 'ip' => $ip]);

            return false;
        }

        $hashKey = $scope === self::SCOPE_ADMIN ? 'ADMIN_PASSWORD_HASH' : 'CATALOG_PASSWORD_HASH';
        $hash = $this->config->str($hashKey);
        if ($hash === '') {
            $this->logger->error("{$hashKey} non configurato: ogni login {$scope} fallirà. Genera l'hash con bin/hash-password.php e mettilo in .env tra apici singoli.");
        } elseif (password_get_info($hash)['algo'] === null) {
            // hash presente ma non riconosciuto: caso tipico, valore in .env senza
            // apici singoli → Docker Compose interpola i segmenti "$..." e lo mutila
            $this->logger->error("{$hashKey} malformato (algoritmo non riconosciuto): probabile interpolazione di \"$\" da parte di Docker Compose. Racchiudi l'hash tra apici singoli in .env e riavvia i container. Diagnosi: php bin/check-auth.php");
        }
        $ok = $hash !== '' && $password !== '' && password_verify($password, $hash);
        $this->attempts->record($ip, $scope, $ok);

        if (!$ok) {
            $this->logger->info('Tentativo di login fallito', ['scope' => $scope, 'ip' => $ip]);

            return false;
        }

        if ($scope === self::SCOPE_ADMIN) {
            $this->session->loginAdmin();
        } else {
            $this->session->loginCatalog();
            $this->session->persist($remember);
        }

        return true;
    }
}
