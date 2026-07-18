<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\VatService;
use App\Support\Http;
use App\Support\Session;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Preferenze persistite in sessione: paese di residenza, lingua e sistema taglie (EU/US).
 */
final class PreferenceController
{
    public function __construct(
        private readonly Session $session,
        private readonly VatService $vat,
    ) {
    }

    public function setCountry(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $country = is_string($body['country'] ?? null) ? strtoupper(trim($body['country'])) : '';
        if ($this->vat->isValidCountry($country)) {
            $this->session->setCountry($country);
        }

        return Http::redirect($response, Http::safeInternalPath($body['redirect'] ?? null, '/'));
    }

    public function setLocale(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $locale = is_string($body['locale'] ?? null) ? $body['locale'] : '';
        $this->session->setLocale($locale);

        return Http::redirect($response, Http::safeInternalPath($body['redirect'] ?? null, '/'));
    }

    public function setSizeSystem(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $system = is_string($body['size_system'] ?? null) ? $body['size_system'] : '';
        $this->session->setSizeSystem($system);

        return Http::redirect($response, Http::safeInternalPath($body['redirect'] ?? null, '/'));
    }

    public function setGridSize(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $size = is_string($body['grid_size'] ?? null) ? $body['grid_size'] : '';
        $this->session->setGridSize($size);

        return Http::redirect($response, Http::safeInternalPath($body['redirect'] ?? null, '/'));
    }
}
