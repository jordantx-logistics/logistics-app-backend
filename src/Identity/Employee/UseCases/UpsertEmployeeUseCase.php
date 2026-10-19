<?php

namespace Src\Identity\Employee\UseCases;

use Illuminate\Support\Arr;
use Src\Identity\Employee\Models\Employee;
use Src\Identity\Employee\Rules\ValidateEmployeeAgency;
use Src\Identity\Employee\Rules\ValidateUniqueEmployeeDocument;
use Src\Shared\Pipelines\RulesPipeline;

/**
 * Alta y edición de un empleado (UC-08).
 *
 * Única puerta de entrada: corre las reglas de negocio (`EMPLOYEE_DOCUMENT_TAKEN`,
 * `EMPLOYEE_AGENCY_INVALID`) y persiste. Un solo caso de uso cubre crear y
 * actualizar porque las reglas son las mismas; el `employee_id` que inyecta la
 * edición es lo que evita que el documento choque consigo mismo.
 *
 * No emite `employee.created`/`employee.updated`: el outbox manda a `failed` un
 * `event_type` sin consumidor, y todavía no hay proyección de RRHH que lo
 * escuche (deuda anotada en el cierre de la Fase 2).
 */
class UpsertEmployeeUseCase
{
    /** Campos que el payload puede escribir en el modelo. */
    private const ATTRIBUTES = [
        'person_id',
        'document_type',
        'document_number',
        'first_name',
        'last_name',
        'email',
        'phone',
        'position',
        'agency_id',
        'hired_at',
        'terminated_at',
        'status',
    ];

    public function execute(array $data, ?Employee $employee = null): Employee
    {
        // La edición se marca con su propio id para que la unicidad del
        // documento se compruebe contra los demás, no contra sí mismo.
        $data['employee_id'] = $employee?->id;

        $data = RulesPipeline::run($data, [
            ValidateUniqueEmployeeDocument::class,
            ValidateEmployeeAgency::class,
        ]);

        $attributes = Arr::only($data, self::ATTRIBUTES);

        if ($employee !== null) {
            $employee->update($attributes);

            return $employee;
        }

        $attributes['status'] ??= Employee::STATUS_ACTIVE;

        return Employee::create($attributes);
    }
}
