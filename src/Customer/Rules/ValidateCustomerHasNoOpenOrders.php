<?php

namespace Src\Customer\Rules;

use Closure;
use Src\Shared\Contracts\CustomerOrderQuery;
use Src\Shared\Exceptions\BusinessRuleException;

/**
 * Un cliente con envíos vivos no se desactiva (`CUSTOMER_HAS_OPEN_ORDERS`, 409).
 *
 * El cliente nunca se borra: `DELETE` lo desactiva. Pero desactivarlo mientras
 * hay órdenes `pending`/`confirmed`/`in_transit` dejaría envíos sin cliente
 * operativo, así que se rechaza. Las preguntas sobre órdenes se hacen por el
 * puerto `CustomerOrderQuery` (ADR-0006: `Customer` no lee `Logistics`).
 */
class ValidateCustomerHasNoOpenOrders
{
    public function __construct(
        private readonly CustomerOrderQuery $orders,
    ) {}

    public function handle(array $payload, Closure $next): mixed
    {
        $customerId = (int) $payload['customer_id'];

        if ($this->orders->hasOpenOrders($customerId)) {
            throw new BusinessRuleException(
                message: "Customer {$customerId} has open orders and cannot be deactivated.",
                errorCode: 'CUSTOMER_HAS_OPEN_ORDERS',
                statusCode: 409,
            );
        }

        return $next($payload);
    }
}
