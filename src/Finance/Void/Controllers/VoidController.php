<?php

namespace Src\Finance\Void\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Src\Finance\Payment\Models\Payment;
use Src\Finance\Void\Models\PaymentVoid;
use Src\Finance\Void\Requests\ListVoidsRequest;
use Src\Finance\Void\Requests\RejectVoidRequest;
use Src\Finance\Void\Requests\RequestVoidRequest;
use Src\Finance\Void\Resources\VoidResource;
use Src\Finance\Void\UseCases\ApproveVoidUseCase;
use Src\Finance\Void\UseCases\RejectVoidUseCase;
use Src\Finance\Void\UseCases\RequestVoidUseCase;
use Src\Shared\Exceptions\BusinessRuleException;
use Src\Shared\Scope\AgencyScope;

/**
 * @group Finance - Voids
 *
 * Anulación de pagos con solicitud y aprobación separadas.
 */
class VoidController extends Controller
{
    /**
     * List void requests
     *
     * @queryParam status string `requested`, `approved` or `rejected`. Example: requested
     * @queryParam payment_id integer Filter by payment. Example: 1
     * @queryParam order_id integer Filter by order. Example: 42
     * @queryParam per_page integer Rows per page (1..100). Example: 15
     *
     * @apiResourceCollection Src\Finance\Void\Resources\VoidResource
     *
     * @apiResourceModel Src\Finance\Void\Models\PaymentVoid paginate=15
     *
     * @apiResourceAdditional success=true message="Voids retrieved successfully."
     */
    public function index(ListVoidsRequest $request, AgencyScope $scope): JsonResponse
    {
        $ids = $scope->unrestricted
            ? null
            : Payment::query()->forAgencyScope($scope)->select('id');

        $query = PaymentVoid::query()->orderByDesc('id');

        if ($ids !== null) {
            $query->whereIn('payment_id', $ids);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('payment_id')) {
            $query->where('payment_id', $request->integer('payment_id'));
        }

        if ($request->filled('order_id')) {
            $query->where('order_id', $request->integer('order_id'));
        }

        return $this->successPaginated(
            resource: VoidResource::collection($query->paginate($request->integer('per_page', 15))),
            message: 'Voids retrieved successfully.',
        );
    }

    /**
     * Request a void
     *
     * @bodyParam payment_id integer required Payment to void. Example: 1
     * @bodyParam reason string required Why the payment is voided. Example: Duplicate charge
     *
     * @apiResource Src\Finance\Void\Resources\VoidResource
     *
     * @apiResourceModel Src\Finance\Void\Models\PaymentVoid
     */
    public function store(
        RequestVoidRequest $request,
        RequestVoidUseCase $useCase,
        AgencyScope $scope,
    ): JsonResponse {
        $void = $useCase->execute($request->validated(), $scope);

        return $this->success(new VoidResource($void), 'Void requested successfully.', 201);
    }

    /**
     * Approve a void
     *
     * @urlParam void integer required Void id. Example: 1
     *
     * @apiResource Src\Finance\Void\Resources\VoidResource
     *
     * @apiResourceModel Src\Finance\Void\Models\PaymentVoid
     */
    public function approve(int $void, ApproveVoidUseCase $useCase, AgencyScope $scope): JsonResponse
    {
        $model = $useCase->execute($void, $scope);

        return $this->success(new VoidResource($model), 'Void approved successfully.');
    }

    /**
     * Reject a void
     *
     * @urlParam void integer required Void id. Example: 1
     *
     * @bodyParam reason string required Why the void is rejected. Example: Payment is valid.
     *
     * @apiResource Src\Finance\Void\Resources\VoidResource
     *
     * @apiResourceModel Src\Finance\Void\Models\PaymentVoid
     */
    public function reject(
        int $void,
        RejectVoidRequest $request,
        RejectVoidUseCase $useCase,
        AgencyScope $scope,
    ): JsonResponse {
        $model = $useCase->execute($void, (string) $request->validated('reason'), $scope);

        return $this->success(new VoidResource($model), 'Void rejected successfully.');
    }

    /**
     * Show a void request
     *
     * @urlParam void integer required Void id. Example: 1
     *
     * @apiResource Src\Finance\Void\Resources\VoidResource
     *
     * @apiResourceModel Src\Finance\Void\Models\PaymentVoid
     */
    public function show(int $void, AgencyScope $scope): JsonResponse
    {
        $model = PaymentVoid::query()->find($void);

        if ($model === null) {
            throw new BusinessRuleException(
                message: 'The void request does not exist.',
                errorCode: 'VOID_NOT_FOUND',
                statusCode: 404,
            );
        }

        $payment = Payment::query()->forAgencyScope($scope)->find($model->payment_id);

        if ($payment === null) {
            throw new BusinessRuleException(
                message: 'The void request does not exist.',
                errorCode: 'VOID_NOT_FOUND',
                statusCode: 404,
            );
        }

        return $this->success(new VoidResource($model), 'Void retrieved successfully.');
    }
}
