<?php

namespace Src\Customer\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Src\Customer\Models\Company;
use Src\Customer\Models\Customer;
use Src\Customer\Resources\CompanyResource;
use Src\Customer\UseCases\CreateCustomerUseCase;

/**
 * @group Customer - Companies
 *
 * APIs for managing corporate customers.
 */
class CompanyController extends Controller
{
    /**
     * List Corporate Customers
     *
     * Retrieves a list of all registered corporate clients.
     *
     * @apiResourceCollection Src\Customer\Resources\CompanyResource
     *
     * @apiResourceModel Src\Customer\Models\Company
     *
     * @apiResourceAdditional success=true message="Companies retrieved successfully."
     */
    public function index(): JsonResponse
    {
        return $this->success(
            data: CompanyResource::collection(Company::query()->orderBy('business_name')->get()),
            message: 'Companies retrieved successfully.'
        );
    }

    /**
     * Register Corporate Customer
     *
     * Creates a new company **and** its customer aggregate (`customers`), so it can be used
     * as sender/receiver of an order. The RUC is validated (11 digits and check digit)
     * in the use case, which answers with an `error_code`.
     *
     * @apiResource Src\Customer\Resources\CompanyResource status=201
     *
     * @apiResourceModel Src\Customer\Models\Company
     *
     * @apiResourceAdditional success=true message="Company created successfully."
     */
    public function store(Request $request, CreateCustomerUseCase $useCase): JsonResponse
    {
        $validated = $request->validate([
            'tax_id' => 'required|string|max:50',
            'business_name' => 'required|string|max:200',
            'trade_name' => 'nullable|string|max:200',
            'address' => 'nullable|string',
            'contact_email' => 'nullable|email',
            'contact_phone' => 'nullable|string|max:20',
        ]);

        $customer = $useCase->execute([
            ...$validated,
            'customer_type' => Customer::TYPE_COMPANY,
            'document_type' => 'RUC',
            'document_number' => $validated['tax_id'],
        ], $request->user()?->id);

        return $this->success(
            data: CompanyResource::make($customer->company),
            message: 'Company created successfully.',
            statusCode: 201
        );
    }
}
