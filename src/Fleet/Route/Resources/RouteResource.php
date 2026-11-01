<?php

namespace Src\Fleet\Route\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Src\Shared\Contracts\AgencyDirectory;

class RouteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $detail = $this->relationLoaded('segments');
        $names = $detail ? $this->agencyNames() : [];

        return [
            'id' => $this->id,
            'ticket_number' => $this->ticket_number,
            'ticket_code' => $this->ticket_code,
            'origin_agency_id' => $this->origin_agency_id,
            'destination_agency_id' => $this->destination_agency_id,
            'driver_id' => $this->driver_id,
            'vehicle_id' => $this->vehicle_id,
            'status' => $this->status,
            'planned_departure_at' => $this->planned_departure_at?->toIso8601String(),
            'planned_arrival_at' => $this->planned_arrival_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'computed_km' => $this->computed_km,
            'cancel_reason' => $this->cancel_reason,
            'created_at' => $this->created_at?->toIso8601String(),
            // Con el detalle: el chofer reconoce agencias y placa, no sus ids.
            'origin_agency_name' => $this->when($detail, fn () => $names[$this->origin_agency_id] ?? null),
            'destination_agency_name' => $this->when($detail, fn () => $names[$this->destination_agency_id] ?? null),
            'vehicle_plate' => $this->when($detail, fn () => $this->vehicle?->plate),
            'segments' => $this->whenLoaded('segments', fn () => $this->segments->map(fn ($segment): array => [
                ...RouteSegmentResource::make($segment)->resolve($request),
                'from_agency_name' => $names[$segment->from_agency_id] ?? null,
                'to_agency_name' => $names[$segment->to_agency_id] ?? null,
            ])->values()),
            'mileage_logs' => MileageLogResource::collection($this->whenLoaded('mileageLogs')),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function agencyNames(): array
    {
        return app(AgencyDirectory::class)->agencyNames(
            $this->segments
                ->flatMap(fn ($segment): array => [$segment->from_agency_id, $segment->to_agency_id])
                ->push($this->origin_agency_id, $this->destination_agency_id)
                ->filter()
                ->unique()
                ->values()
                ->all(),
        );
    }
}
