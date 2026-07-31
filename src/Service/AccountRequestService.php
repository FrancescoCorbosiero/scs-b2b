<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\AccountRequestRepository;
use App\Repository\UserRepository;
use App\Support\Lang;
use App\Support\Session;
use Psr\Log\LoggerInterface;

/**
 * Richiesta di attivazione profilo dal sito pubblico (docs/06):
 *
 * 1. submit(): antispam (honeypot + rate limit per IP), validazione, salvataggio
 *    'pending', email all'admin e conferma di ricezione al cliente.
 * 2. approve(): l'admin crea l'account con i dati della richiesta → parte
 *    l'invito per impostare la password (la password non viaggia mai in chiaro).
 * 3. reject(): richiesta archiviata, nessun account creato.
 *
 * I dati raccolti sono quelli che poi precompilano checkout, ricevuta e ordine
 * dropship: azienda, P.IVA, referente e indirizzo completo.
 */
final class AccountRequestService
{
    private const MAX_REQUESTS_PER_HOUR = 3;

    public function __construct(
        private readonly AccountRequestRepository $requests,
        private readonly UserRepository $users,
        private readonly UserService $userService,
        private readonly AccountMailer $mailer,
        private readonly VatService $vat,
        private readonly Session $session,
        private readonly Lang $lang,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok: bool, request_id: int|null, errors: list<string>}
     */
    public function submit(array $input, string $ip, string $userAgent): array
    {
        $fail = static fn (string $error): array => ['ok' => false, 'request_id' => null, 'errors' => [$error]];

        // honeypot: campo invisibile che i bot compilano
        if (!is_string($input['website'] ?? '') || ($input['website'] ?? '') !== '') {
            $this->logger->warning('Richiesta profilo scartata: honeypot compilato', ['ip' => $ip]);

            return $fail($this->lang->t('signup.error_generic'));
        }
        if ($this->requests->countRecentByIp($ip, 60) >= self::MAX_REQUESTS_PER_HOUR) {
            return $fail($this->lang->t('signup.error_rate_limited'));
        }

        $company = $this->clean($input['company'] ?? null, 128);
        $vatNumber = $this->clean($input['vat_number'] ?? null, 32);
        $name = $this->clean($input['name'] ?? null, 128);
        $email = $this->clean($input['email'] ?? null, 255);
        $phone = $this->clean($input['phone'] ?? null, 32);
        $street = $this->clean($input['address_street'] ?? null, 255);
        $city = $this->clean($input['address_city'] ?? null, 128);
        $zip = $this->clean($input['address_zip'] ?? null, 16);
        $country = strtoupper($this->clean($input['country'] ?? null, 2));
        $notes = $this->clean($input['notes'] ?? null, 2000);

        $errors = [];
        if ($company === '') {
            $errors[] = $this->lang->t('signup.error_company');
        }
        if ($name === '') {
            $errors[] = $this->lang->t('order.error_name');
        }
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = $this->lang->t('order.error_email');
        }
        if ($phone === '') {
            $errors[] = $this->lang->t('order.error_phone');
        }
        if ($street === '') {
            $errors[] = $this->lang->t('order.error_street');
        }
        if ($city === '') {
            $errors[] = $this->lang->t('order.error_city');
        }
        if ($zip === '') {
            $errors[] = $this->lang->t('order.error_zip');
        }
        if (!$this->vat->isValidCountry($country)) {
            $errors[] = $this->lang->t('order.error_country');
        }
        if ($vatNumber !== '' && !VatService::isPlausibleVatNumber($vatNumber, $country !== '' ? $country : 'IT')) {
            $errors[] = $this->lang->t('order.error_vat_number');
        }
        if ($errors !== []) {
            return ['ok' => false, 'request_id' => null, 'errors' => $errors];
        }

        // già cliente o richiesta in corso: si dice cosa fare, senza creare doppioni
        if ($this->users->findByEmail($email) !== null) {
            return $fail($this->lang->t('signup.error_already_customer'));
        }
        if ($this->requests->findPendingByEmail($email) !== null) {
            return $fail($this->lang->t('signup.error_already_requested'));
        }

