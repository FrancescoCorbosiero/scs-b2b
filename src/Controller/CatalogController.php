<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ProductRepository;
use App\Support\Config;
use App\Support\Session;
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
        private readonly Session $session,
        private readonly ProductRepository $products,
        private readonly Config $config,
        private readonly XlsxWriter $xlsx,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $query = $request->getQueryParams();
        $filters = $this->parseFilters($query);
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, $this->config->int('PRODUCTS_PER_PAGE', 24));
        $plan = $this->session->plan();

        $result = $this->products->search(
            $filters,
            $plan,
            $page,
            $perPage,
            $this->config->int('AVAILABILITY_HIGH_MIN', 60),
            $this->config->int('AVAILABILITY_LOW_MAX', 20),
        );

        $ids = [];
        foreach ($result['items'] as $item) {
            $ids[] = (int) $item['id'];
        }
        $sizesByProduct = $this->products->sizesForProducts($ids, $plan);

        $totalPages = max(1, (int) ceil($result['total'] / $perPage));

        return $this->view->render($response, 'catalog/index.twig', [
            'items' => $result['items'],
            'sizes_by_product' => $sizesByProduct,
            'total' => $result['total'],
            'page' => min($page, $totalPages),
            'total_pages' => $totalPages,
            'filters' => $filters,
            'brands' => $this->products->activeBrands(),
            'sorts' => self::SORTS,
            'query_string' => http_build_query(array_filter(
                array_diff_key($query, ['page' => null]),
                static fn ($v) => $v !== '' && $v !== null,
            )),
        ]);
    }

    /**
     * Export Excel del risultato filtrato, una riga per taglia.
     * Colonne: SKU, nome, brand, taglia EU/US, barcode, qty, prezzo del piano
     * attivo. MAI offer_price, MAI i listini degli altri piani.
     */
    public function export(Request $request, Response $response): Response
    {
        $filters = $this->parseFilters($request->getQueryParams());
        $plan = $this->session->plan();

        $result = $this->products->search(
            $filters,
            $plan,
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
        $sizesByProduct = $this->products->sizesForProducts($ids, $plan);

        $headers = ['SKU', 'Prodotto', 'Brand', 'Taglia EU', 'Taglia US', 'Barcode', 'Quantità', 'Prezzo (' . strtoupper($plan) . ', IVA incl.)'];
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
     *   price_min: float|null, price_max: float|null, sort: string}
     */
    private function parseFilters(array $query): array
    {
        $str = static fn (string $key): string => is_string($query[$key] ?? null) ? trim((string) $query[$key]) : '';
        $availability = $str('disponibilita');
        $sort = $str('ordina');
        $priceMin = $query['prezzo_min'] ?? null;
        $priceMax = $query['prezzo_max'] ?? null;

        return [
            'q' => mb_substr($str('q'), 0, 100),
            'brand' => mb_substr($str('brand'), 0, 128),
            'availability' => in_array($availability, ['alta', 'media', 'bassa'], true) ? $availability : '',
            'recommended' => ($query['recommended'] ?? '') === '1',
            'price_min' => is_numeric($priceMin) ? max(0.0, (float) $priceMin) : null,
            'price_max' => is_numeric($priceMax) ? max(0.0, (float) $priceMax) : null,
            'sort' => in_array($sort, self::SORTS, true) ? $sort : 'rilevanza',
        ];
    }
}
