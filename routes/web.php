<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Il backend serve solo API JSON (vedi routes/api.php) per il frontend
| Angular. Questa rotta esiste solo per verificare che l'app sia in piedi.
|
*/

Route::get('/', function () {
    return response()->json([
        'app' => config('app.name'),
        'status' => 'ok',
    ]);
});

