<?php

declare(strict_types=1);

use App\Controller\AdminController;
use App\Controller\CartController;
use App\Controller\CatalogController;
use App\Controller\ContactController;
use App\Controller\LoginController;
use App\Controller\OrderController;
use App\Controller\PreferenceController;
use App\Middleware\AdminAuthMiddleware;
use App\Middleware\CatalogAuthMiddleware;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app): void {
    // ── Pubbliche (solo login) ───────────────────────────────────────
    $app->get('/login', [LoginController::class, 'form']);
    $app->post('/login', [LoginController::class, 'submit']);
    $app->post('/logout', [LoginController::class, 'logout']);

    $app->get('/admin/login', [AdminController::class, 'loginForm']);
    $app->post('/admin/login', [AdminController::class, 'loginSubmit']);
    $app->post('/admin/logout', [AdminController::class, 'logout']);

    // ── Area catalogo (password condivisa) ──────────────────────────
    $app->group('', function (RouteCollectorProxy $group): void {
        $group->get('/', [CatalogController::class, 'index']);
        $group->get('/export.xlsx', [CatalogController::class, 'export']);
        $group->post('/piano', [PreferenceController::class, 'setPlan']);
        $group->post('/taglie', [PreferenceController::class, 'setSizeSystem']);

        $group->get('/carrello', [CartController::class, 'index']);
        $group->post('/carrello/aggiungi', [CartController::class, 'add']);
        $group->post('/carrello/aggiorna', [CartController::class, 'update']);
        $group->post('/carrello/prendi-tutto', [CartController::class, 'takeAll']);
        $group->post('/carrello/svuota', [CartController::class, 'clearProduct']);
        $group->post('/carrello/rimuovi', [CartController::class, 'remove']);

        $group->get('/richiesta-ordine', [OrderController::class, 'form']);
        $group->post('/richiesta-ordine', [OrderController::class, 'submit']);
        $group->get('/conferma', [OrderController::class, 'confirmation']);

        $group->get('/contatti', [ContactController::class, 'index']);
    })->add(CatalogAuthMiddleware::class);

    // ── Area admin (password dedicata, sessione separata) ───────────
    $app->group('/admin', function (RouteCollectorProxy $group): void {
        $group->get('', [AdminController::class, 'dashboard']);
        $group->get('/richieste', [AdminController::class, 'orders']);
        $group->get('/richieste/{id:[0-9]+}', [AdminController::class, 'orderDetail']);
        $group->get('/sync', [AdminController::class, 'syncLogs']);
        $group->post('/sync/run', [AdminController::class, 'syncRun']);
        $group->get('/recommended', [AdminController::class, 'recommended']);
        $group->post('/recommended', [AdminController::class, 'recommendedToggle']);
    })->add(AdminAuthMiddleware::class);
};
