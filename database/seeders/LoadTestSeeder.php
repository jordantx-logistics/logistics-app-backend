<?php

namespace Database\Seeders;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Src\Customer\Models\Customer;
use Src\Customer\UseCases\CreateCustomerUseCase;
use Src\Finance\Invoice\Models\InvoiceSeries;
use Src\Logistics\Tariff\Models\Tariff;
use Src\Organization\Models\Agency;
use Src\Shared\Services\PartitionManager;

/**
 * Datos de la prueba de carga de la Fase 9. Corre solo en local, sobre el stack de
 * escala, y deja en `load/fixture.json` los tokens que usa `load/phase9.js`.
 *
 *   LOAD_SHIPMENTS=200000 LOAD_EVENTS=5000000 php artisan db:seed --class=...
 */
class LoadTestSeeder extends Seeder
{
    private const AGENCIES = 20;

    private const MONTHS_OF_HISTORY = 6;

    public function run(CreateCustomerUseCase $customers): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('LoadTestSeeder only runs on local or testing.');
        }

        $shipments = (int) env('LOAD_SHIPMENTS', 200000);
        $events = (int) env('LOAD_EVENTS', 5000000);

        $sender = $this->customer($customers, 'Sender', '71000001');
        $receiver = $this->customer($customers, 'Receiver', '71000002');
        $this->tariff();

        $sites = [];

        foreach (range(1, self::AGENCIES) as $number) {
            $agency = Agency::query()->updateOrCreate(['code' => sprintf('LOAD-%02d', $number)], [
                'name' => "Load {$number}",
                'type' => 'branch',
                'ubigeo' => '150101',
                'timezone' => 'America/Lima',
                'is_active' => true,
            ]);

            $this->series($agency, $number);

            $sites[] = [
                'agency_id' => $agency->id,
                'counter_token' => $this->token("load.counter.{$number}@logistics.test", 'counter', $agency),
                'cashier_token' => $this->token("load.cashier.{$number}@logistics.test", 'cashier', $agency),
                'operator_token' => $this->token("load.operator.{$number}@logistics.test", 'agency-supervisor', $agency),
            ];
        }

        $this->seedShipments($shipments, array_column($sites, 'agency_id'));
        $this->splitTrackingHistory();
        $this->seedEvents($events, $shipments, array_column($sites, 'agency_id'));

        file_put_contents(base_path('load/fixture.json'), json_encode([
            'sender_id' => $sender->id,
            'receiver_id' => $receiver->id,
            'sites' => $this->withTickets($sites),
        ], JSON_PRETTY_PRINT));

        $this->command?->info("Seeded {$shipments} shipments and {$events} tracking events.");
    }

    private function customer(CreateCustomerUseCase $customers, string $lastName, string $document): Customer
    {
        return Customer::query()->where('document_number', $document)->first() ?? $customers->execute([
            'customer_type' => Customer::TYPE_PERSON,
            'document_type' => 'DNI',
            'document_number' => $document,
            'first_name' => 'Load',
            'last_name' => $lastName,
        ]);
    }

    private function tariff(): void
    {
        Tariff::query()->updateOrCreate(['code' => 'LOAD-NATIONAL'], [
            'name' => 'Load national',
            'service_type' => 'standard',
            'scope_type' => Tariff::SCOPE_NATIONAL,
            'base_price' => '5.00',
            'price_per_kg' => '2.50',
            'min_charge' => '5.00',
            'max_weight_kg' => '1000.00',
            'priority' => 0,
            'valid_from' => now()->subDay()->toDateString(),
            'is_active' => true,
        ]);
    }

    private function series(Agency $agency, int $number): void
    {
        InvoiceSeries::query()->updateOrCreate(
            ['agency_id' => $agency->id, 'document_type' => 'boleta', 'series' => sprintf('B%03d', 100 + $number)],
            [
                'issuer_ruc' => '20123456789',
                'issuer_name' => 'Logistics Load SAC',
                'issuer_trade_name' => 'Logistics Load',
                'issuer_address' => 'Av. Carga 123, Lima',
                'issuer_ubigeo' => '150101',
                'establishment_code' => '0000',
                'is_active' => true,
                'is_default' => true,
            ],
        );
    }

    private function token(string $email, string $role, Agency $agency): string
    {
        $user = User::query()->updateOrCreate(
            ['email' => $email],
            ['name' => 'Load '.$role, 'password' => Str::random(32)],
        );

        $user->forceFill(['email_verified_at' => now()])->save();
        $user->syncRoles([$role]);
        $user->agencies()->syncWithoutDetaching([$agency->id => ['is_primary' => true]]);

        return $user->createToken('k6')->plainTextToken;
    }

    /**
     * Envíos directos a la tabla: esta prueba mide consultas, no su proyección.
     *
     * @param  list<int>  $agencies
     */
    private function seedShipments(int $count, array $agencies): void
    {
        $now = now();

        for ($offset = 0; $offset < $count; $offset += 5000) {
            $rows = [];

            for ($i = $offset; $i < min($offset + 5000, $count); $i++) {
                $code = strtoupper(substr(base_convert((string) (36 ** 7 + $i), 10, 36).Str::random(2), 0, 8));
                $origin = $agencies[$i % count($agencies)];
                $rows[] = [
                    'order_id' => 1_000_000_000 + $i,
                    'ticket_code' => $code,
                    'ticket_number' => sprintf('LOAD-%09d', $i),
                    'origin_agency_id' => $origin,
                    'destination_agency_id' => $agencies[($i + 1) % count($agencies)],
                    'current_location_agency_id' => $origin,
                    'status' => 'received',
                    'created_at' => $now->copy()->subDays($i % (self::MONTHS_OF_HISTORY * 30)),
                    'updated_at' => $now,
                ];
            }

            DB::table('shipments')->insertOrIgnore($rows);
        }
    }

    /**
     * Una muestra de envíos de la agencia de cada sitio: fuera de su agencia, un operador
     * recibiría un rechazo por ámbito y no se mediría el camino de un escaneo aceptado.
     *
     * @param  list<array<string, mixed>>  $sites
     * @return list<array<string, mixed>>
     */
    private function withTickets(array $sites): array
    {
        return array_map(fn (array $site): array => $site + [
            'tickets' => DB::table('shipments')
                ->where('current_location_agency_id', $site['agency_id'])
                ->where('order_id', '>=', 1_000_000_000)
                ->limit(500)
                ->pluck('ticket_code')
                ->all(),
        ], $sites);
    }

    /**
     * Las particiones nacen en el mes de la migración, y la historia caería entera en la
     * primera. Se reparte para que la medición toque particiones de verdad.
     */
    private function splitTrackingHistory(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $first = app(PartitionManager::class)->partitions('tracking_events')[0];
        $month = PartitionManager::monthOf($first);
        $cursor = CarbonImmutable::now()->startOfMonth()->subMonths(self::MONTHS_OF_HISTORY);
        $parts = [];

        while ($cursor->lessThan($month)) {
            $parts[] = sprintf("PARTITION p%s VALUES LESS THAN (TO_DAYS('%s'))", $cursor->format('Y_m'), $cursor->addMonth()->toDateString());
            $cursor = $cursor->addMonth();
        }

        if ($parts !== []) {
            $parts[] = sprintf("PARTITION %s VALUES LESS THAN (TO_DAYS('%s'))", $first, $month->addMonth()->toDateString());
            DB::statement("ALTER TABLE tracking_events REORGANIZE PARTITION {$first} INTO (".implode(', ', $parts).')');
        }
    }

    /**
     * @param  list<int>  $agencies
     */
    private function seedEvents(int $count, int $shipments, array $agencies): void
    {
        $firstShipment = (int) DB::table('shipments')->where('order_id', 1_000_000_000)->value('id');
        $days = self::MONTHS_OF_HISTORY * 30;
        $now = now();

        for ($offset = 0; $offset < $count; $offset += 10000) {
            $rows = [];

            for ($i = $offset; $i < min($offset + 10000, $count); $i++) {
                $shipment = $i % $shipments;
                $rows[] = [
                    'shipment_id' => $firstShipment + $shipment,
                    'order_id' => 1_000_000_000 + $shipment,
                    'event_type' => 'scan.inventory',
                    'status' => 'received',
                    'agency_id' => $agencies[$shipment % count($agencies)],
                    'created_at' => $now->copy()->subDays($shipment % $days)->addMinutes($i % 1000),
                ];
            }

            DB::table('tracking_events')->insert($rows);
        }
    }
}
