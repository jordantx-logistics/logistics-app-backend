<?php

namespace Src\Logistics\Tariff\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Src\Logistics\Tariff\Models\Tariff;
use Src\Logistics\Tariff\Requests\TariffRequest;
use Src\Logistics\Tariff\Resources\TariffResource;
use Src\Logistics\Tariff\UseCases\DeactivateTariffUseCase;
use Src\Logistics\Tariff\UseCases\ListTariffsUseCase;
use Src\Logistics\Tariff\UseCases\UpsertTariffUseCase;

/**
 * @group Logistics - Tariffs
 *
 * Tarifarios por servicio y alcance (nacional, par de zonas o par de
 * ubicaciones), con tramos por peso. Administración completa: `tariffs.manage`.
 */
class TariffController extends Controller
{
    /**
     * List Tariffs
     *
     * @queryParam q string Search by code or name. Example: LIMA
     * @queryParam service_type string standard|express. Example: standard
     * @queryParam scope_type string national|zone_pair|district_pair. Example: national
     * @queryParam is_active boolean Filter by active flag. Example: 1
     * @queryParam page integer Page to return. Example: 2
     * @queryParam per_page integer Rows per page (1..100, default 15). Example: 15
     *
     * @apiResourceCollection Src\Logistics\Tariff\Resources\TariffResource
     *
     * @apiResourceModel Src\Logistics\Tariff\Models\Tariff paginate=15
     *
     * @apiResourceAdditional success=true message="Tariffs retrieved successfully."
     */
    public function index(Request $request, ListTariffsUseCase $useCase): JsonResponse
    {
        $perPage = min(
            max($request->integer('per_page', ListTariffsUseCase::DEFAULT_PER_PAGE), 1),
            ListTariffsUseCase::MAX_PER_PAGE,
        );

        $tariffs = $useCase->execute([
            'q' => $request->query('q'),
            'service_type' => $request->query('service_type'),
            'scope_type' => $request->query('scope_type'),
            'is_active' => $request->has('is_active') ? $request->boolean('is_active') : null,
        ], $perPage);

        return $this->successPaginated(
            resource: TariffResource::collection($tariffs),
            message: 'Tariffs retrieved successfully.',
        );
    }

    /**
     * Create Tariff
     *
     * Rechaza alcances incoherentes (`TARIFF_SCOPE_INVALID`), vigencias
     * invertidas (`TARIFF_VALIDITY_INVALID`), tramos solapados
     * (`TARIFF_BRACKETS_INVALID`) y tarifarios activos solapados del mismo
     * alcance (`TARIFF_OVERLAP`, 409).
     *
     * @apiResource Src\Logistics\Tariff\Resources\TariffResource status=201
     *
     * @apiResourceModel Src\Logistics\Tariff\Models\Tariff
     *
     * @apiResourceAdditional success=true message="Tariff created successfully."
     */
    public function store(TariffRequest $request, UpsertTariffUseCase $useCase): JsonResponse
    {
        $tariff = $useCase->execute($request->validated(), null, $request->user()?->id);

        return $this->success(
            data: TariffResource::make($tariff->load('brackets')),
            message: 'Tariff created successfully.',
            statusCode: 201,
        );
    }

    /**
     * Show Tariff
     *
     * @apiResource Src\Logistics\Tariff\Resources\TariffResource
     *
     * @apiResourceModel Src\Logistics\Tariff\Models\Tariff
     *
     * @apiResourceAdditional success=true message="Tariff retrieved successfully."
     */
    public function show(Tariff $tariff): JsonResponse
    {
        return $this->success(
            data: TariffResource::make($tariff->load('brackets')),
            message: 'Tariff retrieved successfully.',
        );
    }

    /**
     * Update Tariff
     *
     * El payload se funde con el tarifario actual antes de validar, y los
     * tramos se reemplazan en bloque (los enviados sustituyen a los anteriores).
     *
     * @apiResource Src\Logistics\Tariff\Resources\TariffResource
     *
     * @apiResourceModel Src\Logistics\Tariff\Models\Tariff
     *
     * @apiResourceAdditional success=true message="Tariff updated successfully."
     */
    public function update(TariffRequest $request, Tariff $tariff, UpsertTariffUseCase $useCase): JsonResponse
    {
        return $this->success(
            data: TariffResource::make($useCase->execute($request->validated(), $tariff)->load('brackets')),
            message: 'Tariff updated successfully.',
        );
    }

    /**
     * Deactivate Tariff
     *
     * @apiResource Src\Logistics\Tariff\Resources\TariffResource
     *
     * @apiResourceModel Src\Logistics\Tariff\Models\Tariff
     *
     * @apiResourceAdditional success=true message="Tariff deactivated successfully."
     */
    public function destroy(Tariff $tariff, DeactivateTariffUseCase $useCase): JsonResponse
    {
        return $this->success(
            data: TariffResource::make($useCase->execute($tariff)->load('brackets')),
            message: 'Tariff deactivated successfully.',
        );
    }
}
