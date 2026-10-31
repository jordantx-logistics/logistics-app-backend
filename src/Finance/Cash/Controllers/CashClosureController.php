<?php

namespace Src\Finance\Cash\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Src\Finance\Cash\Models\CashClosure;
use Src\Finance\Cash\Requests\RejectCashClosureRequest;
use Src\Finance\Cash\Resources\CashClosureResource;
use Src\Finance\Cash\UseCases\ApproveCashClosureUseCase;
use Src\Finance\Cash\UseCases\RejectCashClosureUseCase;
use Src\Shared\Exceptions\BusinessRuleException;
use Src\Shared\Scope\AgencyScope;

/**
 * @group Finance - Cash closures
 *
 * Aprobación o rechazo de cierres con descuadre y descarga del reporte PDF.
 */
class CashClosureController extends Controller
{
    /**
     * List cash closures
     *
     * @queryParam status string `pending`, `approved` or `rejected`. Example: pending
     * @queryParam agency integer Filter by agency. Example: 1
     * @queryParam per_page integer Rows per page (1..100). Example: 15
     *
     * @apiResourceCollection Src\Finance\Cash\Resources\CashClosureResource
     *
     * @apiResourceModel Src\Finance\Cash\Models\CashClosure paginate=15
     *
     * @apiResourceAdditional success=true message="Cash closures retrieved successfully."
     */
    public function index(Request $request, AgencyScope $scope): JsonResponse
    {
        $query = CashClosure::query()
            ->with('cashSession')
            ->whereHas('cashSession', fn ($query) => $query->forAgencyScope($scope))
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', (string) $request->input('status'));
        }

        if ($request->filled('agency')) {
            $query->whereHas('cashSession', fn ($query) => $query->where('agency_id', $request->integer('agency')));
        }

        return $this->successPaginated(
            resource: CashClosureResource::collection($query->paginate(min(max($request->integer('per_page', 15), 1), 100))),
            message: 'Cash closures retrieved successfully.',
        );
    }

    /**
     * Approve a cash closure
     *
     * @urlParam closure integer required Cash closure id. Example: 1
     *
     * @apiResource Src\Finance\Cash\Resources\CashClosureResource
     *
     * @apiResourceModel Src\Finance\Cash\Models\CashClosure
     */
    public function approve(int $closure, ApproveCashClosureUseCase $useCase, AgencyScope $scope): JsonResponse
    {
        $model = $useCase->execute($closure, $scope);

        return $this->success(new CashClosureResource($model), 'Cash closure approved successfully.');
    }

    /**
     * Reject a cash closure
     *
     * @urlParam closure integer required Cash closure id. Example: 1
     *
     * @bodyParam reason string required Why the closure is rejected. Example: The count was incomplete.
     *
     * @apiResource Src\Finance\Cash\Resources\CashClosureResource
     *
     * @apiResourceModel Src\Finance\Cash\Models\CashClosure
     */
    public function reject(
        int $closure,
        RejectCashClosureRequest $request,
        RejectCashClosureUseCase $useCase,
        AgencyScope $scope,
    ): JsonResponse {
        $model = $useCase->execute($closure, (string) $request->validated('reason'), $scope);

        return $this->success(new CashClosureResource($model), 'Cash closure rejected successfully.');
    }

    /**
     * Download the cash closure report
     *
     * @urlParam closure integer required Cash closure id. Example: 1
     */
    public function report(int $closure, AgencyScope $scope): mixed
    {
        $model = CashClosure::query()
            ->with('cashSession')
            ->whereHas('cashSession', fn ($query) => $query->forAgencyScope($scope))
            ->find($closure);

        if ($model === null) {
            throw new BusinessRuleException(
                message: 'The cash closure does not exist.',
                errorCode: 'CASH_CLOSURE_NOT_FOUND',
                statusCode: 404,
            );
        }

        if ($model->report_path === null || ! Storage::disk((string) config('finance.disk', 'local'))->exists($model->report_path)) {
            throw new BusinessRuleException(
                message: 'The cash closure report is not available yet.',
                errorCode: 'CASH_REPORT_NOT_READY',
                statusCode: 409,
            );
        }

        return Storage::disk((string) config('finance.disk', 'local'))->download($model->report_path);
    }
}
