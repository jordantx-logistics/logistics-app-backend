<?php

namespace Src\Logistics\Order\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Src\Logistics\Order\Models\Order;
use Src\Logistics\Order\Requests\CancelOrderRequest;
use Src\Logistics\Order\Requests\CreateOrderRequest;
use Src\Logistics\Order\Requests\ListOrdersRequest;
use Src\Logistics\Order\Requests\QuoteOrderRequest;
use Src\Logistics\Order\Requests\UpdateDeliveryRequest;
use Src\Logistics\Order\Requests\UpdateOrderRequest;
use Src\Logistics\Order\Resources\OrderResource;
use Src\Logistics\Order\Services\Pricing\PricingResult;
use Src\Logistics\Order\UseCases\CancelOrderUseCase;
use Src\Logistics\Order\UseCases\ConfirmOrderUseCase;
use Src\Logistics\Order\UseCases\CreateOrderUseCase;
use Src\Logistics\Order\UseCases\ListOrdersUseCase;
use Src\Logistics\Order\UseCases\QuoteOrderUseCase;
use Src\Logistics\Order\UseCases\UpdateOrderDeliveryUseCase;
use Src\Logistics\Order\UseCases\UpdateOrderUseCase;
use Src\Shared\Scope\AgencyScope;

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
     * Returns the orders visible to the authenticated user, newest first, paginated with `page` and `per_page` (max 100). All filters are cumulative and never widen the agency scope.
     *
     * @queryParam page integer Page to return. Example: 2
     * @queryParam per_page integer Rows per page (1..100, por defecto 15). Example: 15
     * @queryParam status string One status or a comma-separated list (`pending,confirmed`). Example: pending
     * @queryParam origin_agency_id integer Filter by origin agency. Example: 42
     * @queryParam destination_agency_id integer Filter by destination agency. Example: 51
     * @queryParam agency integer Filter orders that touch this agency (origin or destination). Example: 42
     * @queryParam created_from date Filter orders created on or after this date (ISO 8601). Example: 2026-10-01
     * @queryParam created_to date Filter orders created on or before this date (ISO 8601). Example: 2026-10-31
     * @queryParam sender_id integer Filter by sender customer id. Example: 10
     * @queryParam receiver_id integer Filter by receiver customer id. Example: 11
     * @queryParam sender_document string Filter by sender document number. Example: 12345678
     * @queryParam sender_document_type string Sender document type (`DNI`, `RUC`, `CE`, `PASSPORT`). Example: DNI
     * @queryParam receiver_document string Filter by receiver document number. Example: 20123456789
     * @queryParam receiver_document_type string Receiver document type (`DNI`, `RUC`, `CE`, `PASSPORT`). Example: RUC
     * @queryParam ticket_code string Exact 8-char ticket code. Example: A7F3Q8X4
     * @queryParam ticket_number string Exact or prefix ticket number. Example: ORD-2026
     * @queryParam ticket string Exact ticket code or ticket number prefix (shortcut). Example: ORD-2026
     *
     * @apiResourceCollection Src\Logistics\Order\Resources\OrderResource
     *
     * @apiResourceModel Src\Logistics\Order\Models\Order paginate=15
     *
     * @apiResourceAdditional success=true message="Orders retrieved successfully."
     */
    public function index(ListOrdersRequest $request, ListOrdersUseCase $useCase): JsonResponse
    {
        $perPage = min(
            max($request->integer('per_page', ListOrdersUseCase::DEFAULT_PER_PAGE), 1),
            ListOrdersUseCase::MAX_PER_PAGE,
        );

        // El ámbito de agencia lo resuelve el middleware `ResolveAgencyScope`
        // (grupo `api`) y lo recibe el caso de uso por constructor.
        $orders = $useCase->execute($request->safe()->except('per_page'), $perPage);

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
     * @bodyParam service_type string standard,express. Example: standard
     * @bodyParam payment_responsibility string sender,receiver. Example: sender
     * @bodyParam delivery_type string agency_pickup,home_delivery. Example: agency_pickup
     * @bodyParam delivery_location_id integer required when delivery_type=home_delivery. Example: 1501
     * @bodyParam recipient_address string Delivery address. Example: Av. Los Alamos 123
     * @bodyParam delivery_scheduled_at string Delivery window (ISO 8601). Example: 2026-10-16T09:00:00-05:00
     * @bodyParam requires_signature boolean Example: true
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

    /**
     * Quote Logistics Order
     *
     * Calculates the price without persisting anything, using the same pricing engine and rules as order creation.
     *
     * @bodyParam origin_agency_id integer required Example: 42
     * @bodyParam destination_agency_id integer required Example: 51
     * @bodyParam weight_kg number required Example: 12.5
     * @bodyParam volume_m3 number Example: 0.08
     * @bodyParam service_type string standard,express. Example: standard
     * @bodyParam delivery_type string agency_pickup,home_delivery. Example: home_delivery
     * @bodyParam delivery_location_id integer required when delivery_type=home_delivery. Example: 1501
     *
     * @response 200 scenario="Cotizacion con tarifario" {"success": true, "message": "Order quoted successfully.", "data": {"engine": "tariff", "chargeable_weight_kg": 16, "base_price": 5, "weight_amount": 40, "minimum_adjustment": 0, "surcharge": 8, "total_amount": 53, "tariff_code": "LIM-NOR-2026", "breakdown": {"engine": "tariff", "total_amount": 53}}}
     */
    public function quote(QuoteOrderRequest $request, QuoteOrderUseCase $useCase): JsonResponse
    {
        return $this->success(
            data: $this->pricingPayload($useCase->execute($request->validated())),
            message: 'Order quoted successfully.',
        );
    }

    /**
     * Show Logistics Order
     *
     * @apiResource Src\Logistics\Order\Resources\OrderResource
     *
     * @apiResourceModel Src\Logistics\Order\Models\Order
     *
     * @apiResourceAdditional success=true message="Order retrieved successfully."
     */
    public function show(int $order, AgencyScope $scope): JsonResponse
    {
        return $this->success(
            data: OrderResource::make($this->findOrder($order, $scope)),
            message: 'Order retrieved successfully.',
        );
    }

    /**
     * Show Logistics Order by ticket code
     *
     * @apiResource Src\Logistics\Order\Resources\OrderResource
     *
     * @apiResourceModel Src\Logistics\Order\Models\Order
     *
     * @apiResourceAdditional success=true message="Order retrieved successfully."
     */
    public function byTicket(string $ticketCode, AgencyScope $scope): JsonResponse
    {
        $order = Order::query()
            ->forAgencyScope($scope)
            ->with('statusHistory')
            ->where('ticket_code', $ticketCode)
            ->firstOrFail();

        return $this->success(
            data: OrderResource::make($order),
            message: 'Order retrieved successfully.',
        );
    }

    /**
     * Confirm Logistics Order
     *
     * Confirms a pending order (`pending` -> `confirmed`) and freezes its price. Rejects any other transition with `INVALID_STATUS_TRANSITION`.
     *
     * @apiResource Src\Logistics\Order\Resources\OrderResource
     *
     * @apiResourceModel Src\Logistics\Order\Models\Order
     *
     * @apiResourceAdditional success=true message="Order confirmed successfully."
     */
    public function confirm(int $order, AgencyScope $scope, ConfirmOrderUseCase $useCase): JsonResponse
    {
        $confirmed = $useCase->execute($this->findOrder($order, $scope));

        return $this->success(
            data: OrderResource::make($confirmed->load('statusHistory')),
            message: 'Order confirmed successfully.',
        );
    }

    /**
     * Cancel Logistics Order
     *
     * Cancels a pending or confirmed order. Only `pending` and `confirmed` orders can be cancelled.
     *
     * @bodyParam reason string Cancellation reason. Example: Customer request
     *
     * @apiResource Src\Logistics\Order\Resources\OrderResource
     *
     * @apiResourceModel Src\Logistics\Order\Models\Order
     *
     * @apiResourceAdditional success=true message="Order cancelled successfully."
     */
    public function cancel(int $order, CancelOrderRequest $request, AgencyScope $scope, CancelOrderUseCase $useCase): JsonResponse
    {
        $cancelled = $useCase->execute($this->findOrder($order, $scope), $request->validated('reason'));

        return $this->success(
            data: OrderResource::make($cancelled->load('statusHistory')),
            message: 'Order cancelled successfully.',
        );
    }

    /**
     * Update Logistics Order
     *
     * Edits shipping and delivery data while the order is `pending` or `confirmed`. It cannot change sender, receiver, agencies, service or tariff. Weight, volume or delivery changes re-quote the order; identity changes after departure are rejected with `ORDER_NOT_EDITABLE`.
     *
     * @bodyParam description string Order description. Example: Fragile documents
     * @bodyParam weight_kg number Weight in kilograms. Example: 12.5
     * @bodyParam volume_m3 number Volume in cubic meters. Example: 0.08
     * @bodyParam declared_value number Declared value. Example: 150.00
     * @bodyParam delivery_type string agency_pickup,home_delivery. Example: home_delivery
     * @bodyParam delivery_location_id integer required when delivery_type=home_delivery. Example: 1501
     * @bodyParam recipient_address string required when delivery_type=home_delivery. Example: Av. Arequipa 123
     * @bodyParam delivery_scheduled_at date Delivery date (ISO 8601). Example: 2026-10-25
     * @bodyParam requires_signature boolean Whether the delivery requires a signature. Example: true
     *
     * @apiResource Src\Logistics\Order\Resources\OrderResource
     *
     * @apiResourceModel Src\Logistics\Order\Models\Order
     *
     * @apiResourceAdditional success=true message="Order updated successfully."
     */
    public function update(
        int $order,
        UpdateOrderRequest $request,
        AgencyScope $scope,
        UpdateOrderUseCase $useCase,
    ): JsonResponse {
        $updated = $useCase->execute($this->findOrder($order, $scope), $request->validated());

        return $this->success(
            data: OrderResource::make($updated),
            message: 'Order updated successfully.',
        );
    }

    /**
     * Update Logistics Order delivery
     *
     * Defines delivery while the order is still `pending` and re-quotes the price (delivery surcharge). Once confirmed, the price is immutable (`ORDER_ALREADY_CONFIRMED`).
     *
     * @apiResource Src\Logistics\Order\Resources\OrderResource
     *
     * @apiResourceModel Src\Logistics\Order\Models\Order
     *
     * @apiResourceAdditional success=true message="Order delivery updated successfully."
     */
    public function updateDelivery(
        int $order,
        UpdateDeliveryRequest $request,
        AgencyScope $scope,
        UpdateOrderDeliveryUseCase $useCase,
    ): JsonResponse {
        $updated = $useCase->execute($this->findOrder($order, $scope), $request->validated());

        return $this->success(
            data: OrderResource::make($updated),
            message: 'Order delivery updated successfully.',
        );
    }

    /**
     * Busca la orden dentro del ámbito del usuario.
     *
     * El route model binding no aplica el ámbito: se consulta explícitamente
     * para que pedir por id una orden de otra agencia responda 404.
     */
    private function findOrder(int $id, AgencyScope $scope): Order
    {
        return Order::query()
            ->forAgencyScope($scope)
            ->with('statusHistory')
            ->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function pricingPayload(PricingResult $result): array
    {
        return [
            'engine' => $result->engine,
            'chargeable_weight_kg' => $result->chargeableWeightKg,
            'base_price' => $result->basePrice,
            'weight_amount' => $result->weightAmount,
            'minimum_adjustment' => $result->minimumAdjustment,
            'surcharge' => $result->surcharge,
            'total_amount' => $result->totalAmount,
            'tariff_id' => $result->tariffId,
            'tariff_code' => $result->tariffCode,
            'breakdown' => $result->breakdown,
        ];
    }
}
