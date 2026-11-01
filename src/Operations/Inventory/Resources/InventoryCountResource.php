<?php

namespace Src\Operations\Inventory\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryCountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $summary = $this->whenLoaded('items', fn (): array => [
            'expected' => $this->items->where('expected', true)->count(),
            'found' => $this->items->where('found', true)->count(),
            'missing' => $this->items->where('expected', true)->where('found', false)->count(),
            'unexpected' => $this->items->where('expected', false)->where('found', true)->count(),
            'difference' => $this->items->sum('difference'),
        ]);

        return [
            'id' => $this->id,
            'agency_id' => $this->agency_id,
            'status' => $this->status,
            'counted_by' => $this->counted_by,
            'finished_at' => $this->finished_at?->toIso8601String(),
            'items_count' => $this->items_count ?? ($this->relationLoaded('items') ? $this->items->count() : 0),
            'summary' => $summary,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item): array => [
                'id' => $item->id,
                'shipment_id' => $item->shipment_id,
                'ticket_code' => $item->ticket_code,
                'expected' => $item->expected,
                'found' => $item->found,
                'difference' => $item->difference,
                'motive' => $item->motive,
            ])->values()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
