<?php

declare(strict_types=1);

namespace App\Service;

use App\Adapter\FeedException;
use App\Adapter\GoldenSneakersAdapter;
use App\Repository\ProductRepository;
use App\Support\Lang;

/**
 * Prodotti propri dell'admin (docs/06 § /admin/prodotti-propri): import da
 * JSON o CSV nello STESSO formato del feed GoldenSneakers (assortment-flat,
 * una riga per SKU+taglia), senza campi in più. Ogni riga passa dalla stessa
 * validazione del feed (GoldenSneakersAdapter::normalizeRow) e dalla stessa
 * pipeline di prezzi (FeedSyncService::importCustom: offer_price + margini).
 *
 * I prodotti propri NON si mescolano con quelli del feed:
 *  - products.source = 'custom', sezione dedicata del catalogo;
 *  - uno SKU già usato dal feed viene rifiutato (lo SKU è unico a catalogo);
 *  - il sync del feed non li tocca, l'import non tocca il feed;
 *  - non vanno mai nell'ordine a GoldenSneakers (l'`id` del feed, cioè il
 *    size_id del fornitore, viene ignorato).
 *
 * L'import è tutto o niente: con anche una sola riga non valida non si
 * scrive nulla e l'admin riceve l'elenco degli errori riga per riga.
 */
