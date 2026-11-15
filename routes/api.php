<?php

use App\Http\Controllers\Api\ListNewsController;
use App\Http\Controllers\Api\ListNewsUpdatesController;
use App\Http\Controllers\Api\ShowNewsCaseController;
use App\Http\Controllers\Api\ShowNewsDashboardController;
use Illuminate\Support\Facades\Route;

// API pública de leitura. Todo o conteúdo servido aqui vem dos feeds das
// fontes — a aplicação não consome nenhuma API de terceiros.
Route::prefix('news')->group(function () {
    Route::get('/', ListNewsController::class);
    Route::get('/updates', ListNewsUpdatesController::class);
    Route::get('/dashboard', ShowNewsDashboardController::class);
    Route::get('/case/{uuid}', ShowNewsCaseController::class)->whereUuid('uuid');
});
