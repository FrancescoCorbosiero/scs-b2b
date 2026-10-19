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
 *    timeout FEED_HTTP_TIMEOUT, un retry con backoff sugli errori transitori,
 *    User-Agent identificativo. Gestisce sia payload lista sia paginazione
 *    DRF ({results, next}); `next` si segue solo sull'host di FEED_BASE_URL.
 *
 * Ogni riga viene validata. Poche righe invalide (un prodotto sporco presso
 * il fornitore) vengono scartate e riportate nel log del sync, così il resto
 * del catalogo continua ad aggiornarsi; oltre MAX_INVALID_RATIO il feed è
 * considerato rotto (formato cambiato) e il fetch fallisce per intero:
 * meglio nessun aggiornamento che un catalogo corrotto.
 *
 * Una risposta che non è il feed (pagina HTML di login, JSON d'errore, JSON
 * malformato) fa fallire il fetch con un messaggio che dice COSA è arrivato
 * (stato HTTP, tipo, redirect, chiavi JSON o titolo della pagina): finisce
 * nel log di /admin/sync e basta a capire se è cambiato il formato o il token.
 */
final class GoldenSneakersAdapter
{
    // dominio immagini: il fornitore ha già cambiato host una volta
    // (www.goldensneakers.net → media.goldensneakers.net, 08/2026), quindi
    // si accetta il dominio e QUALSIASI suo sottodominio — mai domini terzi
    public const IMAGE_DOMAIN = 'goldensneakers.net';
    private const MAX_PAGES = 200;
    /** Quota di righe invalide oltre la quale il feed è rotto e il sync abortisce. */
    private const MAX_INVALID_RATIO = 0.05;

