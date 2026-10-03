<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ProductRepository;
use App\Service\SizeCategory;
use App\Support\Config;
use App\Support\Http;
use App\Support\Lang;
use App\Support\Session;
use App\Support\View;
use App\Support\XlsxWriter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Catalogo, in due vesti sugli stessi dati e sugli stessi template:
 *  - /catalogo (dietro login): prezzi netti, carrello, export;
 *  - /vetrina (pubblica, docs/06): lo STESSO catalogo SENZA prezzi. I
 *    prezzi spariscono dai dati passati al client (card, scheda rapida,
 *    frammenti "Carica altri"), non solo dall'HTML, e filtri/ordinamenti
 *    per prezzo vengono ignorati lato server: dalla vetrina non si può
 *    risalire a nessun prezzo.
 * In entrambe, prodotti del feed e prodotti propri stanno in due sezioni
 * separate (?sezione=sede per i prodotti propri).
 */
final class CatalogController
{
    private const SORTS = ['rilevanza', 'nome', 'prezzo_asc', 'prezzo_desc', 'disponibilita'];
    /** Ordinamenti che rivelerebbero i prezzi: mai nella vetrina pubblica. */
    private const PRICE_SORTS = ['prezzo_asc', 'prezzo_desc'];
    private const EXPORT_MAX_ROWS = 20000;

    /** Valore di ?sezione= per i prodotti propri (in sede). */
    public const SECTION_CUSTOM = 'sede';

    public function __construct(
        private readonly View $view,
        private readonly ProductRepository $products,
        private readonly Config $config,
        private readonly XlsxWriter $xlsx,
        private readonly Lang $lang,
        private readonly Session $session,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        return $this->renderCatalog($request, $response, showcase: false);
    }

    /**
     * Vetrina pubblica: il catalogo senza prezzi, senza login. Chi ha già
     * accesso al catalogo viene portato alla versione con i prezzi.
     */
    public function showcase(Request $request, Response $response): Response
    {
        if ($this->session->isCatalogAuthed()) {
            $qs = $this->queryString($request->getQueryParams());

            return Http::redirect($response, '/catalogo' . ($qs !== '' ? '?' . $qs : ''));
        }

        return $this->renderCatalog($request, $response, showcase: true);
    }

    private function renderCatalog(Request $request, Response $response, bool $showcase): Response
    {
        $query = $request->getQueryParams();
        $basePath = $showcase ? '/vetrina' : '/catalogo';
        $sectionCounts = $this->products->activeCountsBySource();
        $source = $this->sourceFor($query, $sectionCounts);
        $filters = $this->parseFilters($query, $showcase) + ['source' => $source];
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, $this->config->int('PRODUCTS_PER_PAGE', 24));
        $highMin = $this->config->int('AVAILABILITY_HIGH_MIN', 60);
        $lowMax = $this->config->int('AVAILABILITY_LOW_MAX', 20);

        $result = $this->products->search($filters, $page, $perPage, $highMin, $lowMax);

        $ids = [];
        foreach ($result['items'] as $item) {
            $ids[] = (int) $item['id'];
        }
        $items = $result['items'];
        $sizesByProduct = $this->products->sizesForProducts($ids);
        if ($showcase) {
            [$items, $sizesByProduct] = self::withoutPrices($items, $sizesByProduct);
        }

        $totalPages = max(1, (int) ceil($result['total'] / $perPage));
        $sectionQs = $source === ProductRepository::SOURCE_CUSTOM ? 'sezione=' . self::SECTION_CUSTOM : '';

        $data = [
            'items' => $items,
            'sizes_by_product' => $sizesByProduct,
            'total' => $result['total'],
            'page' => min($page, $totalPages),
            'total_pages' => $totalPages,
            'per_page' => $perPage,
            'filters' => $filters,
            'brands' => $this->products->activeBrandsWithCounts($source),
            'size_facets' => $this->products->activeSizesWithCounts($source),
            'size_category_facets' => $this->products->activeSizeCategoryCounts($source),
            'sorts' => $showcase ? array_values(array_diff(self::SORTS, self::PRICE_SORTS)) : self::SORTS,
            'availability_high_min' => $highMin,
            'availability_low_max' => $lowMax,
            'active_filters' => $this->activeFilterChips($query, $filters, $basePath),
            'query_string' => $this->queryString($query, ['page']),
            // per i link della navigazione brand: filtri correnti SENZA brand e pagina
            'brand_base_qs' => $this->queryString($query, ['page', 'brand']),
            // vetrina pubblica (senza prezzi) o catalogo riservato
            'showcase' => $showcase,
            'catalog_path' => $basePath,
            // sezioni: catalogo del fornitore / prodotti propri (in sede)
            'section' => $source,
            'section_counts' => $sectionCounts,
            'reset_url' => $basePath . ($sectionQs !== '' ? '?' . $sectionQs : ''),
            // la vetrina usa l'header del sito pubblico, il catalogo il suo
            'public_page' => $showcase,
            'public_contained' => $showcase,
        ];

        // "Carica altri": il client chiede solo le card della pagina successiva
        // e le accoda alla griglia (senza JS restano i link di paginazione)
        if (($query['fragment'] ?? '') === '1') {
            return $this->view->render($response, 'catalog/_cards.twig', $data);
        }

