<?php

namespace Src\Shared\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Evento de un lote móvil ya aplicado (`SyncEventLedger`).
 */
class SyncEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['event_id', 'event_type', 'user_id', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
