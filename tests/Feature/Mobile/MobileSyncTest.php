<?php

require_once __DIR__.'/../Fleet/FleetHelpers.php';

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Src\Customer\Models\Customer;
use Src\Fleet\Expense\Models\Expense;
use Src\Fleet\Route\Models\FleetRoute;
use Src\Identity\Attendance\Models\AttendanceRecord;
use Src\Identity\Employee\Models\Employee;
use Src\Logistics\Order\Models\Order;
use Src\Operations\Delivery\Models\Delivery;
use Src\Operations\Shipment\Models\Embark;
use Src\Operations\Shipment\Models\Shipment;
use Src\Organization\Models\Agency;
use Src\Shared\Models\SyncEvent;
use Src\Shared\Services\ShipmentStatus;

/*
|--------------------------------------------------------------------------
| Backend de las apps móviles (Fase 8)
|--------------------------------------------------------------------------
|
| Lotes idempotentes, evidencia de entrega, hoja de ruta y descarga incremental.
|
*/

function mobileCourier(Agency $agency): User
{
    $employee = Employee::factory()->create(['agency_id' => $agency->id, 'status' => Employee::STATUS_ACTIVE]);
    $user = fleetUserWithRole('courier', $agency);
    $user->forceFill(['employee_id' => $employee->id])->save();

    return $user->refresh();
}

function mobileDelivery(User $courier, Agency $agency, array $attributes = []): Delivery
{
    $receiver = Customer::factory()->create(['display_name' => 'Ana Perez', 'phone' => '999888777']);
    $order = Order::factory()->create([
        'origin_agency_id' => $agency->id,
        'destination_agency_id' => $agency->id,
        'receiver_id' => $receiver->id,
        'recipient_address' => 'Av. Lima 123',
        'requires_signature' => true,
        'idempotency_key' => (string) Str::uuid(),
    ]);
    $shipment = Shipment::factory()->create([
        'order_id' => $order->id,
        'origin_agency_id' => $agency->id,
        'destination_agency_id' => $agency->id,
        'current_location_agency_id' => $agency->id,
        'status' => ShipmentStatus::OUT_FOR_DELIVERY,
    ]);

    return Delivery::query()->create(array_merge([
        'shipment_id' => $shipment->id,
        'ticket_code' => $shipment->ticket_code,
        'ticket_number' => $shipment->ticket_number,
        'agency_id' => $agency->id,
        'courier_id' => $courier->employee_id,
        'status' => Delivery::STATUS_ASSIGNED,
        'attempt' => 1,
    ], $attributes));
}

function mobileCompleteEvent(Delivery $delivery, array $overrides = []): array
{
    return array_merge([
        'event_id' => (string) Str::uuid(),
        'action' => 'complete',
        'delivery_id' => $delivery->id,
        'recipient_name' => 'Ana Perez',
        'recipient_document' => '12345678',
        'signature_path' => "deliveries/{$delivery->id}/signature.png",
        'photo_path' => "deliveries/{$delivery->id}/photo.jpg",
        'latitude' => -12.04,
        'longitude' => -77.04,
        'captured_at' => now()->toIso8601String(),
    ], $overrides);
}

function mobileBatch(array $items)
{
    return test()->postJson('/api/v1/operations/deliveries/batch', ['items' => $items]);
}

function mobileFleetBatch(array $items)
{
    return test()->postJson('/api/v1/fleet/sync', ['items' => $items]);
}

beforeEach(function () {
    Storage::fake('local');
    $this->agency = fleetAgency('MOB01');
    $this->courier = mobileCourier($this->agency);
    $this->actingAs($this->courier);
});

