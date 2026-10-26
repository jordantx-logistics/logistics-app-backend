<?php

use Illuminate\Support\Facades\Log;
use Src\Logistics\Order\Jobs\MarkOrderDeliveredJob;
use Src\Logistics\Order\Jobs\MarkOrderInTransitJob;
use Src\Logistics\Order\Models\Order;
use Src\Operations\Shipment\Jobs\CancelShipmentJob;
use Src\Operations\Shipment\Jobs\ProjectOrderCreatedJob;
use Src\Operations\Shipment\Models\Shipment;
use Src\Operations\Tracking\Models\TrackingEvent;
use Src\Shared\Models\OutboxEvent;
use Src\Shared\Services\ShipmentStatus;

/*
|--------------------------------------------------------------------------
| Idempotencia de los jobs de Operaciones (Fase 4)
|--------------------------------------------------------------------------
|
| El outbox es at-least-once. Dos eventos distintos del mismo hecho (o un
| reintento con otro id de outbox) no pueden duplicar la proyecci��n ni mover
| dos veces el estado. La idempotencia por `(evento, handler)` se suma a la
| del propio caso de uso.
|
*/

function operationsOutboxEvent(string $type, array $payload): OutboxEvent
{
    return OutboxEvent::create([
        'event_type' => $type,
        'payload' => $payload,
    ]);
}

it('proyecta order.created una sola vez por orden', function () {
    $order = Order::factory()->create([
        'origin_agency_id' => 1,
        'destination_agency_id' => 2,
    ]);

    $payload = [
        'order_id' => $order->id,
        'ticket_code' => 'PROJ1234',
        'ticket_number' => 'ORD-2026-999999',
        'origin_agency_id' => 1,
        'destination_agency_id' => 2,
    ];

    $first = operationsOutboxEvent('order.created', $payload);
    $second = operationsOutboxEvent('order.created', $payload);

    app()->call([new ProjectOrderCreatedJob($first->id, $payload), 'handle']);
    app()->call([new ProjectOrderCreatedJob($second->id, $payload), 'handle']);

    $shipment = Shipment::query()->where('order_id', $order->id)->sole();

    expect(Shipment::query()->where('order_id', $order->id)->count())->toBe(1)
        ->and($shipment->status)->toBe(ShipmentStatus::CREATED)
        ->and(TrackingEvent::query()->where('shipment_id', $shipment->id)->count())->toBe(1);
});

it('cancela el env��o una sola vez aunque llegue el evento dos veces', function () {
    $order = Order::factory()->create();
    $shipment = Shipment::factory()->create([
        'order_id' => $order->id,
        'status' => ShipmentStatus::RECEIVED,
        'origin_agency_id' => 1,
        'destination_agency_id' => 2,
        'current_location_agency_id' => 1,
    ]);

    $payload = ['order_id' => $order->id, 'reason' => 'Cliente anul��'];

    $first = operationsOutboxEvent('order.cancelled', $payload);
    $second = operationsOutboxEvent('order.cancelled', $payload);

    app()->call([new CancelShipmentJob($first->id, $payload), 'handle']);
    app()->call([new CancelShipmentJob($second->id, $payload), 'handle']);

    expect($shipment->refresh()->status)->toBe(ShipmentStatus::CANCELLED)
        ->and(TrackingEvent::query()
            ->where('shipment_id', $shipment->id)
            ->where('event_type', 'cancelled')
            ->count())->toBe(1);
});

it('no retrocede un env��o que ya sali�� a reparto al cancelar la orden', function () {
    $order = Order::factory()->create();
    $shipment = Shipment::factory()->create([
        'order_id' => $order->id,
        'status' => ShipmentStatus::OUT_FOR_DELIVERY,
        'origin_agency_id' => 1,
        'destination_agency_id' => 2,
        'current_location_agency_id' => 2,
    ]);

    $payload = ['order_id' => $order->id, 'reason' => 'Cliente anul��'];
    $event = operationsOutboxEvent('order.cancelled', $payload);

    app()->call([new CancelShipmentJob($event->id, $payload), 'handle']);

    expect($shipment->refresh()->status)->toBe(ShipmentStatus::OUT_FOR_DELIVERY)
        ->and(TrackingEvent::query()->where('shipment_id', $shipment->id)->count())->toBe(0);
});

it('pasa la orden a in_transit una sola vez', function () {
    $order = Order::factory()->create([
        'status' => Order::STATUS_CONFIRMED,
    ]);

    $payload = ['order_id' => $order->id];
    $first = operationsOutboxEvent('shipment.embarked', $payload);
    $second = operationsOutboxEvent('shipment.embarked', $payload);

    app()->call([new MarkOrderInTransitJob($first->id, $payload), 'handle']);
    app()->call([new MarkOrderInTransitJob($second->id, $payload), 'handle']);

    expect($order->refresh()->status)->toBe(Order::STATUS_IN_TRANSIT);
});

it('cierra la orden entregada una sola vez', function () {
    $order = Order::factory()->create([
        'status' => Order::STATUS_IN_TRANSIT,
    ]);

    $payload = ['order_id' => $order->id, 'delivery_id' => 10];
    $first = operationsOutboxEvent('delivery.completed', $payload);
    $second = operationsOutboxEvent('delivery.completed', $payload);

    app()->call([new MarkOrderDeliveredJob($first->id, $payload), 'handle']);
    app()->call([new MarkOrderDeliveredJob($second->id, $payload), 'handle']);

    expect($order->refresh()->status)->toBe(Order::STATUS_DELIVERED)
        ->and($order->fresh()->delivered_at)->not->toBeNull();
});

it('registra anomal��a y no fuerza el cierre si la orden no estǭ in_transit', function () {
    Log::spy();

    $order = Order::factory()->create([
        'status' => Order::STATUS_CONFIRMED,
    ]);

    $payload = ['order_id' => $order->id, 'delivery_id' => 10];
    $event = operationsOutboxEvent('delivery.completed', $payload);

    app()->call([new MarkOrderDeliveredJob($event->id, $payload), 'handle']);

    expect($order->refresh()->status)->toBe(Order::STATUS_CONFIRMED);

    Log::shouldHaveReceived('warning')
        ->once()
        ->with('order.delivery_out_of_order', Mockery::on(
            fn (array $context): bool => $context['order_id'] === $order->id
                && $context['status'] === Order::STATUS_CONFIRMED
        ));
});
