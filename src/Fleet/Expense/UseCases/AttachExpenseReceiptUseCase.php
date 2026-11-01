<?php

namespace Src\Fleet\Expense\UseCases;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Src\Fleet\Expense\Models\Expense;
use Src\Fleet\Route\Models\FleetRoute;
use Src\Fleet\Route\Services\FleetEvidenceStore;
use Src\Fleet\Route\Services\RouteActorGuard;
use Src\Shared\Exceptions\BusinessRuleException;
use Src\Shared\Scope\AgencyScope;
use Throwable;

/**
 * Rendición: adjunta o reemplaza el comprobante mientras el gasto no se revisó.
 */
class AttachExpenseReceiptUseCase
{
    public function __construct(
        private readonly RouteActorGuard $guard,
        private readonly FleetEvidenceStore $evidence,
    ) {}

    public function execute(int $expenseId, UploadedFile $receipt, AgencyScope $scope): Expense
    {
        $newPath = null;
        $oldPath = null;

        try {
            $expense = DB::transaction(function () use ($expenseId, $receipt, $scope, &$newPath, &$oldPath): Expense {
                $expense = Expense::query()->lockForUpdate()->find($expenseId);

                if ($expense === null) {
                    throw new BusinessRuleException(
                        message: 'The expense does not exist.',
                        errorCode: 'EXPENSE_NOT_FOUND',
                        statusCode: 404,
                    );
                }

                $this->guard->assertCanOperate(FleetRoute::query()->findOrFail($expense->route_id), $scope);

                if ($expense->status !== Expense::STATUS_REGISTERED) {
                    throw new BusinessRuleException(
                        message: 'An expense already reviewed cannot change its receipt.',
                        errorCode: 'EXPENSE_ALREADY_REVIEWED',
                    );
                }

                $oldPath = $expense->receipt_path;
                $newPath = $this->evidence->store($receipt, (int) $expense->route_id, (string) $expense->type);
                $expense->update(['receipt_path' => $newPath]);

                return $expense;
            });
        } catch (Throwable $exception) {
            $this->evidence->delete($newPath);

            throw $exception;
        }

        // Tras confirmar: si la transacción fallara, el comprobante anterior sigue.
        $this->evidence->delete($oldPath);

        return $expense;
    }
}