    /**
     * @param \Closure|null $transport SOLO per i test: sostituisce cURL.
     *   Firma: fn(string $url, list<string> $headers, int $timeout): array{status: int,
     *   body: string, errno?: int, error?: string, content_type?: string, url?: string,
     *   redirects?: int}
     */
    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly ?\Closure $transport = null,
    ) {
    }

    /**
     * Righe normalizzate e validate del feed, più gli avvisi non bloccanti
     * (righe scartate, JSON riparato) da riportare nel log del sync.
     *
     * @return array{rows: list<array{sku: string, name: string, brand: string, size_mapper: string,
     *   size_eu: string, size_us: string, barcode: string, offer_price: string,
     *   quantity: int, image_url: string|null, supplier_size_id: int|null}>, warnings: list<string>}
     * @throws FeedException feed illeggibile o con troppe righe invalide
     */
    public function fetch(): array
    {
        $warnings = [];
        $source = $this->config->str('FEED_SOURCE', 'fixture');
        $rawRows = $source === 'live' ? $this->fetchLive($warnings) : $this->fetchFixture();

        $rows = [];
        $invalid = [];
        foreach ($rawRows as $index => $raw) {
            try {
                if (!is_array($raw)) {
                    throw new FeedException("Riga {$index} del feed non è un oggetto");
                }
                $rows[] = $this->normalizeRow($raw, (int) $index);
            } catch (FeedException $e) {
                $invalid[] = $e->getMessage();
            }
        }

        if ($invalid !== []) {
            $total = count($rawRows);
            $sample = implode(' · ', array_slice($invalid, 0, 5)) . (count($invalid) > 5 ? ' …' : '');
            if ($rows === [] || count($invalid) > $total * self::MAX_INVALID_RATIO) {
                throw new FeedException(sprintf(
                    'Righe del feed non valide: %d su %d, troppe per aggiornare il catalogo in sicurezza (formato cambiato?). %s',
                    count($invalid),
                    $total,
                    $sample,
                ));
            }
            $warnings[] = sprintf('Righe del feed scartate perché non valide (%d su %d): %s', count($invalid), $total, $sample);
            $this->logger->warning('Righe del feed scartate perché non valide', [
                'invalid' => count($invalid),
                'total' => $total,
            ]);
        }

        // lo stesso avviso può arrivare da più pagine
        return ['rows' => $rows, 'warnings' => array_values(array_unique($warnings))];
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

    /**
     * @param list<string> $warnings avvisi non bloccanti (JSON riparato)
     * @return list<mixed>
     */
    private function fetchLive(array &$warnings): array
    {
        $token = $this->config->str('FEED_BEARER_TOKEN');
        if ($token === '') {
            throw new FeedException('FEED_BEARER_TOKEN mancante: impossibile usare FEED_SOURCE=live');
        }
        $url = $this->baseUrl() . '/' . ltrim($this->config->str('FEED_ENDPOINT', '/api/assortment-flat/'), '/');

        $all = [];
        $pages = 0;
        while ($url !== null) {
            if (++$pages > self::MAX_PAGES) {
                throw new FeedException('Troppe pagine nel feed (possibile loop di paginazione)');
            }
            $response = $this->httpGet($url, $token);
            $decoded = $this->decodeJson($response['body'], $warnings);
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
                $url = $this->nextPageUrl($decoded['next'] ?? null);
            } else {
                throw new FeedException($this->unexpectedResponse($response, $decoded));
            }
        }
        $this->logger->info('Feed scaricato', ['rows' => count($all), 'pages' => $pages]);

        return $all;
    }

    /**
     * json_decode tollerante. Se il JSON stretto fallisce si riprova
     * togliendo il BOM, sostituendo con null i token non standard
     * NaN/Infinity (json.dumps di Python li emette per i valori mancanti) e i
     * byte non UTF-8 col carattere sostitutivo: i valori riparati passano poi
     * dalla validazione di riga come qualsiasi altro valore mancante.
     *
     * @param list<string> $warnings
     * @return mixed null se il body non è JSON nemmeno dopo la riparazione
     */
    private function decodeJson(string $body, array &$warnings): mixed
    {
        $decoded = json_decode($body, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        $repaired = str_starts_with($body, "\xEF\xBB\xBF") ? substr($body, 3) : $body;
        // le stringhe JSON si saltano intere: "NaN" dentro un nome resta com'è
        $repaired = preg_replace_callback(
            '/"(?:[^"\\\\]++|\\\\.)*+"|(-?\bInfinity\b|\bNaN\b)/s',
            static fn (array $m): string => ($m[1] ?? '') !== '' ? 'null' : $m[0],
            $repaired,
        ) ?? $repaired;
        $decoded = json_decode($repaired, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }
        $warnings[] = 'Il feed conteneva JSON non standard (NaN/Infinity, BOM o caratteri non UTF-8): '
            . 'valori sostituiti, le righe rimaste senza dati obbligatori vengono scartate.';
        $this->logger->warning('Feed con JSON non standard riparato');

        return $decoded;
    }

    /**
     * GET del feed con un retry e backoff sugli errori transitori (rete, 5xx,
     * 408/429): un 401/403/404 non cambia ritentando.
     *
     * @return array{status: int, body: string, errno: int, error: string,
     *   content_type: string, url: string, redirects: int}
     */
    private function httpGet(string $url, string $token): array
    {
        $timeout = max(5, $this->config->int('FEED_HTTP_TIMEOUT', 60));
        $headers = [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ];
        $lastError = '';
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            if ($this->transport !== null) {
                /** @var array<string, mixed> $raw */
                $raw = ($this->transport)($url, $headers, $timeout);
                $res = $this->normalizeResponse($raw, $url);
            } else {
                $res = $this->curlGet($url, $headers, $timeout);
            }
            if ($res['errno'] === 0 && $res['status'] >= 200 && $res['status'] < 300) {
                return $res;
            }
            // mai loggare il token: solo status, messaggio cURL e risposta del fornitore
            $lastError = $res['errno'] !== 0 ? $res['error'] : $this->httpError($res);
            $this->logger->warning('Download feed fallito', ['attempt' => $attempt, 'error' => $lastError]);
            $transient = $res['errno'] !== 0 || $res['status'] >= 500 || in_array($res['status'], [0, 408, 429], true);
            if (!$transient) {
                break;
            }
            if ($attempt < 2) {
                sleep(5);
            }
        }

        throw new FeedException("Download del feed fallito: {$lastError}");
    }

    /**
     * @param list<string> $headers
     * @return array{status: int, body: string, errno: int, error: string,
     *   content_type: string, url: string, redirects: int}
     */
    private function curlGet(string $url, array $headers, int $timeout): array
    {
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
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $body = curl_exec($ch);
        $res = [
            'status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
            'body' => is_string($body) ? $body : '',
            'errno' => curl_errno($ch),
            'error' => curl_error($ch),
            // dopo eventuali redirect: dice DOVE è finita la richiesta
            'content_type' => (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE),
            'url' => (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL),
            'redirects' => (int) curl_getinfo($ch, CURLINFO_REDIRECT_COUNT),
        ];
        curl_close($ch);

        return $res;
    }

    /**
     * @param array<string, mixed> $res risposta del transport di test
     * @return array{status: int, body: string, errno: int, error: string,
     *   content_type: string, url: string, redirects: int}
     */
    private function normalizeResponse(array $res, string $url): array
    {
        return [
            'status' => (int) ($res['status'] ?? 0),
            'body' => (string) ($res['body'] ?? ''),
            'errno' => (int) ($res['errno'] ?? 0),
            'error' => (string) ($res['error'] ?? ''),
            'content_type' => (string) ($res['content_type'] ?? ''),
            'url' => (string) ($res['url'] ?? $url),
            'redirects' => (int) ($res['redirects'] ?? 0),
        ];
    }

    /**
     * URL della pagina successiva. Il bearer token viaggia con ogni pagina:
     * si segue `next` solo sull'host di FEED_BASE_URL (relativo → risolto
     * contro la base) e sempre con lo schema della base, perché DRF dietro un
     * proxy genera spesso link http:// anche se la chiamata era https.
     */
    private function nextPageUrl(mixed $next): ?string
    {
        if (!is_string($next) || trim($next) === '') {
            return null;
        }
        $next = trim($next);
        $origin = $this->origin();
        if (str_starts_with($next, '/') && !str_starts_with($next, '//')) {
            return $origin . $next;
        }
        $parts = parse_url($next);
        $baseHost = parse_url($origin, PHP_URL_HOST);
        if (!is_array($parts) || !isset($parts['host']) || !is_string($baseHost)
            || strcasecmp($parts['host'], $baseHost) !== 0) {
            throw new FeedException('La paginazione del feed punta a un host diverso da FEED_BASE_URL: interrotta per non esporre il token.');
        }

        return $origin . ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    private function baseUrl(): string
    {
        return rtrim($this->config->str('FEED_BASE_URL', 'https://www.goldensneakers.net'), '/');
    }

    /** Schema + host (+ porta) di FEED_BASE_URL. */
    private function origin(): string
    {
        $parts = parse_url($this->baseUrl());
        if (!is_array($parts) || !isset($parts['host'])) {
            return $this->baseUrl();
        }

        return ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    // ── Diagnostica: cosa ha risposto il fornitore ───────────────────

    /**
     * Messaggio per una risposta 2xx che non è il feed. Dice cosa è arrivato,
     * così il log di /admin/sync distingue un formato cambiato (chiavi JSON)
     * da un token non più valido (pagina HTML di login dopo un redirect).
     * Mai header né token; dell'URL finale solo host e percorso.
     *
     * @param array{status: int, body: string, errno: int, error: string,
     *   content_type: string, url: string, redirects: int} $response
     */
    private function unexpectedResponse(array $response, mixed $decoded): string
    {
        $body = $response['body'];
        $meta = ['HTTP ' . $response['status']];
        if ($response['content_type'] !== '') {
            $meta[] = $this->clean($response['content_type'], 80);
        }
        $meta[] = $this->humanSize(strlen($body));
        if ($response['redirects'] > 0) {
            $meta[] = sprintf('dopo %d redirect verso %s', $response['redirects'], $this->clean($this->safeUrl($response['url']), 150));
        }
        $where = ' (' . implode(', ', $meta) . ')';

        if (trim($body) === '') {
            return "Il feed ha risposto senza contenuto{$where}: verificare FEED_ENDPOINT o riprovare più tardi.";
        }
        if (is_array($decoded)) {
            $keys = array_map(fn (int|string $key): string => $this->clean((string) $key, 40), array_keys($decoded));
            $detail = $this->detailMessage($decoded);

            return "Formato inatteso della risposta del feed{$where}: oggetto JSON senza la lista delle righe (chiavi: "
                . implode(', ', array_slice($keys, 0, 10)) . (count($keys) > 10 ? ', …' : '') . ')'
                . ($detail !== '' ? " — «{$detail}»" : '') . '.';
        }
        if ($this->looksLikeHtml($body, $response['content_type'])) {
            $title = $this->htmlTitle($body);

            return "Il feed ha risposto con una pagina HTML invece del JSON{$where}"
                . ($title !== '' ? ": «{$title}»" : '')
                . '. Di solito il token non è più valido (FEED_BEARER_TOKEN scaduto o revocato: il sito rimanda al login) oppure il fornitore è in manutenzione.';
        }
        if ($decoded === null) {
            json_decode($body);
            $error = json_last_error() === JSON_ERROR_NONE ? 'valore null' : json_last_error_msg();

            // inizio e fine: un feed troncato si riconosce dalla coda
            return "La risposta del feed non è JSON valido ({$error}){$where}: " . (strlen($body) <= 200
                ? 'contenuto «' . $this->clean($body, 200) . '».'
                : 'inizia con «' . $this->clean(substr($body, 0, 200), 100) . '» e finisce con «'
                    . $this->clean(substr($body, -200), 100, fromEnd: true) . '».');
        }

        return "Formato inatteso della risposta del feed{$where}: valore JSON di tipo "
            . get_debug_type($decoded) . ' invece della lista delle righe.';
    }

    /**
     * Errore HTTP leggibile: stato e messaggio del fornitore (detail JSON o
     * titolo della pagina), con l'indicazione del token per 401/403.
     *
     * @param array{status: int, body: string, errno: int, error: string,
     *   content_type: string, url: string, redirects: int} $res
     */
    private function httpError(array $res): string
    {
        $error = 'HTTP ' . $res['status'];
        $decoded = json_decode($res['body'], true);
        $detail = is_array($decoded)
            ? $this->detailMessage($decoded)
            : ($this->looksLikeHtml($res['body'], $res['content_type']) ? $this->htmlTitle($res['body']) : $this->clean($res['body'], 150));
        if ($detail !== '') {
            $error .= " «{$detail}»";
        }
        if ($res['status'] === 401 || $res['status'] === 403) {
            $error .= ': verificare FEED_BEARER_TOKEN (scaduto, revocato o senza accesso al feed)';
        }

        return $error;
    }

    /** @param array<mixed> $decoded */
    private function detailMessage(array $decoded): string
    {
        foreach (['detail', 'message', 'error', 'errors'] as $key) {
            $value = $decoded[$key] ?? null;
            if (is_array($value) && array_is_list($value)) {
                $value = $value[0] ?? null;
            }
            if (is_string($value) && trim($value) !== '') {
                return $this->clean($value, 200);
            }
        }

        return '';
    }

    private function looksLikeHtml(string $body, string $contentType): bool
    {
        return str_contains(strtolower($contentType), 'html') || preg_match('/^\s*</', $body) === 1;
    }

    /** Titolo della pagina, o in mancanza il suo primo testo visibile. */
    private function htmlTitle(string $html): string
    {
        if (preg_match('~<title[^>]*>(.*?)</title>~is', $html, $m) === 1) {
            $title = $this->clean(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), 150);
            if ($title !== '') {
                return $title;
            }
        }
        $text = preg_replace('~<(script|style)\b.*?</\1>~is', ' ', $html) ?? $html;

        return $this->clean(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'), 150);
    }

    /**
     * Testo sicuro per il log (colonna utf8mb4): UTF-8 valido, spazi
     * compattati, troncato a $max caratteri (dalla fine se $fromEnd).
     */
    private function clean(string $text, int $max, bool $fromEnd = false): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', mb_scrub($text, 'UTF-8')));
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return $fromEnd ? '…' . mb_substr($text, -$max) : mb_substr($text, 0, $max) . '…';
    }

    /** Solo schema, host e percorso: niente query string. */
    private function safeUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['host'])) {
            return '?';
        }

        return ($parts['scheme'] ?? 'https') . '://' . $parts['host']
            . (isset($parts['port']) ? ':' . $parts['port'] : '') . ($parts['path'] ?? '/');
    }

    private function humanSize(int $bytes): string
    {
        return $bytes < 1024 ? $bytes . ' byte' : number_format($bytes / 1024, 0, ',', '.') . ' KB';
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
     * Valida e normalizza UNA riga nel formato assortment-flat. È pubblica
     * perché lo stesso formato vale per l'import dei prodotti propri
     * (/admin/prodotti-propri): un prodotto proprio è una riga di feed come
     * le altre, validata dalle stesse regole.
     *
     * @param array<mixed> $raw
     * @param int $index posizione riportata nei messaggi d'errore
     * @param list<string> $imageDomains domini (e sottodomini) ammessi per le immagini
     * @return array{sku: string, name: string, brand: string, size_mapper: string,
     *   size_eu: string, size_us: string, barcode: string, offer_price: string,
     *   quantity: int, image_url: string|null, supplier_size_id: int|null}
     * @throws FeedException riga non valida
     */
    public function normalizeRow(array $raw, int $index, array $imageDomains = [self::IMAGE_DOMAIN]): array
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
        // intero anche se serializzato come decimale (3.0 o "3.0")
        if (is_float($quantity) || (is_string($quantity) && is_numeric($quantity))) {
            $asFloat = (float) $quantity;
            if (is_finite($asFloat) && $asFloat === floor($asFloat)) {
                $quantity = (int) $asFloat;
            }
        }
        if (!is_int($quantity) && !(is_string($quantity) && ctype_digit($quantity))) {
            throw new FeedException("Riga {$index} (sku {$sku}): available_quantity non intero");
        }
        $quantity = max(0, (int) $quantity);

        // "id" del feed = riga SKU+taglia presso il fornitore: è il size_id
        // dell'API ordini dropship (docs/09). Opzionale per retrocompatibilità.
        $supplierSizeId = $raw['id'] ?? null;
        if (!is_int($supplierSizeId) && !(is_string($supplierSizeId) && ctype_digit($supplierSizeId))) {
            $supplierSizeId = null;
        } else {
            $supplierSizeId = (int) $supplierSizeId;
            if ($supplierSizeId <= 0) {
                $supplierSizeId = null;
            }
        }

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
            'image_url' => $this->imageUrl($raw, $imageDomains),
            'supplier_size_id' => $supplierSizeId,
        ];
    }

    /**
     * @param array<mixed> $raw
     * @param list<string> $imageDomains
     */
    private function imageUrl(array $raw, array $imageDomains): ?string
    {
        // il feed usa image_full_url quando c'è, altrimenti image: su alcune
        // righe il primo è vuoto e solo il secondo porta il percorso
        $base = $raw['image_full_url'] ?? null;
        if (!is_string($base) || trim($base) === '') {
            $base = $raw['image'] ?? null;
        }
        $file = $raw['image_name'] ?? null;
        if (!is_string($base) || !is_string($file)) {
            return null;
        }
        $base = trim($base);
        $file = trim($file);
        if ($base === '' || $file === '') {
            return null;
        }
        $base = $this->absoluteImageUrl($base);
        if ($base === null) {
            return null;
        }
        // Due formati del feed:
        //  - storico (fino a 08/2026): image_full_url = cartella base
        //    ("https://www.../images/SKU/main/") da unire a image_name;
        //  - attuale: image_full_url è GIÀ l'URL completo del file
        //    ("https://media.../raw/c67b5534062a.png") e image_name è solo
        //    il nome. Concatenare anche qui duplicherebbe il file name
        //    (".../x.png/x.png" → 404 su tutto il catalogo).
        $file = ltrim($file, '/');
        $basePath = (string) parse_url($base, PHP_URL_PATH);
        $url = basename($basePath) === $file
            ? $base
            : rtrim($base, '/') . '/' . rawurlencode($file);
        $host = parse_url($url, PHP_URL_HOST);
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if ($scheme !== 'https' || !is_string($host)) {
            return null;
        }
        $host = strtolower($host);
        $allowed = false;
        foreach ($imageDomains as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            return null;
        }

        return strlen($url) <= 512 ? $url : null;
    }

    /**
     * Il feed non è coerente: alcune righe mandano un URL assoluto
     * ("https://media.goldensneakers.net/products/images/…"), altre un
     * percorso relativo senza host ("/images/IH6001/main/"). Il fornitore non
     * lo sistemerà, quindi il percorso relativo si risolve qui contro
     * FEED_BASE_URL; la whitelist del dominio resta a valle e vale comunque.
     */
    private function absoluteImageUrl(string $candidate): ?string
    {
        if (preg_match('~^[a-z][a-z0-9+.\-]*://~i', $candidate) === 1) {
            return $candidate;
        }
        if (str_starts_with($candidate, '//')) {
            // protocol-relative: manca solo lo schema
            return 'https:' . $candidate;
        }
        $base = rtrim($this->config->str('FEED_BASE_URL', 'https://www.goldensneakers.net'), '/');
        if ($base === '' || parse_url($base, PHP_URL_HOST) === null) {
            return null;
        }

        return $base . '/' . ltrim($candidate, '/');
    }

    /** @param array<mixed> $raw */
    private function requireString(array $raw, string $key, int $index, int $maxLength): string
    {
        $value = $raw[$key] ?? null;
        // il feed reale può mandare valori numerici (SKU/barcode/taglie): si castano
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }
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
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }
        if (!is_string($value)) {
            return '';
        }

        return mb_substr(trim($value), 0, $maxLength);
    }
}
