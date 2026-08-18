<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ContiApiController;
use App\Http\Controllers\Api\OperazioniApiController;
use App\Http\Controllers\Api\TagsApiController;
use App\Http\Controllers\Api\GruppiTagApiController;
use App\Http\Controllers\Api\GraficiController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Qui definiamo tutte le route API REST.
| 
| PERCHÉ routes/api.php separato da routes/web.php?
| - Web routes → Ritornano HTML (Blade)
| - API routes → Ritornano JSON
| - Sono due sistemi completamente diversi!
|
| PERCHÉ tutte le route hanno prefix 'api'?
| - Separazione logica: /api/* sono API, /* sono pages
| - Facile distinguerle nel routing
| - Angular chiama http://localhost:8000/api/conti
|
| HTTP Methods:
| - GET: recuperare dati (idempotente, non cambia nulla)
| - POST: creare dati (non idempotente)
| - PUT: sostituire risorsa (idempotente)
| - DELETE: cancellare (idempotente)
|
| PERCHÉ 'idempotente'?
| - Una GET due volte = stesso risultato
| - Una DELETE due volte potrebbe dare errore la seconda volta
| - Non è critico per ora, è solo buona pratica REST
*/


Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// ============================================================
// ROTTE PUBBLICHE - No autenticazione
// ============================================================
Route::prefix('v1')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('login', [AuthController::class, 'login']);
    });
});

// ============================================================
// ROTTE PRIVATE - Richiedono autenticazione Sanctum
// ============================================================

Route::middleware('auth:sanctum')->prefix('v1')->group(function () {

    // Auth (endpoint per verificare chi sei, logout e cambio password)
    Route::prefix('auth')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
        Route::put('password', [AuthController::class, 'updatePassword']);
    });
    

    // ============================================================
    // CONTI ROUTES
    // ============================================================
    // GET    /api/conti              → index (lista tutti)
    // POST   /api/conti              → store (crea nuovo)
    // GET    /api/conti/{id}         → show (recupera uno)
    // PUT    /api/conti/{id}         → update (aggiorna)
    // DELETE /api/conti/{id}         → destroy (cancella)

    Route::get('conti', [ContiApiController::class, 'index']);
    Route::post('conti', [ContiApiController::class, 'store']);
    Route::get('conti/{id}', [ContiApiController::class, 'show']);
    Route::put('conti/{id}', [ContiApiController::class, 'update']);
    Route::delete('conti/{id}', [ContiApiController::class, 'destroy']);


    // ============================================================
    // OPERAZIONI ROUTES
    // ============================================================
    // GET    /api/operazioni                        → index (con filtri opzionali)
    // POST   /api/operazioni                        → store (crea)
    // GET    /api/operazioni/{id}                   → show (una sola)
    // PUT    /api/operazioni/{id}                   → update (aggiorna)
    // DELETE /api/operazioni/{id}                   → destroy (cancella)
    // GET    /api/operazioni/filtro/avanzato       → filtroAvanzato (custom)

    // NOTA: La route del filtro DEVE stare PRIMA di {id}
    // Se no, Laravel confonde "filtro" con un ID numerico
    Route::get('operazioni/filtro/avanzato', [OperazioniApiController::class, 'filtroAvanzato']);
    Route::get('operazioni/statistiche/totali', [OperazioniApiController::class, 'statisticheTotali']);

    Route::get('operazioni', [OperazioniApiController::class, 'index']);
    Route::post('operazioni', [OperazioniApiController::class, 'store']);
    Route::get('operazioni/{id}', [OperazioniApiController::class, 'show']);
    Route::put('operazioni/{id}', [OperazioniApiController::class, 'update']);
    Route::delete('operazioni/{id}', [OperazioniApiController::class, 'destroy']);


    // ============================================================
    // TAGS ROUTES
    // ============================================================
    // GET    /api/tags              → index (lista)
    // POST   /api/tags              → store (crea)
    // GET    /api/tags/{id}         → show (uno)
    // PUT    /api/tags/{id}         → update (aggiorna)
    // DELETE /api/tags/{id}         → destroy (cancella)

    Route::get('tags', [TagsApiController::class, 'index']);
    Route::post('tags', [TagsApiController::class, 'store']);
    Route::get('tags/{id}', [TagsApiController::class, 'show']);
    Route::put('tags/{id}', [TagsApiController::class, 'update']);
    Route::delete('tags/{id}', [TagsApiController::class, 'destroy']);


    // ============================================================
    // GRUPPI TAG ROUTES ("tag virtuali": raccolte di tag salvate)
    // ============================================================
    // GET    /api/gruppi-tag              → index (lista, con i tag inclusi)
    // POST   /api/gruppi-tag              → store (crea, con array di tag_id)
    // GET    /api/gruppi-tag/{id}         → show (uno)
    // PUT    /api/gruppi-tag/{id}         → update (aggiorna nome/tag)
    // DELETE /api/gruppi-tag/{id}         → destroy (cancella)

    Route::get('gruppi-tag', [GruppiTagApiController::class, 'index']);
    Route::post('gruppi-tag', [GruppiTagApiController::class, 'store']);
    Route::get('gruppi-tag/{id}', [GruppiTagApiController::class, 'show']);
    Route::put('gruppi-tag/{id}', [GruppiTagApiController::class, 'update']);
    Route::delete('gruppi-tag/{id}', [GruppiTagApiController::class, 'destroy']);


    // ============================================================
    // GRAFICI ROUTES
    // ============================================================
    Route::prefix('grafici')->group(function () {
        Route::get('spese-per-tag', [GraficiController::class, 'spesePerTag']);
        Route::get('guadagni-vs-spese', [GraficiController::class, 'guadagniVsSpese']);
        Route::get('andamento-saldo', [GraficiController::class, 'andamentoSaldo']);
    });
});

/*
|--------------------------------------------------------------------------
| CONCETTI IMPORTANTI CHE DEVI CAPIRE
|--------------------------------------------------------------------------

1. RESOURCE ROUTES (alternativa più compatta):
   
   Route::apiResource('conti', ContiApiController::class);
   
   Questo genera automaticamente le 5 route sopra.
   Ma per imparare, è meglio esplicito come ho fatto sopra.

2. MIDDLEWARE (non usato qui, ma importante):
   
   Route::middleware('auth:sanctum')->group(function () {
       // Solo utenti autenticati
   });
   
   Lo vedrai quando implementeremo autenticazione.

3. VERSIONING API (per progetti grandi):
   
   Route::prefix('v1')->group(function () { ... });
   
   Così puoi avere /api/v1/conti e /api/v2/conti
   in futuro senza rompere i client vecchi.

*/
