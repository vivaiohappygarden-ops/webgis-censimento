<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\AssetStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Committenti (veste nuova, blocco 6): l'anagrafica con i numeri che servono
 * a colpo d'occhio, per ogni committente: elementi in gestione, aree, lavori
 * aperti e stato del portale. Le scritture restano in Territorio.
 */
class CommittentiController extends Controller
{
    private const TIPI = ['public' => 'Ente pubblico', 'private' => 'Privato', 'condo' => 'Condominio', 'other' => 'Altro'];

    public function riepilogo(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $archivio = AssetStatus::sqlArchivio();

        $righe = DB::select(<<<SQL
            SELECT c.id, c.code, c.name, c.client_type, c.vat_number, c.fiscal_code, c.is_active,
                   c.public_enabled, c.public_slug, c.public_profile, c.label_prefix, c.address, c.contacts, c.notes,
                   COALESCE(n.elementi, 0) AS elementi, COALESCE(n.nascosti, 0) AS nascosti, COALESCE(n.aree, 0) AS aree,
                   COALESCE(w.aperti, 0) AS lavori_aperti
            FROM clients c
            LEFT JOIN (
              SELECT s.client_id,
                     COUNT(DISTINCT ar.id) FILTER (WHERE ar.deleted_at IS NULL AND ar.status IN ('active', 'suspended', 'planned')) AS aree,
                     COUNT(a.id) FILTER (WHERE a.deleted_at IS NULL AND a.status NOT IN ({$archivio})) AS elementi,
                     COUNT(a.id) FILTER (WHERE a.deleted_at IS NULL AND a.status NOT IN ({$archivio}) AND a.public_hidden) AS nascosti
              FROM sites s
              JOIN localities l ON l.site_id = s.id AND l.deleted_at IS NULL
              JOIN areas ar ON ar.locality_id = l.id
              LEFT JOIN assets a ON a.area_id = ar.id
              WHERE s.tenant_id = ? AND s.deleted_at IS NULL
              GROUP BY s.client_id
            ) n ON n.client_id = c.id
            LEFT JOIN (
              SELECT client_id, COUNT(*) AS aperti FROM work_orders
              WHERE tenant_id = ? AND deleted_at IS NULL AND status NOT IN ('completed', 'cancelled')
              GROUP BY client_id
            ) w ON w.client_id = c.id
            WHERE c.tenant_id = ? AND c.deleted_at IS NULL
            ORDER BY c.name
            SQL, [$tenantId, $tenantId, $tenantId]);

        return response()->json(['data' => array_map(function ($r) {
            $profilo = json_decode((string) $r->public_profile, true) ?: [];
            $contatti = json_decode((string) $r->contacts, true) ?: [];
            $indirizzo = json_decode((string) $r->address, true) ?: [];

            return [
                'id' => $r->id,
                'codice' => $r->code,
                'nome' => $r->name,
                'nome_pubblico' => $profilo['display_name'] ?? null,
                'tipo' => $r->client_type,
                'tipo_etichetta' => self::TIPI[$r->client_type] ?? $r->client_type,
                'partita_iva' => $r->vat_number,
                'codice_fiscale' => $r->fiscal_code,
                'attivo' => (bool) $r->is_active,
                'prefisso' => $r->label_prefix,
                'contatti' => $contatti,
                'indirizzo' => $indirizzo,
                'nato_dal_campo' => str_contains((string) $r->notes, 'dal campo'),
                'elementi' => (int) $r->elementi,
                'nascosti' => (int) $r->nascosti,
                'aree' => (int) $r->aree,
                'lavori_aperti' => (int) $r->lavori_aperti,
                'portale' => [
                    'acceso' => (bool) $r->public_enabled,
                    'slug' => $r->public_slug,
                    'copertina' => ! empty($profilo['cover_path']),
                    'co2' => (bool) ($profilo['show_co2'] ?? false),
                    'recapiti' => count(array_filter(['contact_email', 'contact_phone', 'address', 'contact_pec'], fn ($k) => trim((string) ($profilo[$k] ?? '')) !== '')),
                ],
            ];
        }, $righe)]);
    }
}
