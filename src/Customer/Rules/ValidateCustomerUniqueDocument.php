<?php

namespace Src\Customer\Rules;

use Closure;
use Src\Customer\Models\Company;
use Src\Customer\Models\Customer;
use Src\Customer\Models\Person;
use Src\Shared\Exceptions\BusinessRuleException;

/**
 * Unicidad del documento (`CUSTOMER_DOCUMENT_TAKEN`, 409).
 *
 * Se comprueba en dos sitios, porque los dos tienen índice único:
 *
 * 1. `customers` por `[document_type, document_number]` (un DNI y un RUC con el
 *    mismo número son clientes distintos).
 * 2. La tabla de respaldo (`persons.document_number`, `companies.tax_id`), que el
 *    MVP dejó **única por número sin mirar el tipo**. Sin esta comprobación el
 *    alta explotaría con un error de integridad (500) en vez de un 409.
 *
 * Solo se usa en el alta: el documento es un dato de identidad y no se edita.
 */
class ValidateCustomerUniqueDocument
{
    public function handle(array $payload, Closure $next): mixed
    {
        $type = $payload['customer_type'] ?? Customer::TYPE_PERSON;
        $documentType = $payload['document_type'];
        $documentNumber = $payload['document_number'];

        $alreadyCustomer = Customer::query()
            ->where('document_type', $documentType)
            ->where('document_number', $documentNumber)
            ->exists();

        $alreadyBacked = $type === Customer::TYPE_COMPANY
            ? Company::query()->where('tax_id', $documentNumber)->exists()
            : Person::query()->where('document_number', $documentNumber)->exists();

        if ($alreadyCustomer || $alreadyBacked) {
            throw new BusinessRuleException(
                message: "A customer with {$documentType} {$documentNumber} is already registered.",
                errorCode: 'CUSTOMER_DOCUMENT_TAKEN',
                statusCode: 409,
            );
        }

        return $next($payload);
    }
}
