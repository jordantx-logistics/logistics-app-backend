<?php

namespace Src\Finance\Payment\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Src\Finance\Payment\Models\BillingOrder;
use Src\Finance\Payment\Models\Payment;
use Src\Finance\Payment\Requests\ListPaymentsRequest;
use Src\Finance\Payment\Requests\RegisterPaymentRequest;
use Src\Finance\Payment\Resources\PaymentResource;
use Src\Finance\Payment\UseCases\RegisterPaymentUseCase;
use Src\Shared\Contracts\PaymentStatusQuery;
use Src\Shared\Exceptions\BusinessRuleException;
use Src\Shared\Scope\AgencyScope;

/**
 * @group Finance - Payments
 *
 * Registro de cobros totales o parciales y consulta de saldo por orden.
 */
class PaymentController extends Controller
{
    /**
     * List payments
     *
     * @queryParam order_id integer Filter by order. Example: 42
     * @queryParam status string One status or a comma-separated list. Example: registered
     * @queryParam payment_method string `cash` or `pos`. Example: cash
     * @queryParam agency integer Filter by agency. Example: 1
     * @queryParam ticket_number string Ticket number prefix. Example: PAY-2026
     * @queryParam created_from date Filter from this date. Example: 2026-10-01
     * @queryParam created_to date Filter up to this date. Example: 2026-10-31
     * @queryParam per_page integer Rows per page (1..100). Example: 15
     *
     * @apiResourceCollection Src\Finance\Payment\Resources\PaymentResource
     *
     * @apiResourceModel Src\Finance\Payment\Models\Payment paginate=15
     *
     * @apiResourceAdditional success=true message="Payments retrieved successfully."
     */
    public function index(ListPaymentsRequest $request, AgencyScope $scope): JsonResponse
    {
        $query = Payment::query()
            ->with(['currentInvoice', 'creditNotes', 'voids'])
            ->forAgencyScope($scope)
            ->orderByDesc('id');

        if ($request->filled('order_id')) {
            $query->where('order_id', $request->integer('order_id'));
        }

        if ($request->filled('status')) {
            $statuses = array_values(array_filter(array_map('trim', explode(',', (string) $request->input('status')))));
            $query->whereIn('status', $statuses);
        }

        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->input('payment_method'));
        }

        if ($request->filled('agency')) {
            $query->where('agency_id', $request->integer('agency'));
        }

        if ($request->filled('ticket_number')) {
            $query->where('ticket_number', 'like', strtoupper((string) $request->input('ticket_number')).'%');
        }

        if ($request->filled('created_from')) {
            $query->where('created_at', '>=', $request->input('created_from'));
        }

        if ($request->filled('created_to')) {
            $query->where('created_at', '<=', $request->input('created_to').' 23:59:59');
        }

        return $this->successPaginated(
            resource: PaymentResource::collection($query->paginate($request->integer('per_page', 15))),
            message: 'Payments retrieved successfully.',
        );
    }

    /**
     * Register a payment
     *
     * @bodyParam order_id integer required Order to charge. Example: 42
     * @bodyParam amount number required Payment amount (partial payments allowed). Example: 25.90
     * @bodyParam currency string optional ISO currency. Example: PEN
     * @bodyParam payment_method string required `cash` or `pos`. Example: cash
     * @bodyParam document_type string optional `boleta` or `factura`. Example: boleta
     * @bodyParam idempotency_key string required Client idempotency key. Example: c0a8012e-1f4b-4b1e-9c1a-1e2f3a4b5c6d
     * @bodyParam reference string optional Free-text reference (POS operation, voucher). Example: POS-8891
     *
     * @apiResource Src\Finance\Payment\Resources\PaymentResource
     *
     * @apiResourceModel Src\Finance\Payment\Models\Payment
     */
    public function store(
        RegisterPaymentRequest $request,
        RegisterPaymentUseCase $useCase,
        AgencyScope $scope,
    ): JsonResponse {
        $payment = $useCase->execute($request->validated(), $scope);

        return $this->success(new PaymentResource($payment), 'Payment registered successfully.', 201);
    }

    /**
     * Show a payment
     *
     * @urlParam payment integer required Payment id. Example: 1
     *
     * @apiResource Src\Finance\Payment\Resources\PaymentResource
     *
     * @apiResourceModel Src\Finance\Payment\Models\Payment
     */
    public function show(int $payment, AgencyScope $scope): JsonResponse
    {
        $model = Payment::query()
            ->with(['currentInvoice', 'creditNotes', 'voids'])
            ->forAgencyScope($scope)
            ->find($payment);

        if ($model === null) {
            throw new BusinessRuleException(
                message: 'The payment does not exist.',
                errorCode: 'PAYMENT_NOT_FOUND',
                statusCode: 404,
            );
        }

        return $this->success(new PaymentResource($model), 'Payment retrieved successfully.');
    }

    /**
     * Payment status of an order
     *
     * @urlParam order integer required Order id. Example: 42
     */
    public function status(int $order, PaymentStatusQuery $status, AgencyScope $scope): JsonResponse
    {
        $billing = BillingOrder::query()->forAgencyScope($scope)->where('order_id', $order)->first();

        if ($billing === null) {
            throw new BusinessRuleException(
                message: 'The order is not projected for billing yet.',
                errorCode: 'BILLING_ORDER_NOT_FOUND',
                statusCode: 404,
            );
        }

        return $this->success([
            'order_id' => $order,
            'amount_due' => $billing->amount_due,
            'currency' => $billing->currency,
            'paid_amount' => $status->paidAmountOf($order)->toDecimal(),
            'balance' => $status->balanceOf($order)->toDecimal(),
            'is_paid' => $status->isPaid($order),
            'payment_status' => $billing->payment_status,
        ], 'Payment status retrieved successfully.');
    }
}
