<?php

namespace Src\Identity\User\Adapters;

use App\Models\User;
use Src\Shared\Contracts\UserDirectory;

class EloquentUserDirectory implements UserDirectory
{
    public function userExists(int $id): bool
    {
        return User::query()->whereKey($id)->exists();
    }

    public function userName(int $id): ?string
    {
        return User::query()->whereKey($id)->value('name');
    }
}
