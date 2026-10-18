<?php

namespace Src\Customer\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Src\Customer\Models\Person;
use Src\Customer\Resources\PersonResource;

/**
 * @group Customer - Persons
 *
 * APIs for managing individual customers.
 */
class PersonController extends Controller
{
    /**
     * List Individual Customers
     *
     * Retrieves a list of all registered individual clients.
     *
     * @apiResourceCollection Src\Customer\Resources\PersonResource
     *
     * @apiResourceModel Src\Customer\Models\Person
     *
     * @apiResourceAdditional success=true message="Persons retrieved successfully."
     */
    public function index(): JsonResponse
    {
        return $this->success(
            data: PersonResource::collection(Person::query()->orderBy('last_name')->get()),
            message: 'Persons retrieved successfully.'
        );
    }

    /**
     * Register Individual Customer
     *
     * Creates a new person record in the database using their personal document (e.g. DNI).
     *
     * @apiResource Src\Customer\Resources\PersonResource status=201
     *
     * @apiResourceModel Src\Customer\Models\Person
     *
     * @apiResourceAdditional success=true message="Person created successfully."
     */
    public function store(Request $request): JsonResponse
    {
        // Keeping validation simple inside controller for this phase
        $validated = $request->validate([
            'document_type' => 'required|string|max:20',
            'document_number' => 'required|string|max:50|unique:persons,document_number',
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'email' => 'nullable|email',
            'phone' => 'nullable|string|max:20',
        ]);

        $person = Person::create($validated);

        return $this->success(
            data: PersonResource::make($person),
            message: 'Person created successfully.',
            statusCode: 201
        );
    }
}
