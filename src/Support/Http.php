<?php

declare(strict_types=1);

namespace App\Support;

use Psr\Http\Message\ResponseInterface as Response;

final class Http
{
    public static function redirect(Response $response, string $path, int $status = 302): Response
    {
        return $response->withStatus($status)->withHeader('Location', $path);
    }

    /**
     * Consente redirect solo verso percorsi interni ("/..." ma non "//...").
     */
    public static function safeInternalPath(mixed $path, string $fallback): string
    {
        if (!is_string($path) || $path === '' || $path[0] !== '/' || str_starts_with($path, '//') || str_contains($path, "\\")) {
            return $fallback;
        }

        return $path;
    }

    /** @param array<string, mixed> $payload */
    public static function json(Response $response, array $payload, int $status = 200): Response
    {
        $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $response->withStatus($status)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
