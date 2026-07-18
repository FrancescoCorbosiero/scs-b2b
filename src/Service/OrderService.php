<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\OrderRequestRepository;
use App\Repository\ProductRepository;
use App\Support\Config;
use App\Support\Lang;
use App\Support\Session;
use Psr\Log\LoggerInterface;

/**
 * Ciclo di vita della richiesta d'ordine (docs/06):
 *
 * 1. submit(): validazione form (con indirizzo di spedizione), antispam,
 *    snapshot del carrello, VAT per paese (VatService), salvataggio 'pending',
 *    eventuale ordine dropship automatico (AUTO_DROPSHIP_ON_REQUEST) ed email
 *    di istruzioni pagamento (bonifico) — SENZA ricevuta.
 * 2. confirm(): l'admin registra il pagamento → numero ricevuta pro-forma ed
 *    email di conferma col PDF allegato.
 * 3. cancel(): richiesta mai pagata → annullata.
 *
 * Un fallimento SMTP o dropship NON perde la richiesta: è già a DB.
 */
final class OrderService
{
    private const MAX_REQUESTS_PER_HOUR = 3;

    public function __construct(
        private readonly CartService $cart,
        private readonly ProductRepository $products,
        private readonly OrderRequestRepository $orders,
        private readonly OrderMailer $mailer,
        private readonly VatService $vat,
        private readonly ReceiptService $receipts,
        private readonly DropshipOrderService $dropship,
        private readonly Session $session,
        private readonly Config $config,
        private readonly Lang $lang,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok: bool, order_id: int|null, errors: list<string>, cart_adjusted: bool}
     */
    public function submit(array $input, string $ip, string $userAgent): array
    {
        $fail = static fn (string $error, bool $adjusted = false): array => [
            'ok' => false, 'order_id' => null, 'errors' => [$error], 'cart_adjusted' => $adjusted,
        ];

        // honeypot: campo invisibile che i bot compilano
        if (!is_string($input['website'] ?? '') || ($input['website'] ?? '') !== '') {
            $this->logger->warning('Richiesta ordine scartata: honeypot compilato', ['ip' => $ip]);

            return $fail($this->lang->t('order.error_generic'));
        }

        // rate limit per IP
        if ($this->orders->countRecentByIp($ip, 60) >= self::MAX_REQUESTS_PER_HOUR) {
            return $fail($this->lang->t('order.error_rate_limited'));
        }

        // validazione campi
        $name = $this->cleanString($input['customer_name'] ?? null, 128);
        $company = $this->cleanString($input['company'] ?? null, 128);
        $email = $this->cleanString($input['email'] ?? null, 255);
        $phone = $this->cleanString($input['phone'] ?? null, 32);
        $street = $this->cleanString($input['address_street'] ?? null, 255);
        $city = $this->cleanString($input['address_city'] ?? null, 128);
        $zip = $this->cleanString($input['address_zip'] ?? null, 16);
        $notes = $this->cleanString($input['notes'] ?? null, 2000);
        $country = strtoupper($this->cleanString($input['country'] ?? null, 2));
        $vatNumberRaw = $this->cleanString($input['vat_number'] ?? null, 32);

        $errors = [];
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
        if ($vatNumberRaw !== '' && !VatService::isPlausibleVatNumber($vatNumberRaw, $country !== '' ? $country : 'IT')) {
            $errors[] = $this->lang->t('order.error_vat_number');
        }
        if ($errors !== []) {
            return ['ok' => false, 'order_id' => null, 'errors' => $errors, 'cart_adjusted' => false];
        }

        // rivalidazione del carrello contro lo stock corrente
        $adjustments = $this->cart->revalidate();
        if ($adjustments !== []) {
            return $fail($this->lang->t('order.error_stock_changed'), true);
        }
        if (!$this->cart->meetsMinimum()) {
            return $fail($this->lang->t('order.error_minimum', ['min' => $this->cart->minOrderItems()]));
        }

        // il paese scelto nel form diventa la preferenza di sessione (header allineato)
        $this->session->setCountry($country);
        $locale = $this->session->locale();

        $detail = $this->cart->detail();
        $vat = $this->vat->resolve($country, $vatNumberRaw !== '' ? $vatNumberRaw : null);
        $vatAmount = VatService::vatAmount($detail['total_amount'], $vat['rate']);
        $totalGross = VatService::grossTotal($detail['total_amount'], $vatAmount);
        $snapshot = $this->buildSnapshot($detail);
        $snapshotJson = (string) json_encode($snapshot, JSON_UNESCAPED_UNICODE);

        $orderId = $this->orders->insert([
            'customer_name' => $name,
            'company' => $company !== '' ? $company : null,
            'email' => $email,
            'phone' => $phone,
            'address_street' => $street,
            'address_city' => $city,
            'address_zip' => $zip,
            'notes' => $notes !== '' ? $notes : null,
            'locale' => $locale,
            'country_code' => $vat['country_code'],
            'vat_number' => $vat['vat_number'],
            'vat_scheme' => $vat['scheme'],
            'vat_rate' => number_format($vat['rate'], 2, '.', ''),
            'vat_amount' => $vatAmount,
            'total_gross' => $totalGross,
            'total_items' => $detail['total_items'],
            'total_amount' => $detail['total_amount'],
            'cart_snapshot' => $snapshotJson,
            'ip_address' => $ip,
            'user_agent' => $userAgent !== '' ? mb_substr($userAgent, 0, 255) : null,
        ]);

        $order = [
            'id' => $orderId,
            'created_at' => date('Y-m-d H:i:s'),
            'status' => 'pending',
            'customer_name' => $name,
            'company' => $company,
            'email' => $email,
            'phone' => $phone,
            'address_street' => $street,
            'address_city' => $city,
            'address_zip' => $zip,
            'notes' => $notes,
            'locale' => $locale,
            'country_code' => $vat['country_code'],
            'vat_number' => $vat['vat_number'],
            'vat_scheme' => $vat['scheme'],
            'vat_rate' => number_format($vat['rate'], 2, '.', ''),
            'vat_amount' => $vatAmount,
            'total_gross' => $totalGross,
            'receipt_number' => null,
            'total_items' => $detail['total_items'],
            'total_amount' => $detail['total_amount'],
            'cart_snapshot' => $snapshotJson,
            'lines' => $snapshot['lines'],
        ];

        // ordine dropship automatico presso il fornitore (per battere il delta
        // del bonifico): un fallimento non blocca mai la richiesta
        $autoDropship = null;
        if ($this->config->bool('AUTO_DROPSHIP_ON_REQUEST', false)) {
            try {
                $autoDropship = $this->dropship->autoCreateFromRequest($order);
            } catch (\Throwable $e) {
                $this->logger->error('Auto-dropship fallito', ['order_id' => $orderId, 'error' => $e->getMessage()]);
                $autoDropship = ['ok' => false, 'dropship_id' => null, 'message' => $e->getMessage(), 'simulated' => null];
            }
        }

        // email: un fallimento non blocca la richiesta (già salvata).
        // Cliente: istruzioni di pagamento via bonifico, NIENTE ricevuta
        // (arriva con la conferma admin a pagamento ricevuto).
        try {
            $this->mailer->sendAdminEmail($order, $autoDropship);
            $this->orders->markEmailSent($orderId, 'admin');
        } catch (\Throwable $e) {
            $this->logger->error('Invio email admin fallito', ['order_id' => $orderId, 'error' => $e->getMessage()]);
        }
        try {
            $this->mailer->sendCustomerEmail($order);
            $this->orders->markEmailSent($orderId, 'customer');
        } catch (\Throwable $e) {
            $this->logger->error('Invio email cliente fallito', ['order_id' => $orderId, 'error' => $e->getMessage()]);
        }

        $this->cart->clearAll();

        return ['ok' => true, 'order_id' => $orderId, 'errors' => [], 'cart_adjusted' => false];
    }

