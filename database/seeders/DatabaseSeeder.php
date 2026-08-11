<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\Asset;
use App\Models\CatalogObjectType;
use App\Models\Client;
use App\Models\Locality;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use App\Services\Catalog\CatalogInstaller;
use App\Services\Tenancy\TenantProvisioner;
use App\Support\Geometry;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $organization = Organization::query()->firstOrCreate(
            ['slug' => 'demo'],
            ['name' => 'Happy Garden Demo', 'metric_srid' => 7791],
        );

        app(TenantProvisioner::class)->provisionRoles($organization);

        $admin = User::query()->withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $organization->id, 'email' => 'admin@demo.local'],
            [
                'name' => 'Amministratore Demo',
                'password' => 'password',
                'user_type' => 'internal',
            ],
        );

        app(PermissionRegistrar::class)->setPermissionsTeamId($organization->id);
        $admin->assignRole('amministratore');

        $operator = User::query()->withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $organization->id, 'email' => 'operatore@demo.local'],
            [
                'name' => 'Operatore Demo',
                'password' => 'password',
                'user_type' => 'internal',
            ],
        );
        $operator->assignRole('operatore');

        $counts = app(CatalogInstaller::class)->install($organization);
        $this->command?->info(sprintf(
            'Catalogo MD v2.1 installato: %d macro-categorie, %d tipi secondari, %d tipi oggetto.',
            $counts['main_types'], $counts['sub_types'], $counts['object_types'],
        ));

        // Territorio dimostrativo: un parco a Milano
        $client = Client::query()->firstOrCreate(
            ['tenant_id' => $organization->id, 'code' => 'DEMO'],
            ['name' => 'Comune Demo', 'client_type' => 'public'],
        );
        $site = Site::query()->firstOrCreate(
            ['tenant_id' => $organization->id, 'client_id' => $client->id, 'code' => 'SEDE1'],
            ['name' => 'Milano', 'istat_code' => '015146', 'municipality' => 'Milano', 'province' => 'MI'],
        );
        $locality = Locality::query()->firstOrCreate(
            ['tenant_id' => $organization->id, 'site_id' => $site->id, 'code' => 'Z01'],
            ['name' => 'Parco Demo'],
        );

        $parkPolygon = [
            'type' => 'Polygon',
            'coordinates' => [[
                [9.1890, 45.4640], [9.1930, 45.4640], [9.1930, 45.4665], [9.1890, 45.4665], [9.1890, 45.4640],
            ]],
        ];

        $area = Area::query()->firstOrCreate(
            ['tenant_id' => $organization->id, 'code' => 'AREA-001'],
            [
                'locality_id' => $locality->id,
                'name' => 'Parco Demo - settore nord',
                'manager' => 'Happy Garden Demo',
                'geom' => Geometry::toEwkb($parkPolygon, forceMultiPolygon: true),
                'created_by' => $admin->id,
                'updated_by' => $admin->id,
            ],
        );

        if (! Asset::query()->withoutGlobalScopes()->where('tenant_id', $organization->id)->exists()) {
            $treeType = CatalogObjectType::query()->withoutGlobalScopes()
                ->where('tenant_id', $organization->id)->where('code', 'P103108')->firstOrFail();
            $lawnType = CatalogObjectType::query()->withoutGlobalScopes()
                ->where('tenant_id', $organization->id)->where('code', 'S101016')->firstOrFail();

            $treeAsset = Asset::create([
                'tenant_id' => $organization->id,
                'area_id' => $area->id,
                'object_type_id' => $treeType->id,
                'census_code' => 'ALB-0001',
                'status' => 'active',
                'geom' => Geometry::toEwkb(['type' => 'Point', 'coordinates' => [9.1905, 45.4652]]),
                'survey_method' => 'manual_map',
                'created_by' => $admin->id,
                'updated_by' => $admin->id,
            ]);

            \App\Models\Tree::create([
                'asset_id' => $treeAsset->id,
                'tenant_id' => $organization->id,
                'genus' => 'Tilia',
                'species' => 'Tilia cordata',
                'common_name' => 'Tiglio selvatico',
                'height_m' => 14.5,
                'dbh_cm' => 38,
                'crown_diameter_m' => 9,
                'vegetative_state' => 'buono',
            ]);

            Asset::create([
                'tenant_id' => $organization->id,
                'area_id' => $area->id,
                'object_type_id' => $lawnType->id,
                'census_code' => 'PRT-0001',
                'status' => 'active',
                'geom' => Geometry::toEwkb([
                    'type' => 'Polygon',
                    'coordinates' => [[
                        [9.1895, 45.4645], [9.1915, 45.4645], [9.1915, 45.4658], [9.1895, 45.4658], [9.1895, 45.4645],
                    ]],
                ]),
                'survey_method' => 'manual_map',
                'created_by' => $admin->id,
                'updated_by' => $admin->id,
            ]);

            // Tipo personalizzato "Posto libero" (non CAM) + un posto libero demo
            $pianta = \App\Models\CatalogSubType::query()->withoutGlobalScopes()
                ->where('tenant_id', $organization->id)->where('code', '03')->firstOrFail();
            $siteType = CatalogObjectType::query()->withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => $organization->id, 'code' => 'PL001'],
                [
                    'sub_type_id' => $pianta->id,
                    'name' => 'Posto libero (sede di impianto)',
                    'allowed_geometry' => 'P',
                    'is_cam' => false,
                    'is_planting_site' => true,
                ],
            );

            $siteAsset = Asset::create([
                'tenant_id' => $organization->id,
                'area_id' => $area->id,
                'object_type_id' => $siteType->id,
                'census_code' => 'PSL-0001',
                'status' => 'active',
                'geom' => Geometry::toEwkb(['type' => 'Point', 'coordinates' => [9.1912, 45.4648]]),
                'survey_method' => 'manual_map',
                'created_by' => $admin->id,
                'updated_by' => $admin->id,
            ]);
            \App\Models\PlantingSite::create([
                'asset_id' => $siteAsset->id,
                'tenant_id' => $organization->id,
                'status' => 'free',
                'origin' => 'felling',
                'planned_species' => 'Tilia cordata',
                'target_season' => '2026-2027',
            ]);
        }

        // Lavorazioni base e squadra demo per il modulo Lavori
        $workTypes = [
            ['code' => 'SFA', 'name' => 'Sfalcio tappeto erboso', 'category' => 'verde orizzontale', 'unit' => 'mq', 'applicable_geometry' => 'S'],
            ['code' => 'POT', 'name' => 'Potatura', 'category' => 'verde verticale', 'unit' => 'cad', 'applicable_geometry' => 'P', 'requires_photos' => true],
            ['code' => 'ABB', 'name' => 'Abbattimento', 'category' => 'verde verticale', 'unit' => 'cad', 'applicable_geometry' => 'P', 'requires_photos' => true],
            ['code' => 'MAD', 'name' => 'Messa a dimora', 'category' => 'verde verticale', 'unit' => 'cad', 'applicable_geometry' => 'P'],
        ];
        foreach ($workTypes as $wt) {
            \App\Models\WorkType::query()->withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => $organization->id, 'code' => $wt['code']],
                $wt,
            );
        }

        $team = \App\Models\Team::query()->withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $organization->id, 'code' => 'SQ1'],
            ['name' => 'Squadra 1', 'leader_id' => $admin->id],
        );
        $team->members()->syncWithoutDetaching([
            $admin->id => ['tenant_id' => $organization->id, 'member_role' => 'leader'],
            $operator->id => ['tenant_id' => $organization->id, 'member_role' => 'operator'],
        ]);

        // Listino demo: valorizza i consuntivi che arrivano dal campo
        $priceList = \App\Models\PriceList::query()->withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $organization->id, 'code' => 'LIS-'.now()->year],
            ['name' => 'Listino interno '.now()->year, 'year' => now()->year, 'source' => 'Interno'],
        );
        $demoPrices = ['SFA' => ['mq', 0.35], 'POT' => ['cad', 85.00], 'ABB' => ['cad', 320.00], 'MAD' => ['cad', 140.00]];
        foreach ($demoPrices as $code => [$unit, $price]) {
            $workType = \App\Models\WorkType::query()->withoutGlobalScopes()
                ->where('tenant_id', $organization->id)->where('code', $code)->first();
            if ($workType) {
                \App\Models\PriceListItem::query()->withoutGlobalScopes()->firstOrCreate(
                    ['tenant_id' => $organization->id, 'price_list_id' => $priceList->id, 'work_type_id' => $workType->id],
                    ['unit' => $unit, 'unit_price' => $price],
                );
            }
        }

        if (! \App\Models\WorkOrder::query()->withoutGlobalScopes()->where('tenant_id', $organization->id)->exists()) {
            $pot = \App\Models\WorkType::query()->withoutGlobalScopes()
                ->where('tenant_id', $organization->id)->where('code', 'POT')->first();
            $treeAssetId = Asset::query()->withoutGlobalScopes()
                ->where('tenant_id', $organization->id)->where('census_code', 'ALB-0001')->value('id');

            $workOrder = \App\Models\WorkOrder::create([
                'tenant_id' => $organization->id,
                'code' => 'ODL-'.now()->year.'-0001',
                'client_id' => $client->id,
                'area_id' => $area->id,
                'work_type_id' => $pot?->id,
                'title' => 'Potatura di contenimento tigli Parco Demo',
                'status' => 'assigned',
                'priority' => 'high',
                'planned_start' => now()->addDays(3)->toDateString(),
                'planned_end' => now()->addDays(4)->toDateString(),
                'team_id' => $team->id,
                'assigned_to' => $admin->id,
                'price_list_id' => $priceList->id,
                'created_by' => $admin->id,
                'updated_by' => $admin->id,
            ]);
            if ($treeAssetId) {
                \App\Models\WorkOrderAsset::create([
                    'tenant_id' => $organization->id,
                    'work_order_id' => $workOrder->id,
                    'asset_id' => $treeAssetId,
                    'work_type_id' => $pot?->id,
                    'planned_quantity' => 1,
                    'unit' => 'cad',
                ]);
            }
        }

        $this->command?->info('Tenant demo pronto: login admin@demo.local / password (slug organizzazione: demo).');
    }
}
