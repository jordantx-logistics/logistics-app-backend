<?php

namespace Src\Identity\User\UseCases;

use Src\Identity\User\Services\AuthenticateUserService;
use Src\Shared\Contracts\DeviceRegistrar;

/**
 * Token opaco para apps/PDA. Verifica las credenciales (bloqueo, estado, intentos
 * fallidos) sin abrir sesión (no queremos cookie para un cliente nativo) y
 * guarda el token hasheado en `personal_access_tokens`: el valor en claro solo se
 * devuelve una vez.
 *
 * Si la petición trae `device_uuid`, además da de alta el dispositivo a través
 * del puerto `DeviceRegistrar` (`Identity/Device` no se importa entre
 * sub-contextos, ADR-0006). El nombre del token —`device_name (platform)`— es el
 * mismo que el dispositivo usa para revocarlo (`Device::tokenName()`).
 */
class IssueTokenUseCase
{
    public function __construct(
        private readonly AuthenticateUserService $authenticator,
        private readonly DeviceRegistrar $devices,
    ) {}

    /**
     * @param  array{email: string, password: string}  $credentials
     * @return array{token: string, expires_in: int|null}
     */
    public function execute(
        array $credentials,
        string $deviceName,
        ?string $platform = null,
        ?string $deviceUuid = null,
        ?string $appVersion = null,
    ): array {
        $user = $this->authenticator->authenticate($credentials);

        $name = $platform === null || $platform === ''
            ? $deviceName
            : sprintf('%s (%s)', $deviceName, $platform);

        if ($deviceUuid !== null && $deviceUuid !== '') {
            $this->devices->register(
                userId: $user->id,
                deviceName: $deviceName,
                platform: $platform === null || $platform === '' ? 'web' : $platform,
                deviceUuid: $deviceUuid,
                appVersion: $appVersion,
            );
        }

        $expiration = config('sanctum.expiration');

        return [
            'token' => $user->createToken($name)->plainTextToken,
            'expires_in' => $expiration === null ? null : ((int) $expiration) * 60,
        ];
    }
}
