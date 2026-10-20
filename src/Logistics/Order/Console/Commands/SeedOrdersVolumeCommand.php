<?php

namespace Src\Logistics\Order\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Siembra órdenes de volumen para verificar el SLO de listado.
 *
 * Inserta por lotes con `insertOrIgnore` sobre `idempotency_key`, así que se
 * puede repetir sin duplicar. **No usar en producción**: existe para medir el
 * listado acotado por agencia con datos realistas (`100000` órdenes) y para
 * correr el `EXPLAIN` de la consulta (`--explain`).
 *
 * Ver `spec/03-roadmap/fase-03-core-logistica.md` (criterio de rendimiento).
 */
class SeedOrdersVolumeCommand extends Command
{
    protected $signature = 'orders:seed-volume
                            {--count=100000 : Número de órdenes a sembrar}
                            {--chunk=1000 : Filas por INSERT}
                            {--explain : Muestra el plan de la consulta del listado}';

    protected $description = 'Siembra órdenes de volumen para verificar el SLO del listado (solo desarrollo)';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('orders:seed-volume no se ejecuta en producción.');

            return self::FAILURE;
        }

        $count = max(1, (int) $this->option('count'));
        $chunk = max(1, (int) $this->option('chunk'));
        $now = Carbon::now();
        $inserted = 0;
        $startedAt = microtime(true);

        for ($offset = 0; $offset < $count; $offset += $chunk) {
            $rows = [];

            for ($i = $offset; $i < min($offset + $chunk, $count); $i++) {
                $rows[] = $this->row($i, $now, $count);
            }

            $inserted += DB::table('orders')->insertOrIgnore($rows);
        }

        $seconds = round(microtime(true) - $startedAt, 2);

        $this->info("Sembradas {$inserted} de {$count} órdenes en {$seconds}s.");

        if ($this->option('explain')) {
            $this->explainListing();
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $index, Carbon $now, int $count): array
    {
        $weight = 1 + ($index % 50) / 2;

        return [
            'ticket_number' => sprintf('VOL-%07d', $index + 1),
            // Base 36 en 8 caracteres: único para cualquier `count` razonable.
            'ticket_code' => strtoupper(str_pad(base_convert((string) ($index + 1), 10, 36), 8, '0', STR_PAD_LEFT)),
            'idempotency_key' => sprintf('seed-%07d', $index + 1),
            'sender_id' => ($index % 500) + 1,
            'receiver_id' => (($index + 7) % 500) + 1,
            'origin_agency_id' => ($index % 25) + 1,
            'destination_agency_id' => (($index + 3) % 25) + 1,
            'description' => 'Carga de volumen',
            'weight_kg' => $weight,
            'volume_m3' => ($index % 100) / 1000,
            'chargeable_weight_kg' => $weight,
            'service_type' => $index % 5 === 0 ? 'express' : 'standard',
            'payment_responsibility' => $index % 2 === 0 ? 'sender' : 'receiver',
            'pricing_snapshot' => json_encode(['engine' => 'seed', 'total_amount' => 20 + ($index % 30)]),
            'declared_value' => 100,
            'total_amount' => 20 + ($index % 30),
            'status' => ['pending', 'confirmed', 'in_transit', 'delivered'][$index % 4],
            'delivery_type' => 'agency_pickup',
            'requires_signature' => false,
            'delivery_attempts' => 0,
            'version' => 1,
            'created_by' => 1,
            // Reparte las fechas para que la paginación no dependa de empates.
            'created_at' => $now->copy()->subMinutes($count - $index),
            'updated_at' => $now,
        ];
    }

    /**
     * Plan de la consulta del listado acotado por agencia, tal cual lo ejecuta
     * `ListOrdersUseCase` (dos `whereIn` + `order by id desc` + límite).
     */
    private function explainListing(): void
    {
        $query = DB::table('orders')
            ->where(fn ($query) => $query
                ->whereIn('origin_agency_id', [1, 2, 3])
                ->orWhereIn('destination_agency_id', [1, 2, 3]))
            ->orderByDesc('id')
            ->limit(15);

        $this->line('EXPLAIN del listado:');

        foreach (DB::select('EXPLAIN '.$query->toSql(), $query->getBindings()) as $row) {
            $this->line('  '.json_encode($row));
        }
    }
}
