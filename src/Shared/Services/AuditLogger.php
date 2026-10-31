<?php

namespace Src\Shared\Services;

use Illuminate\Support\Facades\Request;
use Src\Shared\Models\AuditLog;

/**
 * Escribe la bitácora de una operación sensible. Se llama dentro de la misma
 * transacción que el cambio de dominio: si la operación falla, la auditoría
 * tampoco queda escrita.
 */
final class AuditLogger
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(
        string $action,
        string $entityType,
        int $entityId,
        ?array $before = null,
        ?array $after = null,
        ?int $actorId = null,
    ): AuditLog {
        return AuditLog::create([
            'actor_id' => $actorId ?? auth()->id(),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before' => $before,
            'after' => $after,
            'ip' => Request::ip(),
            'user_agent' => substr((string) Request::userAgent(), 0, 255),
        ]);
    }
}
