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
