<?php

namespace Src\Customer\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Src\Customer\Models\Company;
use Src\Customer\Resources\CompanyResource;

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
     * Creates a new company record, typically requiring RUC formatting and business names.
     *
     * @apiResource Src\Customer\Resources\CompanyResource
     *
     * @apiResourceModel Src\Customer\Models\Company
     *
     * @apiResourceAdditional success=true message="Company created successfully."
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tax_id' => 'required|string|max:50|unique:companies,tax_id',
            'business_name' => 'required|string|max:200',
            'trade_name' => 'nullable|string|max:200',
            'address' => 'nullable|string',
            'contact_email' => 'nullable|email',
            'contact_phone' => 'nullable|string|max:20',
        ]);

        $company = Company::create($validated);

        return $this->success(
            data: CompanyResource::make($company),
            message: 'Company created successfully.',
            statusCode: 201
        );
    }
}
