<?php

namespace Src\Fleet\Driver\UseCases;

use Src\Fleet\Driver\Models\Driver;
use Src\Shared\Contracts\AgencyDirectory;
use Src\Shared\Contracts\UserDirectory;
use Src\Shared\Exceptions\BusinessRuleException;
use Src\Shared\Scope\AgencyScope;

/**
 * Alta de la ficha del chofer, vinculada a un usuario existente y a una agencia
 * activa dentro del ámbito de quien la registra.
 */
class RegisterDriverUseCase
{
    public function __construct(
        private readonly UserDirectory $users,
        private readonly AgencyDirectory $agencies,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(array $data, AgencyScope $scope): Driver
    {
        $agencyId = (int) $data['home_agency_id'];

        if (! $scope->covers($agencyId)) {
            throw new BusinessRuleException(
                message: 'The agency is outside your scope.',
                errorCode: 'FLEET_AGENCY_SCOPE',
                statusCode: 403,
            );
        }

        if (! $this->agencies->agencyIsActive($agencyId)) {
            throw new BusinessRuleException(
                message: 'The agency does not exist or is inactive.',
                errorCode: 'FLEET_AGENCY_INVALID',
            );
        }

        if (! $this->users->userExists((int) $data['user_id'])) {
            throw new BusinessRuleException(
                message: 'The user does not exist.',
                errorCode: 'DRIVER_USER_NOT_FOUND',
            );
        }

        return Driver::create([...$data, 'status' => Driver::STATUS_ACTIVE]);
    }
}
