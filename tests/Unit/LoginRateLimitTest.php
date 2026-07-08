<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Repository\LoginAttemptRepository;
use App\Service\AuthService;
use App\Support\Config;
use App\Support\Session;
use App\Tests\Support\TestDb;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class LoginRateLimitTest extends TestCase
{
    private AuthService $auth;
    private LoginAttemptRepository $attempts;
    /** @var non-empty-string */
    private string $hash;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->attempts = new LoginAttemptRepository(TestDb::create());
        $this->hash = password_hash('password-giusta', PASSWORD_ARGON2ID);
        $config = new Config([
            'CATALOG_PASSWORD_HASH' => $this->hash,
            'ADMIN_PASSWORD_HASH' => $this->hash,
        ]);
        $this->auth = new AuthService($config, new Session($config), $this->attempts, new NullLogger());
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testCorrectPasswordLogsIn(): void
    {
        self::assertTrue($this->auth->attempt(AuthService::SCOPE_CATALOG, 'password-giusta', '1.2.3.4'));
        self::assertTrue(($_SESSION['auth_catalog'] ?? false) === true);
        self::assertFalse(($_SESSION['auth_admin'] ?? false) === true, 'La sessione admin resta separata');
    }

    public function testWrongPasswordFails(): void
    {
        self::assertFalse($this->auth->attempt(AuthService::SCOPE_CATALOG, 'sbagliata', '1.2.3.4'));
    }

    public function testLockoutAfterFiveFailuresEvenWithCorrectPassword(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->auth->attempt(AuthService::SCOPE_CATALOG, 'sbagliata', '1.2.3.4');
        }
        self::assertFalse(
            $this->auth->attempt(AuthService::SCOPE_CATALOG, 'password-giusta', '1.2.3.4'),
            'Dopo 5 fallimenti in 15 minuti l\'IP è bloccato anche con la password giusta',
        );
    }

    /**
     * Test richiesto da docs/07: due client con IP diversi NON devono
     * condividere il contatore (dietro proxy mal configurato succederebbe).
     */
    public function testDifferentIpsDoNotShareTheCounter(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->auth->attempt(AuthService::SCOPE_CATALOG, 'sbagliata', '10.0.0.1');
        }
        self::assertTrue(
            $this->auth->attempt(AuthService::SCOPE_CATALOG, 'password-giusta', '10.0.0.2'),
            'Il lockout di 10.0.0.1 non deve bloccare 10.0.0.2',
        );
    }

    public function testScopesHaveSeparateCounters(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->auth->attempt(AuthService::SCOPE_CATALOG, 'sbagliata', '1.2.3.4');
        }
        self::assertTrue(
            $this->auth->attempt(AuthService::SCOPE_ADMIN, 'password-giusta', '1.2.3.4'),
            'Il lockout catalogo non blocca lo scope admin dello stesso IP',
        );
    }

    public function testEmptyHashNeverAuthenticates(): void
    {
        $config = new Config(['CATALOG_PASSWORD_HASH' => '']);
        $auth = new AuthService($config, new Session($config), $this->attempts, new NullLogger());
        self::assertFalse($auth->attempt(AuthService::SCOPE_CATALOG, '', '1.2.3.4'));
        self::assertFalse($auth->attempt(AuthService::SCOPE_CATALOG, 'qualsiasi', '1.2.3.4'));
    }
}
