<?php

namespace Src\Fleet\Expense\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Src\Fleet\Expense\Models\Expense;
use Src\Fleet\Expense\Requests\AttachReceiptRequest;
use Src\Fleet\Expense\Requests\LiquidateExpensesRequest;
use Src\Fleet\Expense\Requests\ListExpensesRequest;
use Src\Fleet\Expense\Requests\StoreExpenseRequest;
use Src\Fleet\Expense\Resources\ExpenseResource;
use Src\Fleet\Expense\UseCases\AttachExpenseReceiptUseCase;
use Src\Fleet\Expense\UseCases\LiquidateExpensesUseCase;
use Src\Fleet\Expense\UseCases\RegisterExpenseUseCase;
use Src\Fleet\Route\Models\FleetRoute;
use Src\Fleet\Route\Services\FleetEvidenceStore;
use Src\Shared\Exceptions\BusinessRuleException;
use Src\Shared\Scope\AgencyScope;

/**
 * @group Fleet - Expenses
 *
 * Viáticos y combustible de las rutas, con su comprobante y liquidación.
 */
class ExpenseController extends Controller
{
    /**
     * List expenses
     *
     * @queryParam route_id integer Filter by route. Example: 1
     * @queryParam type string `per_diem` or `fuel`. Example: fuel
     * @queryParam status string `registered`, `liquidated` or `rejected`. Example: registered
     * @queryParam per_page integer Rows per page (1..100). Example: 15
     *
     * @apiResourceCollection Src\Fleet\Expense\Resources\ExpenseResource
     *
     * @apiResourceModel Src\Fleet\Expense\Models\Expense paginate=15
     *
     * @apiResourceAdditional success=true message="Expenses retrieved successfully."
     */
    public function index(ListExpensesRequest $request, AgencyScope $scope): JsonResponse
    {
        $query = Expense::query()
            ->whereIn('route_id', FleetRoute::query()->visibleTo($scope, (int) auth()->id())->select('id'))
            ->orderByDesc('id');

        foreach (['route_id', 'type', 'status'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        return $this->successPaginated(
            resource: ExpenseResource::collection($query->paginate($request->integer('per_page', 15))),
            message: 'Expenses retrieved successfully.',
        );
    }

    /**
     * Register an expense
     *
     * Fuel requires the receipt and the liters. A per diem can add its receipt
     * later, but cannot be liquidated without it.
     *
     * @bodyParam route_id integer required Route. Example: 1
     * @bodyParam type string required `per_diem` or `fuel`. Example: fuel
     * @bodyParam amount number required Amount in PEN. Example: 380
     * @bodyParam liters number optional Liters (fuel). Example: 95
     * @bodyParam odometer_km number optional Odometer reading. Example: 153600
     * @bodyParam idempotency_key string optional Tolerates offline retries. Example: 5f0c1a2b-3c4d-4e5f-8a9b-0c1d2e3f4a5b
     * @bodyParam receipt file optional Receipt (jpg, png or pdf, up to 5 MB).
     *
     * @apiResource Src\Fleet\Expense\Resources\ExpenseResource
     *
     * @apiResourceModel Src\Fleet\Expense\Models\Expense
     */
    public function store(StoreExpenseRequest $request, RegisterExpenseUseCase $useCase, AgencyScope $scope): JsonResponse
    {
        $expense = $useCase->execute($request->safe()->except('receipt'), $request->file('receipt'), $scope);

        return $this->success(new ExpenseResource($expense), 'Expense registered successfully.', 201);
    }

    /**
     * Attach a receipt to an expense
     *
     * @urlParam expense integer required Expense id. Example: 1
     *
     * @bodyParam receipt file required Receipt (jpg, png or pdf, up to 5 MB).
     *
     * @apiResource Src\Fleet\Expense\Resources\ExpenseResource
     *
     * @apiResourceModel Src\Fleet\Expense\Models\Expense
     */
    public function attachReceipt(int $expense, AttachReceiptRequest $request, AttachExpenseReceiptUseCase $useCase, AgencyScope $scope): JsonResponse
    {
        $model = $useCase->execute($expense, $request->file('receipt'), $scope);

        return $this->success(new ExpenseResource($model), 'Receipt attached successfully.');
    }

    /**
     * Liquidate or reject expenses
     *
     * @bodyParam expense_ids integer[] required Expenses of closed routes. Example: [1, 2]
     * @bodyParam decision string required `liquidated` or `rejected`. Example: liquidated
     * @bodyParam reason string optional Required to reject. Example: Receipt is illegible
     *
     * @apiResourceCollection Src\Fleet\Expense\Resources\ExpenseResource
     *
     * @apiResourceModel Src\Fleet\Expense\Models\Expense
     *
     * @apiResourceAdditional success=true message="Expenses reviewed successfully."
     */
    public function liquidate(LiquidateExpensesRequest $request, LiquidateExpensesUseCase $useCase, AgencyScope $scope): JsonResponse
    {
        $data = $request->validated();
        $expenses = $useCase->execute($data['expense_ids'], $data['decision'], $data['reason'] ?? null, $scope);

        return $this->success(ExpenseResource::collection($expenses), 'Expenses reviewed successfully.');
    }

    /**
     * Download the receipt of an expense
     *
     * @urlParam expense integer required Expense id. Example: 1
     */
    public function downloadReceipt(int $expense, AgencyScope $scope, FleetEvidenceStore $evidence): mixed
    {
        $path = Expense::query()
            ->whereIn('route_id', FleetRoute::query()->visibleTo($scope, (int) auth()->id())->select('id'))
            ->whereKey($expense)
            ->value('receipt_path');

        if (! $evidence->exists($path)) {
            throw new BusinessRuleException(
                message: 'The receipt is not available.',
                errorCode: 'FLEET_EVIDENCE_NOT_FOUND',
                statusCode: 404,
            );
        }

        return $evidence->download($path);
    }
}
