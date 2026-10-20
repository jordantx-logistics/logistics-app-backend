<?php

namespace Src\Customer\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Src\Customer\Models\Customer;
use Src\Customer\Requests\StoreCustomerRequest;
use Src\Customer\Requests\UpdateCustomerRequest;
use Src\Customer\Resources\CustomerResource;
use Src\Customer\UseCases\CreateCustomerUseCase;
use Src\Customer\UseCases\DeactivateCustomerUseCase;
use Src\Customer\UseCases\ListCustomersUseCase;
use Src\Customer\UseCases\UpdateCustomerUseCase;

/**
 * @group Customer - Customers
 *
 * APIs for managing the customer aggregate (sender/receiver of orders).
 */
class CustomerController extends Controller
{
    /**
     * List Customers
     *
     * Returns customers, newest first, paginated with `page` and `per_page` (max 100).
     * `q` searches by display name, document number or email.
     *
     * @queryParam q string Search term (name, document or email). Example: Tech
     * @queryParam customer_type string Filter by type: person|company. Example: person
     * @queryParam is_active boolean Filter by active flag. Example: 1
     * @queryParam page integer Page to return. Example: 2
     * @queryParam per_page integer Rows per page (1..100, default 15). Example: 15
     *
     * @apiResourceCollection Src\Customer\Resources\CustomerResource
     *
     * @apiResourceModel Src\Customer\Models\Customer paginate=15
     *
     * @apiResourceAdditional success=true message="Customers retrieved successfully."
     */
    public function index(Request $request, ListCustomersUseCase $useCase): JsonResponse
    {
        $perPage = min(
            max($request->integer('per_page', ListCustomersUseCase::DEFAULT_PER_PAGE), 1),
            ListCustomersUseCase::MAX_PER_PAGE,
        );

        $customers = $useCase->execute([
            'q' => $request->query('q'),
            'customer_type' => $request->query('customer_type'),
            'is_active' => $request->has('is_active') ? $request->boolean('is_active') : null,
        ], $perPage);

        return $this->successPaginated(
            resource: CustomerResource::collection($customers),
            message: 'Customers retrieved successfully.',
        );
    }

    /**
     * Register Customer
     *
     * Creates the customer aggregate and its backing person or company in one transaction.
     * The document is unique per type and validated (RUC check digit included).
     *
     * @apiResource Src\Customer\Resources\CustomerResource status=201
     *
     * @apiResourceModel Src\Customer\Models\Customer
     *
     * @apiResourceAdditional success=true message="Customer created successfully."
     */
    public function store(StoreCustomerRequest $request, CreateCustomerUseCase $useCase): JsonResponse
    {
        $customer = $useCase->execute($request->validated(), $request->user()?->id);

        return $this->success(
            data: CustomerResource::make($customer),
            message: 'Customer created successfully.',
            statusCode: 201,
        );
    }

    /**
     * Show Customer
     *
     * @apiResource Src\Customer\Resources\CustomerResource
     *
     * @apiResourceModel Src\Customer\Models\Customer
     *
     * @apiResourceAdditional success=true message="Customer retrieved successfully."
     */
    public function show(Customer $customer): JsonResponse
    {
        return $this->success(
            data: CustomerResource::make($customer),
            message: 'Customer retrieved successfully.',
        );
    }

    /**
     * Update Customer
     *
     * Updates contact data and the backing person/company. The document cannot be changed.
     *
     * @apiResource Src\Customer\Resources\CustomerResource
     *
     * @apiResourceModel Src\Customer\Models\Customer
     *
     * @apiResourceAdditional success=true message="Customer updated successfully."
     */
    public function update(
        UpdateCustomerRequest $request,
        Customer $customer,
        UpdateCustomerUseCase $useCase,
    ): JsonResponse {
        return $this->success(
            data: CustomerResource::make($useCase->execute($customer, $request->validated())),
            message: 'Customer updated successfully.',
        );
    }

    /**
     * Deactivate Customer
     *
     * Soft-style deletion: sets `is_active = false`. Rejected with 409 while the
     * customer has pending, confirmed or in-transit orders.
     *
     * @apiResource Src\Customer\Resources\CustomerResource
     *
     * @apiResourceModel Src\Customer\Models\Customer
     *
     * @apiResourceAdditional success=true message="Customer deactivated successfully."
     */
    public function destroy(Customer $customer, DeactivateCustomerUseCase $useCase): JsonResponse
    {
        return $this->success(
            data: CustomerResource::make($useCase->execute($customer)),
            message: 'Customer deactivated successfully.',
        );
    }
}
