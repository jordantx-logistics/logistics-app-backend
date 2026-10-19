<?php

namespace Src\Identity\Device\Rules;

use Closure;
use Src\Identity\Device\Models\Device;
use Src\Shared\Exceptions\BusinessRuleException;

/**
 * Un usuario `active` no supera el límite de dispositivos activos
 * (`DEVICE_LIMIT_REACHED`).
 *
 * Se cuentan los dispositivos activos del usuario **excluyendo el propio
 * `device_uuid`**: reactivar un dispositivo revocado ocupa una plaza nueva, así
 * que también se comprueba contra el tope. El `lockForUpdate` (dentro de la
 * transacción del caso de uso) evita la carrera de dos altas simultáneas.
 */
class ValidateDeviceLimit
{
    public function handle(array $payload, Closure $next): mixed
    {
        $limit = (int) config('identity.device_limit');

        $activeDevices = Device::query()
            ->where('user_id', $payload['user_id'])
            ->where('status', Device::STATUS_ACTIVE)
            ->where('device_uuid', '!=', $payload['device_uuid'])
            ->lockForUpdate()
            ->count();

        if ($activeDevices >= $limit) {
            throw new BusinessRuleException(
                message: 'The maximum number of active devices has been reached.',
                errorCode: 'DEVICE_LIMIT_REACHED',
                statusCode: 409,
            );
        }

        return $next($payload);
    }
}
