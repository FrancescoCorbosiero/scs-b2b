<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Repository\LoginAttemptRepository;
use App\Repository\UserRepository;
use App\Repository\UserTokenRepository;
use App\Repository\VatRateRepository;
use App\Service\AccountMailer;
use App\Service\UserService;
use App\Service\VatService;
use App\Support\Config;
use App\Support\Lang;
use App\Support\Session;
use App\Support\SmtpMailer;
use App\Support\TwigExtension;
use App\Tests\Support\TestDb;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Account clienti: invito con token monouso, login, reset, disattivazione.
 * SMTP volutamente assente: i flussi devono restare validi anche senza email.
 */
final class UserServiceTest extends TestCase
{
    private PDO $pdo;
    private UserService $service;
    private UserRepository $users;
    private UserTokenRepository $tokens;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->pdo = TestDb::create();
        $root = dirname(__DIR__, 2);
        $config = new Config(['ROOT_PATH' => $root]);
        $lang = new Lang($root);
        $twig = new Environment(new FilesystemLoader($root . '/templates'), ['autoescape' => 'html']);
        $twig->addExtension(new TwigExtension($lang));

        $this->users = new UserRepository($this->pdo);
        $this->tokens = new UserTokenRepository($this->pdo);
        $this->service = new UserService(
            $this->users,
            $this->tokens,
            new LoginAttemptRepository($this->pdo),
            new AccountMailer($config, $twig, $lang, new SmtpMailer($config)),
            new VatService(new VatRateRepository($this->pdo)),
            new Session($config),
            $lang,
            new NullLogger(),
        );
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    /** @return int id dell'utente creato */
    private function createUser(string $email = 'bob@example.it'): int
    {
        $result = $this->service->create([
            'name' => 'Bob Cliente', 'email' => $email, 'company' => 'Bob Srl',
            'country' => 'IT', 'locale' => 'it',
        ]);
        self::assertTrue($result['ok'], implode(' / ', $result['errors']));

        return (int) $result['user_id'];
    }

    public function testCreateIssuesInviteTokenEvenIfEmailFails(): void
    {
        $result = $this->service->create([
            'name' => 'Bob Cliente', 'email' => 'bob@example.it', 'country' => 'IT', 'locale' => 'it',
        ]);

        self::assertTrue($result['ok']);
        self::assertFalse($result['email_sent'], 'SMTP assente: account creato comunque');
        $user = $this->users->find((int) $result['user_id']);
        self::assertNull($user['password_hash'], 'Password assente finché l\'invito non è completato');

        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM user_tokens WHERE purpose = 'invite' AND used_at IS NULL")->fetchColumn();
        self::assertSame(1, $count, 'Token di invito emesso');
    }

    public function testDuplicateEmailIsRejected(): void
    {
        $this->createUser();
        $result = $this->service->create([
            'name' => 'Altro', 'email' => 'BOB@example.it', 'country' => 'IT', 'locale' => 'it',
        ]);
        self::assertFalse($result['ok'], 'Email uguale (case-insensitive) → rifiutata');
    }

    public function testInviteTokenSetsPasswordAndLogsIn(): void
    {
        $userId = $this->createUser();
        $plain = $this->tokens->issue($userId, 'invite', 72);

        $result = $this->service->completeToken($plain, 'password-sicura-123', 'password-sicura-123');

        self::assertTrue($result['ok'], implode(' / ', $result['errors']));
        self::assertNotNull($this->users->find($userId)['password_hash']);
        self::assertSame($userId, (new Session(new Config([])))->userId(), 'Auto-login dopo l\'impostazione');

        // il token è monouso
        $again = $this->service->completeToken($plain, 'altra-password-456', 'altra-password-456');
        self::assertFalse($again['ok']);
    }

    public function testExpiredOrBogusTokenIsRejected(): void
    {
        $userId = $this->createUser();
        $plain = $this->tokens->issue($userId, 'invite', 72);
        $this->pdo->exec("UPDATE user_tokens SET expires_at = '2000-01-01 00:00:00'");

        self::assertFalse($this->service->completeToken($plain, 'password-sicura-123', 'password-sicura-123')['ok']);
        self::assertFalse($this->service->completeToken('token-inventato', 'password-sicura-123', 'password-sicura-123')['ok']);
    }

    public function testWeakOrMismatchedPasswordIsRejected(): void
    {
        $userId = $this->createUser();
        $plain = $this->tokens->issue($userId, 'invite', 72);

        self::assertFalse($this->service->completeToken($plain, 'corta', 'corta')['ok'], 'Troppo corta');
        self::assertFalse($this->service->completeToken($plain, 'password-sicura-123', 'diversa-password-123')['ok'], 'Non coincidono');
        // il token NON deve essere stato bruciato dai tentativi invalidi
        self::assertTrue($this->tokens->isValid($plain));
    }

    public function testAuthenticateHappyPathAndFailures(): void
    {
        $userId = $this->createUser();
        $this->users->setPasswordHash($userId, (string) password_hash('password-sicura-123', PASSWORD_ARGON2ID));

        self::assertFalse($this->service->authenticate('bob@example.it', 'sbagliata', '10.0.0.1', false));
        self::assertTrue($this->service->authenticate('BOB@example.it', 'password-sicura-123', '10.0.0.1', false), 'Email case-insensitive');
        self::assertNotNull($this->users->find($userId)['last_login_at']);

        // account disattivato → login rifiutato
        $this->users->setActive($userId, false);
        self::assertFalse($this->service->authenticate('bob@example.it', 'password-sicura-123', '10.0.0.1', false));
    }

    public function testAuthenticateIsRateLimitedPerIp(): void
    {
        $userId = $this->createUser();
        $this->users->setPasswordHash($userId, (string) password_hash('password-sicura-123', PASSWORD_ARGON2ID));

        for ($i = 0; $i < 5; $i++) {
            self::assertFalse($this->service->authenticate('bob@example.it', 'sbagliata', '10.0.0.9', false));
        }
        self::assertFalse(
            $this->service->authenticate('bob@example.it', 'password-sicura-123', '10.0.0.9', false),
            'Dopo 5 fallimenti anche la password giusta viene bloccata (lockout 15 min)'
        );
    }

    public function testResetIsNeutralAndThrottled(): void
    {
        $userId = $this->createUser();
        $this->users->setPasswordHash($userId, (string) password_hash('password-sicura-123', PASSWORD_ARGON2ID));

        // email sconosciuta: nessun token, nessun errore
        $this->service->requestReset('ignoto@example.it');
        $resetCount = fn (): int => (int) $this->pdo->query("SELECT COUNT(*) FROM user_tokens WHERE purpose = 'reset'")->fetchColumn();
        self::assertSame(0, $resetCount());

        // throttle: max 3 token/ora per utente
        for ($i = 0; $i < 5; $i++) {
            $this->service->requestReset('bob@example.it');
        }
        self::assertSame(3, $resetCount());
    }

    public function testChangePasswordRequiresCurrent(): void
    {
        $userId = $this->createUser();
        $this->users->setPasswordHash($userId, (string) password_hash('password-sicura-123', PASSWORD_ARGON2ID));

        self::assertFalse($this->service->changePassword($userId, 'sbagliata', 'nuova-password-456', 'nuova-password-456')['ok']);
        self::assertTrue($this->service->changePassword($userId, 'password-sicura-123', 'nuova-password-456', 'nuova-password-456')['ok']);
        self::assertTrue($this->service->authenticate('bob@example.it', 'nuova-password-456', '10.0.0.2', false));
    }
}