    /**
     * Conferma admin (pagamento ricevuto): assegna il numero ricevuta e invia
     * al cliente l'email di conferma con la ricevuta pro-forma PDF allegata.
     *
     * @return array{ok: bool, error: string|null, email_sent: bool}
     */
    public function confirm(int $orderId): array
    {
        $order = $this->orders->find($orderId);
        if ($order === null) {
            return ['ok' => false, 'error' => $this->lang->t('admin.order_not_found'), 'email_sent' => false];
        }
        if (($order['status'] ?? 'pending') !== 'pending') {
            return ['ok' => false, 'error' => $this->lang->t('admin.order_not_pending'), 'email_sent' => false];
        }

        // il numero esiste solo per ordini confermati (richieste legacy incluse)
        $receiptNumber = is_string($order['receipt_number'] ?? null) && $order['receipt_number'] !== ''
            ? (string) $order['receipt_number']
            : $this->receipts->assignNumber();
        if (!$this->orders->markConfirmed($orderId, $receiptNumber)) {
            return ['ok' => false, 'error' => $this->lang->t('admin.order_not_pending'), 'email_sent' => false];
        }

        $order['status'] = 'confirmed';
        $order['receipt_number'] = $receiptNumber;
        $snapshot = json_decode(is_string($order['cart_snapshot'] ?? null) ? $order['cart_snapshot'] : '[]', true);
        $order['lines'] = is_array($snapshot) && is_array($snapshot['lines'] ?? null) ? $snapshot['lines'] : [];

        $emailSent = true;
        try {
            $this->mailer->sendCustomerConfirmedEmail($order);
        } catch (\Throwable $e) {
            $emailSent = false;
            $this->logger->error('Invio email conferma fallito', ['order_id' => $orderId, 'error' => $e->getMessage()]);
        }

        return ['ok' => true, 'error' => null, 'email_sent' => $emailSent];
    }

