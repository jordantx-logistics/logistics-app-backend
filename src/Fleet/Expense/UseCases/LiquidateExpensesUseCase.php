<?php

namespace Src\Fleet\Expense\UseCases;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Src\Fleet\Expense\Models\Expense;
use Src\Fleet\Route\Models\FleetRoute;
use Src\Shared\Exceptions\BusinessRuleException;
use Src\Shared\Scope\AgencyScope;
use Src\Shared\Services\AuditLogger;

/**
 * Liquida o rechaza un lote de gastos de rutas ya cerradas. Es atómico: un gasto
 * inválido deja todo el lote como estaba. Liquidar exige comprobante.
 */
class LiquidateExpensesUseCase
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<int>  $expenseIds
     * @return Collection<int, Expense>
     */
    public function execute(array $expenseIds, string $decision, ?string $reason, AgencyScope $scope): Collection
    {
        if ($decision === Expense::STATUS_REJECTED && trim((string) $reason) === '') {
            throw new BusinessRuleException(
                message: 'Rejecting expenses requires a reason.',
                errorCode: 'EXPENSE_REJECTION_REASON_REQUIRED',
            );
        }

        return DB::transaction(function () use ($expenseIds, $decision, $reason, $scope): Collection {
            // Por id ascendente: dos lotes solapados bloquean en el mismo orden.
            $expenses = Expense::query()->whereIn('id', $expenseIds)->orderBy('id')->lockForUpdate()->get();

            if ($expenses->count() !== count(array_unique($expenseIds))) {
                throw new BusinessRuleException(
                    message: 'An expense of the batch does not exist.',
                    errorCode: 'EXPENSE_NOT_FOUND',
                    statusCode: 404,
                );
            }

            foreach ($expenses as $expense) {
                $this->assertReviewable($expense, $decision, $scope);

                $before = $expense->status;

                $expense->update([
                    'status' => $decision,
                    'rejection_reason' => $decision === Expense::STATUS_REJECTED ? $reason : null,
                    'reviewed_by' => (int) auth()->id(),
                    'reviewed_at' => now(),
                ]);

                $this->audit->record(
                    action: "expense.{$decision}",
                    entityType: 'expense',
                    entityId: $expense->id,
                    before: ['status' => $before],
                    after: ['status' => $decision, 'amount' => (string) $expense->amount, 'reason' => $reason],
                );
            }

            return $expenses;
        });
    }

    private function assertReviewable(Expense $expense, string $decision, AgencyScope $scope): void
    {
        $route = FleetRoute::query()->findOrFail($expense->route_id);

        if (! $scope->covers((int) $route->origin_agency_id)) {
            throw new BusinessRuleException(
                message: 'The route belongs to an agency outside your scope.',
                errorCode: 'FLEET_AGENCY_SCOPE',
                statusCode: 403,
            );
        }

        if ($expense->status !== Expense::STATUS_REGISTERED) {
            throw new BusinessRuleException(
                message: 'The expense was already reviewed.',
                errorCode: 'EXPENSE_ALREADY_REVIEWED',
            );
        }

        if (! in_array($route->status, [FleetRoute::STATUS_FINISHED, FleetRoute::STATUS_CANCELLED], true)) {
            throw new BusinessRuleException(
                message: 'Expenses are reviewed once the route is closed.',
                errorCode: 'EXPENSE_ROUTE_NOT_CLOSED',
            );
        }

        if ($decision === Expense::STATUS_LIQUIDATED && $expense->receipt_path === null) {
            throw new BusinessRuleException(
                message: 'An expense without a receipt cannot be liquidated.',
                errorCode: 'EXPENSE_RECEIPT_REQUIRED',
            );
        }
    }
}
