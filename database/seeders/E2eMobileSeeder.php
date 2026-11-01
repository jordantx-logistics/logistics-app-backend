<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;
use Src\Customer\Models\Customer;
use Src\Fleet\Driver\Models\Driver;
use Src\Fleet\Route\Models\FleetRoute;
use Src\Fleet\Route\Models\RouteSegment;
use Src\Fleet\Vehicle\Models\Vehicle;
use Src\Identity\Employee\Models\Employee;
use Src\Logistics\Order\Models\Order;
use Src\Operations\Delivery\Models\Delivery;
use Src\Operations\Shipment\Models\Shipment;
use Src\Organization\Models\Agency;
use Src\Shared\Services\ShipmentStatus;

/**
 * Datos de los tests de integración de las apps móviles, sobre `E2eSeeder`: un usuario
 * por rol de campo, 520 envíos por recibir (`M0000001`...), entregas y una ruta asignada.
 * Solo corre sobre el stack aislado (`docker-compose.e2e.yml`).
 */
class E2eMobileSeeder extends Seeder
{
    public const SHIPMENTS = 520;

    public const DELIVERIES = 4;

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('E2eMobileSeeder only runs on local or testing.');
        }

        $origin = Agency::query()->where('code', 'E2E-A')->firstOrFail();
        $destination = Agency::query()->where('code', 'E2E-B')->firstOrFail();

        $this->fieldUser('warehouse-operator', $origin);
        $this->fieldUser('employee', $origin);

        $this->shipments($origin, $destination);
        $this->deliveries($this->fieldUser('courier', $origin), $origin);
        $this->route($this->fieldUser('driver', $origin), $origin, $destination);
    }

    private function fieldUser(string $role, Agency $agency): User
    {
        $user = User::query()->updateOrCreate(
            ['email' => "e2e.{$role}@logistics.test"],
            ['name' => 'E2E '.ucfirst($role), 'password' => E2eSeeder::PASSWORD],
        );

        $employee = Employee::factory()->create(['agency_id' => $agency->id, 'status' => Employee::STATUS_ACTIVE]);

        $user->forceFill(['email_verified_at' => now(), 'employee_id' => $employee->id])->save();
        $user->syncRoles([$role]);
        $user->agencies()->syncWithoutDetaching([$agency->id => ['is_primary' => true]]);

        return $user->refresh();
    }

    private function shipments(Agency $origin, Agency $destination): void
    {
        foreach (range(1, self::SHIPMENTS) as $number) {
            Shipment::factory()->create([
                'ticket_code' => sprintf('M%07d', $number),
                'order_id' => 900000 + $number,
                'origin_agency_id' => $origin->id,
                'destination_agency_id' => $destination->id,
                'current_location_agency_id' => $origin->id,
                'status' => ShipmentStatus::CREATED,
            ]);
        }
    }

    private function deliveries(User $courier, Agency $agency): void
    {
        $sender = Customer::query()->where('document_number', '70000001')->firstOrFail();
        $receiver = Customer::query()->where('document_number', '70000002')->firstOrFail();

        foreach (range(1, self::DELIVERIES) as $number) {
            $order = Order::factory()->create([
                'sender_id' => $sender->id,
                'receiver_id' => $receiver->id,
                'origin_agency_id' => $agency->id,
                'destination_agency_id' => $agency->id,
                'recipient_address' => "Av. Lima {$number}",
                'requires_signature' => true,
                'idempotency_key' => (string) Str::uuid(),
            ]);

            $shipment = Shipment::factory()->create([
                'ticket_code' => sprintf('D%07d', $number),
                'order_id' => $order->id,
                'origin_agency_id' => $agency->id,
                'destination_agency_id' => $agency->id,
                'current_location_agency_id' => $agency->id,
                'status' => ShipmentStatus::OUT_FOR_DELIVERY,
            ]);

            Delivery::query()->create([
                'shipment_id' => $shipment->id,
                'ticket_code' => $shipment->ticket_code,
                'ticket_number' => $shipment->ticket_number,
                'agency_id' => $agency->id,
                'courier_id' => $courier->employee_id,
                'status' => Delivery::STATUS_ASSIGNED,
                'attempt' => 1,
            ]);
        }
    }

    private function route(User $user, Agency $origin, Agency $destination): void
    {
        $driver = Driver::factory()->create([
            'user_id' => $user->id,
            'home_agency_id' => $origin->id,
            'status' => Driver::STATUS_ACTIVE,
            'license_expires_at' => now()->addYear(),
        ]);
        $vehicle = Vehicle::factory()->create([
            'owner_agency_id' => $origin->id,
            'status' => Vehicle::STATUS_AVAILABLE,
            'odometer_km' => '1000.00',
        ]);
        $route = FleetRoute::factory()->create([
            'origin_agency_id' => $origin->id,
            'destination_agency_id' => $destination->id,
            'driver_id' => $driver->id,
            'vehicle_id' => $vehicle->id,
            'status' => FleetRoute::STATUS_ASSIGNED,
            'planned_departure_at' => now()->addHour(),
        ]);

        RouteSegment::query()->create([
            'route_id' => $route->id,
            'sequence' => 1,
            'from_agency_id' => $origin->id,
            'to_agency_id' => $destination->id,
            'distance_km' => '100.00',
            'status' => RouteSegment::STATUS_PENDING,
        ]);
    }
}
