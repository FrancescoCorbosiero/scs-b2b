<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\LoginAttemptRepository;
use App\Repository\UserRepository;
use App\Repository\UserTokenRepository;
use App\Support\Lang;
use App\Support\Session;
use Psr\Log\LoggerInterface;

/**
 * Account clienti (docs/06 e 07): creati dall'admin, attivati con link di
 * invito monouso a scadenza (la password non viaggia MAI via email).
 * Stesso meccanismo a token per il reset password. Le risposte dei flussi
 * pubblici sono neutre: mai rivelare se un'email esiste.
 */
final class UserService
{
    public const SCOPE_USER = 'user';
    public const INVITE_TTL_HOURS = 72;
    public const RESET_TTL_HOURS = 2;

    private const MAX_FAILURES = 5;
    private const WINDOW_MINUTES = 15;
    private const MIN_PASSWORD_LENGTH = 10;
    private const MAX_RESET_PER_HOUR = 3;

    public function __construct(
        private readonly UserRepository $users,
        private readonly UserTokenRepository $tokens,
        private readonly LoginAttemptRepository $attempts,
        private readonly AccountMailer $mailer,
        private readonly VatService $vat,
        private readonly Session $session,
        private readonly Lang $lang,
        private readonly LoggerInterface $logger,
    ) {
    }

    // ── Creazione da /admin ──────────────────────────────────────────

