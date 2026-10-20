<?php

namespace Src\Identity\Device\UseCases;

use Illuminate\Support\Facades\DB;
use Src\Identity\Device\Models\Device;
use Src\Identity\Device\Rules\ValidateDeviceLimit;
use Src\Shared\Pipelines\RulesPipeline;

/**
 * Alta (o refresco) del dispositivo que pide un token (UC-03).
 *
 * Única puerta de entrada: valida el límite de dispositivos activos y hace
 * `updateOrCreate` por `[user_id, device_uuid]` —el mismo equipo no acumula
 * filas—, reactivándolo si estaba revocado. Se llama desde `IssueTokenUseCase`
 * a través del puerto `DeviceRegistrar`.
 *
 * No emite `device.registered`: el outbox manda a `failed` un `event_type` sin
 * consumidor (deuda anotada en el cierre de la Fase 2).
 */
class RegisterDeviceUseCase
{
    public function execute(array $data): Device
    {
        return DB::transaction(function () use ($data): Device {
            $data = RulesPipeline::run($data, [
                ValidateDeviceLimit::class,
            ]);

            return Device::updateOrCreate(
                [
                    'user_id' => $data['user_id'],
                    'device_uuid' => $data['device_uuid'],
                ],
                [
                    'device_name' => $data['device_name'],
                    'platform' => $data['platform'],
                    'app_version' => $data['app_version'] ?? null,
                    'status' => Device::STATUS_ACTIVE,
                    'revoked_at' => null,
                    'last_seen_at' => now(),
                ],
            );
        });
    }
}
