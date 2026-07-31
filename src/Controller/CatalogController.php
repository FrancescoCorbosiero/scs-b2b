<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ProductRepository;
use App\Support\Config;
use App\Support\Lang;
use App\Support\View;
use App\Support\XlsxWriter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class CatalogController
{
    private const SORTS = ['rilevanza', 'nome', 'prezzo_asc', 'prezzo_desc', 'disponibilita'];
    private const EXPORT_MAX_ROWS = 20000;

    public function __construct(
        private readonly View $view,
        private readonly ProductRepository $products,
        private readonly Config $config,
        private readonly XlsxWriter $xlsx,
        private readonly Lang $lang,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $query = $request->getQueryParams();
        $filters = $this->parseFilters($query);
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, $this->config->int('PRODUCTS_PER_PAGE', 24));
        $highMin = $this->config->int('AVAILABILITY_HIGH_MIN', 60);
        $lowMax = $this->config->int('AVAILABILITY_LOW_MAX', 20);

        $result = $this->products->search($filters, $page, $perPage, $highMin, $lowMax);

        $ids = [];
        foreach ($result['items'] as $item) {
            $ids[] = (int) $item['id'];
        }
        $sizesByProduct = $this->products->sizesForProducts($ids);

        $totalPages = max(1, (int) ceil($result['total'] / $perPage));

        $data = [
            'items' => $result['items'],
            'sizes_by_product' => $sizesByProduct,
            'total' => $result['total'],
            'page' => min($page, $totalPages),
            'total_pages' => $totalPages,
            'per_page' => $perPage,
            'filters' => $filters,
            'brands' => $this->products->activeBrandsWithCounts(),
            'size_facets' => $this->products->activeSizesWithCounts(),
            'sorts' => self::SORTS,
            'availability_high_min' => $highMin,
            'availability_low_max' => $lowMax,
            'active_filters' => $this->activeFilterChips($query, $filters),
            'query_string' => $this->queryString($query, ['page']),
            // per i link della navigazione brand: filtri correnti SENZA brand e pagina
            'brand_base_qs' => $this->queryString($query, ['page', 'brand']),
        ];

        // "Carica altri": il client chiede solo le card della pagina successiva
        // e le accoda alla griglia (senza JS restano i link di paginazione)
        if (($query['fragment'] ?? '') === '1') {
            return $this->view->render($response, 'catalog/_cards.twig', $data);
        }

        return $this->view->render($response, 'catalog/index.twig', $data);
    }

    /**
     * Filtri attivi come "chip" rimovibili: ognuno con l'URL che lo toglie
     * lasciando gli altri (stato interamente nella query string).
     *
     * @param array<string, mixed> $query
     * @param array<string, mixed> $filters
     * @return list<array{label: string, value: string, remove_url: string}>
     */
    private function activeFilterChips(array $query, array $filters): array
    {
        $chips = [];
        $urlWithout = function (string $key, ?string $value = null) use ($query): string {
            $clean = array_diff_key($query, ['page' => null]);
            if ($value !== null && is_array($clean[$key] ?? null)) {
                $clean[$key] = array_values(array_filter(
                    $clean[$key],
                    static fn ($v): bool => (string) $v !== $value,
                ));
            } else {
                unset($clean[$key]);
            }
            $qs = http_build_query(array_filter($clean, static fn ($v) => $v !== '' && $v !== null && $v !== []));

            return $qs === '' ? '/catalogo' : '/catalogo?' . $qs;
        };

        if ($filters['q'] !== '') {
            $chips[] = ['label' => $this->lang->t('catalog.chip_search'), 'value' => $filters['q'], 'remove_url' => $urlWithout('q')];
        }
        if ($filters['brand'] !== '') {
            $chips[] = ['label' => $this->lang->t('catalog.filter_brand'), 'value' => $filters['brand'], 'remove_url' => $urlWithout('brand')];
        }
        foreach ($filters['sizes'] as $size) {
            $chips[] = [
                'label' => $this->lang->t('catalog.chip_size'),
                'value' => $size,
                'remove_url' => $urlWithout('taglia', $size),
            ];
        }
        if ($filters['availability'] !== '') {
            $chips[] = [
                'label' => $this->lang->t('catalog.filter_availability'),
                'value' => $this->lang->t('catalog.availability_' . $filters['availability']),
                'remove_url' => $urlWithout('disponibilita'),
            ];
        }
        if ($filters['price_min'] !== null || $filters['price_max'] !== null) {
            $min = $filters['price_min'] !== null ? number_format($filters['price_min'], 0, ',', '.') . ' €' : '—';
            $max = $filters['price_max'] !== null ? number_format($filters['price_max'], 0, ',', '.') . ' €' : '—';
            $chips[] = [
                'label' => $this->lang->t('catalog.chip_price'),
                'value' => $min . ' – ' . $max,
                // il prezzo è una coppia: si azzera insieme
                'remove_url' => (static function () use ($query): string {
                    $clean = array_diff_key($query, ['page' => null, 'prezzo_min' => null, 'prezzo_max' => null]);
                    $qs = http_build_query(array_filter($clean, static fn ($v) => $v !== '' && $v !== null && $v !== []));

                    return $qs === '' ? '/catalogo' : '/catalogo?' . $qs;
                })(),
            ];
        }
        if ($filters['recommended']) {
            $chips[] = [
                'label' => $this->lang->t('catalog.chip_filter'),
                'value' => $this->lang->t('catalog.filter_recommended'),
                'remove_url' => $urlWithout('recommended'),
            ];
        }
        if ($filters['in_stock']) {
            $chips[] = [
                'label' => $this->lang->t('catalog.chip_filter'),
                'value' => $this->lang->t('catalog.filter_in_stock'),
                'remove_url' => $urlWithout('disponibili'),
            ];
        }

        return $chips;
    }

    /**
     * Query string corrente senza le chiavi indicate (e senza valori vuoti).
     *
     * @param array<string, mixed> $query
     * @param list<string> $without
     */
    private function queryString(array $query, array $without = []): string
    {
        $clean = array_diff_key($query, array_fill_keys([...$without, 'fragment'], null));

        return http_build_query(array_filter($clean, static fn ($v) => $v !== '' && $v !== null && $v !== []));
    }

    /**
     * Export Excel del risultato filtrato, una riga per taglia.
     * Colonne: SKU, nome, brand, taglia EU/US, barcode, qty, prezzo netto
     * di listino (VAT esclusa). MAI offer_price.
     */
    public function export(Request $request, Response $response): Response
    {
        $filters = $this->parseFilters($request->getQueryParams());

        $result = $this->products->search(
            $filters,
            1,
            self::EXPORT_MAX_ROWS,
            $this->config->int('AVAILABILITY_HIGH_MIN', 60),
            $this->config->int('AVAILABILITY_LOW_MAX', 20),
        );
        $ids = [];
        $productsById = [];
        foreach ($result['items'] as $item) {
            $id = (int) $item['id'];
            $ids[] = $id;
            $productsById[$id] = $item;
        }
        $sizesByProduct = $this->products->sizesForProducts($ids);

        $headers = [
            'SKU',
            $this->lang->t('export.product'),
            $this->lang->t('export.brand'),
            $this->lang->t('export.size_eu'),
            $this->lang->t('export.size_us'),
            $this->lang->t('export.barcode'),
            $this->lang->t('export.quantity'),
            $this->lang->t('export.price_net'),
        ];
        $rows = [];
        foreach ($ids as $id) {
            $product = $productsById[$id];
            foreach ($sizesByProduct[$id] ?? [] as $size) {
                $rows[] = [
                    (string) $product['sku'],
                    (string) $product['name'],
                    (string) $product['brand'],
                    $size['size_eu'],
                    $size['size_us'],
                    $size['barcode'],
                    $size['quantity'],
                    (float) $size['price'],
                ];
            }
        }

        $path = $this->xlsx->write('Catalogo', $headers, $rows);
        $content = (string) file_get_contents($path);
        @unlink($path);

        $response->getBody()->write($content);

        return $response
            ->withHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->withHeader('Content-Disposition', 'attachment; filename="catalogo-' . date('Ymd-Hi') . '.xlsx"')
            ->withHeader('Content-Length', (string) strlen($content));
    }

    /**
     * @param array<string, mixed> $query
     * @return array{q: string, brand: string, availability: string, recommended: bool,
     *   price_min: float|null, price_max: float|null, sort: string,
     *   sizes: list<string>, in_stock: bool}
     */
    private function parseFilters(array $query): array
    {
        $str = static fn (string $key): string => is_string($query[$key] ?? null) ? trim((string) $query[$key]) : '';
        $availability = $str('disponibilita');
        $sort = $str('ordina');
        $priceMin = $query['prezzo_min'] ?? null;
        $priceMax = $query['prezzo_max'] ?? null;

        // taglie: ?taglia[]=42&taglia[]=43 (max 40, valori normalizzati)
        $sizes = [];
        foreach ((array) ($query['taglia'] ?? []) as $size) {
            if (is_string($size) && trim($size) !== '' && !in_array(trim($size), $sizes, true)) {
                $sizes[] = mb_substr(trim($size), 0, 10);
            }
        }

        return [
            'q' => mb_substr($str('q'), 0, 100),
            'brand' => mb_substr($str('brand'), 0, 128),
            'availability' => in_array($availability, ['alta', 'media', 'bassa'], true) ? $availability : '',
            'recommended' => ($query['recommended'] ?? '') === '1',
            'price_min' => is_numeric($priceMin) ? max(0.0, (float) $priceMin) : null,
            'price_max' => is_numeric($priceMax) ? max(0.0, (float) $priceMax) : null,
            'sort' => in_array($sort, self::SORTS, true) ? $sort : 'rilevanza',
            'sizes' => array_slice($sizes, 0, 40),
            'in_stock' => ($query['disponibili'] ?? '') === '1',
        ];
    }
}
