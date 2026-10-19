<?php

namespace Src\Customer\UseCases;

use Src\Customer\Models\Customer;
use Src\Customer\Rules\ValidateCustomerHasNoOpenOrders;
use Src\Shared\Pipelines\RulesPipeline;

/**
 * Baja de cliente: **no se borra**, se desactiva.
 *
 * Borrar la fila dejaría las órdenes históricas sin cliente (no hay FK, pero el
 * `sender_id` apuntaría a la nada). Se comprueba antes que no tenga envíos vivos
 * (`ValidateCustomerHasNoOpenOrders`); si los tiene, 409 y no se toca nada.
 */
class DeactivateCustomerUseCase
{
    public function execute(Customer $customer): Customer
    {
        RulesPipeline::run(['customer_id' => $customer->id], [
            ValidateCustomerHasNoOpenOrders::class,
        ]);

        $customer->update(['is_active' => false]);

        return $customer->refresh();
    }
}
