<?php

namespace Src\Shared\Contracts;

/**
 * Puerto hacia el sub-contexto `Identity/Device`.
 *
 * `Identity/User` (la emisión de token en `POST /auth/token`) da de alta el
 * dispositivo del token, pero los sub-dominios de Identity son contextos de pleno
 * derecho y no se importan entre sí (ADR-0006). El adaptador que conoce el
 * modelo `Device` vive en `Src\Identity\Device\Adapters` y se enlaza en el
 * composition root.
 */
interface DeviceRegistrar
{
    /**
     * Da de alta (o refresca) el dispositivo del usuario y devuelve su id.
     *
     * Si el usuario ya alcanzó su límite de dispositivos activos, la
     * implementación lanza `BusinessRuleException` con `DEVICE_LIMIT_REACHED`.
     */
    public function register(
        int $userId,
        string $deviceName,
        string $platform,
        string $deviceUuid,
        ?string $appVersion = null,
    ): int;
}
