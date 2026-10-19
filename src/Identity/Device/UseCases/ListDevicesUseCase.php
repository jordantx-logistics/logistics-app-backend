<?php

namespace Src\Identity\Device\UseCases;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Src\Identity\Device\Models\Device;

/**
 * Listado de dispositivos (UC-19).
 *
 * Self-service acotado: por defecto el usuario ve los suyos. Quien tiene
 * `devices.manage` puede pedir los de otro usuario con `user_id`.
 */
class ListDevicesUseCase
{
    public const DEFAULT_PER_PAGE = 15;

    public const MAX_PER_PAGE = 100;

    /**
     * @param  array{user_id?: int|string|null, status?: string|null}  $filters
     * @return LengthAwarePaginator<int, Device>
     */
    public function execute(User $user, array $filters = [], int $perPage = self::DEFAULT_PER_PAGE): LengthAwarePaginator
    {
        $targetUserId = $user->id;

        if ($user->can('devices.manage') && ! empty($filters['user_id'])) {
            $targetUserId = (int) $filters['user_id'];
        }

        return Device::query()
            ->where('user_id', $targetUserId)
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->orderByDesc('last_seen_at')
            ->paginate($perPage);
    }
}
