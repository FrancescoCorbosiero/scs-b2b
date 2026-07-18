<?php

declare(strict_types=1);

namespace App\Service;

use App\Support\Config;
use App\Support\Lang;
use App\Support\SmtpMailer;
use Twig\Environment;

/**
 * Email del ciclo ordine via PHPMailer/SMTP (docs/06).
 *
 * - Admin: tabella completa + esito auto-dropship, SEMPRE in italiano; costo
 *   fornitore e margine SOLO se ADMIN_EMAIL_SHOW_COST=1 (default on).
 * - Cliente alla richiesta: istruzioni di pagamento via bonifico nel locale
 *   del cliente, con l'avviso esplicito che l'ordine è confermato SOLO
 *   all'arrivo del pagamento. NIENTE ricevuta a questo stadio.
 * - Cliente alla conferma admin: email di conferma con la ricevuta pro-forma
 *   PDF in allegato.
 * Mai costi, margini o offer_price verso il cliente (Regola d'oro n.1:
 * i template cliente non ricevono proprio quei dati).
 */
final class OrderMailer
{
    public function __construct(
        private readonly Config $config,
        private readonly Environment $twig,
        private readonly Lang $lang,
        private readonly ReceiptService $receipts,
        private readonly SmtpMailer $smtp,
    ) {
    }

    /**
     * @param array<string, mixed> $order
     * @param array{ok: bool, dropship_id: int|null, message: string|null, simulated: bool|null}|null $autoDropship
     */
    public function sendAdminEmail(array $order, ?array $autoDropship = null): void
    {
        $showCost = $this->config->bool('ADMIN_EMAIL_SHOW_COST', true);
        $order = $showCost ? $order : self::stripCosts($order);

        // l'email amministratore resta in italiano, qualunque sia la lingua del cliente
        $previousLocale = $this->lang->locale();
        $this->lang->setLocale('it');
        try {
            $html = $this->twig->render('emails/admin_order.twig', [
                'order' => $order,
                'show_cost' => $showCost,
                'auto_dropship' => $autoDropship,
            ]);
        } finally {
            $this->lang->setLocale($previousLocale);
        }
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

    /**
     * Email alla richiesta: istruzioni di pagamento (bonifico), nessun allegato.
     * Con $isUpdate=true è il RIALLINEAMENTO admin (docs/06): stesso contenuto
     * con importi aggiornati e intro/oggetto dedicati.
     *
     * @param array<string, mixed> $order
     */
    public function sendCustomerEmail(array $order, bool $isUpdate = false): void
    {
        // difesa in profondità: il template cliente non deve MAI vedere i costi
        $order = self::stripCosts($order);
        $locale = is_string($order['locale'] ?? null) && $order['locale'] !== '' ? $order['locale'] : 'it';

        $previousLocale = $this->lang->locale();
        $this->lang->setLocale($locale);
        try {
            $html = $this->twig->render('emails/customer_order.twig', [
                'order' => $order,
                'is_update' => $isUpdate,
                'bank' => $this->bankDetails(),
                'company_name' => $this->config->str('CONTACT_COMPANY_NAME', 'SHOES & CLOTHING RESELLING'),
                'contact_email' => $this->config->str('CONTACT_EMAIL'),
                'contact_phone' => $this->config->str('CONTACT_PHONE'),
                'contact_whatsapp' => $this->config->str('CONTACT_WHATSAPP'),
            ]);
        } finally {
            $this->lang->setLocale($previousLocale);
        }
        $subject = $this->lang->tIn($locale, $isUpdate ? 'email.updated_subject' : 'email.customer_subject', [
            'id' => (int) ($order['id'] ?? 0),
            'company' => $this->config->str('CONTACT_COMPANY_NAME', 'SHOES & CLOTHING RESELLING'),
        ]);

        $email = $order['email'] ?? '';
        if (is_string($email) && $email !== '') {
            $this->send($email, $subject, $html);
        }
    }

    /**
     * Email alla conferma admin (pagamento ricevuto): ricevuta pro-forma PDF allegata.
     *
     * @param array<string, mixed> $order
     */
    public function sendCustomerConfirmedEmail(array $order): void
    {
        $order = self::stripCosts($order);
        $locale = is_string($order['locale'] ?? null) && $order['locale'] !== '' ? $order['locale'] : 'it';

        $previousLocale = $this->lang->locale();
        $this->lang->setLocale($locale);
        try {
            $html = $this->twig->render('emails/customer_confirmed.twig', [
                'order' => $order,
                'company_name' => $this->config->str('CONTACT_COMPANY_NAME', 'SHOES & CLOTHING RESELLING'),
                'contact_email' => $this->config->str('CONTACT_EMAIL'),
                'contact_phone' => $this->config->str('CONTACT_PHONE'),
                'contact_whatsapp' => $this->config->str('CONTACT_WHATSAPP'),
            ]);
        } finally {
            $this->lang->setLocale($previousLocale);
        }
        $subject = $this->lang->tIn($locale, 'email.confirmed_subject', [
            'id' => (int) ($order['id'] ?? 0),
            'company' => $this->config->str('CONTACT_COMPANY_NAME', 'SHOES & CLOTHING RESELLING'),
        ]);

        // ricevuta pro-forma in allegato: se la generazione fallisce l'email parte comunque
        $attachment = null;
        try {
            $attachment = [
                'content' => $this->receipts->buildPdf($order, $locale),
                'name' => $this->receipts->fileName($order, $locale),
            ];
        } catch (\Throwable) {
            $attachment = null;
        }

        $email = $order['email'] ?? '';
        if (is_string($email) && $email !== '') {
            $this->send($email, $subject, $html, null, $attachment);
        }
    }

    /** @return array{holder: string, name: string, iban: string, bic: string} */
    private function bankDetails(): array
    {
        return [
            'holder' => $this->config->str('BANK_ACCOUNT_HOLDER'),
            'name' => $this->config->str('BANK_NAME'),
            'iban' => $this->config->str('BANK_IBAN'),
            'bic' => $this->config->str('BANK_BIC'),
        ];
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

    /** @param array{content: string, name: string}|null $attachment */
    private function send(string $to, string $subject, string $html, ?string $replyTo = null, ?array $attachment = null): void
    {
        $this->smtp->send($to, $subject, $html, $replyTo, $attachment);
    }
}
