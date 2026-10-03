<?php

declare(strict_types=1);

namespace App\Service;

use App\Support\Config;
use App\Support\Lang;
use Dompdf\Dompdf;
use Dompdf\Options;
use PDO;
use Twig\Environment;

/**
 * Ricevute pro-forma: numerazione progressiva per anno (PF-<anno>-<NNNN>)
 * e generazione del PDF dal template Twig (dompdf).
 *
 * Documento NON fiscale: riepiloga imponibile, VAT applicato in base al
 * paese (VatService) e totale. Se l'IVA non è addebitata, la "Nota IVA"
 * indica quanto varrebbe la richiesta con l'IVA di legge
 * (VatService::indicativeVat). La numerazione può presentare salti se una
 * richiesta fallisce dopo l'assegnazione del numero: accettabile per un
 * documento pro-forma.
 *
 * Le pro-forma manuali (/admin/proforma, ManualReceiptService) usano la
 * stessa serie di numeri e lo stesso template, con `manual: true`.
 */
final class ReceiptService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Environment $twig,
        private readonly Lang $lang,
        private readonly Config $config,
        private readonly VatService $vat,
    ) {
    }

    /** Assegna il prossimo numero: PF-2026-0001, PF-2026-0002, … */
    public function assignNumber(): string
    {
        $year = (int) date('Y');
        $ownTransaction = !$this->pdo->inTransaction();
        if ($ownTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            // FOR UPDATE serializza i writer su MySQL; SQLite (solo dev) serializza da sé
            $forUpdate = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $stmt = $this->pdo->prepare("SELECT last_number FROM receipt_counters WHERE year = ?{$forUpdate}");
            $stmt->execute([$year]);
            $last = $stmt->fetchColumn();

            if ($last === false) {
                $next = 1;
                $insert = $this->pdo->prepare('INSERT INTO receipt_counters (year, last_number) VALUES (?, ?)');
                $insert->execute([$year, $next]);
            } else {
                $next = (int) $last + 1;
                $update = $this->pdo->prepare('UPDATE receipt_counters SET last_number = ? WHERE year = ?');
                $update->execute([$next, $year]);
            }
            if ($ownTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return sprintf('PF-%d-%04d', $year, $next);
    }

    /**
     * PDF binario della ricevuta pro-forma, nel locale del cliente.
     * L'ordine NON deve contenere offer_price nelle righe (usare stripCosts a monte).
     *
     * @param array<string, mixed> $order
     * @param array{manual?: bool} $extra vedi renderHtml()
     */
    public function buildPdf(array $order, string $locale, array $extra = []): string
    {
        $html = $this->renderHtml($order, $locale, $extra);

        $options = new Options();
        $options->set('isRemoteEnabled', false); // nessuna risorsa esterna nel PDF
        $options->setChroot($this->config->rootPath());
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /**
     * Nome file allegato/download, es. "ricevuta-PF-2026-0001.pdf".
     *
     * @param array<string, mixed> $order
     */
    public function fileName(array $order, string $locale): string
    {
        $number = is_string($order['receipt_number'] ?? null) && $order['receipt_number'] !== ''
            ? $order['receipt_number']
            : 'ordine-' . (int) ($order['id'] ?? 0);
        $prefix = $locale === 'en' ? 'receipt' : 'ricevuta';

        return $prefix . '-' . $number . '.pdf';
    }

    /**
     * HTML della ricevuta (sorgente del PDF), pubblico anche per i test.
     *
     * Con `manual` (pro-forma manuale) il documento non cita una richiesta
     * d'ordine e riporta indirizzo, note, stato "annullata" e — se la
     * pro-forma lo prevede (show_bank) — le coordinate per il bonifico con il
     * numero della pro-forma come causale.
     *
     * @param array<string, mixed> $order
     * @param array{manual?: bool} $extra
     */
    public function renderHtml(array $order, string $locale, array $extra = []): string
    {
        $vatNote = $this->vat->indicativeVat($order);
        if ($vatNote !== null) {
            // 22.0 → "22", 25.5 → "25,5": stesso formato italiano degli importi
            $vatNote['rate_label'] = rtrim(rtrim(number_format($vatNote['rate'], 2, ',', ''), '0'), ',');
        }

        $previous = $this->lang->locale();
        $this->lang->setLocale($locale);
        try {
            return $this->twig->render('receipt/proforma.twig', [
                'order' => $order,
                'manual' => (bool) ($extra['manual'] ?? false),
                'bank' => $this->config->bankDetails(),
                'vat_note' => $vatNote,
                'company' => [
                    'name' => $this->config->str('CONTACT_COMPANY_NAME', 'SHOES & CLOTHING RESELLING'),
                    'owner' => $this->config->str('CONTACT_OWNER_NAME'),
                    'address' => $this->config->str('CONTACT_ADDRESS'),
                    'vat' => $this->config->str('CONTACT_VAT'),
                    'email' => $this->config->str('CONTACT_EMAIL'),
                    'phone' => $this->config->str('CONTACT_PHONE'),
                    'site' => $this->config->str('APP_URL', 'https://b2b.shoesclothingstore.com'),
                ],
            ]);
        } finally {
            $this->lang->setLocale($previous);
        }
    }
}
