<?php

use App\Http\Controllers\Api\V1\AreaController;
use App\Http\Controllers\Api\V1\AssetController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CatalogAdminController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\ClientController;
use App\Http\Controllers\Api\V1\CustomFieldController;
use App\Http\Controllers\Api\V1\ImportController;
use App\Http\Controllers\Api\V1\LocalityController;
use App\Http\Controllers\Api\V1\PhotoController;
use App\Http\Controllers\Api\V1\SearchController;
use App\Http\Controllers\Api\V1\SiteController;
use App\Http\Controllers\Api\V1\TileController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);

        Route::get('catalog', [CatalogController::class, 'index'])->middleware('can:catalog.view');
        Route::post('catalog/object-types', [CatalogAdminController::class, 'storeObjectType']);
        Route::patch('catalog/object-types/{id}', [CatalogAdminController::class, 'updateObjectType'])->whereUuid('id');
        Route::delete('catalog/object-types/{id}', [CatalogAdminController::class, 'destroyObjectType'])->whereUuid('id');
        Route::apiResource('custom-fields', CustomFieldController::class)
            ->only(['index', 'store', 'update', 'destroy'])->whereUuid('custom_field');

        Route::get('search', [SearchController::class, 'index']);

        Route::apiResource('clients', ClientController::class)->whereUuid('client');
        Route::apiResource('sites', SiteController::class)
            ->only(['index', 'store', 'update', 'destroy'])->whereUuid('site');
        Route::apiResource('localities', LocalityController::class)
            ->only(['index', 'store', 'update', 'destroy'])->whereUuid('locality');

        Route::get('sync/bootstrap', [\App\Http\Controllers\Api\V1\SyncController::class, 'bootstrap']);
        Route::get('sync/changes', [\App\Http\Controllers\Api\V1\SyncController::class, 'changes']);
        Route::post('sync/batch', [\App\Http\Controllers\Api\V1\SyncController::class, 'batch']);

        Route::post('imports/geojson', [ImportController::class, 'geojson']);
        Route::get('exports/cam', [\App\Http\Controllers\Api\V1\ExportController::class, 'cam']);

        Route::get('assets/{asset}/assessments', [\App\Http\Controllers\Api\V1\TreeAssessmentController::class, 'index'])->whereUuid('asset');
        Route::post('assets/{asset}/assessments', [\App\Http\Controllers\Api\V1\TreeAssessmentController::class, 'store'])->whereUuid('asset');
        Route::delete('assessments/{id}', [\App\Http\Controllers\Api\V1\TreeAssessmentController::class, 'destroy'])->whereUuid('id');
        Route::get('assessments/{assessment}/instrumental-analyses', [\App\Http\Controllers\Api\V1\InstrumentalAnalysisController::class, 'index'])->whereUuid('assessment');
        Route::post('assessments/{assessment}/instrumental-analyses', [\App\Http\Controllers\Api\V1\InstrumentalAnalysisController::class, 'store'])->whereUuid('assessment');
        Route::delete('instrumental-analyses/{id}', [\App\Http\Controllers\Api\V1\InstrumentalAnalysisController::class, 'destroy'])->whereUuid('id');
        Route::get('vta/dashboard', [\App\Http\Controllers\Api\V1\VtaDashboardController::class, 'index']);
        Route::get('vta/tutelati', [\App\Http\Controllers\Api\V1\VtaDashboardController::class, 'tutelati']);
        Route::get('vta/bilancio', [\App\Http\Controllers\Api\V1\TreeBalanceController::class, 'index']);

        Route::apiResource('areas', AreaController::class)->whereUuid('area');
        Route::apiResource('assets', AssetController::class)->whereUuid('asset');

        Route::post('assets/{asset}/photos', [PhotoController::class, 'store'])->whereUuid('asset');
        Route::get('photos/{id}/file', [PhotoController::class, 'file'])
            ->whereUuid('id')->name('v1.photos.file');
        Route::delete('photos/{id}', [PhotoController::class, 'destroy'])->whereUuid('id');

        Route::get('documents/{id}/file', [\App\Http\Controllers\Api\V1\DocumentController::class, 'file'])
            ->whereUuid('id')->name('v1.documents.file');

        Route::get('tiles/assets/{z}/{x}/{y}', [TileController::class, 'assets'])
            ->whereNumber(['z', 'x', 'y']);
    });
});