    /**
     * Riallineamento admin delle quantità (es. stock cambiato durante l'attesa
     * del bonifico): ricalcola subtotali, imponibile, VAT e totale mantenendo i
     * prezzi unitari quotati alla richiesta. Con $renotify invia al cliente le
     * istruzioni di pagamento aggiornate. Solo per richieste 'pending'.
     *
     * @param array<int, mixed> $quantities indice riga snapshot => nuova qty (0 = rimuovi)
     * @return array{ok: bool, error: string|null, email_sent: bool|null}
     */
    public function adminUpdate(int $orderId, array $quantities, bool $renotify): array
    {
        $order = $this->orders->find($orderId);
        if ($order === null) {
            return ['ok' => false, 'error' => $this->lang->t('admin.order_not_found'), 'email_sent' => null];
        }
        if (($order['status'] ?? 'pending') !== 'pending') {
            return ['ok' => false, 'error' => $this->lang->t('admin.order_not_pending'), 'email_sent' => null];
        }

        $snapshot = json_decode(is_string($order['cart_snapshot'] ?? null) ? $order['cart_snapshot'] : '[]', true);
        $rawLines = is_array($snapshot) && is_array($snapshot['lines'] ?? null) ? $snapshot['lines'] : [];

        $lines = [];
        $totalItems = 0;
        $totalCents = 0;
        foreach (array_values($rawLines) as $i => $line) {
            if (!is_array($line)) {
                continue;
            }
            $qty = is_numeric($quantities[$i] ?? null) ? max(0, (int) $quantities[$i]) : 0;
            if ($qty < 1) {
                continue;
            }
            $unitCents = CartService::cents((string) ($line['unit_price'] ?? '0'));
            $line['qty'] = $qty;
            $line['subtotal'] = CartService::money($unitCents * $qty);
            $lines[] = $line;
            $totalItems += $qty;
            $totalCents += $unitCents * $qty;
        }
        if ($lines === []) {
            return ['ok' => false, 'error' => $this->lang->t('admin.edit_error_empty'), 'email_sent' => null];
        }

        $totalAmount = CartService::money($totalCents);
        $vatAmount = VatService::vatAmount($totalAmount, (float) ($order['vat_rate'] ?? 0));
        $totalGross = VatService::grossTotal($totalAmount, $vatAmount);
        $snapshotJson = (string) json_encode(['lines' => $lines], JSON_UNESCAPED_UNICODE);

        if (!$this->orders->updateTotalsAndSnapshot($orderId, $totalItems, $totalAmount, $vatAmount, $totalGross, $snapshotJson)) {
            return ['ok' => false, 'error' => $this->lang->t('admin.order_not_pending'), 'email_sent' => null];
        }
        $this->logger->info('Richiesta riallineata da admin', ['order_id' => $orderId, 'total_items' => $totalItems]);

        $emailSent = null;
        if ($renotify) {
            $order['total_items'] = $totalItems;
            $order['total_amount'] = $totalAmount;
            $order['vat_amount'] = $vatAmount;
            $order['total_gross'] = $totalGross;
            $order['lines'] = $lines;
            try {
                $this->mailer->sendCustomerEmail($order, isUpdate: true);
                $emailSent = true;
            } catch (\Throwable $e) {
                $emailSent = false;
                $this->logger->error('Invio email aggiornamento fallito', ['order_id' => $orderId, 'error' => $e->getMessage()]);
            }
        }

        return ['ok' => true, 'error' => null, 'email_sent' => $emailSent];
    }