    /**
     * @param array<string, mixed> $input
     * @return array{ok: bool, errors: list<string>, user_id: int|null, email_sent: bool}
     */
    public function create(array $input): array
    {
        $name = $this->clean($input['name'] ?? null, 128);
        $email = $this->clean($input['email'] ?? null, 255);
        $company = $this->clean($input['company'] ?? null, 128);
        $phone = $this->clean($input['phone'] ?? null, 32);
        $vatNumber = $this->clean($input['vat_number'] ?? null, 32);
        $country = strtoupper($this->clean($input['country'] ?? null, 2));
        $locale = is_string($input['locale'] ?? null) && in_array($input['locale'], Session::LOCALES, true)
            ? $input['locale'] : Session::DEFAULT_LOCALE;

        $errors = [];
        if ($name === '') {
            $errors[] = $this->lang->t('order.error_name');
        }
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = $this->lang->t('order.error_email');
        } elseif ($this->users->findByEmail($email) !== null) {
            $errors[] = $this->lang->t('users.error_email_taken');
        }
        if (!$this->vat->isValidCountry($country)) {
            $errors[] = $this->lang->t('order.error_country');
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'user_id' => null, 'email_sent' => false];
        }

        $userId = $this->users->insert([
            'email' => $email,
            'name' => $name,
            'company' => $company !== '' ? $company : null,
            'phone' => $phone !== '' ? $phone : null,
            'vat_number' => $vatNumber !== '' ? $vatNumber : null,
            'country_code' => $country,
            'locale' => $locale,
        ]);
        $this->logger->info('Account cliente creato', ['user_id' => $userId]);

        return [
            'ok' => true,
            'errors' => [],
            'user_id' => $userId,
            'email_sent' => $this->sendAccessLink($userId),
        ];
    }

    /**
     * Invia (o reinvia) il link di accesso: invito se la password non è mai
     * stata impostata, reset altrimenti. Ritorna true se l'email è partita.
     */
    public function sendAccessLink(int $userId): bool
    {
        $user = $this->users->find($userId);
        if ($user === null) {
            return false;
        }
        $isInvite = $user['password_hash'] === null || $user['password_hash'] === '';
        $ttl = $isInvite ? self::INVITE_TTL_HOURS : self::RESET_TTL_HOURS;
        $plain = $this->tokens->issue($userId, $isInvite ? 'invite' : 'reset', $ttl);
        try {
            if ($isInvite) {
                $this->mailer->sendInvite($user, $plain, $ttl);
            } else {
                $this->mailer->sendReset($user, $plain, $ttl);
            }

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Invio email account fallito', ['user_id' => $userId, 'error' => $e->getMessage()]);

            return false;
        }
    }

    // ── Reset self-service (risposta SEMPRE neutra) ──────────────────

    public function requestReset(string $email): void
    {
        $user = $this->users->findByEmail($this->clean($email, 255));
        if ($user === null || !(bool) $user['is_active']) {
            $this->logger->info('Reset richiesto per email sconosciuta o account disattivato');

            return;
        }
        $userId = (int) $user['id'];
        if ($this->tokens->recentCount($userId, 'reset', 60) >= self::MAX_RESET_PER_HOUR) {
            $this->logger->warning('Reset password: troppe richieste', ['user_id' => $userId]);

            return;
        }
        $plain = $this->tokens->issue($userId, 'reset', self::RESET_TTL_HOURS);
        try {
            $this->mailer->sendReset($user, $plain, self::RESET_TTL_HOURS);
        } catch (\Throwable $e) {
            $this->logger->error('Invio email reset fallito', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }

    // ── Impostazione password via token (invito E reset) ─────────────

    /**
     * @return array{ok: bool, errors: list<string>}
     */
    public function completeToken(string $plainToken, string $password, string $confirm): array
    {
        $errors = $this->validateNewPassword($password, $confirm);
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        $consumed = $this->tokens->consume($plainToken);
        if ($consumed === null) {
            return ['ok' => false, 'errors' => [$this->lang->t('account.error_token')]];
        }
        $user = $this->users->findActive($consumed['user_id']);
        if ($user === null) {
            return ['ok' => false, 'errors' => [$this->lang->t('account.error_token')]];
        }

        $this->users->setPasswordHash((int) $user['id'], (string) password_hash($password, PASSWORD_ARGON2ID));
        $this->loginSession($user, remember: false);
        $this->logger->info('Password impostata via token', ['user_id' => $user['id'], 'purpose' => $consumed['purpose']]);

        return ['ok' => true, 'errors' => []];
    }

    // ── Login ────────────────────────────────────────────────────────

    public function authenticate(string $email, string $password, string $ip, bool $remember): bool
    {
        if ($this->attempts->recentFailures($ip, self::SCOPE_USER, self::WINDOW_MINUTES) >= self::MAX_FAILURES) {
            $this->logger->warning('Login utente bloccato dal rate limiting', ['ip' => $ip]);

            return false;
        }

        $user = $this->users->findByEmail($this->clean($email, 255));
        $hash = is_array($user) && is_string($user['password_hash'] ?? null) ? $user['password_hash'] : '';
        $ok = $user !== null
            && (bool) $user['is_active']
            && $hash !== ''
            && $password !== ''
            && password_verify($password, $hash);
        $this->attempts->record($ip, self::SCOPE_USER, $ok);
        if (!$ok) {
            $this->logger->info('Login utente fallito', ['ip' => $ip]);

            return false;
        }

        $this->loginSession($user, $remember);

        return true;
    }

    // ── Area personale ───────────────────────────────────────────────

    /**
     * @param array<string, mixed> $input
     * @return array{ok: bool, errors: list<string>}
     */
    public function updateProfile(int $userId, array $input): array
    {
        $name = $this->clean($input['name'] ?? null, 128);
        $country = strtoupper($this->clean($input['country'] ?? null, 2));
        $vatNumber = $this->clean($input['vat_number'] ?? null, 32);
        $locale = is_string($input['locale'] ?? null) && in_array($input['locale'], Session::LOCALES, true)
            ? $input['locale'] : Session::DEFAULT_LOCALE;

        $errors = [];
        if ($name === '') {
            $errors[] = $this->lang->t('order.error_name');
        }
        if (!$this->vat->isValidCountry($country)) {
            $errors[] = $this->lang->t('order.error_country');
        }
        if ($vatNumber !== '' && !VatService::isPlausibleVatNumber($vatNumber, $country !== '' ? $country : 'IT')) {
            $errors[] = $this->lang->t('order.error_vat_number');
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        $company = $this->clean($input['company'] ?? null, 128);
        $phone = $this->clean($input['phone'] ?? null, 32);
        $street = $this->clean($input['address_street'] ?? null, 255);
        $city = $this->clean($input['address_city'] ?? null, 128);
        $zip = $this->clean($input['address_zip'] ?? null, 16);
        $this->users->updateProfile($userId, [
            'name' => $name,
            'company' => $company !== '' ? $company : null,
            'phone' => $phone !== '' ? $phone : null,
            'vat_number' => $vatNumber !== '' ? $vatNumber : null,
            'address_street' => $street !== '' ? $street : null,
            'address_city' => $city !== '' ? $city : null,
            'address_zip' => $zip !== '' ? $zip : null,
            'country_code' => $country,
            'locale' => $locale,
        ]);
        // preferenze di sessione allineate subito
        $this->session->setCountry($country);
        $this->session->setLocale($locale);
        $this->lang->setLocale($locale);

        return ['ok' => true, 'errors' => []];
    }

    /** @return array{ok: bool, errors: list<string>} */
    public function changePassword(int $userId, string $current, string $new, string $confirm): array
    {
        $user = $this->users->findActive($userId);
        $hash = is_array($user) && is_string($user['password_hash'] ?? null) ? $user['password_hash'] : '';
        if ($user === null || $hash === '' || !password_verify($current, $hash)) {
            return ['ok' => false, 'errors' => [$this->lang->t('account.error_current_password')]];
        }
        $errors = $this->validateNewPassword($new, $confirm);
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }
        $this->users->setPasswordHash($userId, (string) password_hash($new, PASSWORD_ARGON2ID));

        return ['ok' => true, 'errors' => []];
    }

    // ── Interni ──────────────────────────────────────────────────────

    /** @param array<string, mixed> $user */
    private function loginSession(array $user, bool $remember): void
    {
        $this->session->loginUser((int) $user['id']);
        $this->session->persist($remember);
        // le preferenze del profilo diventano quelle della sessione
        $this->session->setCountry((string) $user['country_code']);
        $this->session->setLocale((string) $user['locale']);
        $this->lang->setLocale((string) $user['locale']);
        $this->users->touchLastLogin((int) $user['id']);
    }

    /** @return list<string> */
    private function validateNewPassword(string $password, string $confirm): array
    {
        $errors = [];
        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $errors[] = $this->lang->t('account.error_password_short', ['min' => self::MIN_PASSWORD_LENGTH]);
        }
        if ($password !== $confirm) {
            $errors[] = $this->lang->t('account.error_password_mismatch');
        }

        return $errors;
    }

    private function clean(mixed $value, int $maxLength): string
    {
        if (!is_string($value)) {
            return '';
        }

        return mb_substr(trim(strip_tags($value)), 0, $maxLength);
    }
}
