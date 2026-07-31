<?php

declare(strict_types=1);

namespace App\Service;

use App\Support\Config;
use App\Support\Lang;
use App\Support\SmtpMailer;
use Twig\Environment;

/**
 * Email account (invito e reset password), nel locale dell'utente.
 * I link contengono il token in CHIARO monouso: mai loggarli.
 */
final class AccountMailer
{
    public function __construct(
        private readonly Config $config,
        private readonly Environment $twig,
        private readonly Lang $lang,
        private readonly SmtpMailer $smtp,
    ) {
    }

    /** @param array<string, mixed> $user */
    public function sendInvite(array $user, string $plainToken, int $ttlHours): void
    {
        $this->sendAccountEmail($user, 'emails/account_invite.twig', 'email.invite_subject', $plainToken, $ttlHours);
    }

    /** @param array<string, mixed> $user */
    public function sendReset(array $user, string $plainToken, int $ttlHours): void
    {
        $this->sendAccountEmail($user, 'emails/account_reset.twig', 'email.reset_subject', $plainToken, $ttlHours);
    }

    /**
     * Nuova richiesta di profilo → admin (sempre in italiano), con i dati
     * completi e il promemoria di approvarla da /admin/richieste-profilo.
     *
     * @param array<string, mixed> $request
     */
    public function sendAccountRequestAdmin(array $request): void
    {
        $previousLocale = $this->lang->locale();
        $this->lang->setLocale('it');
        try {
            $html = $this->twig->render('emails/account_request_admin.twig', [
                'request' => $request,
                'admin_url' => rtrim($this->config->str('APP_URL', 'https://b2b.shoesclothingstore.com'), '/') . '/admin/richieste-profilo',
            ]);
        } finally {
            $this->lang->setLocale($previousLocale);
        }
        $subject = sprintf('Nuova richiesta profilo — %s (%s)',
            (string) ($request['company'] ?? ''), (string) ($request['name'] ?? ''));

        $replyTo = is_string($request['email'] ?? null) && $request['email'] !== '' ? (string) $request['email'] : null;
        $this->smtp->send(
            $this->config->str('ADMIN_EMAIL', 'info@shoesclothingstore.com'),
            $subject,
            $html,
            $replyTo,
        );
    }

    /**
     * Conferma di ricezione al cliente, nel suo locale: nessun link, nessun
     * dato riservato — l'accesso arriva solo dopo l'approvazione.
     *
     * @param array<string, mixed> $request
     */
    public function sendAccountRequestAck(array $request): void
    {
        $locale = is_string($request['locale'] ?? null) && $request['locale'] !== '' ? (string) $request['locale'] : 'it';
        $company = $this->config->str('CONTACT_COMPANY_NAME', 'SHOES & CLOTHING RESELLING');

        $previousLocale = $this->lang->locale();
        $this->lang->setLocale($locale);
        try {
            $html = $this->twig->render('emails/account_request_ack.twig', [
                'request' => $request,
                'company_name' => $company,
                'contact_email' => $this->config->str('CONTACT_EMAIL'),
            ]);
        } finally {
            $this->lang->setLocale($previousLocale);
        }

        $email = (string) ($request['email'] ?? '');
        if ($email !== '') {
            $this->smtp->send($email, $this->lang->tIn($locale, 'email.signup_ack_subject', ['company' => $company]), $html);
        }
    }

    /** @param array<string, mixed> $user */
    private function sendAccountEmail(array $user, string $template, string $subjectKey, string $plainToken, int $ttlHours): void
    {
        $locale = is_string($user['locale'] ?? null) && $user['locale'] !== '' ? $user['locale'] : 'it';
        $link = rtrim($this->config->str('APP_URL', 'https://b2b.shoesclothingstore.com'), '/')
            . '/account/imposta-password?token=' . rawurlencode($plainToken);

        $previousLocale = $this->lang->locale();
        $this->lang->setLocale($locale);
        try {
            $html = $this->twig->render($template, [
                'user' => $user,
                'link' => $link,
                'ttl_hours' => $ttlHours,
                'company_name' => $this->config->str('CONTACT_COMPANY_NAME', 'SHOES & CLOTHING RESELLING'),
                'contact_email' => $this->config->str('CONTACT_EMAIL'),
            ]);
        } finally {
            $this->lang->setLocale($previousLocale);
        }
        $subject = $this->lang->tIn($locale, $subjectKey, [
            'company' => $this->config->str('CONTACT_COMPANY_NAME', 'SHOES & CLOTHING RESELLING'),
        ]);

        $email = $user['email'] ?? '';
        if (is_string($email) && $email !== '') {
            $this->smtp->send($email, $subject, $html);
        }
    }
}
