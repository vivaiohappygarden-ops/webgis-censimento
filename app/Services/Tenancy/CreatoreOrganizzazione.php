<?php

namespace App\Services\Tenancy;

use App\Models\Organization;
use App\Models\User;
use App\Services\Catalog\CatalogInstaller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Crea un'organizzazione pronta all'uso: ruoli, catalogo Modello Dati v2.1
 * e amministratore con password generata. E' la stessa procedura per il
 * comando tenant:create e per la console della piattaforma: una sola
 * definizione di "organizzazione pronta".
 */
class CreatoreOrganizzazione
{
    /**
     * @return array{organizzazione: Organization, amministratore: User, password: string, catalogo: array}
     *
     * @throws InvalidArgumentException con il motivo, in italiano, se i dati non vanno
     */
    public function crea(string $nome, string $slug, string $emailAmministratore, ?string $nomeAmministratore = null, ?string $partitaIva = null): array
    {
        $slug = Str::slug($slug);
        $email = strtolower(trim($emailAmministratore));
        $nome = trim($nome);

        if ($nome === '') {
            throw new InvalidArgumentException('Il nome dell\'organizzazione manca.');
        }
        if ($slug === '') {
            throw new InvalidArgumentException('Slug non valido.');
        }
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("Email non valida: {$email}");
        }
        if (Organization::query()->withTrashed()->where('slug', $slug)->exists()) {
            throw new InvalidArgumentException("Esiste già un'organizzazione con slug '{$slug}'.");
        }

        $password = Str::password(16, symbols: false);

        return DB::transaction(function () use ($nome, $slug, $email, $nomeAmministratore, $partitaIva, $password) {
            $organizzazione = Organization::create([
                'name' => $nome,
                'slug' => $slug,
                'vat_number' => trim((string) $partitaIva) !== '' ? trim($partitaIva) : null,
                'metric_srid' => 7791,
            ]);

            app(TenantProvisioner::class)->provisionRoles($organizzazione);

            // tenant_id esplicito: chi crea dalla console sta in un'altra
            // organizzazione e il trait non deve appiccicare la sua
            $amministratore = User::query()->newModelInstance([
                'name' => trim((string) $nomeAmministratore) !== '' ? trim($nomeAmministratore) : 'Amministratore',
                'email' => $email,
                'password' => $password,
                'user_type' => 'internal',
            ]);
            $amministratore->tenant_id = $organizzazione->id;
            $amministratore->save();

            // Il contesto dei permessi si sposta sulla nuova organizzazione
            // solo per il tempo di assegnare il ruolo, poi torna com'era
            $registrar = app(PermissionRegistrar::class);
            $contestoPrima = $registrar->getPermissionsTeamId();
            $registrar->setPermissionsTeamId($organizzazione->id);
            $amministratore->assignRole('amministratore');
            $registrar->setPermissionsTeamId($contestoPrima);

            $catalogo = app(CatalogInstaller::class)->install($organizzazione);

            return [
                'organizzazione' => $organizzazione,
                'amministratore' => $amministratore,
                'password' => $password,
                'catalogo' => $catalogo,
            ];
        });
    }
}
