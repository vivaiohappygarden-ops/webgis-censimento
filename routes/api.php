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
        Route::post('clients/{id}/stemma', [ClientController::class, 'stemma'])->whereUuid('id');

        // Vincoli del territorio e loro collegamento agli elementi
        Route::get('constraints', [\App\Http\Controllers\Api\V1\LandConstraintController::class, 'index']);
        Route::post('constraints', [\App\Http\Controllers\Api\V1\LandConstraintController::class, 'store']);
        Route::patch('constraints/{id}', [\App\Http\Controllers\Api\V1\LandConstraintController::class, 'update'])->whereUuid('id');
        Route::delete('constraints/{id}', [\App\Http\Controllers\Api\V1\LandConstraintController::class, 'destroy'])->whereUuid('id');
        Route::post('constraints/{id}/documento', [\App\Http\Controllers\Api\V1\LandConstraintController::class, 'documento'])->whereUuid('id');
        Route::post('constraints/{id}/ricalcola', [\App\Http\Controllers\Api\V1\LandConstraintController::class, 'ricalcola'])->whereUuid('id');
        Route::get('assets/{asset}/constraints', [\App\Http\Controllers\Api\V1\LandConstraintController::class, 'perAsset'])->whereUuid('asset');
        Route::post('assets/{asset}/constraints', [\App\Http\Controllers\Api\V1\LandConstraintController::class, 'collega'])->whereUuid('asset');
        Route::delete('assets/{asset}/constraints/{constraint}', [\App\Http\Controllers\Api\V1\LandConstraintController::class, 'scollega'])->whereUuid(['asset', 'constraint']);
        Route::delete('clients/{id}/stemma', [ClientController::class, 'rimuoviStemma'])->whereUuid('id');
        Route::apiResource('sites', SiteController::class)
            ->only(['index', 'store', 'update', 'destroy'])->whereUuid('site');
        Route::apiResource('localities', LocalityController::class)
            ->only(['index', 'store', 'update', 'destroy'])->whereUuid('locality');

        // Scheda completa della localita' e documenti allegati
        Route::get('localities/{id}/scheda', [LocalityController::class, 'scheda'])->whereUuid('id');
        Route::post('localities/{id}/documenti', [LocalityController::class, 'documento'])->whereUuid('id');
        Route::delete('localities/{id}/documenti/{documentId}', [LocalityController::class, 'eliminaDocumento'])
            ->whereUuid(['id', 'documentId']);

        Route::get('sync/bootstrap', [\App\Http\Controllers\Api\V1\SyncController::class, 'bootstrap']);
        Route::get('sync/changes', [\App\Http\Controllers\Api\V1\SyncController::class, 'changes']);
        Route::post('sync/batch', [\App\Http\Controllers\Api\V1\SyncController::class, 'batch']);

        Route::post('imports/geojson', [ImportController::class, 'geojson']);
        Route::post('imports/cam', [ImportController::class, 'cam']);
        Route::get('portal/overview', [\App\Http\Controllers\Api\V1\PortalController::class, 'overview']);
        Route::get('portal/requests', [\App\Http\Controllers\Api\V1\PortalController::class, 'requests']);
        Route::post('portal/requests', [\App\Http\Controllers\Api\V1\PortalController::class, 'storeRequest'])->middleware('throttle:5,1');
        Route::apiResource('irrigation-systems', \App\Http\Controllers\Api\V1\IrrigationController::class)
            ->whereUuid('irrigation_system');
        Route::put('irrigation-systems/{id}/sectors', [\App\Http\Controllers\Api\V1\IrrigationController::class, 'syncSectors'])->whereUuid('id');
        Route::post('irrigation-systems/{id}/work-order', [\App\Http\Controllers\Api\V1\IrrigationController::class, 'createWorkOrder'])->whereUuid('id');
        Route::get('irrigation-systems/{id}/readings', [\App\Http\Controllers\Api\V1\IrrigationController::class, 'readings'])->whereUuid('id');
        Route::post('irrigation-systems/{id}/readings', [\App\Http\Controllers\Api\V1\IrrigationController::class, 'storeReading'])->whereUuid('id');
        Route::delete('irrigation-systems/{id}/readings/{readingId}', [\App\Http\Controllers\Api\V1\IrrigationController::class, 'destroyReading'])->whereUuid(['id', 'readingId']);
        Route::get('exports/cam/delivery', [\App\Http\Controllers\Api\V1\ExportController::class, 'camDelivery']);
        Route::get('exports/cam', [\App\Http\Controllers\Api\V1\ExportController::class, 'cam']);
        Route::get('exports/assets.csv', [\App\Http\Controllers\Api\V1\ExportController::class, 'assetsCsv']);

        Route::get('assets/{asset}/assessments', [\App\Http\Controllers\Api\V1\TreeAssessmentController::class, 'index'])->whereUuid('asset');
        Route::post('assets/{asset}/assessments', [\App\Http\Controllers\Api\V1\TreeAssessmentController::class, 'store'])->whereUuid('asset');
        Route::patch('assessments/{id}', [\App\Http\Controllers\Api\V1\TreeAssessmentController::class, 'update'])->whereUuid('id');
        Route::delete('assessments/{id}', [\App\Http\Controllers\Api\V1\TreeAssessmentController::class, 'destroy'])->whereUuid('id');
        Route::post('assessments/{id}/valida', [\App\Http\Controllers\Api\V1\TreeAssessmentController::class, 'valida'])->whereUuid('id');
        Route::get('assessments/{assessment}/instrumental-analyses', [\App\Http\Controllers\Api\V1\InstrumentalAnalysisController::class, 'index'])->whereUuid('assessment');
        Route::post('assessments/{assessment}/instrumental-analyses', [\App\Http\Controllers\Api\V1\InstrumentalAnalysisController::class, 'store'])->whereUuid('assessment');
        Route::delete('instrumental-analyses/{id}', [\App\Http\Controllers\Api\V1\InstrumentalAnalysisController::class, 'destroy'])->whereUuid('id');
        Route::get('assessments/{id}/perizia-pdf', [\App\Http\Controllers\Api\V1\PeriziaController::class, 'pdf'])->whereUuid('id');
        Route::get('perizia/settings', [\App\Http\Controllers\Api\V1\PeriziaController::class, 'settings']);
        Route::put('perizia/settings', [\App\Http\Controllers\Api\V1\PeriziaController::class, 'updateSettings']);
        Route::get('vta/dashboard', [\App\Http\Controllers\Api\V1\VtaDashboardController::class, 'index']);
        Route::get('vta/alberi', [\App\Http\Controllers\Api\V1\VtaDashboardController::class, 'alberi']);
        Route::post('vta/valida', [\App\Http\Controllers\Api\V1\VtaDashboardController::class, 'valida']);
        // POST e non GET: fino a 500 id selezionati non stanno in un indirizzo
        Route::post('vta/registro', [\App\Http\Controllers\Api\V1\VtaDashboardController::class, 'registro']);
        Route::get('vta/tutelati', [\App\Http\Controllers\Api\V1\VtaDashboardController::class, 'tutelati']);
        Route::get('vta/bilancio', [\App\Http\Controllers\Api\V1\TreeBalanceController::class, 'index']);
        Route::get('vta/bilancio/pdf', [\App\Http\Controllers\Api\V1\TreeBalanceController::class, 'pdf']);

        Route::get('users', [\App\Http\Controllers\Api\V1\UserAdminController::class, 'index']);
        Route::post('users', [\App\Http\Controllers\Api\V1\UserAdminController::class, 'store']);
        Route::patch('users/{id}', [\App\Http\Controllers\Api\V1\UserAdminController::class, 'update'])->whereUuid('id');
        Route::post('users/{id}/reset-password', [\App\Http\Controllers\Api\V1\UserAdminController::class, 'resetPassword'])->whereUuid('id');

        Route::get('teams', [\App\Http\Controllers\Api\V1\TeamController::class, 'index']);
        Route::post('teams', [\App\Http\Controllers\Api\V1\TeamController::class, 'store']);
        Route::patch('teams/{id}', [\App\Http\Controllers\Api\V1\TeamController::class, 'update'])->whereUuid('id');

        Route::get('work-types', fn () => response()->json([
            'data' => \App\Models\WorkType::query()->where('is_active', true)->orderBy('name')->get(),
        ]))->middleware('can:works.view');
        Route::get('personnel', fn () => response()->json([
            'data' => \App\Models\User::query()->where('user_type', 'internal')
                ->orderBy('name')->get(['id', 'name']),
        ]))->middleware('can:works.manage');

        Route::get('reports/lavori', [\App\Http\Controllers\Api\V1\WorkReportController::class, 'lavori']);
        Route::get('dashboard/today', [\App\Http\Controllers\Api\V1\DashboardController::class, 'today']);
        Route::get('stats/overview', [\App\Http\Controllers\Api\V1\StatsController::class, 'overview']);

        Route::apiResource('price-lists', \App\Http\Controllers\Api\V1\PriceListController::class)
            ->whereUuid('price_list');
        Route::put('price-lists/{id}/items', [\App\Http\Controllers\Api\V1\PriceListController::class, 'syncItems'])->whereUuid('id');

        Route::apiResource('estimates', \App\Http\Controllers\Api\V1\EstimateController::class)
            ->whereUuid('estimate');
        Route::put('estimates/{id}/items', [\App\Http\Controllers\Api\V1\EstimateController::class, 'syncItems'])->whereUuid('id');
        Route::post('estimates/{id}/transition', [\App\Http\Controllers\Api\V1\EstimateController::class, 'transition'])->whereUuid('id');
        Route::post('estimates/{id}/work-order', [\App\Http\Controllers\Api\V1\EstimateController::class, 'createWorkOrder'])->whereUuid('id');
        Route::get('estimates/{id}/pdf', [\App\Http\Controllers\Api\V1\EstimateController::class, 'pdf'])->whereUuid('id');

        Route::get('gestionale/settings', [\App\Http\Controllers\Api\V1\GestionaleController::class, 'settings']);
        Route::put('gestionale/settings', [\App\Http\Controllers\Api\V1\GestionaleController::class, 'updateSettings']);
        Route::post('gestionale/test', [\App\Http\Controllers\Api\V1\GestionaleController::class, 'test']);
        Route::get('gestionale/dispatches', [\App\Http\Controllers\Api\V1\GestionaleController::class, 'index']);
        Route::post('gestionale/dispatches/{id}/retry', [\App\Http\Controllers\Api\V1\GestionaleController::class, 'retry'])->whereUuid('id');
        Route::post('assets/{id}/gestionale', [\App\Http\Controllers\Api\V1\GestionaleController::class, 'dispatchAsset'])->whereUuid('id');

        Route::apiResource('certificates', \App\Http\Controllers\Api\V1\CertificateController::class)
            ->only(['index', 'store', 'update', 'destroy'])->whereUuid('certificate');

        Route::get('phyto-treatments/register-pdf', [\App\Http\Controllers\Api\V1\PhytoTreatmentController::class, 'registerPdf']);
        Route::apiResource('phyto-treatments', \App\Http\Controllers\Api\V1\PhytoTreatmentController::class)
            ->only(['index', 'store', 'update', 'destroy'])->whereUuid('phyto_treatment');

        Route::apiResource('work-orders', \App\Http\Controllers\Api\V1\WorkOrderController::class)
            ->whereUuid('work_order');
        Route::post('work-orders/{id}/transition', [\App\Http\Controllers\Api\V1\WorkOrderController::class, 'transition'])->whereUuid('id');
        Route::post('work-orders/{id}/day', [\App\Http\Controllers\Api\V1\WorkOrderController::class, 'toggleDay'])->whereUuid('id');

        // Azioni su piu' elementi selezionati in un elenco
        Route::post('azioni/chiudi-lavori', [\App\Http\Controllers\Api\V1\AzioniMultipleController::class, 'chiudiLavori']);
        Route::post('azioni/modifica-elementi', [\App\Http\Controllers\Api\V1\AzioniMultipleController::class, 'modificaElementi']);
        Route::post('azioni/lavori/{id}/collega-elementi', [\App\Http\Controllers\Api\V1\AzioniMultipleController::class, 'collegaElementi'])->whereUuid('id');
        Route::post('work-orders/{id}/checks', [\App\Http\Controllers\Api\V1\WorkCheckController::class, 'store'])->whereUuid('id');
        Route::apiResource('inspection-templates', \App\Http\Controllers\Api\V1\InspectionTemplateController::class)
            ->whereUuid('inspection_template');
        Route::put('inspection-templates/{id}/items', [\App\Http\Controllers\Api\V1\InspectionTemplateController::class, 'syncItems'])->whereUuid('id');
        Route::get('inspections/deadlines', [\App\Http\Controllers\Api\V1\InspectionController::class, 'deadlines']);
        Route::get('inspections/{id}/pdf', [\App\Http\Controllers\Api\V1\PdfController::class, 'inspection'])->whereUuid('id');
        Route::get('assets/{id}/pdf', [\App\Http\Controllers\Api\V1\PdfController::class, 'asset'])->whereUuid('id');
        Route::post('assets/{id}/public-page', [\App\Http\Controllers\Api\V1\PublicPageController::class, 'enable'])->whereUuid('id');
        Route::delete('assets/{id}/public-page', [\App\Http\Controllers\Api\V1\PublicPageController::class, 'disable'])->whereUuid('id');
        Route::get('assets/{id}/public-tag', [\App\Http\Controllers\Api\V1\PublicPageController::class, 'tag'])->whereUuid('id');
        Route::post('assets/{id}/removal', [AssetController::class, 'registerRemoval'])->whereUuid('id');
        Route::delete('assets/{id}/removal', [AssetController::class, 'cancelRemoval'])->whereUuid('id');
        Route::get('inspections', [\App\Http\Controllers\Api\V1\InspectionController::class, 'index']);
        Route::post('inspections', [\App\Http\Controllers\Api\V1\InspectionController::class, 'store']);
        Route::get('inspections/{id}', [\App\Http\Controllers\Api\V1\InspectionController::class, 'show'])->whereUuid('id');

        Route::get('issues', [\App\Http\Controllers\Api\V1\IssueController::class, 'index']);
        Route::post('issues', [\App\Http\Controllers\Api\V1\IssueController::class, 'store']);
        Route::patch('issues/{id}', [\App\Http\Controllers\Api\V1\IssueController::class, 'update'])->whereUuid('id');
        Route::post('issues/{id}/work-order', [\App\Http\Controllers\Api\V1\IssueController::class, 'createWorkOrder'])->whereUuid('id');
        Route::get('non-conformities', [\App\Http\Controllers\Api\V1\NonConformityController::class, 'index']);
        Route::patch('non-conformities/{id}', [\App\Http\Controllers\Api\V1\NonConformityController::class, 'update'])->whereUuid('id');
        Route::post('work-orders/{id}/assets', [\App\Http\Controllers\Api\V1\WorkOrderController::class, 'attachAsset'])->whereUuid('id');
        Route::delete('work-orders/{id}/assets/{rowId}', [\App\Http\Controllers\Api\V1\WorkOrderController::class, 'detachAsset'])->whereUuid(['id', 'rowId']);

        Route::apiResource('areas', AreaController::class)->whereUuid('area');
        Route::apiResource('assets', AssetController::class)->whereUuid('asset');

        Route::post('assets/{asset}/photos', [PhotoController::class, 'store'])->whereUuid('asset');
        Route::get('photos/{id}/file', [PhotoController::class, 'file'])
            ->whereUuid('id')->name('v1.photos.file');
        Route::delete('photos/{id}', [PhotoController::class, 'destroy'])->whereUuid('id');

        Route::get('documents/{id}/file', [\App\Http\Controllers\Api\V1\DocumentController::class, 'file'])
            ->whereUuid('id')->name('v1.documents.file');

        // Conto delle richieste a parte: vedi il limite "tiles" in AppServiceProvider
        Route::get('assets/{id}/versioni', [\App\Http\Controllers\Api\V1\AssetController::class, 'versioni'])
            ->whereUuid('id');

        // Viste salvate: filtri con nome, per gli elenchi
        Route::get('viste', [\App\Http\Controllers\Api\V1\VisteController::class, 'index']);
        Route::post('viste', [\App\Http\Controllers\Api\V1\VisteController::class, 'store']);
        Route::patch('viste/{id}', [\App\Http\Controllers\Api\V1\VisteController::class, 'update'])->whereUuid('id');
        Route::delete('viste/{id}', [\App\Http\Controllers\Api\V1\VisteController::class, 'destroy'])->whereUuid('id');

        Route::get('tiles/assets/{z}/{x}/{y}', [TileController::class, 'assets'])
            ->middleware('throttle:tiles')
            ->whereNumber(['z', 'x', 'y']);
    });
});
