<?php

namespace Src\Fleet\Route\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Src\Fleet\Driver\Models\Driver;
use Src\Fleet\Expense\Models\Expense;
use Src\Fleet\Vehicle\Models\Vehicle;
use Src\Shared\Scope\AgencyScope;
use Src\Shared\Traits\HasTicketIdentifiers;

/**
 * Ruta troncal entre agencias (raíz del agregado). Se llama `FleetRoute` para no
 * chocar con la fachada `Route` de los archivos de rutas.
 */
class FleetRoute extends Model
{
    use HasFactory, HasTicketIdentifiers;

    public const STATUS_PLANNED = 'planned';

    public const STATUS_ASSIGNED = 'assigned';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_FINISHED = 'finished';

    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'routes';

    protected $fillable = [
        'ticket_number',
        'ticket_code',
        'origin_agency_id',
        'destination_agency_id',
        'driver_id',
        'vehicle_id',
        'status',
        'planned_departure_at',
        'planned_arrival_at',
        'started_at',
        'finished_at',
        'computed_km',
        'cancel_reason',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'planned_departure_at' => 'datetime',
            'planned_arrival_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'computed_km' => 'decimal:2',
        ];
    }

    public function getTicketPrefix(): string
    {
        return 'RUT';
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function segments(): HasMany
    {
        return $this->hasMany(RouteSegment::class, 'route_id')->orderBy('sequence');
    }

    public function mileageLogs(): HasMany
    {
        return $this->hasMany(MileageLog::class, 'route_id')->orderBy('id');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'route_id');
    }

    /**
     * Rutas que ve un usuario: las de sus agencias (origen o destino) y las que
     * conduce. Un chofer no siempre tiene agencias asignadas a su usuario.
     *
     * @param  Builder<FleetRoute>  $query
     * @return Builder<FleetRoute>
     */
    public function scopeVisibleTo(Builder $query, AgencyScope $scope, int $userId): Builder
    {
        if ($scope->unrestricted) {
            return $query;
        }

        // El id del chofer se resuelve antes: un `OR EXISTS` impediría al optimizador
        // combinar los índices y recorrería toda la tabla.
        $driverId = Driver::query()->where('user_id', $userId)->value('id');

        return $query->where(function (Builder $visible) use ($scope, $driverId): void {
            $visible->whereIn('origin_agency_id', $scope->ids)
                ->orWhereIn('destination_agency_id', $scope->ids);

            if ($driverId !== null) {
                $visible->orWhere('driver_id', $driverId);
            }
        });
    }
}
