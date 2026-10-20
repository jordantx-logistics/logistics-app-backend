<?php

namespace Src\Logistics\Order\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Src\Logistics\Order\Mail\OrderConfirmationMail;
use Src\Logistics\Order\Models\Order;
use Src\Shared\Contracts\CustomerDirectory;
use Src\Shared\Services\ProcessedEvents;

/**
 * Correo de confirmación al remitente (cola `default`).
 *
 * El payload del evento no trae el email —es dato del contexto `Customer`—, así
 * que se resuelve por el puerto `CustomerDirectory`. Si el cliente no tiene
 * email no se envía nada: en `customers` es opcional, y no tenerlo no es un
 * fallo que deba reintentarse.
 */
final class SendOrderConfirmationMailJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $outboxEventId,
        public readonly array $payload,
    ) {
        $this->onQueue('default');
    }

    public function handle(ProcessedEvents $processed, CustomerDirectory $customers): void
    {
        $order = Order::query()->find($this->payload['order_id'] ?? null);

        if ($order === null) {
            Log::warning('order.confirmation_skipped', [
                'reason' => 'order_not_found',
                'order_id' => $this->payload['order_id'] ?? null,
            ]);

            return;
        }

        $email = $customers->customerEmail((int) $order->sender_id);

        if ($email === null) {
            Log::info('order.confirmation_skipped', [
                'reason' => 'sender_without_email',
                'order_id' => $order->id,
            ]);

            return;
        }

        $processed->once($this->outboxEventId, self::class, function () use ($order, $email) {
            Mail::to($email)->send(new OrderConfirmationMail([
                'order_id' => $order->id,
                'ticket_code' => $order->ticket_code,
                'ticket_number' => $order->ticket_number,
                'total_amount' => $order->total_amount,
            ]));
        });
    }
}
