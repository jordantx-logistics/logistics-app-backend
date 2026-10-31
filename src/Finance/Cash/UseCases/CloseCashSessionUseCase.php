<?php

namespace Src\Finance\Cash\UseCases;

use Illuminate\Support\Facades\DB;
use Src\Finance\Cash\Models\CashClosure;
use Src\Finance\Cash\Models\CashMovement;
use Src\Finance\Cash\Models\CashSession;
use Src\Finance\Cash\Services\CashBalanceCalculator;
use Src\Finance\Cash\Services\CashEventPublisher;
use Src\Shared\Exceptions\BusinessRuleException;
use Src\Shared\Scope\AgencyScope;
use Src\Shared\Services\AuditLogger;
use Src\Shared\ValueObjects\Money;

/**
 * Cierra una sesión de caja.
 *
 * El cierre cuadrado se completa en la petición. Un descuadre (cualquier
 * diferencia, tolerancia 0.00) deja una solicitud pendiente y la sesión abierta
 * hasta que un supervisor la apruebe; rechazarla permite volver a contar.
 */
class CloseCashSessionUseCase
{
    public function __construct(
        private readonly CashBalanceCalculator $balance,
        private readonly CashEventPublisher $events,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{session: CashSession, closure: CashClosure, requires_approval: bool}
     */
    public function execute(int $sessionId, array $data, AgencyScope $scope): array
    {
        return DB::transaction(function () use ($sessionId, $data, $scope): array {
            $session = CashSession::query()->lockForUpdate()->find($sessionId);

            if ($session === null) {
                throw new BusinessRuleException(
                    message: 'The cash session does not exist.',
                    errorCode: 'CASH_SESSION_NOT_FOUND',
                    statusCode: 404,
                );
            }

            if (! $scope->covers((int) $session->agency_id)) {
                throw new BusinessRuleException(
                    message: 'The cash session belongs to an agency outside your scope.',
                    errorCode: 'CASH_AGENCY_SCOPE',
                    statusCode: 403,
                );
            }

            if ($session->status !== CashSession::STATUS_OPEN) {
                throw new BusinessRuleException(
                    message: 'The cash session is already closed.',
                    errorCode: 'CASH_SESSION_ALREADY_CLOSED',
                );
            }

            if ($session->pendingClosure()->exists()) {
                throw new BusinessRuleException(
                    message: 'The cash session already has a closure pending approval.',
                    errorCode: 'CASH_CLOSURE_PENDING',
                );
            }

            $closing = Money::fromDecimal($data['closing_amount']);

            if ($closing->isNegative()) {
                throw new BusinessRuleException(
                    message: 'The closing amount cannot be negative.',
                    errorCode: 'CASH_CLOSING_AMOUNT_INVALID',
                );
            }

            $expected = $this->balance->expected($session);
            $difference = $closing->subtract($expected);

            if ($difference->isZero()) {
                return $this->closeBalanced($session, $closing, $expected, $data);
            }

            $session->update([
                'expected_amount' => $expected->toDecimal(),
                'closing_amount' => $closing->toDecimal(),
                'difference' => $difference->toDecimal(),
            ]);

            $closure = CashClosure::create([
                'cash_session_id' => $session->id,
                'status' => CashClosure::STATUS_PENDING,
                'expected_amount' => $expected->toDecimal(),
                'closing_amount' => $closing->toDecimal(),
                'difference' => $difference->toDecimal(),
                'requested_by' => (int) auth()->id(),
                'requested_at' => now(),
            ]);

            $this->audit->record(
                action: 'cash.closure.requested',
                entityType: 'cash_closure',
                entityId: $closure->id,
                before: null,
                after: [
                    'cash_session_id' => $session->id,
                    'expected_amount' => $expected->toDecimal(),
                    'closing_amount' => $closing->toDecimal(),
                    'difference' => $difference->toDecimal(),
                    'count' => $data['count'] ?? null,
                ],
            );

            return [
                'session' => $session->refresh()->load('movements'),
                'closure' => $closure,
                'requires_approval' => true,
            ];
        });
    }

    /**
     * @return array{session: CashSession, closure: CashClosure, requires_approval: bool}
     */
    private function closeBalanced(
        CashSession $session,
        Money $closing,
        Money $expected,
        array $data,
    ): array {
        $session->update([
            'status' => CashSession::STATUS_CLOSED,
            'expected_amount' => $expected->toDecimal(),
            'closing_amount' => $closing->toDecimal(),
            'difference' => '0.00',
            'closed_at' => now(),
        ]);

        CashMovement::create([
            'cash_session_id' => $session->id,
            'type' => CashMovement::TYPE_CLOSING,
            'amount' => $closing->toDecimal(),
            'reference_type' => 'cash_closure',
            'reference_id' => null,
            'note' => null,
            'created_by' => auth()->id(),
        ]);

        $closure = CashClosure::create([
            'cash_session_id' => $session->id,
            'status' => CashClosure::STATUS_APPROVED,
            'expected_amount' => $expected->toDecimal(),
            'closing_amount' => $closing->toDecimal(),
            'difference' => '0.00',
            'requested_by' => (int) auth()->id(),
            'requested_at' => now(),
            'approved_at' => now(),
        ]);

        $session->refresh();
        $closure->setRelation('cashSession', $session);

        $this->audit->record(
            action: 'cash.closure.approved',
            entityType: 'cash_closure',
            entityId: $closure->id,
            before: null,
            after: [
                'cash_session_id' => $session->id,
                'difference' => '0.00',
                'count' => $data['count'] ?? null,
            ],
        );

        $this->events->closed($closure);

        return [
            'session' => $session->load('movements'),
            'closure' => $closure,
            'requires_approval' => false,
        ];
    }
}
