<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\ManualReceiptRepository;
use App\Repository\ProductRepository;
use App\Repository\UserRepository;
use App\Support\Lang;
use PDO;
use Psr\Log\LoggerInterface;

/**
 * Pro-forma manuali (/admin/proforma, docs/06): ricevute pro-forma create a
 * mano dall'admin, fuori dal ciclo delle richieste d'ordine.
 *
 * - Numero dalla STESSA serie delle ricevute degli ordini (PF-<anno>-<NNNN>,
 *   ReceiptService::assignNumber), assegnato alla creazione nella stessa
 *   transazione dell'inserimento: un errore non consuma il numero.
 * - Righe libere (descrizione, taglia, quantità, prezzo netto); la ricerca
 *   SKU precompila solo nome, taglie e prezzo di LISTINO: mai offer_price
 *   (Regola d'oro n.1).
 * - IVA come nelle richieste d'ordine: P.IVA obbligatoria, schema da paese +
 *   P.IVA, aliquota azzerata con VAT_ON_ORDER=0 (VatService); imponibile =
 *   righe + spedizione.
 * - PDF dallo stesso template della ricevuta degli ordini; email al cliente
 *   nella sua lingua con il PDF allegato.
 */
final class ManualReceiptService
{
    public const MAX_LINES = 100;
    private const MAX_QTY = 9999;
    private const MAX_UNIT_CENTS = 9_999_999;          // 99.999,99 €
    private const MAX_TOTAL_CENTS = 9_999_999_999;     // DECIMAL(10,2)
    private const MAX_NOTES = 2000;
    private const LOCALES = ['it', 'en'];

    public function __construct(
        private readonly ManualReceiptRepository $receipts,
        private readonly ReceiptService $receiptService,
        private readonly VatService $vat,
        private readonly OrderMailer $mailer,
        private readonly ProductRepository $products,
        private readonly UserRepository $users,
        private readonly Lang $lang,
        private readonly PDO $pdo,
        private readonly LoggerInterface $logger,
    ) {
    }

    // ── Form ─────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    public function emptyForm(): array
    {
        return [
            'user_id' => '', 'locale' => 'it', 'customer_name' => '', 'company' => '', 'email' => '',
            'phone' => '', 'address_street' => '', 'address_city' => '', 'address_zip' => '',
            'country' => 'IT', 'vat_number' => '', 'shipping' => '', 'notes' => '', 'show_bank' => true,
            'lines' => [self::blankLine()],
        ];
    }

    /**
     * Form precompilato con i dati di un account cliente (/admin/clienti).
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function formFromUser(array $user): array
    {
        $locale = (string) ($user['locale'] ?? 'it');

        return [
            'user_id' => (string) (int) ($user['id'] ?? 0),
            'locale' => in_array($locale, self::LOCALES, true) ? $locale : 'it',
            'customer_name' => (string) ($user['name'] ?? ''),
            'company' => (string) ($user['company'] ?? ''),
            'email' => (string) ($user['email'] ?? ''),
            'phone' => (string) ($user['phone'] ?? ''),
            'address_street' => (string) ($user['address_street'] ?? ''),
            'address_city' => (string) ($user['address_city'] ?? ''),
            'address_zip' => (string) ($user['address_zip'] ?? ''),
            'country' => (string) ($user['country_code'] ?? 'IT'),
            'vat_number' => (string) ($user['vat_number'] ?? ''),
        ] + $this->emptyForm();
    }

    /**
     * Form con i dati di una pro-forma esistente: per modificarla o per
     * crearne una nuova uguale (duplica).
     *
     * @param array<string, mixed> $receipt
     * @return array<string, mixed>
     */
    public function formFromReceipt(array $receipt): array
    {
        $lines = [];
        foreach (is_array($receipt['lines'] ?? null) ? $receipt['lines'] : [] as $line) {
            if (!is_array($line)) {
                continue;
            }
            $lines[] = [
                'sku' => (string) ($line['sku'] ?? ''),
                'name' => (string) ($line['name'] ?? ''),
                'size_eu' => (string) ($line['size_eu'] ?? ''),
                'size_us' => (string) ($line['size_us'] ?? ''),
                'barcode' => (string) ($line['barcode'] ?? ''),
                'qty' => (string) (int) ($line['qty'] ?? 1),
                'unit_price' => self::formatInput((string) ($line['unit_price'] ?? '0')),
            ];
        }
        $shipping = (string) ($receipt['shipping_amount'] ?? '0');

        return [
            'user_id' => $receipt['user_id'] !== null ? (string) $receipt['user_id'] : '',
            'locale' => (string) ($receipt['locale'] ?? 'it'),
            'customer_name' => (string) ($receipt['customer_name'] ?? ''),
            'company' => (string) ($receipt['company'] ?? ''),
            'email' => (string) ($receipt['email'] ?? ''),
            'phone' => (string) ($receipt['phone'] ?? ''),
            'address_street' => (string) ($receipt['address_street'] ?? ''),
            'address_city' => (string) ($receipt['address_city'] ?? ''),
            'address_zip' => (string) ($receipt['address_zip'] ?? ''),
            'country' => (string) ($receipt['country_code'] ?? 'IT'),
            'vat_number' => (string) ($receipt['vat_number'] ?? ''),
            'shipping' => CartService::cents($shipping) > 0 ? self::formatInput($shipping) : '',
            'notes' => (string) ($receipt['notes'] ?? ''),
            'show_bank' => (bool) ($receipt['show_bank'] ?? true),
            'lines' => $lines !== [] ? $lines : [self::blankLine()],
        ];
    }

