<?php

namespace Src\Fleet\Vehicle\UseCases;

use Src\Fleet\Vehicle\Models\Vehicle;
use Src\Shared\Contracts\AgencyDirectory;
use Src\Shared\Exceptions\BusinessRuleException;
use Src\Shared\Scope\AgencyScope;

class RegisterVehicleUseCase
{
    public function __construct(
        private readonly AgencyDirectory $agencies,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(array $data, AgencyScope $scope): Vehicle
    {
        $agencyId = (int) $data['owner_agency_id'];

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

        return Vehicle::create([
            ...$data,
            'status' => Vehicle::STATUS_AVAILABLE,
            'odometer_km' => $data['odometer_km'] ?? 0,
        ]);
    }
}
