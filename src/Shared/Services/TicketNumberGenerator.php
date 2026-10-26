<?php

namespace Src\Shared\Services;

use Illuminate\Support\Facades\DB;

/**
 * Número de ticket legible: `ORD-2026-000001` (ADR-0009).
 *
 * La secuencia es **por prefijo y año**: vive en `sequences` bajo la clave
 * `ticket_ORD_2026`, así que el contador de un año no arrastra el del anterior.
 * Es el mismo patrón que `CustomerCodeGenerator`; no hace falta una columna
 * `year` ni resetear filas a mano.
 *
 * Se llama dentro del `creating` del modelo, que a su vez va dentro de la
 * transacción del caso de uso: el `lockForUpdate` mantiene la secuencia sin
 * huecos concurrentes.
 */
class TicketNumberGenerator
{
    public function generate(string $prefix = 'ORD'): string
    {
        $year = (int) now()->format('Y');
        $type = "ticket_{$prefix}_{$year}";

        $next = DB::table('sequences')
            ->where('type', $type)
            ->lockForUpdate()
            ->value('current_value');

        if ($next === null) {
            DB::table('sequences')->insert([
                'type' => $type,
                'current_value' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $next = 1;
        } else {
            $next = ((int) $next) + 1;

            DB::table('sequences')
                ->where('type', $type)
                ->update(['current_value' => $next, 'updated_at' => now()]);
        }

        return sprintf('%s-%d-%06d', $prefix, $year, $next);
    }
}
