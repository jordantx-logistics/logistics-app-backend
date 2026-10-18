<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Src\Customer\Models\Person;
use Src\Logistics\Order\Jobs\GenerateOrderGuidePdfJob;
use Src\Logistics\Order\Jobs\ProjectOrderCreatedJob;
use Src\Logistics\Order\Jobs\SendOrderConfirmationMailJob;
use Src\Logistics\Order\Mail\OrderConfirmationMail;
use Src\Logistics\Order\Models\Order;
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

    $sender = Person::factory()->create(['email' => 'cliente@ejemplo.test']);
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

    $sender = Person::factory()->create(['email' => null]);
    $order = makeOrderForJob($sender->id);

    app()->call([new SendOrderConfirmationMailJob(1, ['order_id' => $order->id]), 'handle']);

    Mail::assertNothingSent();
});

it('no reenvía el correo si el evento ya se procesó', function () {
    Mail::fake();

    $sender = Person::factory()->create();
    $order = makeOrderForJob($sender->id);
    $payload = ['order_id' => $order->id];

    app()->call([new SendOrderConfirmationMailJob(1, $payload), 'handle']);
    app()->call([new SendOrderConfirmationMailJob(1, $payload), 'handle']);

    Mail::assertSentCount(1);

    expect(ProcessedEvent::query()->count())->toBe(1);
});

it('guarda el borrador de la guía en el disco privado', function () {
    Storage::fake(GenerateOrderGuidePdfJob::DISK);

    $job = new GenerateOrderGuidePdfJob(2, [
        'order_id' => 10,
        'ticket_code' => 'A7F3Q8X4',
        'ticket_number' => 'ORD-2026-000010',
    ]);

    app()->call([$job, 'handle']);

    Storage::disk(GenerateOrderGuidePdfJob::DISK)->assertExists('orders/10/guia-A7F3Q8X4.html');

    expect(Storage::disk(GenerateOrderGuidePdfJob::DISK)->get($job->path()))
        ->toContain('ORD-2026-000010');
});

it('no reescribe la guía en un reintento', function () {
    Storage::fake(GenerateOrderGuidePdfJob::DISK);

    $job = new GenerateOrderGuidePdfJob(3, ['order_id' => 10, 'ticket_code' => 'A7F3Q8X4']);

    app()->call([$job, 'handle']);

    // Si el job volviera a ejecutarse de verdad, el guardián lo corta: lo que
    // hubiera en el disco se queda como está.
    Storage::disk(GenerateOrderGuidePdfJob::DISK)->put($job->path(), 'ya estaba');

    app()->call([$job, 'handle']);

    expect(Storage::disk(GenerateOrderGuidePdfJob::DISK)->get($job->path()))->toBe('ya estaba');
});

it('deja constancia de la proyección pendiente hacia Operaciones', function () {
    Log::spy();

    $job = new ProjectOrderCreatedJob(4, ['order_id' => 10]);

    app()->call([$job, 'handle']);

    Log::shouldHaveReceived('info')
        ->with('ops.projection_pending', Mockery::type('array'))
        ->once();

    expect(ProcessedEvent::query()->where('handler', ProjectOrderCreatedJob::class)->exists())->toBeTrue();
});