    /**
     * Valida il form e calcola righe, totali e IVA. Le righe completamente
     * vuote vengono ignorate; gli errori citano il numero di riga a video.
     *
     * @param array<string, mixed> $input corpo POST
     * @return array{ok: bool, errors: list<string>, form: array<string, mixed>, data: array<string, mixed>|null}
     */
    public function validate(array $input): array
    {
        $str = static fn (string $key): string => is_string($input[$key] ?? null) ? trim($input[$key]) : '';
        $form = [
            'user_id' => $str('user_id'),
            'locale' => $str('locale'),
            'customer_name' => $str('customer_name'),
            'company' => $str('company'),
            'email' => $str('email'),
            'phone' => $str('phone'),
            'address_street' => $str('address_street'),
            'address_city' => $str('address_city'),
            'address_zip' => $str('address_zip'),
            'country' => strtoupper($str('country')),
            'vat_number' => $str('vat_number'),
            'shipping' => $str('shipping'),
            'notes' => str_replace("\r\n", "\n", $str('notes')),
            'show_bank' => ($input['show_bank'] ?? '') === '1',
            'lines' => [],
        ];
        $errors = [];
        $t = fn (string $key, array $params = []): string => $this->lang->t('proforma.' . $key, $params);

        if (!in_array($form['locale'], self::LOCALES, true)) {
            $form['locale'] = 'it';
        }
        if ($form['customer_name'] === '') {
            $errors[] = $t('error_name');
        }
        foreach ([
            'customer_name' => 128, 'company' => 128, 'email' => 255, 'phone' => 32,
            'address_street' => 255, 'address_city' => 128, 'address_zip' => 16,
        ] as $field => $max) {
            if (mb_strlen($form[$field]) > $max) {
                $errors[] = $t('error_too_long', ['field' => $t('field_' . $field), 'max' => $max]);
            }
        }
        if ($form['email'] !== '' && filter_var($form['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = $t('error_email');
        }
        if (!$this->vat->isValidCountry($form['country'])) {
            $errors[] = $t('error_country');
        } elseif (!VatService::isRequiredVatNumberValid($form['vat_number'], $form['country'])) {
            $errors[] = $t('error_vat_number');
        }
        if (mb_strlen($form['notes']) > self::MAX_NOTES) {
            $errors[] = $t('error_too_long', ['field' => $t('field_notes'), 'max' => self::MAX_NOTES]);
        }

        $shippingCents = 0;
        if ($form['shipping'] !== '') {
            $shipping = CustomProductService::decimal($form['shipping']);
            if (!is_numeric($shipping) || (float) $shipping < 0 || CartService::cents($shipping) > self::MAX_UNIT_CENTS) {
                $errors[] = $t('error_shipping');
            } else {
                $shippingCents = CartService::cents($shipping);
            }
        }

        // ── righe ──
        $rawLines = is_array($input['lines'] ?? null) ? array_values($input['lines']) : [];
        if (count($rawLines) > self::MAX_LINES) {
            $errors[] = $t('error_too_many_lines', ['max' => self::MAX_LINES]);
            $rawLines = array_slice($rawLines, 0, self::MAX_LINES);
        }
        $lines = [];
        $totalItems = 0;
        $totalCents = 0;
        foreach ($rawLines as $i => $raw) {
            $raw = is_array($raw) ? $raw : [];
            $field = static fn (string $key): string => is_string($raw[$key] ?? null) ? trim($raw[$key]) : '';
            $line = [
                'sku' => $field('sku'), 'name' => $field('name'), 'size_eu' => $field('size_eu'),
                'size_us' => $field('size_us'), 'barcode' => $field('barcode'),
                'qty' => $field('qty'), 'unit_price' => $field('unit_price'),
            ];
            $form['lines'][] = $line;
            if ($line['name'] === '' && $line['sku'] === '' && $line['unit_price'] === '') {
                continue; // riga vuota
            }
            $row = $i + 1;
            $lineErrors = [];
            if ($line['name'] === '') {
                $lineErrors[] = $t('error_line_name', ['row' => $row]);
            }
            foreach (['name' => 255, 'sku' => 64, 'size_eu' => 16, 'size_us' => 16, 'barcode' => 32] as $key => $max) {
                if (mb_strlen($line[$key]) > $max) {
                    $lineErrors[] = $t('error_line_too_long', ['row' => $row, 'field' => $t('col_' . $key), 'max' => $max]);
                }
            }
            $qty = ctype_digit($line['qty']) ? (int) $line['qty'] : 0;
            if ($qty < 1 || $qty > self::MAX_QTY) {
                $lineErrors[] = $t('error_line_qty', ['row' => $row, 'max' => self::MAX_QTY]);
            }
            $price = CustomProductService::decimal($line['unit_price']);
            $unitCents = is_numeric($price) ? CartService::cents($price) : -1;
            if ($line['unit_price'] === '' || $unitCents < 0 || $unitCents > self::MAX_UNIT_CENTS) {
                $lineErrors[] = $t('error_line_price', ['row' => $row]);
            }
            if ($lineErrors !== []) {
                array_push($errors, ...$lineErrors);
                continue;
            }
            $lines[] = [
                'sku' => $line['sku'],
                'name' => $line['name'],
                'size_eu' => $line['size_eu'],
                'size_us' => $line['size_us'],
                'barcode' => $line['barcode'],
                'qty' => $qty,
                'unit_price' => CartService::money($unitCents),
                'subtotal' => CartService::money($unitCents * $qty),
            ];
            $totalItems += $qty;
            $totalCents += $unitCents * $qty;
        }
        if ($form['lines'] === []) {
            $form['lines'][] = self::blankLine();
        }
        if ($lines === [] && $errors === []) {
            $errors[] = $t('error_no_lines');
        }
        if ($totalCents + $shippingCents > self::MAX_TOTAL_CENTS) {
            $errors[] = $t('error_total_too_high');
        }

        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'form' => $form, 'data' => null];
        }

        // ── IVA e totali: stessi calcoli della richiesta d'ordine ──
        $vat = $this->vat->resolve($form['country'], $form['vat_number']);
        $totalAmount = CartService::money($totalCents);
        $shippingAmount = CartService::money($shippingCents);
        $taxable = CartService::money($totalCents + $shippingCents);
        $vatAmount = VatService::vatAmount($taxable, $vat['rate']);
        $userId = ctype_digit($form['user_id']) && $this->users->find((int) $form['user_id']) !== null
            ? (int) $form['user_id']
            : null;
        $nullable = static fn (string $value): ?string => $value === '' ? null : $value;

        $data = [
            'user_id' => $userId,
            'locale' => $form['locale'],
            'customer_name' => $form['customer_name'],
            'company' => $nullable($form['company']),
            'email' => $nullable($form['email']),
            'phone' => $nullable($form['phone']),
            'address_street' => $nullable($form['address_street']),
            'address_city' => $nullable($form['address_city']),
            'address_zip' => $nullable($form['address_zip']),
            'country_code' => $vat['country_code'],
            'vat_number' => (string) $vat['vat_number'],
            'vat_scheme' => $vat['scheme'],
            'vat_rate' => number_format($vat['rate'], 2, '.', ''),
            'vat_amount' => $vatAmount,
            'lines_json' => json_encode($lines, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'total_items' => $totalItems,
            'total_amount' => $totalAmount,
            'shipping_amount' => $shippingAmount,
            'total_gross' => VatService::grossTotal($taxable, $vatAmount),
            'notes' => $nullable($form['notes']),
            'show_bank' => $form['show_bank'],
        ];

        return ['ok' => true, 'errors' => [], 'form' => $form, 'data' => $data];
    }

    // ── Ciclo di vita ────────────────────────────────────────────────

    /**
     * Crea la pro-forma assegnando il prossimo numero della serie PF, nella
     * stessa transazione dell'inserimento.
     *
     * @param array<string, mixed> $data da validate()
     */
    public function create(array $data): int
    {
        $this->pdo->beginTransaction();
        try {
            $number = $this->receiptService->assignNumber();
            $id = $this->receipts->insert($number, $data);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        $this->logger->info('Pro-forma manuale creata', ['id' => $id, 'number' => $number]);

        return $id;
    }

    /** @param array<string, mixed> $data da validate() */
    public function update(int $id, array $data): bool
    {
        return $this->receipts->update($id, $data);
    }

    public function cancel(int $id): bool
    {
        return $this->receipts->cancel($id);
    }

    /**
     * PDF nella lingua della pro-forma.
     *
     * @param array<string, mixed> $receipt
     * @return array{content: string, name: string}
     */
    public function pdf(array $receipt): array
    {
        $locale = self::localeOf($receipt);

        return [
            'content' => $this->receiptService->buildPdf($receipt, $locale, ['manual' => true]),
            'name' => $this->receiptService->fileName($receipt, $locale),
        ];
    }

    /**
     * Invia la pro-forma al cliente (email nella sua lingua, PDF allegato).
     *
     * @param array<string, mixed> $receipt
     * @return array{ok: bool, error: string|null}
     */
    public function send(array $receipt): array
    {
        if (($receipt['status'] ?? '') !== ManualReceiptRepository::STATUS_ISSUED) {
            return ['ok' => false, 'error' => $this->lang->t('proforma.error_send_cancelled')];
        }
        $to = is_string($receipt['email'] ?? null) ? $receipt['email'] : '';
        if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return ['ok' => false, 'error' => $this->lang->t('proforma.error_send_no_email')];
        }
        try {
            $this->mailer->sendManualReceiptEmail($receipt, $this->pdf($receipt));
        } catch (\Throwable $e) {
            $this->logger->error('Invio pro-forma manuale fallito', [
                'id' => $receipt['id'] ?? null,
                'number' => $receipt['receipt_number'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'error' => $this->lang->t('proforma.error_send_failed', ['error' => $e->getMessage()])];
        }
        $this->receipts->markSent((int) $receipt['id'], $to);

        return ['ok' => true, 'error' => null];
    }

    /**
     * Ricerca di uno SKU a catalogo (feed o prodotti propri) per precompilare
     * una riga: nome, taglie e prezzo di LISTINO. Mai offer_price.
     *
     * @return array{sku: string, name: string, brand: string,
     *   sizes: list<array{size_eu: string, size_us: string, barcode: string, price: string, quantity: int}>}|null
     */
    public function lookupSku(string $sku): ?array
    {
        $sku = trim($sku);
        if ($sku === '' || mb_strlen($sku) > 64) {
            return null;
        }
        $product = $this->products->findActiveBySku($sku);
        if ($product === null) {
            return null;
        }
        $sizes = [];
        foreach ($this->products->sizesForSku((string) $product['sku']) as $size) {
            $sizes[] = [
                'size_eu' => $size['size_eu'],
                'size_us' => $size['size_us'],
                'barcode' => $size['barcode'],
                'price' => $size['price'],
                'quantity' => $size['quantity'],
            ];
        }

        return [
            'sku' => (string) $product['sku'],
            'name' => (string) $product['name'],
            'brand' => (string) $product['brand'],
            'sizes' => $sizes,
        ];
    }

    /** @param array<string, mixed> $receipt */
    public static function localeOf(array $receipt): string
    {
        $locale = $receipt['locale'] ?? 'it';

        return in_array($locale, self::LOCALES, true) ? $locale : 'it';
    }

    /** @return array{sku: string, name: string, size_eu: string, size_us: string, barcode: string, qty: string, unit_price: string} */
    private static function blankLine(): array
    {
        return ['sku' => '', 'name' => '', 'size_eu' => '', 'size_us' => '', 'barcode' => '', 'qty' => '1', 'unit_price' => ''];
    }

    /** "89.90" → "89,90" per i campi del form (il parser accetta entrambi). */
    private static function formatInput(string $amount): string
    {
        return number_format((float) $amount, 2, ',', '');
    }
}
