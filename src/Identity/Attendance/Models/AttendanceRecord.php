<?php

namespace Src\Identity\Attendance\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Src\Shared\Traits\CanBeScopedByAgency;

/**
 * Marcación de asistencia (`spec/04-modulos/identity/02-entidades.md`).
 *
 * Tabla append-only salvo la anulación (`status = voided`). La idempotencia es
 * por `idempotency_key` único: repetir una marcación offline (misma clave) no
 * duplica. `agency_id` es una columna indexada sin FK (`agencies` es de
 * Organization, ADR-0006).
 */
class AttendanceRecord extends Model
{
    use CanBeScopedByAgency, HasFactory;

    public const TYPE_IN = 'in';

    public const TYPE_OUT = 'out';

    public const TYPE_BREAK_START = 'break-start';

    public const TYPE_BREAK_END = 'break-end';

    /** @var list<string> */
    public const TYPES = [
        self::TYPE_IN,
        self::TYPE_OUT,
        self::TYPE_BREAK_START,
        self::TYPE_BREAK_END,
    ];

    /** @var list<string> */
    public const SOURCES = ['web', 'app', 'pda', 'biometric'];

    public const STATUS_VALID = 'valid';

    public const STATUS_LATE = 'late';

    public const STATUS_DUPLICATE = 'duplicate';

    public const STATUS_VOIDED = 'voided';

    protected $fillable = [
        'employee_id',
        'agency_id',
        'type',
        'marked_at',
        'source',
        'device_id',
        'latitude',
        'longitude',
        'ip_address',
        'status',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'marked_at' => 'datetime',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }
}
