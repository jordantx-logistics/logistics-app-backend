<?php

namespace Src\Incident\UseCases;

use Illuminate\Support\Facades\DB;
use Src\Incident\Models\Incident;
use Src\Shared\Contracts\OrderQuery;
use Src\Shared\Exceptions\BusinessRuleException;
use Src\Shared\Scope\AgencyScope;
use Src\Shared\Services\AuditLogger;

/**
 * Abre una incidencia sobre una orden del ámbito. El plazo sale del tipo.
 */
class OpenIncidentUseCase
{
    public function __construct(
        private readonly OrderQuery $orders,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{order_id: int, type: string, priority?: string, description: string}  $data
     */
    public function execute(array $data, AgencyScope $scope): Incident
    {
        $order = $this->orders->findBillable((int) $data['order_id']);

        if ($order === null) {
            throw new BusinessRuleException(
                message: 'The order does not exist.',
                errorCode: 'INCIDENT_ORDER_NOT_FOUND',
                statusCode: 404,
            );
        }

        if (! $scope->covers($order->originAgencyId) && ! $scope->covers($order->destinationAgencyId)) {
            throw new BusinessRuleException(
                message: 'The order belongs to an agency outside your scope.',
                errorCode: 'INCIDENT_AGENCY_SCOPE',
                statusCode: 403,
            );
        }

        return DB::transaction(function () use ($data, $order): Incident {
            $incident = Incident::query()->create([
                'order_id' => $order->orderId,
                'ticket_code' => $order->ticketCode,
                'origin_agency_id' => $order->originAgencyId,
                'destination_agency_id' => $order->destinationAgencyId,
                'type' => $data['type'],
                'priority' => $data['priority'] ?? 'normal',
                'status' => Incident::STATUS_OPEN,
                'description' => $data['description'],
                'due_at' => now()->addHours((int) config("incidents.sla_hours.{$data['type']}")),
                'created_by' => auth()->id(),
            ]);

            $this->audit->record(
                action: 'incident.opened',
                entityType: 'incident',
                entityId: $incident->id,
                after: ['order_id' => $order->orderId, 'type' => $incident->type, 'priority' => $incident->priority],
            );

            return $incident;
        });
    }
}
