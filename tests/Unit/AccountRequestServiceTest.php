<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Repository\AccountRequestRepository;
use App\Repository\LoginAttemptRepository;
use App\Repository\UserRepository;
use App\Repository\UserTokenRepository;
use App\Repository\VatRateRepository;
use App\Service\AccountMailer;
use App\Service\AccountRequestService;
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
 * Richieste di profilo dal sito pubblico (docs/06): invio, antispam,
 * approvazione (crea l'account con i dati inviati) e rifiuto.
 * SMTP volutamente non configurato: le email falliscono ma il flusso regge.
 */
final class AccountRequestServiceTest extends TestCase
{
    private const VALID = [
        'company' => 'Sneaker Shop SRL',
        'vat_number' => 'IT01234567890',
        'name' => 'Mario Rossi',
        'email' => 'mario@sneakershop.it',
        'phone' => '+39 340 1234567',
        'address_street' => 'Via Roma 1',
        'address_city' => 'Milano',
        'address_zip' => '20121',
        'country' => 'IT',
        'notes' => 'Negozio in centro',
        'website' => '',
    ];

    private PDO $pdo;
    private AccountRequestRepository $requests;
    private UserRepository $users;
    private AccountRequestService $service;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->pdo = TestDb::create();
        $root = dirname(__DIR__, 2);
        $config = new Config(['ROOT_PATH' => $root]);
        $lang = new Lang($root);
        $twig = new Environment(new FilesystemLoader($root . '/templates'), ['autoescape' => 'html']);
        $twig->addExtension(new TwigExtension($lang));

        $session = new Session($config);
        $this->requests = new AccountRequestRepository($this->pdo);
        $this->users = new UserRepository($this->pdo);
        $mailer = new AccountMailer($config, $twig, $lang, new SmtpMailer($config));
        $vat = new VatService(new VatRateRepository($this->pdo));
        $userService = new UserService(
            $this->users,
            new UserTokenRepository($this->pdo),
            new LoginAttemptRepository($this->pdo),
            $mailer,
            $vat,
            $session,
            $lang,
            new NullLogger(),
        );

        $this->service = new AccountRequestService(
            $this->requests,
            $this->users,
            $userService,
            $mailer,
            $vat,
            $session,
            $lang,
            new NullLogger(),
        );
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    /** @param array<string, mixed> $overrides */
    private function submit(array $overrides = [], string $ip = '127.0.0.1'): array
    {
        return $this->service->submit($overrides + self::VALID, $ip, 'PHPUnit');
    }

    public function testValidRequestIsStoredAsPending(): void
    {
        $result = $this->submit();

        self::assertTrue($result['ok'], implode(' ', $result['errors']));
        $request = $this->requests->find((int) $result['request_id']);
        self::assertNotNull($request);
        self::assertSame('pending', $request['status']);
        self::assertSame('Sneaker Shop SRL', $request['company']);
        self::assertSame('Via Roma 1', $request['address_street']);
        self::assertSame('IT01234567890', $request['vat_number'], 'P.IVA normalizzata col prefisso paese');
        self::assertNull($request['user_id'], 'Nessun account prima dell\'approvazione');
    }

    public function testRequiredFieldsAreValidated(): void
    {
        $result = $this->submit(['company' => '', 'email' => 'non-una-email', 'address_zip' => '']);

        self::assertFalse($result['ok']);
        self::assertCount(3, $result['errors']);
        self::assertSame(0, $this->requests->countPending());
    }

    public function testHoneypotSilentlyRejects(): void
    {
        $result = $this->submit(['website' => 'http://spam.example']);

        self::assertFalse($result['ok']);
        self::assertSame(0, $this->requests->countPending());
    }

    public function testRateLimitPerIp(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            self::assertTrue($this->submit(['email' => "cliente{$i}@example.it"])['ok']);
        }
        $result = $this->submit(['email' => 'quarto@example.it']);

        self::assertFalse($result['ok'], 'Oltre 3 richieste/ora dallo stesso IP');
        self::assertSame(3, $this->requests->countPending());
    }

    public function testDuplicateEmailIsRejectedWhilePending(): void
    {
        self::assertTrue($this->submit()['ok']);
        $result = $this->submit(['name' => 'Altro referente']);

        self::assertFalse($result['ok']);
        self::assertSame(1, $this->requests->countPending());
    }

    public function testExistingCustomerIsToldToSignIn(): void
    {
        $this->users->insert([
            'email' => self::VALID['email'], 'name' => 'Mario', 'company' => null, 'phone' => null,
            'vat_number' => null, 'country_code' => 'IT', 'locale' => 'it',
        ]);

        $result = $this->submit();

        self::assertFalse($result['ok']);
        self::assertSame(0, $this->requests->countPending());
    }

    public function testApprovalCreatesAccountWithRequestData(): void
    {
        $requestId = (int) $this->submit()['request_id'];

        $result = $this->service->approve($requestId);

        self::assertTrue($result['ok'], (string) $result['error']);
        $request = $this->requests->find($requestId);
        self::assertSame('approved', $request['status']);
        self::assertNotNull($request['user_id']);

        // l'account nasce coi dati della richiesta: indirizzo incluso (serve al dropship)
        $user = $this->users->find((int) $request['user_id']);
        self::assertNotNull($user);
        self::assertSame('mario@sneakershop.it', $user['email']);
        self::assertSame('Sneaker Shop SRL', $user['company']);
        self::assertSame('Via Roma 1', $user['address_street']);
        self::assertSame('Milano', $user['address_city']);
        self::assertSame('20121', $user['address_zip']);
        self::assertSame('IT', $user['country_code']);
        self::assertNull($user['password_hash'], 'La password la imposta il cliente dall\'invito');
    }

    public function testApprovalIsIdempotent(): void
    {
        $requestId = (int) $this->submit()['request_id'];
        self::assertTrue($this->service->approve($requestId)['ok']);

        $second = $this->service->approve($requestId);
        self::assertFalse($second['ok'], 'Una richiesta già approvata non si riapprova');
    }

    public function testRejectionLeavesNoAccount(): void
    {
        $requestId = (int) $this->submit()['request_id'];

        self::assertTrue($this->service->reject($requestId)['ok']);
        $request = $this->requests->find($requestId);
        self::assertSame('rejected', $request['status']);
        self::assertNull($this->users->findByEmail(self::VALID['email']));
        self::assertFalse($this->service->approve($requestId)['ok'], 'Una rifiutata non si approva');
    }
}
