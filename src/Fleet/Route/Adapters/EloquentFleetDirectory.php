<?php

namespace Src\Fleet\Route\Adapters;

use Src\Fleet\Driver\Models\Driver;
use Src\Fleet\Vehicle\Models\Vehicle;
use Src\Shared\Contracts\FleetDirectory;
use Src\Shared\Contracts\UserDirectory;

class EloquentFleetDirectory implements FleetDirectory
{
    public function __construct(
        private readonly UserDirectory $users,
    ) {}

    public function vehiclePlates(array $vehicleIds): array
    {
        return Vehicle::query()->whereKey($vehicleIds)->pluck('plate', 'id')->all();
    }

    public function vehicleIdsByPlate(string $plate): array
    {
        return Vehicle::query()
            ->where('plate', 'like', '%'.addcslashes($plate, '%_\\').'%')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    public function driverNames(array $driverIds): array
    {
        $names = [];

        foreach (Driver::query()->whereKey($driverIds)->pluck('user_id', 'id') as $driverId => $userId) {
            $name = $userId === null ? null : $this->users->userName((int) $userId);

            if ($name !== null) {
                $names[(int) $driverId] = $name;
            }
        }

        return $names;
    }
}
