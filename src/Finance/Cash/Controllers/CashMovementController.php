<?php

namespace Src\Finance\Cash\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Src\Finance\Cash\Requests\RegisterCashMovementRequest;
use Src\Finance\Cash\Resources\CashMovementResource;
use Src\Finance\Cash\UseCases\RegisterCashMovementUseCase;
use Src\Shared\Scope\AgencyScope;

/**
 * @group Finance - Cash movements
 */
class CashMovementController extends Controller
{
    /**
     * Register an expense or withdrawal
     *
     * Income movements are generated only by cash payments.
     *
     * @bodyParam type string required `expense` or `withdrawal`. Example: expense
     * @bodyParam amount number required Movement amount. Example: 18.50
     * @bodyParam note string required Reason. Example: Courier advance
     * @bodyParam reference_type string optional External reference type. Example: courier
     * @bodyParam reference_id integer optional External reference id. Example: 9
     *
     * @apiResource Src\Finance\Cash\Resources\CashMovementResource
     *
     * @apiResourceModel Src\Finance\Cash\Models\CashMovement
     */
    public function store(
        RegisterCashMovementRequest $request,
        RegisterCashMovementUseCase $useCase,
        AgencyScope $scope,
    ): JsonResponse {
        $movement = $useCase->execute($request->validated(), $scope);

        return $this->success(new CashMovementResource($movement), 'Cash movement registered successfully.', 201);
    }
}
