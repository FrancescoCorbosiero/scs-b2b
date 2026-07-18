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
 * paese (VatService) e totale. La numerazione può presentare salti se una
 * richiesta fallisce dopo l'assegnazione del numero: accettabile per un
 * documento pro-forma.
 */
final class ReceiptService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Environment $twig,
        private readonly Lang $lang,
        private readonly Config $config,
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
     */
    public function buildPdf(array $order, string $locale): string
    {
        $html = $this->renderHtml($order, $locale);

        $options = new Options();
        $options->set('isRemoteEnabled', false); // nessuna risorsa esterna nel PDF
        $options->setChroot($this->config->rootPath());
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /** Nome file allegato/download, es. "ricevuta-PF-2026-0001.pdf". */
    public function fileName(array $order, string $locale): string
    {
        $number = is_string($order['receipt_number'] ?? null) && $order['receipt_number'] !== ''
            ? $order['receipt_number']
            : 'ordine-' . (int) ($order['id'] ?? 0);
        $prefix = $locale === 'en' ? 'receipt' : 'ricevuta';

        return $prefix . '-' . $number . '.pdf';
    }

    /** @param array<string, mixed> $order */
    private function renderHtml(array $order, string $locale): string
    {
        $previous = $this->lang->locale();
        $this->lang->setLocale($locale);
        try {
            return $this->twig->render('receipt/proforma.twig', [
                'order' => $order,
                'company' => [
                    'name' => $this->config->str('CONTACT_COMPANY_NAME', 'SHOES & CLOTHING RESELLING'),
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