describe('lote de entregas', function () {
    it('aplica cada evento y reporta el resultado por event_id', function () {
        $delivery = mobileDelivery($this->courier, $this->agency);
        $start = ['event_id' => (string) Str::uuid(), 'action' => 'start', 'delivery_id' => $delivery->id];
        $complete = mobileCompleteEvent($delivery);

        mobileBatch([$start, $complete])
            ->assertOk()
            ->assertJsonPath('data.accepted', 2)
            ->assertJsonPath('data.items.0.event_id', $start['event_id'])
            ->assertJsonPath('data.items.1.status', 'accepted');

        expect($delivery->refresh()->status)->toBe(Delivery::STATUS_DELIVERED);
    });

    it('reintentar un lote ya aplicado no repite nada ni lo rechaza', function () {
        $delivery = mobileDelivery($this->courier, $this->agency);
        $items = [
            ['event_id' => (string) Str::uuid(), 'action' => 'start', 'delivery_id' => $delivery->id],
            mobileCompleteEvent($delivery),
        ];

        mobileBatch($items)->assertOk();

        // `start` sobre una entrega ya entregada se rechazaría si no estuviera registrado.
        mobileBatch($items)
            ->assertOk()
            ->assertJsonPath('data.accepted', 0)
            ->assertJsonPath('data.duplicated', 2)
            ->assertJsonPath('data.rejected', 0);

        expect(SyncEvent::query()->count())->toBe(2);
    });

    it('un evento rechazado no bloquea a los demás y se puede corregir', function () {
        $delivery = mobileDelivery($this->courier, $this->agency);
        $other = mobileDelivery($this->courier, $this->agency);
        $invalid = mobileCompleteEvent($delivery, ['recipient_name' => '']);

        mobileBatch([
            $invalid,
            ['event_id' => (string) Str::uuid(), 'action' => 'start', 'delivery_id' => $other->id],
        ])
            ->assertOk()
            ->assertJsonPath('data.rejected', 1)
            ->assertJsonPath('data.accepted', 1)
            ->assertJsonPath('data.items.0.error_code', 'SYNC_ITEM_INVALID');

        mobileBatch([array_merge($invalid, ['recipient_name' => 'Ana Perez'])])
            ->assertJsonPath('data.accepted', 1);
    });

    it('rechaza con el código de negocio lo que el estado no permite', function () {
        $delivery = mobileDelivery($this->courier, $this->agency, ['status' => Delivery::STATUS_RETURNED]);

        mobileBatch([mobileCompleteEvent($delivery)])
            ->assertJsonPath('data.rejected', 1)
            ->assertJsonPath('data.items.0.error_code', 'INVALID_DELIVERY_TRANSITION');

        expect(SyncEvent::query()->count())->toBe(0);
    });

    it('registra un fallo con su motivo', function () {
        $delivery = mobileDelivery($this->courier, $this->agency);

        mobileBatch([['event_id' => (string) Str::uuid(), 'action' => 'fail', 'delivery_id' => $delivery->id]])
            ->assertJsonPath('data.items.0.error_code', 'SYNC_ITEM_INVALID');

        mobileBatch([[
            'event_id' => (string) Str::uuid(),
            'action' => 'fail',
            'delivery_id' => $delivery->id,
            'failure_reason' => 'Recipient not found',
        ]])->assertJsonPath('data.accepted', 1);

        expect($delivery->refresh()->failure_reason)->toBe('Recipient not found');
    });

    it('valida la forma del lote y su tamaño', function () {
        mobileBatch([['event_id' => 'not-a-uuid', 'action' => 'start']])->assertUnprocessable();
        mobileBatch([])->assertUnprocessable();
        mobileBatch(array_fill(0, 26, ['event_id' => (string) Str::uuid(), 'action' => 'start']))->assertUnprocessable();
    });

    it('fuera del ámbito no se encuentra la entrega', function () {
        $foreign = fleetAgency('MOB02');
        $delivery = mobileDelivery($this->courier, $foreign);

        mobileBatch([mobileCompleteEvent($delivery)])
            ->assertJsonPath('data.items.0.error_code', 'DELIVERY_NOT_FOUND');
    });

    it('exige delivery.execute', function () {
        $this->actingAs(fleetUserWithRole('analyst'));

        mobileBatch([['event_id' => (string) Str::uuid(), 'action' => 'start', 'delivery_id' => 1]])->assertForbidden();
    });
});

