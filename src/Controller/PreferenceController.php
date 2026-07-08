<?php

declare(strict_types=1);

namespace App\Controller;

use App\Support\Http;
use App\Support\Session;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Preferenze persistite in sessione: piano prezzi e sistema taglie (EU/US).
 */
final class PreferenceController
{
    public function __construct(private readonly Session $session)
    {
    }

    public function setPlan(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $plan = is_string($body['plan'] ?? null) ? $body['plan'] : '';
        $this->session->setPlan($plan);

        return Http::redirect($response, Http::safeInternalPath($body['redirect'] ?? null, '/'));
    }

    public function setSizeSystem(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $system = is_string($body['size_system'] ?? null) ? $body['size_system'] : '';
        $this->session->setSizeSystem($system);

        return Http::redirect($response, Http::safeInternalPath($body['redirect'] ?? null, '/'));
    }
}
