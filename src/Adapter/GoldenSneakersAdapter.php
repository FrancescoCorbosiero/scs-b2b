<?php

declare(strict_types=1);

namespace App\Adapter;

use App\Support\Config;
use Psr\Log\LoggerInterface;

/**
 * Feed GoldenSneakers (endpoint assortment-flat): una riga per SKU+taglia.
 *
 * Sorgenti:
 *  - FEED_SOURCE=fixture → legge FEED_FIXTURE_PATH (sviluppo senza token)
 *  - FEED_SOURCE=live    → GET FEED_BASE_URL+FEED_ENDPOINT con bearer token,
 *    timeout FEED_HTTP_TIMEOUT, un retry con backoff, User-Agent identificativo.
 *    Gestisce sia payload lista sia paginazione DRF ({results, next}).
 *
 * Ogni riga viene validata; una riga invalida fa fallire TUTTO il fetch:
 * meglio nessun aggiornamento che un catalogo corrotto.
 */
final class GoldenSneakersAdapter
{
    private const ALLOWED_IMAGE_HOSTS = ['goldensneakers.net', 'www.goldensneakers.net'];
    private const MAX_PAGES = 200;

    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Righe normalizzate e validate del feed.
     *
     * @return list<array{sku: string, name: string, brand: string, size_mapper: string,
     *   size_eu: string, size_us: string, barcode: string, offer_price: string,
     *   quantity: int, image_url: string|null}>
     */
    public function fetch(): array
    {
        $source = $this->config->str('FEED_SOURCE', 'fixture');
        $rawRows = $source === 'live' ? $this->fetchLive() : $this->fetchFixture();

        $rows = [];
        foreach ($rawRows as $index => $raw) {
            if (!is_array($raw)) {
                throw new FeedException("Riga {$index} del feed non è un oggetto");
            }
            $rows[] = $this->normalizeRow($raw, (int) $index);
        }

        return $rows;
    }

    /** @return list<mixed> */
    private function fetchFixture(): array
    {
        $path = $this->config->str('FEED_FIXTURE_PATH', 'fixtures/goldensneakers-dev.json');
        if (!str_starts_with($path, '/')) {
            $path = $this->config->rootPath() . '/' . $path;
        }
        if (!is_file($path)) {
            throw new FeedException("Fixture non trovata: {$path}");
        }
        $json = file_get_contents($path);
        if ($json === false) {
            throw new FeedException("Impossibile leggere la fixture: {$path}");
        }

        return $this->decodeList($json, 'fixture');
    }

    /** @return list<mixed> */
    private function fetchLive(): array
    {
        $token = $this->config->str('FEED_BEARER_TOKEN');
        if ($token === '') {
            throw new FeedException('FEED_BEARER_TOKEN mancante: impossibile usare FEED_SOURCE=live');
        }
        $url = rtrim($this->config->str('FEED_BASE_URL', 'https://www.goldensneakers.net'), '/')
            . '/' . ltrim($this->config->str('FEED_ENDPOINT', '/api/assortment-flat/'), '/');

        $all = [];
        $pages = 0;
        while ($url !== null) {
            if (++$pages > self::MAX_PAGES) {
                throw new FeedException('Troppe pagine nel feed (possibile loop di paginazione)');
            }
            $body = $this->httpGet($url, $token);
            $decoded = json_decode($body, true);
            if (is_array($decoded) && array_is_list($decoded)) {
                // payload piatto non paginato
                foreach ($decoded as $row) {
                    $all[] = $row;
                }
                $url = null;
            } elseif (is_array($decoded) && isset($decoded['results']) && is_array($decoded['results'])) {
                // paginazione stile DRF: {count, next, previous, results}
                foreach ($decoded['results'] as $row) {
                    $all[] = $row;
                }
                $next = $decoded['next'] ?? null;
                $url = is_string($next) && $next !== '' ? $next : null;
            } else {
                throw new FeedException('Formato inatteso della risposta del feed (né lista né paginata)');
            }
        }
        $this->logger->info('Feed scaricato', ['rows' => count($all), 'pages' => $pages]);

        return $all;
    }

