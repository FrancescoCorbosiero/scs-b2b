<?php

declare(strict_types=1);

namespace App\Service;

use App\Support\Config;
use PHPMailer\PHPMailer\PHPMailer;
use Twig\Environment;

/**
 * Email della richiesta d'ordine via PHPMailer/SMTP.
 *
 * - Admin: tabella completa; costo fornitore e margine SOLO se
 *   ADMIN_EMAIL_SHOW_COST=1 (default on).
 * - Cliente: riepilogo SENZA costi, margini o offer_price (Regola d'oro n.1:
 *   il template customer_order non riceve proprio quei dati).
 */
final class OrderMailer
{
    public function __construct(
        private readonly Config $config,
        private readonly Environment $twig,
    ) {
    }

    /** @param array<string, mixed> $order */
    public function sendAdminEmail(array $order): void
    {
        $showCost = $this->config->bool('ADMIN_EMAIL_SHOW_COST', true);
        $order = $showCost ? $order : self::stripCosts($order);
        $html = $this->twig->render('emails/admin_order.twig', [
            'order' => $order,
            'show_cost' => $showCost,
        ]);
        $subject = sprintf('Nuova richiesta ordine #%d — %s (%d pezzi)',
            (int) ($order['id'] ?? 0), (string) ($order['customer_name'] ?? ''), (int) ($order['total_items'] ?? 0));

        // Reply-To = cliente: rispondendo dalla casella admin si scrive
        // direttamente a lui (il From resta il mittente verificato, es. SES)
        $replyTo = $order['email'] ?? null;
        $this->send(
            $this->config->str('ADMIN_EMAIL', 'info@shoesclothingstore.com'),
            $subject,
            $html,
            is_string($replyTo) && $replyTo !== '' ? $replyTo : null,
        );
    }

    /** @param array<string, mixed> $order */
    public function sendCustomerEmail(array $order): void
    {
        // difesa in profondità: il template cliente non deve MAI vedere i costi
        $order = self::stripCosts($order);
        $html = $this->twig->render('emails/customer_order.twig', [
            'order' => $order,
            'contact_email' => $this->config->str('CONTACT_EMAIL'),
            'contact_phone' => $this->config->str('CONTACT_PHONE'),
            'contact_whatsapp' => $this->config->str('CONTACT_WHATSAPP'),
        ]);
        $subject = sprintf('Richiesta ordine ricevuta #%d — %s',
            (int) ($order['id'] ?? 0), $this->config->str('CONTACT_COMPANY_NAME', 'SHOES & CLOTHING RESELLING'));

        $email = $order['email'] ?? '';
        if (is_string($email) && $email !== '') {
            $this->send($email, $subject, $html);
        }
    }

    /**
     * Rimuove offer_price da tutte le righe (email cliente / admin senza costi).
     *
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    public static function stripCosts(array $order): array
    {
        if (isset($order['lines']) && is_array($order['lines'])) {
            $lines = [];
            foreach ($order['lines'] as $line) {
                if (is_array($line)) {
                    unset($line['offer_price']);
                }
                $lines[] = $line;
            }
            $order['lines'] = $lines;
        }

        return $order;
    }

    private function send(string $to, string $subject, string $html, ?string $replyTo = null): void
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
        $mailer->isHTML(true);
        $mailer->Subject = $subject;
        $mailer->Body = $html;
        $mailer->AltBody = trim((string) preg_replace('/\s+/', ' ', strip_tags($html)));
        $mailer->send();
    }
}
