<?php

declare(strict_types=1);

use App\Controller\AdminController;
use App\Controller\CartController;
use App\Controller\CatalogController;
use App\Controller\ContactController;
use App\Controller\DropshipController;
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
        $group->post('/paese', [PreferenceController::class, 'setCountry']);
        $group->post('/lingua', [PreferenceController::class, 'setLocale']);
        $group->post('/taglie', [PreferenceController::class, 'setSizeSystem']);
        $group->post('/griglia', [PreferenceController::class, 'setGridSize']);

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

        // ciclo di vita: conferma (pagamento ricevuto → ricevuta) / annulla
        $group->post('/richieste/{id:[0-9]+}/conferma', [AdminController::class, 'orderConfirm']);
        $group->post('/richieste/{id:[0-9]+}/annulla', [AdminController::class, 'orderCancel']);

        // riallineamento righe (stock cambiato durante l'attesa del bonifico)
        $group->get('/richieste/{id:[0-9]+}/modifica', [AdminController::class, 'orderEdit']);
        $group->post('/richieste/{id:[0-9]+}/modifica', [AdminController::class, 'orderEditSave']);

        // ordine dropship GoldenSneakers: 3 step di conferma (docs/09)
        $group->get('/richieste/{id:[0-9]+}/dropship', [DropshipController::class, 'prepare']);
        $group->post('/richieste/{id:[0-9]+}/dropship/riepilogo', [DropshipController::class, 'review']);
        $group->post('/richieste/{id:[0-9]+}/dropship/conferma', [DropshipController::class, 'confirm']);
        $group->post('/richieste/{id:[0-9]+}/dropship/invia', [DropshipController::class, 'send']);
        $group->get('/dropship/{id:[0-9]+}', [DropshipController::class, 'detail']);
        $group->post('/dropship/{id:[0-9]+}/aggiorna', [DropshipController::class, 'refresh']);

        $group->get('/sync', [AdminController::class, 'syncLogs']);
        $group->post('/sync/run', [AdminController::class, 'syncRun']);
        $group->get('/recommended', [AdminController::class, 'recommended']);
        $group->post('/recommended', [AdminController::class, 'recommendedToggle']);

        // gestione margini: regole per brand/nome, margine default, aliquote VAT
        $group->get('/margini', [AdminController::class, 'margins']);
        $group->post('/margini/regole', [AdminController::class, 'marginRuleCreate']);
        $group->post('/margini/regole/{id:[0-9]+}/attiva', [AdminController::class, 'marginRuleToggle']);
        $group->post('/margini/regole/{id:[0-9]+}/elimina', [AdminController::class, 'marginRuleDelete']);
        $group->post('/margini/default', [AdminController::class, 'marginDefaultSave']);
        $group->post('/margini/vat', [AdminController::class, 'vatRateSave']);

        // ricevuta pro-forma della richiesta (rigenerata dallo snapshot)
        $group->get('/richieste/{id:[0-9]+}/ricevuta.pdf', [AdminController::class, 'receiptPdf']);
    })->add(AdminAuthMiddleware::class);
};
