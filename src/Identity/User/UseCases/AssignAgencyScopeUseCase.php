<?php

namespace Src\Identity\User\UseCases;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Src\Identity\User\Rules\ValidateUserAgencyScope;
use Src\Shared\Exceptions\BusinessRuleException;
use Src\Shared\Pipelines\RulesPipeline;

/**
 * Sincroniza el ámbito de agencia de un usuario (UC-16).
 *
 * `agency_user` se sincroniza completo (lo que no venga, se revoca) y se
 * garantiza **una sola** agencia `is_primary`. La invariante «un rol operativo
 * necesita al menos una agencia» la comprueba `ValidateUserAgencyScope`.
 */
class AssignAgencyScopeUseCase
{
    /**
     * @param  list<int>  $agencyIds
     */
    public function execute(User $user, array $agencyIds, ?int $primaryAgencyId = null): User
    {
        $agencyIds = array_values(array_unique(array_map('intval', $agencyIds)));

        $primaryAgencyId ??= $agencyIds[0] ?? null;

        if ($primaryAgencyId !== null && ! in_array($primaryAgencyId, $agencyIds, true)) {
            throw new BusinessRuleException(
                message: 'The primary agency must belong to the agency scope.',
                errorCode: 'PRIMARY_AGENCY_NOT_IN_SCOPE',
                statusCode: 422,
            );
        }

        RulesPipeline::run(
            ['user' => $user, 'agency_ids' => $agencyIds],
            [ValidateUserAgencyScope::class],
        );

        return DB::transaction(function () use ($user, $agencyIds, $primaryAgencyId): User {
            $sync = [];

            foreach ($agencyIds as $agencyId) {
                $sync[$agencyId] = ['is_primary' => $agencyId === $primaryAgencyId];
            }

            $user->agencies()->sync($sync);

            return $user->fresh(['agencies']);
        });
    }
}