    private function httpGet(string $url, string $token): string
    {
        $timeout = max(5, $this->config->int('FEED_HTTP_TIMEOUT', 60));
        $attempts = 0;
        $lastError = '';
        while ($attempts < 2) {
            $attempts++;
            $ch = curl_init($url);
            if ($ch === false) {
                throw new FeedException('Inizializzazione cURL fallita');
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_USERAGENT => 'SCS-B2B-Catalog/1.0 (+https://b2b.shoesclothingstore.com)',
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $token,
                    'Accept: application/json',
                ],
            ]);
            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if (is_string($body) && $status >= 200 && $status < 300) {
                return $body;
            }
            // mai loggare il token: solo status e messaggio cURL
            $lastError = $curlError !== '' ? $curlError : "HTTP {$status}";
            $this->logger->warning('Download feed fallito, retry', ['attempt' => $attempts, 'error' => $lastError]);
            if ($attempts < 2) {
                sleep(5);
            }
        }

        throw new FeedException("Download del feed fallito: {$lastError}");
    }

    /** @return list<mixed> */
    private function decodeList(string $json, string $context): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new FeedException("Il payload {$context} non è una lista JSON valida");
        }

        return $decoded;
    }

    /**
     * @param array<mixed> $raw
     * @return array{sku: string, name: string, brand: string, size_mapper: string,
     *   size_eu: string, size_us: string, barcode: string, offer_price: string,
     *   quantity: int, image_url: string|null}
     */
    private function normalizeRow(array $raw, int $index): array
    {
        $sku = $this->requireString($raw, 'sku', $index, 64);
        $name = $this->requireString($raw, 'product_name', $index, 255);
        // taglie SEMPRE stringhe: esistono valori frazionari tipo "36 2/3"
        $sizeEu = $this->requireString($raw, 'size_eu', $index, 10);

        $offer = $raw['offer_price'] ?? null;
        if (!is_int($offer) && !is_float($offer) && !(is_string($offer) && is_numeric($offer))) {
            throw new FeedException("Riga {$index} (sku {$sku}): offer_price non numerico");
        }
        $offerFloat = (float) $offer;
        if ($offerFloat < 0 || $offerFloat > 100000) {
            throw new FeedException("Riga {$index} (sku {$sku}): offer_price fuori range");
        }

        $quantity = $raw['available_quantity'] ?? null;
        if (!is_int($quantity) && !(is_string($quantity) && ctype_digit($quantity))) {
            throw new FeedException("Riga {$index} (sku {$sku}): available_quantity non intero");
        }
        $quantity = max(0, (int) $quantity);

        return [
            'sku' => $sku,
            'name' => $name,
            'brand' => $this->optionalString($raw, 'brand_name', 128),
            'size_mapper' => $this->optionalString($raw, 'size_mapper_name', 128),
            'size_eu' => $sizeEu,
            'size_us' => $this->optionalString($raw, 'size_us', 10),
            'barcode' => $this->optionalString($raw, 'barcode', 32),
            'offer_price' => number_format($offerFloat, 2, '.', ''),
            'quantity' => $quantity,
            'image_url' => $this->imageUrl($raw),
        ];
    }

    /** @param array<mixed> $raw */
    private function imageUrl(array $raw): ?string
    {
        $base = $raw['image_full_url'] ?? null;
        $file = $raw['image_name'] ?? null;
        if (!is_string($base) || $base === '' || !is_string($file) || $file === '') {
            return null;
        }
        $url = rtrim($base, '/') . '/' . rawurlencode(ltrim($file, '/'));
        $host = parse_url($url, PHP_URL_HOST);
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if ($scheme !== 'https' || !is_string($host) || !in_array(strtolower($host), self::ALLOWED_IMAGE_HOSTS, true)) {
            return null;
        }

        return strlen($url) <= 512 ? $url : null;
    }

    /** @param array<mixed> $raw */
    private function requireString(array $raw, string $key, int $index, int $maxLength): string
    {
        $value = $raw[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new FeedException("Riga {$index}: campo {$key} mancante o vuoto");
        }
        $value = trim($value);
        if (mb_strlen($value) > $maxLength) {
            throw new FeedException("Riga {$index}: campo {$key} oltre {$maxLength} caratteri");
        }

        return $value;
    }

    /** @param array<mixed> $raw */
    private function optionalString(array $raw, string $key, int $maxLength): string
    {
        $value = $raw[$key] ?? '';
        if (!is_string($value)) {
            return '';
        }

        return mb_substr(trim($value), 0, $maxLength);
    }
}
