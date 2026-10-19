<?php

namespace Src\Identity\Employee\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Src\Identity\Employee\Models\Employee;
use Src\Identity\Employee\Requests\EmployeeRequest;
use Src\Identity\Employee\Resources\EmployeeResource;
use Src\Identity\Employee\UseCases\DeactivateEmployeeUseCase;
use Src\Identity\Employee\UseCases\ListEmployeesUseCase;
use Src\Identity\Employee\UseCases\UpsertEmployeeUseCase;

/**
 * @group Identity - Employees
 *
 * APIs para gestionar empleados de RRHH (UC-08/UC-09).
 */
class EmployeeController extends Controller
{
    /**
     * List Employees
     *
     * Lista los empleados visibles para el usuario, filtrables por estado, agencia y texto.
     *
     * @queryParam status string active,on_leave,terminated. Example: active
     * @queryParam agency_id integer Agencia base. Example: 42
     * @queryParam q string Busca por documento o nombre. Example: Quispe
     * @queryParam per_page integer Rows per page (1..100, por defecto 15). Example: 15
     *
     * @apiResourceCollection Src\Identity\Employee\Resources\EmployeeResource
     *
     * @apiResourceModel Src\Identity\Employee\Models\Employee paginate=15
     *
     * @apiResourceAdditional success=true message="Employees retrieved successfully."
     */
    public function index(Request $request, ListEmployeesUseCase $useCase): JsonResponse
    {
        $this->authorize('viewAny', Employee::class);

        $perPage = min(
            max($request->integer('per_page', ListEmployeesUseCase::DEFAULT_PER_PAGE), 1),
            ListEmployeesUseCase::MAX_PER_PAGE,
        );

        $employees = $useCase->execute(
            $request->only(['status', 'agency_id', 'q']),
            $perPage,
        );

        return $this->successPaginated(
            resource: EmployeeResource::collection($employees),
            message: 'Employees retrieved successfully.',
        );
    }

    /**
     * Create Employee
     *
     * Da de alta un empleado de RRHH (UC-08).
     *
     * @apiResource Src\Identity\Employee\Resources\EmployeeResource status=201
     *
     * @apiResourceModel Src\Identity\Employee\Models\Employee
     *
     * @apiResourceAdditional success=true message="Employee created successfully."
     */
    public function store(EmployeeRequest $request, UpsertEmployeeUseCase $useCase): JsonResponse
    {
        $this->authorize('create', Employee::class);

        $employee = $useCase->execute($request->validated());

        return $this->success(
            data: EmployeeResource::make($employee),
            message: 'Employee created successfully.',
            statusCode: 201,
        );
    }

    /**
     * Request Employee Details
     *
     * @apiResource Src\Identity\Employee\Resources\EmployeeResource
     *
     * @apiResourceModel Src\Identity\Employee\Models\Employee
     *
     * @apiResourceAdditional success=true message="Employee retrieved successfully."
     */
    public function show(Employee $employee): JsonResponse
    {
        $this->authorize('view', $employee);

        return $this->success(
            data: EmployeeResource::make($employee),
            message: 'Employee retrieved successfully.',
        );
    }

    /**
     * Modify Employee
     *
     * @apiResource Src\Identity\Employee\Resources\EmployeeResource
     *
     * @apiResourceModel Src\Identity\Employee\Models\Employee
     *
     * @apiResourceAdditional success=true message="Employee updated successfully."
     */
    public function update(EmployeeRequest $request, Employee $employee, UpsertEmployeeUseCase $useCase): JsonResponse
    {
        $this->authorize('update', $employee);

        $employee = $useCase->execute($request->validated(), $employee);

        return $this->success(
            data: EmployeeResource::make($employee),
            message: 'Employee updated successfully.',
        );
    }

    /**
     * Deactivate Employee
     *
     * Da de baja al empleado (UC-09): `status = terminated` y desactiva su usuario si lo tuviera.
     *
     * @response 200 {"success": true, "message": "Employee deactivated successfully."}
     */
    public function destroy(Employee $employee, DeactivateEmployeeUseCase $useCase): JsonResponse
    {
        $this->authorize('delete', $employee);

        $useCase->execute($employee);

        return $this->success(message: 'Employee deactivated successfully.');
    }
}
