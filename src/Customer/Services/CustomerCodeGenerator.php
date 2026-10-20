<?php

namespace Src\Customer\Services;

use Illuminate\Support\Facades\DB;

/**
 * Código de cliente: `CLI-2026-000123`.
 *
 * Es **propio del contexto Customer** y reinicia cada año: la secuencia vive en
 * `sequences` bajo la clave `customer_code_<año>`, así que el contador de 2026 no
 * arrastra el de 2025. No reutiliza `TicketNumberGenerator` porque ese formatea
 * sin año (`ORD-0000001`), y el código de cliente sí lo lleva
 * (`spec/04-modulos/core-logistica/02-entidades.md`).
 *
 * Se llama dentro del `creating` del modelo, que a su vez va dentro de la
 * transacción del caso de uso: el `lockForUpdate` mantiene la secuencia sin
 * huecos concurrentes.
 */
class CustomerCodeGenerator
{
    public function generate(): string
    {
        $year = (int) now()->format('Y');
        $type = "customer_code_{$year}";

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

        return sprintf('CLI-%d-%06d', $year, $next);
    }
}
