<?php

namespace Src\Customer\Adapters;

use Src\Customer\Models\Customer;
use Src\Shared\Contracts\CustomerDirectory;

/**
 * Adaptador Eloquent del puerto `CustomerDirectory`. Vive en el contexto dueño
 * del modelo (`Customer`), que es el único que puede conocerlo.
 */
class EloquentCustomerDirectory implements CustomerDirectory
{
    public function customerExists(int $id): bool
    {
        return Customer::query()->whereKey($id)->exists();
    }

    public function customerEmail(int $id): ?string
    {
        $email = Customer::query()->whereKey($id)->value('email');

        return is_string($email) ? $email : null;
    }

    public function customerDisplayName(int $id): ?string
    {
        $name = Customer::query()->whereKey($id)->value('display_name');

        return is_string($name) ? $name : null;
    }

    public function customerIdsByDocument(string $number, ?string $type = null): array
    {
        $query = Customer::query()->where('document_number', $number);

        if ($type !== null && $type !== '') {
            $query->where('document_type', strtoupper($type));
        }

        return $query->pluck('id')->all();
    }
}
