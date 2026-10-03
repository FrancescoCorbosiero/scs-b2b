<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ProductRepository;
use App\Service\CustomProductService;
use App\Support\Http;
use App\Support\Lang;
use App\Support\Session;
use App\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * /admin/prodotti-propri: import dei prodotti propri da JSON/CSV nel formato
 * del feed, elenco, visibilità ed eliminazione (docs/06). I prodotti del
 * feed non si gestiscono da qui: la loro fonte di verità resta il fornitore.
 */
final class AdminCustomProductController
{
    private const ERRORS_KEY = 'custom_import_errors';

    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly Lang $lang,
        private readonly ProductRepository $products,
        private readonly CustomProductService $customProducts,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        // errori dell'ultimo import: mostrati una volta, come elenco unico
        $errors = $this->session->get(self::ERRORS_KEY);
        $this->session->remove(self::ERRORS_KEY);

        return $this->view->render($response, 'admin/custom_products.twig', [
            'products' => $this->products->customProducts(),
            'import_errors' => is_array($errors) ? array_values(array_filter($errors, is_string(...))) : [],
            'columns' => CustomProductService::COLUMNS,
            'required_columns' => CustomProductService::REQUIRED_COLUMNS,
            'max_mb' => CustomProductService::MAX_BYTES / 1024 / 1024,
        ]);
    }

    public function import(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $file = $request->getUploadedFiles()['file'] ?? null;
        if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            $tooBig = $file instanceof UploadedFileInterface
                && in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
            $this->session->flash('error', $tooBig
                ? $this->lang->t('custom.error_too_big', ['mb' => CustomProductService::MAX_BYTES / 1024 / 1024])
                : $this->lang->t('custom.file_required'));

            return Http::redirect($response, '/admin/prodotti-propri');
        }

        $name = (string) ($file->getClientFilename() ?? '');
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($extension, ['json', 'csv', 'txt'], true)) {
            $this->session->flash('error', $this->lang->t('custom.error_bad_type'));

            return Http::redirect($response, '/admin/prodotti-propri');
        }
        if ((int) ($file->getSize() ?? 0) > CustomProductService::MAX_BYTES) {
            $this->session->flash('error', $this->lang->t('custom.error_too_big', ['mb' => CustomProductService::MAX_BYTES / 1024 / 1024]));

            return Http::redirect($response, '/admin/prodotti-propri');
        }

        // il file non viene mai salvato: si legge in memoria e si scarta
        $result = $this->customProducts->import(
            (string) $file->getStream(),
            $name,
            ($body['replace'] ?? '') === '1',
        );
        if (!$result['ok']) {
            $this->session->flash('error', $this->lang->t('custom.import_failed'));
            $this->session->set(self::ERRORS_KEY, $result['errors']);

            return Http::redirect($response, '/admin/prodotti-propri');
        }

        $summary = $result['result'];
        $this->session->flash('success', $this->lang->t('custom.import_done', [
            'read' => $summary['rows_read'] ?? 0,
            'created' => $summary['products_created'] ?? 0,
            'updated' => $summary['products_updated'] ?? 0,
            'deactivated' => $summary['products_deactivated'] ?? 0,
        ]));

        return Http::redirect($response, '/admin/prodotti-propri');
    }

    /** @param array<string, string> $args */
    public function toggle(Request $request, Response $response, array $args): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $active = ($body['active'] ?? '') === '1';
        if (!$this->products->setCustomActive((int) ($args['id'] ?? 0), $active)) {
            $this->session->flash('error', $this->lang->t('custom.not_found'));
        } else {
            $this->session->flash('success', $this->lang->t($active ? 'custom.shown' : 'custom.hidden'));
        }

        return Http::redirect($response, '/admin/prodotti-propri');
    }

    /** @param array<string, string> $args */
    public function delete(Request $request, Response $response, array $args): Response
    {
        if (!$this->products->deleteCustom((int) ($args['id'] ?? 0))) {
            $this->session->flash('error', $this->lang->t('custom.not_found'));
        } else {
            $this->session->flash('success', $this->lang->t('custom.deleted'));
        }

        return Http::redirect($response, '/admin/prodotti-propri');
    }

    public function templateCsv(Request $request, Response $response): Response
    {
        return $this->download($response, CustomProductService::csvTemplate(), 'text/csv; charset=utf-8', 'prodotti-propri-modello.csv');
    }

    public function templateJson(Request $request, Response $response): Response
    {
        return $this->download($response, CustomProductService::jsonTemplate(), 'application/json; charset=utf-8', 'prodotti-propri-esempio.json');
    }

    private function download(Response $response, string $content, string $type, string $fileName): Response
    {
        $response->getBody()->write($content);

        return $response
            ->withHeader('Content-Type', $type)
            ->withHeader('Content-Disposition', 'attachment; filename="' . $fileName . '"')
            ->withHeader('Content-Length', (string) strlen($content));
    }
}
