<?php

namespace Src\Operations\Inventory\UseCases;

use Illuminate\Support\Facades\DB;
use Src\Operations\Inventory\Models\InventoryCount;
use Src\Shared\Exceptions\BusinessRuleException;
use Src\Shared\Scope\AgencyScope;

class OpenInventoryCountUseCase
{
    public function execute(int $agencyId, AgencyScope $scope, ?int $actorId = null): InventoryCount
    {
        if (! $scope->covers($agencyId)) {
            throw new BusinessRuleException(
                message: 'The agency is outside the user scope.',
                errorCode: 'AGENCY_OUT_OF_SCOPE',
                statusCode: 403,
            );
        }

        return DB::transaction(function () use ($agencyId, $actorId): InventoryCount {
            $open = InventoryCount::query()
                ->where('agency_id', $agencyId)
                ->where('status', InventoryCount::STATUS_OPEN)
                ->lockForUpdate()
                ->first();

            if ($open !== null) {
                throw new BusinessRuleException(
                    message: 'The agency already has an open inventory count.',
                    errorCode: 'INVENTORY_ALREADY_OPEN',
                );
            }

            return InventoryCount::create([
                'agency_id' => $agencyId,
                'status' => InventoryCount::STATUS_OPEN,
                'counted_by' => $actorId,
            ]);
        });
    }
}
