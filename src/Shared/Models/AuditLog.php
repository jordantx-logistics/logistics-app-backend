<?php

namespace Src\Shared\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Bitácora de operaciones sensibles (anulaciones, cierres con descuadre,
 * notas de crédito y cambios de series).
 *
 * Vive en `Shared` porque varios contextos la escriben; no es una tabla de
 * negocio de ningún agregado. Se guarda `before`/`after` en JSON para que la
 * auditoría sea útil aunque el esquema cambie.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'actor_id',
        'action',
        'entity_type',
        'entity_id',
        'before',
        'after',
        'ip',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'entity_id' => 'integer',
            'actor_id' => 'integer',
        ];
    }
}
