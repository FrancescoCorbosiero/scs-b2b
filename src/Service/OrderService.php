<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\OrderRequestRepository;
use App\Repository\ProductRepository;
use App\Support\Lang;
use App\Support\Session;
use Psr\Log\LoggerInterface;

/**
 * Richiesta d'ordine: validazione form, antispam, snapshot del carrello,
 * salvataggio a DB e invio email. Un fallimento SMTP NON perde la richiesta:
 * è già a DB con i flag email_*_sent a 0.
 */
final class OrderService
{
    private const MAX_REQUESTS_PER_HOUR = 3;

    public function __construct(
        private readonly CartService $cart,
        private readonly ProductRepository $products,
        private readonly OrderRequestRepository $orders,
        private readonly OrderMailer $mailer,
        private readonly Session $session,
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
        $notes = $this->cleanString($input['notes'] ?? null, 2000);

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

        $plan = $this->session->plan();
        $detail = $this->cart->detail($plan);
        $snapshot = $this->buildSnapshot($detail, $plan);

        $orderId = $this->orders->insert([
            'customer_name' => $name,
            'company' => $company !== '' ? $company : null,
            'email' => $email,
            'phone' => $phone,
            'notes' => $notes !== '' ? $notes : null,
            'plan' => $plan,
            'total_items' => $detail['total_items'],
            'total_amount' => $detail['total_amount'],
            'cart_snapshot' => (string) json_encode($snapshot, JSON_UNESCAPED_UNICODE),
            'ip_address' => $ip,
            'user_agent' => $userAgent !== '' ? mb_substr($userAgent, 0, 255) : null,
        ]);

        $order = [
            'id' => $orderId,
            'created_at' => date('Y-m-d H:i:s'),
            'customer_name' => $name,
            'company' => $company,
            'email' => $email,
            'phone' => $phone,
            'notes' => $notes,
            'plan' => $plan,
            'total_items' => $detail['total_items'],
            'total_amount' => $detail['total_amount'],
            'lines' => $snapshot['lines'],
        ];

        // email: un fallimento non blocca la richiesta (già salvata)
        try {
            $this->mailer->sendAdminEmail($order);
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
     * Snapshot completo per order_requests.cart_snapshot. L'offer_price per riga
     * è incluso SOLO qui (visibile esclusivamente lato admin, mai al cliente).
     *
     * @param array{products: list<array{sku: string, name: string, brand: string,
     *   image_url: string|null, sizes: list<array{size_eu: string, size_us: string,
     *   quantity_stock: int, price: string, qty: int, row_total: string}>,
     *   product_items: int, product_total: string}>, total_items: int, total_amount: string} $detail
     * @return array{plan: string, lines: list<array<string, mixed>>}
     */
    private function buildSnapshot(array $detail, string $plan): array
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

        return ['plan' => $plan, 'lines' => $lines];
    }

    private function cleanString(mixed $value, int $maxLength): string
    {
        if (!is_string($value)) {
            return '';
        }

        return mb_substr(trim(strip_tags($value)), 0, $maxLength);
    }
}
