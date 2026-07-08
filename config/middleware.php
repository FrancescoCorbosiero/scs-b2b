<?php

declare(strict_types=1);

use App\Middleware\CsrfMiddleware;
use App\Middleware\SessionMiddleware;
use App\Support\Config;
use App\Support\View;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Slim\App;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Response;

return static function (App $app): void {
    $container = $app->getContainer();
    if ($container === null) {
        throw new RuntimeException('Container non inizializzato');
    }
    $config = $container->get(Config::class);

    // Ordine di esecuzione: Error → Routing → Session → BodyParsing → CSRF → rotta
    $app->add(CsrfMiddleware::class);
    $app->addBodyParsingMiddleware();
    $app->add(SessionMiddleware::class);
    $app->addRoutingMiddleware();

    $displayDetails = !$config->isProduction();
    $errorMiddleware = $app->addErrorMiddleware($displayDetails, true, true, $container->get(LoggerInterface::class));

    if (!$displayDetails) {
        $errorMiddleware->setDefaultErrorHandler(
            function (ServerRequestInterface $request, Throwable $exception) use ($container): Response {
                $status = 500;
                $template = 'errors/500.twig';
                if ($exception instanceof HttpNotFoundException || $exception instanceof HttpMethodNotAllowedException) {
                    $status = 404;
                    $template = 'errors/404.twig';
                } else {
                    $container->get(LoggerInterface::class)->error('Errore non gestito', [
                        'exception' => $exception::class,
                        'message' => $exception->getMessage(),
                        'file' => $exception->getFile() . ':' . $exception->getLine(),
                    ]);
                }
                try {
                    $view = $container->get(View::class);

                    return $view->render(new Response($status), $template)->withStatus($status);
                } catch (Throwable) {
                    $response = new Response($status);
                    $response->getBody()->write('Si è verificato un errore.');

                    return $response;
                }
            }
        );
    }
};
