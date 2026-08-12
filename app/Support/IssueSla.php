<?php

namespace App\Support;

use App\Models\Issue;
use Illuminate\Support\Carbon;

/**
 * Tempi di risposta attesi sulle segnalazioni (SLA), in giorni di
 * calendario per gravità: entro quanto va presa in carico e entro
 * quanto va risolta. Le archiviate non hanno SLA: nessun intervento.
 */
class IssueSla
{
    public const POLICY = [
        'critical' => ['take_charge' => 1, 'resolve' => 3],
        'high' => ['take_charge' => 2, 'resolve' => 7],
        'medium' => ['take_charge' => 5, 'resolve' => 15],
        'low' => ['take_charge' => 10, 'resolve' => 30],
    ];

    public static function days(?string $severity, string $phase): int
    {
        return (self::POLICY[$severity] ?? self::POLICY['medium'])[$phase];
    }

    public static function resolveDueAt(Carbon $createdAt, ?string $severity): Carbon
    {
        return $createdAt->copy()->addDays(self::days($severity, 'resolve'));
    }

    public static function takeChargeDueAt(Carbon $createdAt, ?string $severity): Carbon
    {
        return $createdAt->copy()->addDays(self::days($severity, 'take_charge'));
    }

    /** Scadenze e stato delle due fasi; null per le archiviate. */
    public static function describe(Issue $issue): ?array
    {
        if ($issue->status === 'dismissed') {
            return null;
        }
        $createdAt = $issue->created_at ?? now();

        return [
            // Le scadenze registrate fanno fede; il calcolo è la rete di
            // sicurezza per righe storiche senza valore
            'take_charge' => self::phase(
                $issue->taken_charge_due_at ?? self::takeChargeDueAt($createdAt, $issue->severity),
                $issue->taken_charge_at,
            ),
            'resolve' => self::phase(
                $issue->sla_due_at ?? self::resolveDueAt($createdAt, $issue->severity),
                $issue->resolved_at,
            ),
        ];
    }

    private static function phase(Carbon $dueAt, ?Carbon $doneAt): array
    {
        if ($doneAt !== null) {
            $state = $doneAt->lte($dueAt) ? 'met' : 'late';
        } else {
            $state = now()->gt($dueAt) ? 'overdue' : 'pending';
        }

        return [
            'due_at' => $dueAt->toIso8601String(),
            'done_at' => $doneAt?->toIso8601String(),
            'state' => $state,
            'days_late' => in_array($state, ['late', 'overdue'], true)
                ? (int) floor($dueAt->diffInDays($doneAt ?? now(), true))
                : 0,
        ];
    }
}