        return $this->view->render($response, 'catalog/index.twig', $data);
    }

    /**
     * Sezione richiesta: i prodotti propri solo se ce ne sono (altrimenti la
     * scheda non esiste e si resta sul catalogo del fornitore).
     *
     * @param array<string, mixed> $query
     * @param array<string, int> $sectionCounts
     */
    private function sourceFor(array $query, array $sectionCounts): string
    {
        return ($query['sezione'] ?? '') === self::SECTION_CUSTOM && ($sectionCounts[ProductRepository::SOURCE_CUSTOM] ?? 0) > 0
            ? ProductRepository::SOURCE_CUSTOM
            : ProductRepository::SOURCE_FEED;
    }

    /**
     * Vetrina: i prezzi spariscono dai DATI destinati al client (card, JSON
     * della scheda rapida, frammenti), non solo dal markup. Delle taglie
     * restano misura e disponibilità; il barcode non serve a chi non ordina.
     *
     * @param list<array<string, mixed>> $items
     * @param array<int, list<array{size_eu: string, size_us: string, barcode: string, quantity: int, price: string}>> $sizesByProduct
     * @return array{0: list<array<string, mixed>>, 1: array<int, list<array{size_eu: string, size_us: string, quantity: int}>>}
     */
    private static function withoutPrices(array $items, array $sizesByProduct): array
    {
        $publicItems = [];
        foreach ($items as $item) {
            unset($item['price_from']);
            $publicItems[] = $item;
        }
        $publicSizes = [];
        foreach ($sizesByProduct as $productId => $sizes) {
            foreach ($sizes as $size) {
                $publicSizes[$productId][] = [
                    'size_eu' => $size['size_eu'],
                    'size_us' => $size['size_us'],
                    'quantity' => $size['quantity'],
                ];
            }
        }

        return [$publicItems, $publicSizes];
    }

    /**
     * Filtri attivi come "chip" rimovibili: ognuno con l'URL che lo toglie
     * lasciando gli altri (stato interamente nella query string).
     *
     * @param array<string, mixed> $query
     * @param array<string, mixed> $filters
     * @return list<array{label: string, value: string, remove_url: string}>
     */
    private function activeFilterChips(array $query, array $filters, string $basePath): array
    {
        $chips = [];
        $urlWithout = function (string $key, ?string $value = null) use ($query, $basePath): string {
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

            return $qs === '' ? $basePath : $basePath . '?' . $qs;
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
        foreach ($filters['size_categories'] as $category) {
            $chips[] = [
                'label' => $this->lang->t('catalog.filter_size_category'),
                'value' => $this->lang->t('catalog.size_category_' . $category),
                'remove_url' => $urlWithout('categoria', $category),
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
                'remove_url' => (static function () use ($query, $basePath): string {
                    $clean = array_diff_key($query, ['page' => null, 'prezzo_min' => null, 'prezzo_max' => null]);
                    $qs = http_build_query(array_filter($clean, static fn ($v) => $v !== '' && $v !== null && $v !== []));

                    return $qs === '' ? $basePath : $basePath . '?' . $qs;
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
     * Colonne: SKU, nome, brand, categoria taglia, taglia EU/US, barcode,
     * qty, prezzo netto di listino (VAT esclusa). MAI offer_price.
     *
     * L'export è un listino di ciò che si può ordinare: i prodotti esauriti e
     * le singole taglie a quantità 0 restano fuori, anche quando il catalogo
     * a schermo li mostra marcati "Esaurito".
     */
    public function export(Request $request, Response $response): Response
    {
        $query = $request->getQueryParams();
        $filters = $this->parseFilters($query, showcase: false)
            + ['source' => $this->sourceFor($query, $this->products->activeCountsBySource())];
        $filters['in_stock'] = true;

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
            $this->lang->t('export.size_category'),
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
                if ($size['quantity'] <= 0) {
                    continue;
                }
                $rows[] = [
                    (string) $product['sku'],
                    (string) $product['name'],
                    (string) $product['brand'],
                    $this->lang->t('catalog.size_category_' . SizeCategory::normalize((string) $product['size_category'])),
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
     * Con $showcase (vetrina pubblica) filtri e ordinamenti per prezzo sono
     * ignorati QUI, lato server: una query string costruita a mano non deve
     * permettere di risalire ai prezzi per bisezione.
     *
     * @param array<string, mixed> $query
     * @return array{q: string, brand: string, availability: string, recommended: bool,
     *   price_min: float|null, price_max: float|null, sort: string,
     *   sizes: list<string>, in_stock: bool, size_categories: list<string>}
     */
    private function parseFilters(array $query, bool $showcase): array
    {
        $str = static fn (string $key): string => is_string($query[$key] ?? null) ? trim((string) $query[$key]) : '';
        $availability = $str('disponibilita');
        $sort = $str('ordina');
        $priceMin = $showcase ? null : ($query['prezzo_min'] ?? null);
        $priceMax = $showcase ? null : ($query['prezzo_max'] ?? null);
        if ($showcase && in_array($sort, self::PRICE_SORTS, true)) {
            $sort = 'rilevanza';
        }

        // taglie: ?taglia[]=42&taglia[]=43 (max 40, valori normalizzati)
        $sizes = [];
        foreach ((array) ($query['taglia'] ?? []) as $size) {
            if (is_string($size) && trim($size) !== '' && !in_array(trim($size), $sizes, true)) {
                $sizes[] = mb_substr(trim($size), 0, 10);
            }
        }

        // categoria di taglia: ?categoria[]=gs&categoria[]=ps (normali/GS/PS)
        $categories = [];
        foreach ((array) ($query['categoria'] ?? []) as $category) {
            $category = is_string($category) ? mb_strtolower(trim($category)) : '';
            if (in_array($category, SizeCategory::ALL, true) && !in_array($category, $categories, true)) {
                $categories[] = $category;
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
            'size_categories' => $categories,
        ];
    }
}