describe('lote de escaneos', function () {
    it('reintentar un escaneo rechazado sigue informando el rechazo, no un duplicado', function () {
        $this->actingAs(adminUser());
        $shipment = Shipment::factory()->create([
            'origin_agency_id' => $this->agency->id,
            'destination_agency_id' => $this->agency->id,
            'current_location_agency_id' => $this->agency->id,
            'status' => ShipmentStatus::DELIVERED,
        ]);
        $scan = [
            'scan_id' => (string) Str::uuid(),
            'ticket_code' => $shipment->ticket_code,
            'scan_type' => 'reception',
            'scanned_at' => now()->toIso8601String(),
            'agency_id' => $this->agency->id,
        ];

        $first = $this->postJson('/api/v1/operations/scans/sync', ['scans' => [$scan]])
            ->assertOk()
            ->assertJsonPath('data.items.0.status', 'rejected')
            ->json('data.items.0.error_code');

        $this->postJson('/api/v1/operations/scans/sync', ['scans' => [$scan]])
            ->assertOk()
            ->assertJsonPath('data.duplicated', 0)
            ->assertJsonPath('data.rejected', 1)
            ->assertJsonPath('data.items.0.error_code', $first);
    });
});

describe('evidencia de entrega', function () {
    it('guarda el archivo, devuelve la ruta y reintentar lo reemplaza', function () {
        $delivery = mobileDelivery($this->courier, $this->agency);
        $url = "/api/v1/operations/deliveries/{$delivery->id}/evidence";

        $path = $this->post($url, ['type' => 'photo', 'file' => UploadedFile::fake()->image('p.jpg')], ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('data.path');

        $this->post($url, ['type' => 'photo', 'file' => UploadedFile::fake()->image('q.jpg')], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.path', $path);

        Storage::disk('local')->assertExists($path);
        expect(Storage::disk('local')->files("deliveries/{$delivery->id}"))->toHaveCount(1);
    });

    it('rechaza otro tipo de archivo o de evidencia', function () {
        $delivery = mobileDelivery($this->courier, $this->agency);
        $url = "/api/v1/operations/deliveries/{$delivery->id}/evidence";
        $headers = ['Accept' => 'application/json'];

        $this->post($url, ['type' => 'photo', 'file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')], $headers)->assertUnprocessable();
        $this->post($url, ['type' => 'other', 'file' => UploadedFile::fake()->image('p.jpg')], $headers)->assertUnprocessable();
    });

    it('no acepta evidencia de una entrega fuera del ámbito', function () {
        $delivery = mobileDelivery($this->courier, fleetAgency('MOB02'));

        $this->post("/api/v1/operations/deliveries/{$delivery->id}/evidence", [
            'type' => 'photo',
            'file' => UploadedFile::fake()->image('p.jpg'),
        ], ['Accept' => 'application/json'])->assertNotFound();
    });

    it('la evidencia subida se descarga una vez completada la entrega', function () {
        $delivery = mobileDelivery($this->courier, $this->agency);

        foreach (['signature' => 'signature.png', 'photo' => 'photo.jpg'] as $type => $name) {
            $this->post("/api/v1/operations/deliveries/{$delivery->id}/evidence", [
                'type' => $type,
                'file' => UploadedFile::fake()->image($name),
            ], ['Accept' => 'application/json'])->assertCreated();
        }

        mobileBatch([mobileCompleteEvent($delivery)])->assertJsonPath('data.accepted', 1);

        $this->actingAs(adminUser());
        $this->get("/api/v1/operations/deliveries/{$delivery->id}/evidence/photo")->assertOk();
        $this->get("/api/v1/operations/deliveries/{$delivery->id}/evidence/signature")->assertOk();
    });

    it('sin evidencia registrada la descarga responde 404', function () {
        $delivery = mobileDelivery($this->courier, $this->agency);
        $this->actingAs(adminUser());

        $this->getJson("/api/v1/operations/deliveries/{$delivery->id}/evidence/photo")
            ->assertNotFound()
            ->assertJsonPath('data.error_code', 'DELIVERY_EVIDENCE_NOT_FOUND');
    });
});

describe('hoja de ruta', function () {
    it('trae las entregas abiertas del repartidor con destinatario y dirección', function () {
        $mine = mobileDelivery($this->courier, $this->agency);
        mobileDelivery($this->courier, $this->agency, ['status' => Delivery::STATUS_DELIVERED]);
        mobileDelivery(mobileCourier($this->agency), $this->agency);

        $this->getJson('/api/v1/operations/deliveries/route-sheet')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id)
            ->assertJsonPath('data.0.recipient_name', 'Ana Perez')
            ->assertJsonPath('data.0.recipient_phone', '999888777')
            ->assertJsonPath('data.0.recipient_address', 'Av. Lima 123')
            ->assertJsonPath('data.0.requires_signature', true);
    });

    it('con cursor trae también las suyas cerradas después', function () {
        $old = mobileDelivery($this->courier, $this->agency);
        Delivery::query()->whereKey($old->id)->update(['updated_at' => now()->subDays(2)]);
        $closed = mobileDelivery($this->courier, $this->agency, ['status' => Delivery::STATUS_DELIVERED]);

        $since = urlencode(now()->subDay()->toIso8601String());

        $this->getJson("/api/v1/operations/deliveries/route-sheet?updated_since={$since}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $closed->id);
    });

    it('sin ficha de empleado la hoja está vacía', function () {
        $this->actingAs(fleetUserWithRole('courier', $this->agency));

        $this->getJson('/api/v1/operations/deliveries/route-sheet')->assertOk()->assertJsonCount(0, 'data');
    });
});

describe('lote de flota', function () {
    beforeEach(function () {
        $this->actingAs(adminUser());
        $this->destination = fleetAgency('MOB03');
        $this->driver = fleetDriver($this->agency);
        $this->vehicle = fleetVehicle($this->agency, ['odometer_km' => '1000.00']);
        $this->route = fleetAssignedRoute($this->agency, $this->destination, $this->driver, $this->vehicle);
    });

    it('recorre la ruta y sus gastos en un lote, sin repetir al reintentar', function () {
        $segment = $this->route->segments()->orderBy('sequence')->first();
        $items = [
            ['event_id' => (string) Str::uuid(), 'type' => 'route.start', 'route_id' => $this->route->id, 'odometer_km' => 1000, 'recorded_at' => now()->toIso8601String()],
            ['event_id' => (string) Str::uuid(), 'type' => 'mileage', 'route_id' => $this->route->id, 'odometer_km' => 1100, 'recorded_at' => now()->toIso8601String()],
            ['event_id' => (string) Str::uuid(), 'type' => 'expense', 'route_id' => $this->route->id, 'expense_type' => 'per_diem', 'amount' => 50],
            ['event_id' => (string) Str::uuid(), 'type' => 'segment.complete', 'route_id' => $this->route->id, 'segment_id' => $segment->id],
            ['event_id' => (string) Str::uuid(), 'type' => 'route.finish', 'route_id' => $this->route->id, 'odometer_km' => 1200, 'recorded_at' => now()->toIso8601String()],
        ];

        mobileFleetBatch($items)->assertOk()->assertJsonPath('data.accepted', 5);

        expect($this->route->refresh()->status)->toBe(FleetRoute::STATUS_FINISHED)
            ->and(Expense::query()->count())->toBe(1);

        mobileFleetBatch($items)->assertJsonPath('data.duplicated', 5)->assertJsonPath('data.rejected', 0);

        expect(Expense::query()->count())->toBe(1);
    });

    it('rechaza un odómetro que retrocede sin afectar al resto del lote', function () {
        $this->postJson("/api/v1/fleet/routes/{$this->route->id}/start", ['odometer_km' => '1000.00'])->assertOk();

        mobileFleetBatch([
            ['event_id' => (string) Str::uuid(), 'type' => 'mileage', 'route_id' => $this->route->id, 'odometer_km' => 10, 'recorded_at' => now()->toIso8601String()],
            ['event_id' => (string) Str::uuid(), 'type' => 'expense', 'route_id' => $this->route->id, 'expense_type' => 'per_diem', 'amount' => 20],
        ])
            ->assertJsonPath('data.items.0.error_code', 'MILEAGE_NOT_MONOTONIC')
            ->assertJsonPath('data.accepted', 1);
    });

    it('el combustible sin comprobante se rechaza con su código', function () {
        mobileFleetBatch([[
            'event_id' => (string) Str::uuid(),
            'type' => 'expense',
            'route_id' => $this->route->id,
            'expense_type' => 'fuel',
            'amount' => 100,
            'liters' => 20,
        ]])->assertJsonPath('data.items.0.error_code', 'EXPENSE_RECEIPT_REQUIRED');
    });

    it('rechaza campos que faltan y tipos desconocidos', function () {
        mobileFleetBatch([['event_id' => (string) Str::uuid(), 'type' => 'route.start', 'route_id' => $this->route->id]])
            ->assertJsonPath('data.items.0.error_code', 'SYNC_ITEM_INVALID');

        mobileFleetBatch([['event_id' => (string) Str::uuid(), 'type' => 'nope', 'route_id' => 1]])->assertUnprocessable();
    });

    it('comprueba el permiso de cada evento', function () {
        // Quien solo ejecuta rutas no registra gastos: el lote admite ambos permisos.
        $role = Role::findOrCreate('route-runner', 'web');
        $role->givePermissionTo('routes.execute');
        $user = User::factory()->create();
        $user->assignRole($role);
        $this->actingAs($user);

        mobileFleetBatch([[
            'event_id' => (string) Str::uuid(),
            'type' => 'expense',
            'route_id' => $this->route->id,
            'expense_type' => 'per_diem',
            'amount' => 20,
        ]])->assertJsonPath('data.items.0.error_code', 'FLEET_SYNC_FORBIDDEN');
    });
});

describe('sesión y descarga incremental', function () {
    it('/auth/me expone la ficha de empleado y su agencia', function () {
        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.employee_id', $this->courier->employee_id)
            ->assertJsonPath('data.employee_agency_id', $this->agency->id);
    });

    it('/auth/me sin ficha responde null', function () {
        $this->actingAs(adminUser());

        $this->getJson('/api/v1/auth/me')
            ->assertJsonPath('data.employee_id', null)
            ->assertJsonPath('data.employee_agency_id', null);
    });

    it('el empleado consulta solo sus marcaciones', function () {
        $user = fleetUserWithRole('employee', $this->agency);
        $employee = Employee::factory()->create(['agency_id' => $this->agency->id, 'status' => Employee::STATUS_ACTIVE]);
        $user->forceFill(['employee_id' => $employee->id])->save();
        $mine = AttendanceRecord::factory()->create(['employee_id' => $employee->id, 'agency_id' => $this->agency->id]);
        AttendanceRecord::factory()->create(['agency_id' => $this->agency->id]);
        $this->actingAs($user->refresh());

        $this->getJson('/api/v1/identity/attendance/mine')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);

        // Sin `attendance.view`, el listado general sigue cerrado.
        $this->getJson('/api/v1/identity/attendance')->assertForbidden();
    });

    it('filtra por updated_since envíos, embarques y rutas', function () {
        $this->actingAs(adminUser());
        $old = Shipment::factory()->create(['origin_agency_id' => $this->agency->id, 'destination_agency_id' => $this->agency->id]);
        Shipment::query()->whereKey($old->id)->update(['updated_at' => now()->subDays(3)]);
        $new = Shipment::factory()->create(['origin_agency_id' => $this->agency->id, 'destination_agency_id' => $this->agency->id]);
        $since = urlencode(now()->subDay()->toIso8601String());

        $this->getJson("/api/v1/operations/shipments?updated_since={$since}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $new->id);

        $embark = Embark::factory()->create(['agency_id' => $this->agency->id]);
        Embark::query()->whereKey($embark->id)->update(['updated_at' => now()->subDays(3)]);
        $this->getJson("/api/v1/operations/embarks?updated_since={$since}")->assertOk()->assertJsonCount(0, 'data');

        $route = fleetPlannedRoute($this->agency, fleetAgency('MOB04'));
        FleetRoute::query()->whereKey($route->id)->update(['updated_at' => now()->subDays(3)]);
        $this->getJson("/api/v1/fleet/routes?updated_since={$since}")->assertOk()->assertJsonCount(0, 'data');
    });
});