    /** @return array{ok: bool, error: string|null} */
    public function cancel(int $orderId): array
    {
        $order = $this->orders->find($orderId);
        if ($order === null) {
            return ['ok' => false, 'error' => $this->lang->t('admin.order_not_found')];
        }
        if (($order['status'] ?? 'pending') !== 'pending' || !$this->orders->markCancelled($orderId)) {
            return ['ok' => false, 'error' => $this->lang->t('admin.order_not_pending')];
        }

        return ['ok' => true, 'error' => null];
    }

    /**
     * Snapshot completo per order_requests.cart_snapshot. L'offer_price per riga
     * è incluso SOLO qui (visibile esclusivamente lato admin, mai al cliente).
     *
     * @param array{products: list<array{sku: string, name: string, brand: string,
     *   image_url: string|null, sizes: list<array{size_eu: string, size_us: string,
     *   quantity_stock: int, price: string, qty: int, row_total: string}>,
     *   product_items: int, product_total: string}>, total_items: int, total_amount: string} $detail
     * @return array{lines: list<array<string, mixed>>}
     */
    private function buildSnapshot(array $detail): array
    {
        $skus = array_map(static fn (array $p): string => $p['sku'], $detail['products']);
        $costs = $this->products->costBySkuSize($skus);
        $barcodes = [];
        foreach ($skus as $sku) {
            foreach ($this->products->sizesForSku($sku) as $size) {
                $barcodes[$sku][$size['size_eu']] = $size['barcode'];
            }
        }

        $lines = [];
        foreach ($detail['products'] as $product) {
            foreach ($product['sizes'] as $size) {
                if ($size['qty'] < 1) {
                    continue;
                }
                $lines[] = [
                    'sku' => $product['sku'],
                    'name' => $product['name'],
                    'brand' => $product['brand'],
                    'size_eu' => $size['size_eu'],
                    'size_us' => $size['size_us'],
                    'barcode' => $barcodes[$product['sku']][$size['size_eu']] ?? '',
                    'qty' => $size['qty'],
                    'unit_price' => $size['price'],
                    'subtotal' => $size['row_total'],
                    // riservato: solo email/vista admin
                    'offer_price' => $costs[$product['sku']][$size['size_eu']]['offer_price'] ?? null,
                ];
            }
        }

        return ['lines' => $lines];
    }

    private function cleanString(mixed $value, int $maxLength): string
    {
        if (!is_string($value)) {
            return '';
        }

        return mb_substr(trim(strip_tags($value)), 0, $maxLength);
    }
}
