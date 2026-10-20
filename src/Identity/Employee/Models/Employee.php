<?php

namespace Src\Identity\Employee\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Src\Shared\Traits\CanBeScopedByAgency;

/**
 * Empleado de RRHH (`spec/04-modulos/identity/02-entidades.md`).
 *
 * Separa *empleado* (persona que trabaja) de *usuario de sistema*: el vínculo
 * opcional vive en `users.employee_id` (una persona puede tener usuario y un
 * usuario puede no representar a ningún empleado).
 *
 * `person_id` (Core) y `agency_id` (Organization) son columnas indexadas sin FK
 * y sin relación Eloquent: son de otros contextos y se validan por puerto
 * (ADR-0006). `Identity/Employee` tampoco importa `Identity/Attendance` —los
 * sub-dominios de Identity son contextos de pleno derecho—, así que aquí no hay
 * relación hacia las marcaciones.
 */
class Employee extends Model
{
    use CanBeScopedByAgency, HasFactory;

    /** Persona en alta, puede marcar. */
    public const STATUS_ACTIVE = 'active';

    /** De permiso/licencia: sigue vinculado, no marca. */
    public const STATUS_ON_LEAVE = 'on_leave';

    /** Baja definitiva: no puede registrar marcaciones. */
    public const STATUS_TERMINATED = 'terminated';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_ON_LEAVE,
        self::STATUS_TERMINATED,
    ];

    /** @var list<string> */
    public const DOCUMENT_TYPES = ['DNI', 'CE', 'PASSPORT'];

    protected $fillable = [
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

    protected function casts(): array
    {
        return [
            'hired_at' => 'date',
            'terminated_at' => 'date',
        ];
    }

    /**
     * ¿Puede registrar marcaciones? Un empleado `terminated` no puede
     * (invariante de `02-entidades.md`, regla `EMPLOYEE_INACTIVE`).
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