        $locale = $this->session->locale();
        $requestId = $this->requests->insert([
            'company' => $company,
            'vat_number' => $vatNumber !== '' ? VatService::normalizeVatNumber($vatNumber, $country) : null,
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'address_street' => $street,
            'address_city' => $city,
            'address_zip' => $zip,
            'country_code' => $country,
            'locale' => $locale,
            'notes' => $notes !== '' ? $notes : null,
            'ip_address' => $ip,
            'user_agent' => $userAgent !== '' ? mb_substr($userAgent, 0, 255) : null,
        ]);
        $this->logger->info('Richiesta profilo ricevuta', ['request_id' => $requestId]);

        // le email non devono far perdere la richiesta (già salvata)
        $request = $this->requests->find($requestId) ?? [];
        try {
            $this->mailer->sendAccountRequestAdmin($request);
        } catch (\Throwable $e) {
            $this->logger->error('Invio email admin richiesta profilo fallito', ['request_id' => $requestId, 'error' => $e->getMessage()]);
        }
        try {
            $this->mailer->sendAccountRequestAck($request);
        } catch (\Throwable $e) {
            $this->logger->error('Invio conferma richiesta profilo fallito', ['request_id' => $requestId, 'error' => $e->getMessage()]);
        }

        return ['ok' => true, 'request_id' => $requestId, 'errors' => []];
    }

    /**
     * Approvazione admin: crea l'account con i dati della richiesta e invia
     * l'invito per impostare la password.
     *
     * @return array{ok: bool, error: string|null, email_sent: bool}
     */
    public function approve(int $requestId): array
    {
        $request = $this->requests->find($requestId);
        if ($request === null) {
            return ['ok' => false, 'error' => $this->lang->t('signup.admin_not_found'), 'email_sent' => false];
        }
        if (($request['status'] ?? 'pending') !== 'pending') {
            return ['ok' => false, 'error' => $this->lang->t('signup.admin_not_pending'), 'email_sent' => false];
        }

        $result = $this->userService->create([
            'name' => (string) $request['name'],
            'email' => (string) $request['email'],
            'company' => (string) ($request['company'] ?? ''),
            'phone' => (string) ($request['phone'] ?? ''),
            'vat_number' => (string) ($request['vat_number'] ?? ''),
            'address_street' => (string) ($request['address_street'] ?? ''),
            'address_city' => (string) ($request['address_city'] ?? ''),
            'address_zip' => (string) ($request['address_zip'] ?? ''),
            'country' => (string) ($request['country_code'] ?? 'IT'),
            'locale' => (string) ($request['locale'] ?? 'it'),
        ]);
        if (!$result['ok'] || $result['user_id'] === null) {
            return ['ok' => false, 'error' => implode(' ', $result['errors']), 'email_sent' => false];
        }

        $this->requests->markApproved($requestId, $result['user_id']);
        $this->logger->info('Richiesta profilo approvata', ['request_id' => $requestId, 'user_id' => $result['user_id']]);

        return ['ok' => true, 'error' => null, 'email_sent' => $result['email_sent']];
    }

    /** @return array{ok: bool, error: string|null} */
    public function reject(int $requestId): array
    {
        $request = $this->requests->find($requestId);
        if ($request === null) {
            return ['ok' => false, 'error' => $this->lang->t('signup.admin_not_found')];
        }
        if (!$this->requests->markRejected($requestId)) {
            return ['ok' => false, 'error' => $this->lang->t('signup.admin_not_pending')];
        }
        $this->logger->info('Richiesta profilo rifiutata', ['request_id' => $requestId]);

        return ['ok' => true, 'error' => null];
    }

    private function clean(mixed $value, int $maxLength): string
    {
        if (!is_string($value)) {
            return '';
        }

        return mb_substr(trim(strip_tags($value)), 0, $maxLength);
    }
}
