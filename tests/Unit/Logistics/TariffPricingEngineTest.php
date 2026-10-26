<?php

use Src\Logistics\Order\Services\Pricing\PricingRequest;
use Src\Logistics\Order\Services\Pricing\TariffPricingEngine;
use Src\Shared\Contracts\AgencyDirectory;
use Src\Shared\Contracts\CoverageDirectory;
use Src\Shared\Contracts\LocationDirectory;
use Src\Shared\Contracts\TariffDirectory;
use Src\Shared\DTOs\ResolvedTariff;
use Src\Shared\Exceptions\BusinessRuleException;

/*
|--------------------------------------------------------------------------
| Motor de precios por tarifario (casos límite)
|--------------------------------------------------------------------------
|
| El motor es cálculo puro con puertos: aquí se prueba la rama que no se puede
| alcanzar por HTTP porque el request exige `delivery_location_id` cuando la
| entrega es a domicilio, pero el motor también acepta llamadas internas sin él.
|
*/

it('exige ubicación resoluble para el reparto a domicilio', function () {
    $engine = new TariffPricingEngine(
        tariffs: new class implements TariffDirectory
        {
            public function findForRoute(
                string $serviceType,
                ?int $originZoneId,
                ?int $destinationZoneId,
                ?int $originLocationId,
                ?int $destinationLocationId,
            ): ?ResolvedTariff {
                return new ResolvedTariff(
                    id: 1,
                    code: 'NAC-TEST',
                    name: 'Nacional de prueba',
                    serviceType: 'standard',
                    scopeType: 'national',
                    basePrice: 5,
                    pricePerKg: 2.5,
                    pricePerM3: 0,
                    minCharge: 0,
                    maxWeightKg: 100,
                    volumetricFactor: 0,
                );
            }
        },
        locations: new class implements LocationDirectory
        {
            public function zoneIdForUbigeo(string $ubigeo): ?int
            {
                return null;
            }

            public function locationIdForUbigeo(string $ubigeo): ?int
            {
                return null;
            }

            public function zoneExists(int $id): bool
            {
                return true;
            }

            public function locationExists(int $id): bool
            {
                return true;
            }
        },
        agencies: new class implements AgencyDirectory
        {
            public function agencyExists(int $id): bool
            {
                return true;
            }

            public function agencyIsActive(int $id): bool
            {
                return true;
            }

            public function agencyUbigeo(int $id): ?string
            {
                return '150101';
            }

            public function agencyName(int $id): ?string
            {
                return 'Agencia';
            }
        },
        coverages: new class implements CoverageDirectory
        {
            public function deliverySurcharge(int $agencyId, int $locationId): ?float
            {
                return null;
            }
        },
    );

    $request = new PricingRequest(
        originAgencyId: 1,
        destinationAgencyId: 2,
        serviceType: 'standard',
        weightKg: 10,
        volumeM3: 0,
        homeDelivery: true,
        deliveryLocationId: null,
    );

    try {
        $engine->quote($request);

        $this->fail('Se esperaba DELIVERY_NOT_COVERED.');
    } catch (BusinessRuleException $error) {
        expect($error->errorCode)->toBe('DELIVERY_NOT_COVERED');
    }
});
