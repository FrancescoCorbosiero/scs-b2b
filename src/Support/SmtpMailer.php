<?php

declare(strict_types=1);

namespace App\Support;

use PHPMailer\PHPMailer\PHPMailer;

/**
 * Invio SMTP condiviso (PHPMailer): usato da OrderMailer e AccountMailer.
 * Configurazione da .env (vedi README § Email via AWS SES).
 */
final class SmtpMailer
{
    public function __construct(private readonly Config $config)
    {
    }

    /** @param array{content: string, name: string}|null $attachment */
    public function send(string $to, string $subject, string $html, ?string $replyTo = null, ?array $attachment = null): void
    {
        $host = $this->config->str('SMTP_HOST');
        if ($host === '') {
            throw new \RuntimeException('SMTP non configurato (SMTP_HOST vuoto)');
        }

        $mailer = new PHPMailer(true);
        $mailer->isSMTP();
        $mailer->Host = $host;
        $mailer->Port = $this->config->int('SMTP_PORT', 587);
        $mailer->SMTPAuth = $this->config->str('SMTP_USER') !== '';
        if ($mailer->SMTPAuth) {
            $mailer->Username = $this->config->str('SMTP_USER');
            $mailer->Password = $this->config->str('SMTP_PASSWORD');
        }
        $mailer->SMTPSecure = $this->config->str('SMTP_ENCRYPTION', 'tls') === 'ssl'
            ? PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer::ENCRYPTION_STARTTLS;
        if (!$this->config->isProduction()) {
            // Mailpit e simili non hanno TLS
            $mailer->SMTPSecure = '';
            $mailer->SMTPAutoTLS = false;
        }
        $mailer->CharSet = PHPMailer::CHARSET_UTF8;
        $mailer->setFrom(
            $this->config->str('MAIL_FROM_ADDRESS', 'noreply@shoesclothingstore.com'),
            $this->config->str('MAIL_FROM_NAME', 'SHOES & CLOTHING RESELLING'),
        );
        $mailer->addAddress($to);
        if ($replyTo !== null) {
            $mailer->addReplyTo($replyTo);
        }
        if ($attachment !== null) {
            $mailer->addStringAttachment($attachment['content'], $attachment['name'], PHPMailer::ENCODING_BASE64, 'application/pdf');
        }
        $mailer->isHTML(true);
        $mailer->Subject = $subject;
        $mailer->Body = $html;
        $mailer->AltBody = trim((string) preg_replace('/\s+/', ' ', strip_tags($html)));
        $mailer->send();
    }
}
