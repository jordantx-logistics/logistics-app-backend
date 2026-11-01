<?php

namespace Src\Finance\Payment\UseCases;

use Src\Finance\Payment\Models\BillingOrder;

/**
 * Consume los eventos que mantienen la proyección de cobro.
 *
 * Es idempotente por `order_id`: el outbox es at-least-once y un reintento no
 * puede duplicar la proyección ni perder los importes ya cobrados.
 */
class ProjectOrderUseCase
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function created(array $payload): void
    {
        $orderId = (int) ($payload['order_id'] ?? 0);

        if ($orderId === 0) {
            return;
        }

        $billing = BillingOrder::query()->firstOrNew(['order_id' => $orderId]);

        $document = is_array($payload['customer_document'] ?? null)
            ? $payload['customer_document']
            : [];

        $billing->fill([
            'ticket_number' => (string) ($payload['ticket_number'] ?? $billing->ticket_number ?? ''),
            'ticket_code' => (string) ($payload['ticket_code'] ?? $billing->ticket_code ?? ''),
            'status' => (string) ($payload['status'] ?? $billing->status ?? 'pending'),
            'origin_agency_id' => (int) ($payload['origin_agency_id'] ?? $billing->origin_agency_id ?? 0),
            'destination_agency_id' => (int) ($payload['destination_agency_id'] ?? $billing->destination_agency_id ?? 0),
            'sender_id' => (int) ($payload['sender_id'] ?? $billing->sender_id ?? 0),
            'receiver_id' => (int) ($payload['receiver_id'] ?? $billing->receiver_id ?? 0),
            'payer_id' => $payload['payer_id'] ?? $billing->payer_id,
            'payer_name' => $payload['payer_name'] ?? $billing->payer_name,
            'customer_document_type' => $document['type'] ?? $billing->customer_document_type,
            'customer_document_number' => $document['number'] ?? $billing->customer_document_number,
            'amount_due' => (string) ($payload['amount_due'] ?? $billing->amount_due ?? '0.00'),
            'currency' => (string) ($payload['currency'] ?? $billing->currency ?? 'PEN'),
            'payment_responsibility' => (string) ($payload['payment_responsibility'] ?? $billing->payment_responsibility ?? 'sender'),
        ]);

        if (! $billing->exists) {
            $billing->paid_amount = '0.00';
            $billing->refunded_amount = '0.00';
            $billing->payment_status = BillingOrder::PAYMENT_UNPAID;
        }

        $billing->save();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function cancelled(array $payload): void
    {
        $orderId = (int) ($payload['order_id'] ?? 0);

        BillingOrder::query()->where('order_id', $orderId)->update([
            'status' => 'cancelled',
            'cancelled_at' => $payload['cancelled_at'] ?? now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function delivered(array $payload): void
    {
        $orderId = (int) ($payload['order_id'] ?? 0);

        BillingOrder::query()->where('order_id', $orderId)->update([
            'delivered_at' => $payload['delivered_at'] ?? now(),
            'ready_for_settlement' => true,
            'updated_at' => now(),
        ]);
    }
}