final class CustomProductService
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const MAX_ROWS = 10000;
    private const MAX_ERRORS = 20;

    /**
     * Domini ammessi per le immagini: quello del fornitore (come per il feed)
     * e il nostro sito principale, dove l'admin carica le foto dei prodotti
     * in sede (vale anche per i sottodomini, b2b compreso). Devono coincidere
     * con img-src della Content-Security-Policy (docker/nginx/default.conf).
     */
    public const IMAGE_DOMAINS = [GoldenSneakersAdapter::IMAGE_DOMAIN, 'shoesclothingstore.com'];

    /** Colonne del formato (le chiavi del feed); le altre vengono ignorate. */
    public const COLUMNS = [
        'sku', 'product_name', 'brand_name', 'size_mapper_name', 'size_eu', 'size_us',
        'barcode', 'offer_price', 'available_quantity', 'image_full_url', 'image', 'image_name',
    ];
    public const REQUIRED_COLUMNS = ['sku', 'product_name', 'size_eu', 'offer_price', 'available_quantity'];

    public function __construct(
        private readonly GoldenSneakersAdapter $adapter,
        private readonly ProductRepository $products,
        private readonly FeedSyncService $sync,
        private readonly Lang $lang,
    ) {
    }

    /**
     * Valida e importa il file. Con $replace i prodotti propri che NON sono
     * nel file vengono disattivati (il file diventa l'elenco completo),
     * altrimenti si aggiungono/aggiornano solo quelli presenti.
     *
     * @return array{ok: bool, errors: list<string>, result: array{status: string, rows_read: int,
     *   products_created: int, products_updated: int, products_deactivated: int, message: string|null}|null}
     */
    public function import(string $content, string $fileName, bool $replace): array
    {
        $fail = static fn (array $errors): array => ['ok' => false, 'errors' => $errors, 'result' => null];

        if (strlen($content) > self::MAX_BYTES) {
            return $fail([$this->lang->t('custom.error_too_big', ['mb' => self::MAX_BYTES / 1024 / 1024])]);
        }
        try {
            $rawRows = $this->parse($content, $fileName);
        } catch (FeedException $e) {
            return $fail([$e->getMessage()]);
        }
        if ($rawRows === []) {
            return $fail([$this->lang->t('custom.error_empty')]);
        }
        if (count($rawRows) > self::MAX_ROWS) {
            return $fail([$this->lang->t('custom.error_too_many_rows', ['max' => self::MAX_ROWS])]);
        }

        $errors = [];
        $rows = [];
        $firstLine = [];
        foreach ($rawRows as $number => $raw) {
            try {
                $row = $this->adapter->normalizeRow($this->prepareRaw($raw), $number, self::IMAGE_DOMAINS);
            } catch (FeedException $e) {
                $errors[] = $e->getMessage();
                if (count($errors) >= self::MAX_ERRORS) {
                    break;
                }
                continue;
            }
            // mai un size_id del fornitore su un prodotto proprio: non si
            // ordina a GoldenSneakers
            $row['supplier_size_id'] = null;
            $key = mb_strtolower($row['sku']) . "\0" . $row['size_eu'];
            if (isset($firstLine[$key])) {
                $errors[] = $this->lang->t('custom.error_duplicate_size', [
                    'row' => $number, 'sku' => $row['sku'], 'size' => $row['size_eu'], 'first' => $firstLine[$key],
                ]);
                continue;
            }
            $firstLine[$key] = $number;
            $rows[] = $row;
        }
        if ($errors !== []) {
            return $fail($errors);
        }

        // lo SKU è unico su tutto il catalogo: niente SKU del feed
        $feedSkus = array_keys(array_filter(
            $this->products->sourcesBySku(array_values(array_unique(array_column($rows, 'sku')))),
            static fn (string $source): bool => $source !== ProductRepository::SOURCE_CUSTOM,
        ));
        if ($feedSkus !== []) {
            return $fail([$this->lang->t('custom.error_feed_sku', [
                'skus' => implode(', ', array_slice(array_map(strval(...), $feedSkus), 0, 10)) . (count($feedSkus) > 10 ? '…' : ''),
            ])]);
        }

        $result = $this->sync->importCustom($rows, $replace);
        if ($result['status'] !== 'ok') {
            return ['ok' => false, 'errors' => [(string) $result['message']], 'result' => $result];
        }

        return ['ok' => true, 'errors' => [], 'result' => $result];
    }

    /**
     * Righe grezze dal file: JSON (lista di oggetti, come il feed, o
     * {"results": [...]}) oppure CSV con intestazioni = chiavi del feed.
     * Le chiavi sono il numero di riga da citare negli errori (CSV: la riga
     * del foglio, intestazione = 1; JSON: la posizione, da 1).
     *
     * @return array<int, array<mixed>>
     * @throws FeedException file illeggibile
     */
    public function parse(string $content, string $fileName): array
    {
        $content = $this->toUtf8($content);
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $trimmed = ltrim($content);
        $looksJson = $trimmed !== '' && ($trimmed[0] === '[' || $trimmed[0] === '{');

        return $extension === 'json' || ($extension !== 'csv' && $looksJson)
            ? $this->parseJson($content)
            : $this->parseCsv($content);
    }

    /**
     * Modello CSV scaricabile: separatore ";" e virgola decimale, con BOM
     * UTF-8, così Excel in italiano lo apre già in colonne.
     */
    public static function csvTemplate(): string
    {
        $rows = [
            ['sku', 'product_name', 'brand_name', 'size_mapper_name', 'size_eu', 'size_us', 'barcode', 'offer_price', 'available_quantity', 'image_full_url', 'image_name'],
            ['SCS-0001', 'Nike Dunk Low Panda', 'Nike', 'Nike MENS', '42', '8.5', '0195866123456', '89,90', '3', 'https://shoesclothingstore.com/wp-content/uploads/dunk-panda.jpg', ''],
            ['SCS-0001', 'Nike Dunk Low Panda', 'Nike', 'Nike MENS', '42.5', '9', '0195866123463', '89,90', '2', 'https://shoesclothingstore.com/wp-content/uploads/dunk-panda.jpg', ''],
        ];
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }
        foreach ($rows as $row) {
            fputcsv($handle, $row, ';', '"', '');
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return "\u{FEFF}" . $csv;
    }

    /** Esempio JSON: le stesse righe del modello CSV, nel formato del feed. */
    public static function jsonTemplate(): string
    {
        $base = [
            'sku' => 'SCS-0001',
            'product_name' => 'Nike Dunk Low Panda',
            'brand_name' => 'Nike',
            'size_mapper_name' => 'Nike MENS',
        ];

        return (string) json_encode([
            $base + ['size_eu' => '42', 'size_us' => '8.5', 'barcode' => '0195866123456', 'offer_price' => 89.90,
                'available_quantity' => 3, 'image_full_url' => 'https://shoesclothingstore.com/wp-content/uploads/dunk-panda.jpg', 'image_name' => ''],
            $base + ['size_eu' => '42.5', 'size_us' => '9', 'barcode' => '0195866123463', 'offer_price' => 89.90,
                'available_quantity' => 2, 'image_full_url' => 'https://shoesclothingstore.com/wp-content/uploads/dunk-panda.jpg', 'image_name' => ''],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }

    // ── Interni ──────────────────────────────────────────────────────

    /**
     * @return array<int, array<mixed>>
     * @throws FeedException
     */
    private function parseJson(string $content): array
    {
        $decoded = json_decode($content, true);
        if (is_array($decoded) && !array_is_list($decoded) && is_array($decoded['results'] ?? null)) {
            $decoded = $decoded['results'];
        }
        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new FeedException($this->lang->t('custom.error_json'));
        }
        $rows = [];
        foreach ($decoded as $i => $item) {
            if (!is_array($item)) {
                throw new FeedException($this->lang->t('custom.error_json_row', ['row' => $i + 1]));
            }
            $rows[$i + 1] = $item;
        }

        return $rows;
    }

    /**
     * @return array<int, array<mixed>>
     * @throws FeedException
     */
    private function parseCsv(string $content): array
    {
        $firstLine = strtok($content, "\r\n");
        if ($firstLine === false || trim($firstLine) === '') {
            return [];
        }
        // separatore: quello più frequente nell'intestazione (Excel IT usa ";")
        $counts = [';' => substr_count($firstLine, ';'), ',' => substr_count($firstLine, ','), "\t" => substr_count($firstLine, "\t")];
        arsort($counts);
        $delimiter = (string) array_key_first($counts);

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new FeedException($this->lang->t('custom.error_csv'));
        }
        fwrite($handle, $content);
        rewind($handle);

        $header = fgetcsv($handle, null, $delimiter, '"', '');
        if (!is_array($header)) {
            fclose($handle);
            throw new FeedException($this->lang->t('custom.error_csv'));
        }
        $columns = array_map(
            static fn ($name): string => str_replace([' ', '-'], '_', mb_strtolower(trim((string) $name))),
            $header,
        );
        $missing = array_values(array_diff(self::REQUIRED_COLUMNS, $columns));
        if ($missing !== []) {
            fclose($handle);
            throw new FeedException($this->lang->t('custom.error_csv_columns', ['columns' => implode(', ', $missing)]));
        }

        $rows = [];
        $line = 1;
        while (($values = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
            $line++;
            if ($values === [null] || implode('', array_map(strval(...), $values)) === '') {
                continue; // riga vuota
            }
            $row = [];
            foreach ($columns as $index => $column) {
                if (in_array($column, self::COLUMNS, true)) {
                    $row[$column] = trim((string) ($values[$index] ?? ''));
                }
            }
            $rows[$line] = $row;
        }
        fclose($handle);

        return $rows;
    }

    /**
     * Ritocchi tollerati PRIMA della validazione del feed, per i file
     * scritti a mano o da Excel: virgola decimale nei prezzi e nelle
     * taglie, simbolo €, image_name ricavato dall'URL quando manca. Le
     * chiavi fuori formato (es. `id`, `presented_price`) vengono scartate.
     *
     * @param array<mixed> $raw
     * @return array<string, mixed>
     */
    private function prepareRaw(array $raw): array
    {
        $row = [];
        foreach (self::COLUMNS as $column) {
            if (array_key_exists($column, $raw)) {
                $row[$column] = $raw[$column];
            }
        }
        if (is_string($row['offer_price'] ?? null)) {
            $row['offer_price'] = self::decimal($row['offer_price']);
        }
        foreach (['size_eu', 'size_us'] as $sizeKey) {
            if (is_string($row[$sizeKey] ?? null) && preg_match('/^\d+,\d+$/', trim($row[$sizeKey])) === 1) {
                // "42,5" da Excel → "42.5" come nel feed
                $row[$sizeKey] = str_replace(',', '.', trim($row[$sizeKey]));
            }
        }
        $imageName = $row['image_name'] ?? null;
        if ($imageName === null || (is_string($imageName) && trim($imageName) === '')) {
            $url = $row['image_full_url'] ?? $row['image'] ?? null;
            $path = is_string($url) ? (string) parse_url(trim($url), PHP_URL_PATH) : '';
            // URL completo del file (non una cartella): il nome è l'ultimo pezzo
            if ($path !== '' && !str_ends_with($path, '/')) {
                $row['image_name'] = rawurldecode(basename($path));
            }
        }

        return $row;
    }

    /** "1.234,56 €" / "89,90" / "89.90" → "1234.56" / "89.90" / "89.90". */
    private static function decimal(string $value): string
    {
        $value = str_replace(['€', ' ', "\u{00A0}"], '', trim($value));
        $comma = strrpos($value, ',');
        $dot = strrpos($value, '.');
        if ($comma !== false && ($dot === false || $comma > $dot)) {
            // virgola decimale: i punti sono migliaia
            return str_replace(',', '.', str_replace('.', '', $value));
        }

        // punto decimale: le virgole sono migliaia
        return str_replace(',', '', $value);
    }

    /** Toglie il BOM; un file non UTF-8 (CSV di Excel su Windows) viene convertito da Windows-1252. */
    private function toUtf8(string $content): string
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = (string) mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        return $content;
    }
}
