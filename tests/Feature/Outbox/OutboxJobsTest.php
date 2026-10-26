<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Src\Customer\Models\Customer;
use Src\Logistics\Order\Jobs\GenerateOrderGuidePdfJob;
use Src\Logistics\Order\Jobs\SendOrderConfirmationMailJob;
use Src\Logistics\Order\Mail\OrderConfirmationMail;
use Src\Logistics\Order\Models\Order;
use Src\Operations\Shipment\Jobs\ProjectOrderCreatedJob;
use Src\Operations\Shipment\Models\Shipment;
use Src\Operations\Tracking\Models\TrackingEvent;
use Src\Shared\Jobs\AcknowledgeEventJob;
use Src\Shared\Models\ProcessedEvent;

/*
|--------------------------------------------------------------------------
| Jobs base del outbox (Fase 1)
|--------------------------------------------------------------------------
|
| `app()->call([$job, 'handle'])` es lo que hace el worker: resuelve las
| dependencias del método (`ProcessedEvents`, puertos) sin pasar por Redis.
|
*/

function makeOrderForJob(int $senderId): Order
{
    return Order::create([
        'idempotency_key' => Str::uuid()->toString(),
        'sender_id' => $senderId,
        'receiver_id' => $senderId,
        'origin_agency_id' => 1,
        'destination_agency_id' => 1,
        'weight_kg' => 10,
        'total_amount' => 99.90,
        'status' => 'pending',
        'created_by' => 1,
    ]);
}

it('envía la confirmación al email del remitente', function () {
    Mail::fake();

    $sender = Customer::factory()->create(['email' => 'cliente@ejemplo.test']);
    $order = makeOrderForJob($sender->id);

    $job = new SendOrderConfirmationMailJob(1, [
        'order_id' => $order->id,
        'ticket_code' => $order->ticket_code,
    ]);

    app()->call([$job, 'handle']);

    Mail::assertSent(
        OrderConfirmationMail::class,
        fn (OrderConfirmationMail $mail) => $mail->hasTo('cliente@ejemplo.test'),
    );
});

it('no envía nada si el remitente no tiene email', function () {
    Mail::fake();

    $sender = Customer::factory()->create(['email' => null]);
    $order = makeOrderForJob($sender->id);

    app()->call([new SendOrderConfirmationMailJob(1, ['order_id' => $order->id]), 'handle']);

    Mail::assertNothingSent();
});

it('no reenvía el correo si el evento ya se procesó', function () {
    Mail::fake();

    $sender = Customer::factory()->create();
    $order = makeOrderForJob($sender->id);
    $payload = ['order_id' => $order->id];

    app()->call([new SendOrderConfirmationMailJob(1, $payload), 'handle']);
    app()->call([new SendOrderConfirmationMailJob(1, $payload), 'handle']);

    Mail::assertSentCount(1);

    expect(ProcessedEvent::query()->count())->toBe(1);
});

it('guarda la guía en PDF en el disco privado', function () {
    Storage::fake(GenerateOrderGuidePdfJob::DISK);

    $order = Order::factory()->create();

    $job = new GenerateOrderGuidePdfJob(2, [
        'order_id' => $order->id,
        'ticket_code' => $order->ticket_code,
        'ticket_number' => $order->ticket_number,
    ]);

    app()->call([$job, 'handle']);

    Storage::disk(GenerateOrderGuidePdfJob::DISK)->assertExists("orders/{$order->ticket_code}/guia.pdf");

    // dompdf siempre empieza el binario con `%PDF-`.
    expect(Storage::disk(GenerateOrderGuidePdfJob::DISK)->get($job->path()))->toStartWith('%PDF');
});

it('no hace nada si la orden del evento ya no existe', function () {
    Storage::fake(GenerateOrderGuidePdfJob::DISK);

    $job = new GenerateOrderGuidePdfJob(4, ['order_id' => 999999, 'ticket_code' => 'NOEXISTE']);

    app()->call([$job, 'handle']);

    Storage::disk(GenerateOrderGuidePdfJob::DISK)->assertMissing('orders/NOEXISTE/guia.pdf');
});

it('no reescribe la guía en un reintento', function () {
    Storage::fake(GenerateOrderGuidePdfJob::DISK);

    $order = Order::factory()->create();

    $job = new GenerateOrderGuidePdfJob(3, ['order_id' => $order->id, 'ticket_code' => $order->ticket_code]);

    app()->call([$job, 'handle']);

    // Si el job volviera a ejecutarse de verdad, el guardián lo corta: lo que
    // hubiera en el disco se queda como está.
    Storage::disk(GenerateOrderGuidePdfJob::DISK)->put($job->path(), 'ya estaba');

    app()->call([$job, 'handle']);

    expect(Storage::disk(GenerateOrderGuidePdfJob::DISK)->get($job->path()))->toBe('ya estaba');
});

it('proyecta order.created en el envío operativo y su primer tracking', function () {
    $order = Order::factory()->create([
        'origin_agency_id' => 10,
        'destination_agency_id' => 20,
    ]);

    $job = new ProjectOrderCreatedJob(4, [
        'order_id' => $order->id,
        'ticket_code' => $order->ticket_code,
        'ticket_number' => $order->ticket_number,
        'origin_agency_id' => 10,
        'destination_agency_id' => 20,
        'created_at' => now()->toIso8601String(),
    ]);

    app()->call([$job, 'handle']);

    $shipment = Shipment::query()->where('order_id', $order->id)->first();

    expect($shipment)->not->toBeNull()
        ->and($shipment->status)->toBe(Shipment::STATUS_CREATED)
        ->and($shipment->current_location_agency_id)->toBe(10)
        ->and(TrackingEvent::query()->where('shipment_id', $shipment->id)->where('event_type', 'created')->count())->toBe(1)
        ->and(ProcessedEvent::query()->where('handler', ProjectOrderCreatedJob::class)->exists())->toBeTrue();
});

it('reconoce un evento sin consumidor y lo registra una sola vez', function () {
    Log::spy();

    $job = new AcknowledgeEventJob(77, ['order_id' => 10, 'ticket_code' => 'A7F3Q8X4']);

    app()->call([$job, 'handle']);
    app()->call([$job, 'handle']);

    Log::shouldHaveReceived('info')
        ->with('outbox.acknowledged_without_consumer', Mockery::type('array'))
        ->once();

    expect(ProcessedEvent::query()->where('handler', AcknowledgeEventJob::class)->count())->toBe(1);
});
