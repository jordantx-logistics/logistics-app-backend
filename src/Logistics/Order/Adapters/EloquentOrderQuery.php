<?php

namespace Src\Logistics\Order\Adapters;

use Src\Logistics\Order\Models\Order;
use Src\Shared\Contracts\CustomerDirectory;
use Src\Shared\Contracts\OrderQuery;
use Src\Shared\DTOs\BillableOrder;
use Src\Shared\DTOs\DeliveryContact;
use Src\Shared\ValueObjects\Money;

/**
 * Adaptador del puerto `OrderQuery`. Es el único punto de Core que conoce el
 * modelo `Order` para responder a Finanzas.
 */
class EloquentOrderQuery implements OrderQuery
{
    public function __construct(
        private readonly CustomerDirectory $customers,
    ) {}

    public function findBillable(int $orderId): ?BillableOrder
    {
        $order = Order::query()->find($orderId);

        if ($order === null) {
            return null;
        }

        $payerId = $order->payment_responsibility === Order::PAYMENT_RECEIVER
            ? $order->receiver_id
            : $order->sender_id;

        return new BillableOrder(
            orderId: $order->id,
            ticketNumber: $order->ticket_number,
            ticketCode: $order->ticket_code,
            status: $order->status,
            totalAmount: Money::fromDecimal($order->total_amount),
            senderId: $order->sender_id,
            receiverId: $order->receiver_id,
            originAgencyId: $order->origin_agency_id,
            destinationAgencyId: $order->destination_agency_id,
            paymentResponsibility: $order->payment_responsibility,
            payerId: $payerId,
            payerName: $this->customers->customerDisplayName($payerId) ?? "Customer #{$payerId}",
            fiscalDocument: $this->customers->customerFiscalData($payerId),
        );
    }

    public function findDeliveryContact(int $orderId): ?DeliveryContact
    {
        $order = Order::query()->find($orderId);

        if ($order === null) {
            return null;
        }

        return new DeliveryContact(
            recipientName: $this->customers->customerDisplayName($order->receiver_id),
            recipientPhone: $this->customers->customerPhone($order->receiver_id),
            recipientAddress: $order->recipient_address,
            requiresSignature: (bool) $order->requires_signature,
        );
    }
}
