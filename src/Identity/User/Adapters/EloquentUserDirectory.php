<?php

namespace Src\Identity\User\Adapters;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
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

    public function usersWithPermission(string $permission, array $agencyIds): array
    {
        return User::permission($permission)
            ->where(fn (Builder $query) => $query
                ->whereHas('agencies', fn (Builder $agencies) => $agencies->whereIn('agencies.id', $agencyIds))
                ->orWhereHas('roles', fn (Builder $roles) => $roles->whereIn('name', User::NATIONAL_ROLES)))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name])
            ->all();
    }
}
