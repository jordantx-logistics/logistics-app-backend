<?php

namespace Src\Operations\Inventory\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Fila del listado de conteos.
 *
 * No incluye las líneas ni el resumen: el listado solo pinta el estado y el
 * número de líneas. El detalle sí carga `items` y lo sirve `InventoryCountResource`.
 */
class InventoryCountListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'agency_id' => $this->agency_id,
            'status' => $this->status,
            'counted_by' => $this->counted_by,
            'finished_at' => $this->finished_at?->toIso8601String(),
            'items_count' => $this->items_count ?? 0,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
