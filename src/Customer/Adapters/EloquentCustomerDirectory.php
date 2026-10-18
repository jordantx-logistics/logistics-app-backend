<?php

namespace Src\Customer\Adapters;

use Src\Customer\Models\Person;
use Src\Shared\Contracts\CustomerDirectory;

/**
 * Adaptador Eloquent del puerto `CustomerDirectory`. Vive en el contexto dueño
 * del modelo (`Customer`), que es el único que puede conocerlo.
 */
class EloquentCustomerDirectory implements CustomerDirectory
{
    public function personExists(int $id): bool
    {
        return Person::query()->whereKey($id)->exists();
    }

    public function personEmail(int $id): ?string
    {
        $email = Person::query()->whereKey($id)->value('email');

        return is_string($email) ? $email : null;
    }
}
