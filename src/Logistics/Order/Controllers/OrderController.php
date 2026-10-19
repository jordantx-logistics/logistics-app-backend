<?php

namespace Src\Logistics\Order\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Src\Logistics\Order\Requests\CreateOrderRequest;
use Src\Logistics\Order\Resources\OrderResource;
use Src\Logistics\Order\UseCases\CreateOrderUseCase;
use Src\Logistics\Order\UseCases\ListOrdersUseCase;

/**
 * @group Logistics - Orders
 *
 * APIs for managing core logistic orders.
 */
class OrderController extends Controller
{
    /**
     * List Logistics Orders
     *
     * Returns the orders visible to the authenticated user, newest first, paginated with `page` and `per_page` (max 100).
     *
     * @queryParam page integer Page to return. Example: 2
     * @queryParam per_page integer Rows per page (1..100, por defecto 15). Example: 15
     *
     * @apiResourceCollection Src\Logistics\Order\Resources\OrderResource
     *
     * @apiResourceModel Src\Logistics\Order\Models\Order paginate=15
     *
     * @apiResourceAdditional success=true message="Orders retrieved successfully."
     */
    public function index(Request $request, ListOrdersUseCase $useCase): JsonResponse
    {
        $perPage = min(
            max($request->integer('per_page', ListOrdersUseCase::DEFAULT_PER_PAGE), 1),
            ListOrdersUseCase::MAX_PER_PAGE,
        );

        // El ámbito de agencia lo resuelve el middleware `ResolveAgencyScope`
        // (grupo `api`) y lo recibe el caso de uso por constructor.
        $orders = $useCase->execute($perPage);

        // El recurso envuelve el paginador: `successPaginated` solo añade el
        // sobre, porque `data`/`meta`/`links` los emite Laravel (y así lo
        // documenta Scribe con el `paginate=15` del docblock).
        return $this->successPaginated(
            resource: OrderResource::collection($orders),
            message: 'Orders retrieved successfully.',
        );
    }

    /**
     * Create Logistics Order
     *
     * Registers a new order in the system, taking into account idempotency, customer and agency validations, and applies dynamic tariff pricing.
     * Fires a background event when successful.
     *
     * @apiResource Src\Logistics\Order\Resources\OrderResource status=201
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
