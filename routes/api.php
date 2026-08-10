<?php

use App\Http\Controllers\Api\V1\AreaController;
use App\Http\Controllers\Api\V1\AssetController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\TileController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);

        Route::get('catalog', [CatalogController::class, 'index']);

        Route::apiResource('areas', AreaController::class)->whereUuid('area');
        Route::apiResource('assets', AssetController::class)->whereUuid('asset');

        Route::get('tiles/assets/{z}/{x}/{y}', [TileController::class, 'assets'])
            ->whereNumber(['z', 'x', 'y']);
    });
});
