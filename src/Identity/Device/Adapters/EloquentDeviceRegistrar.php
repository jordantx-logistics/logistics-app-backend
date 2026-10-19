<?php

namespace Src\Identity\Device\Adapters;

use Src\Identity\Device\UseCases\RegisterDeviceUseCase;
use Src\Shared\Contracts\DeviceRegistrar;

/**
 * Adaptador del puerto `DeviceRegistrar`. Vive en el contexto dueño del modelo
 * (`Identity/Device`) y delega en el caso de uso, que es la única puerta de
 * entrada.
 */
class EloquentDeviceRegistrar implements DeviceRegistrar
{
    public function __construct(
        private readonly RegisterDeviceUseCase $useCase,
    ) {}

    public function register(
        int $userId,
        string $deviceName,
        string $platform,
        string $deviceUuid,
        ?string $appVersion = null,
    ): int {
        return $this->useCase->execute([
            'user_id' => $userId,
            'device_name' => $deviceName,
            'platform' => $platform,
            'device_uuid' => $deviceUuid,
            'app_version' => $appVersion,
        ])->id;
    }
}
