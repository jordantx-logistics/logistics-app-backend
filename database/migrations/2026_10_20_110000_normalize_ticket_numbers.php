<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Normaliza los tickets al formato `ORD-2026-000001` (ADR-0009).
     *
     * Antes el generador usaba una única secuencia global y formateaba sin año
     * (`ORD-0000001`). Ahora la secuencia es por prefijo y año
     * (`ticket_ORD_2026`), así que la migración:
     *
     *  1. reformatea las órdenes existentes conservando su número y usando el
     *     año de `created_at`;
     *  2. traslada la secuencia global `ticket_number` al año en curso;
     *  3. deja el contador de cada año en el máximo número usado, para que una
     *     orden nueva no repita ticket.
     *
     * Es una migración de datos: `down()` no revierte los números porque el
     * formato nuevo es el que viaja impreso. Revertirlos podría colisionar dos
     * años con el mismo número global.
     */
    public function up(): void
    {
        $maxByPrefixAndYear = [];

        // Los números ya emitidos se reservan: en una base con datos mixtos
        // (órdenes viejas y nuevas) el candidato `PREFIJO-AÑO-N` puede existir.
        $used = array_fill_keys(
            DB::table('orders')->pluck('ticket_number')->all(),
            true,
        );

        DB::table('orders')
            ->select(['id', 'ticket_number', 'created_at'])
            ->orderBy('id')
            ->chunk(500, function ($orders) use (&$maxByPrefixAndYear, &$used): void {
                foreach ($orders as $order) {
                    $number = (string) $order->ticket_number;

                    if (preg_match('/^([A-Z]+)-(\d{4})-(\d{6,})$/', $number, $matches)) {
                        $prefix = $matches[1];
                        $year = (int) $matches[2];
                        $sequence = (int) $matches[3];

                        $maxByPrefixAndYear[$prefix][$year] = max(
                            $maxByPrefixAndYear[$prefix][$year] ?? 0,
                            $sequence,
                        );

                        continue;
                    }

                    if (! preg_match('/^([A-Z]+)-(\d{6,})$/', $number, $matches)) {
                        continue;
                    }

                    $prefix = $matches[1];
                    $sequence = (int) $matches[2];
                    $year = Carbon::parse($order->created_at)->year;

                    // Si el número ya está ocupado en el formato nuevo, se
                    // toma el siguiente libre; nunca se pisa un ticket emitido.
                    while (isset($used[sprintf('%s-%d-%06d', $prefix, $year, $sequence)])) {
                        $sequence++;
                    }

                    $normalized = sprintf('%s-%d-%06d', $prefix, $year, $sequence);

                    DB::table('orders')
                        ->where('id', $order->id)
                        ->update(['ticket_number' => $normalized]);

                    $used[$normalized] = true;

                    $maxByPrefixAndYear[$prefix][$year] = max(
                        $maxByPrefixAndYear[$prefix][$year] ?? 0,
                        $sequence,
                    );
                }
            });

        // La secuencia global antigua cuenta también las órdenes que ya no
        // existen; conservar su valor evita reutilizar un número histórico.
        $legacy = (int) DB::table('sequences')->where('type', 'ticket_number')->value('current_value');
        $currentYear = (int) now()->format('Y');

        if ($legacy > 0) {
            $maxByPrefixAndYear['ORD'][$currentYear] = max(
                $maxByPrefixAndYear['ORD'][$currentYear] ?? 0,
                $legacy,
            );
        }

        foreach ($maxByPrefixAndYear as $prefix => $years) {
            foreach ($years as $year => $value) {
                DB::table('sequences')->updateOrInsert(
                    ['type' => "ticket_{$prefix}_{$year}"],
                    ['current_value' => $value, 'created_at' => now(), 'updated_at' => now()],
                );
            }
        }

        DB::table('sequences')->where('type', 'ticket_number')->delete();
    }

    public function down(): void
    {
        // Los números de ticket son inmutables una vez emitidos. Revertir el
        // formato podría repetir un número entre dos años; se deja como está.
    }
};
