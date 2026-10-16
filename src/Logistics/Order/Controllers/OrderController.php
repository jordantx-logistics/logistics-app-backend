<?php

namespace Src\Logistics\Order\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Src\Logistics\Order\Requests\CreateOrderRequest;
use Src\Logistics\Order\Resources\OrderResource;
use Src\Logistics\Order\UseCases\CreateOrderUseCase;

/**
 * @group Logistics - Orders
 *
 * APIs for managing core logistic orders.
 */
class OrderController extends Controller
{
    /**
     * Create Logistics Order
     *
     * Registers a new order in the system, taking into account idempotency, customer and agency validations, and applies dynamic tariff pricing.
     * Fires a background event when successful.
     *
     * @apiResource Src\Logistics\Order\Resources\OrderResource
     *
     * @apiResourceModel Src\Logistics\Order\Models\Order
     *
     * @apiResourceAdditional success=true message="Order created successfully."
     */
    public function store(
        CreateOrderRequest $request,
        CreateOrderUseCase $useCase,
    ): JsonResponse {
        $order = $useCase->execute($request->validated());

        return $this->success(
            data: OrderResource::make($order),
            message: 'Order created successfully.',
            statusCode: 201
        );
    }
}
