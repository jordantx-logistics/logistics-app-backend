<?php

namespace Src\Identity\Device\UseCases;

use Illuminate\Support\Facades\DB;
use Src\Identity\Device\Models\Device;

/**
 * Revocación de un dispositivo (UC-19).
 *
 * Marca `status = revoked` con `revoked_at` y borra los tokens Sanctum emitidos
 * para él (el token responde `401` en la siguiente petición, criterio 5 de la
 * Fase 2).
 *
 * El token se emite con el nombre del dispositivo (`device_name (platform)`, ver
 * `IssueTokenUseCase`) porque la tabla de Sanctum no guarda el vínculo con
 * `devices`; por eso aquí se reconstruye el mismo nombre con `Device::tokenName()`.
 * Deuda: si dos dispositivos del mismo usuario comparten nombre y plataforma, sus
 * tokens no se distinguen.
 */
class RevokeDeviceUseCase
{
    public function execute(Device $device): Device
    {
        return DB::transaction(function () use ($device): Device {
            $device->update([
                'status' => Device::STATUS_REVOKED,
                'revoked_at' => now(),
            ]);

            DB::table('personal_access_tokens')
                ->where('tokenable_id', $device->user_id)
                ->where('name', $device->tokenName())
                ->delete();

            return $device;
        });
    }
}
