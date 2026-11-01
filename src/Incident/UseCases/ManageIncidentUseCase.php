<?php

namespace Src\Incident\UseCases;

use Illuminate\Support\Facades\DB;
use Src\Incident\Models\Incident;
use Src\Shared\Contracts\UserDirectory;
use Src\Shared\Exceptions\BusinessRuleException;
use Src\Shared\Scope\AgencyScope;
use Src\Shared\Services\AuditLogger;

/**
 * Asignación y transiciones de una incidencia:
 * `open → in_progress → resolved → closed`, con `resolved → in_progress` al
 * reabrir. Una incidencia cerrada no cambia.
 */
class ManageIncidentUseCase
{
    public const MANAGE_PERMISSION = 'incidents.manage';

    private const TRANSITIONS = [
        'start' => [Incident::STATUS_OPEN, Incident::STATUS_IN_PROGRESS],
        'resolve' => [Incident::STATUS_IN_PROGRESS, Incident::STATUS_RESOLVED],
        'close' => [Incident::STATUS_RESOLVED, Incident::STATUS_CLOSED],
        'reopen' => [Incident::STATUS_RESOLVED, Incident::STATUS_IN_PROGRESS],
    ];

    public function __construct(
        private readonly UserDirectory $users,
        private readonly AuditLogger $audit,
    ) {}

    public function assign(int $incidentId, int $userId, AgencyScope $scope): Incident
    {
        return $this->locked($incidentId, $scope, function (Incident $incident) use ($userId): void {
            if ($incident->status === Incident::STATUS_CLOSED) {
                throw $this->invalidTransition();
            }

            if (! $this->canBeAssigned($incident, $userId)) {
                throw new BusinessRuleException(
                    message: 'The user cannot attend incidents of this order.',
                    errorCode: 'INCIDENT_ASSIGNEE_INVALID',
                );
            }

            $before = $incident->assigned_to;
            $incident->update(['assigned_to' => $userId]);

            $this->audit->record(
                action: 'incident.assigned',
                entityType: 'incident',
                entityId: $incident->id,
                before: ['assigned_to' => $before],
                after: ['assigned_to' => $userId],
            );
        });
    }

    public function transition(int $incidentId, string $action, ?string $note, AgencyScope $scope): Incident
    {
        return $this->locked($incidentId, $scope, function (Incident $incident) use ($action, $note): void {
            [$from, $to] = self::TRANSITIONS[$action];

            if ($incident->status !== $from) {
                throw $this->invalidTransition();
            }

            if ($action === 'start' && $incident->assigned_to === null) {
                throw new BusinessRuleException(
                    message: 'An incident needs someone responsible before it starts.',
                    errorCode: 'INCIDENT_NOT_ASSIGNED',
                );
            }

            if ($action === 'resolve' && trim((string) $note) === '') {
                throw new BusinessRuleException(
                    message: 'Resolving an incident requires a note.',
                    errorCode: 'INCIDENT_RESOLUTION_REQUIRED',
                );
            }

            $incident->update(match ($action) {
                'resolve' => ['status' => $to, 'resolution_note' => $note, 'resolved_at' => now()],
                'close' => ['status' => $to, 'closed_at' => now()],
                'reopen' => ['status' => $to, 'resolution_note' => null, 'resolved_at' => null],
                default => ['status' => $to],
            });

            $this->audit->record(
                action: "incident.{$action}",
                entityType: 'incident',
                entityId: $incident->id,
                before: ['status' => $from],
                after: ['status' => $to, 'note' => $note],
            );
        });
    }

    /**
     * @param  callable(Incident): void  $change
     */
    private function locked(int $incidentId, AgencyScope $scope, callable $change): Incident
    {
        return DB::transaction(function () use ($incidentId, $scope, $change): Incident {
            $incident = Incident::query()->lockForUpdate()->find($incidentId);

            if ($incident === null) {
                throw new BusinessRuleException(
                    message: 'The incident does not exist.',
                    errorCode: 'INCIDENT_NOT_FOUND',
                    statusCode: 404,
                );
            }

            if (! $scope->covers((int) $incident->origin_agency_id) && ! $scope->covers((int) $incident->destination_agency_id)) {
                throw new BusinessRuleException(
                    message: 'The incident belongs to an agency outside your scope.',
                    errorCode: 'INCIDENT_AGENCY_SCOPE',
                    statusCode: 403,
                );
            }

            $change($incident);

            return $incident;
        });
    }

    private function canBeAssigned(Incident $incident, int $userId): bool
    {
        $candidates = $this->users->usersWithPermission(
            self::MANAGE_PERMISSION,
            [(int) $incident->origin_agency_id, (int) $incident->destination_agency_id],
        );

        return in_array($userId, array_column($candidates, 'id'), true);
    }

    private function invalidTransition(): BusinessRuleException
    {
        return new BusinessRuleException(
            message: 'The incident does not allow that change in its current status.',
            errorCode: 'INCIDENT_INVALID_TRANSITION',
        );
    }
}
