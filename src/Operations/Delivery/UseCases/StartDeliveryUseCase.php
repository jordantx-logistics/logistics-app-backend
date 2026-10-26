<?php

namespace Src\Operations\Delivery\UseCases;

use Illuminate\Support\Facades\DB;
use Src\Operations\Delivery\Models\Delivery;
use Src\Shared\Exceptions\BusinessRuleException;
use Src\Shared\Scope\AgencyScope;

class StartDeliveryUseCase
{
    public function execute(int $deliveryId, AgencyScope $scope): Delivery
    {
        return DB::transaction(function () use ($deliveryId, $scope): Delivery {
            $delivery = Delivery::query()
                ->forAgencyScope($scope)
                ->lockForUpdate()
                ->find($deliveryId);

            if ($delivery === null) {
                throw new BusinessRuleException(
                    message: 'The delivery does not exist.',
                    errorCode: 'DELIVERY_NOT_FOUND',
                    statusCode: 404,
                );
            }

            if ($delivery->status === Delivery::STATUS_IN_PROGRESS) {
                return $delivery;
            }

            if ($delivery->status !== Delivery::STATUS_ASSIGNED) {
                throw new BusinessRuleException(
                    message: "The delivery cannot be started from [{$delivery->status}].",
                    errorCode: 'INVALID_DELIVERY_TRANSITION',
                );
            }

            $delivery->update(['status' => Delivery::STATUS_IN_PROGRESS]);

            return $delivery->refresh();
        });
    }
}
