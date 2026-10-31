<?php

namespace Src\Finance\Cash\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Src\Finance\Cash\Models\CashSession;
use Src\Finance\Cash\Requests\CloseCashSessionRequest;
use Src\Finance\Cash\Requests\ListCashSessionsRequest;
use Src\Finance\Cash\Requests\OpenCashSessionRequest;
use Src\Finance\Cash\Resources\CashClosureResource;
use Src\Finance\Cash\Resources\CashSessionResource;
use Src\Finance\Cash\Services\CashierAgencyResolver;
use Src\Finance\Cash\UseCases\CloseCashSessionUseCase;
use Src\Finance\Cash\UseCases\OpenCashSessionUseCase;
use Src\Shared\Exceptions\BusinessRuleException;
use Src\Shared\Scope\AgencyScope;

/**
 * @group Finance - Cash sessions
 *
 * Turnos de caja, cierre cuadrado y cierre con descuadre pendiente de aprobación.
 */
class CashSessionController extends Controller
{
    /**
     * List cash sessions
     *
     * @queryParam status string `open` or `closed`. Example: closed
     * @queryParam cashier_id integer Filter by cashier. Example: 5
     * @queryParam agency integer Filter by agency. Example: 1
     * @queryParam opened_from date Filter from this date. Example: 2026-10-01
     * @queryParam opened_to date Filter up to this date. Example: 2026-10-31
     * @queryParam per_page integer Rows per page (1..100). Example: 15
     *
     * @apiResourceCollection Src\Finance\Cash\Resources\CashSessionResource
     *
     * @apiResourceModel Src\Finance\Cash\Models\CashSession paginate=15
     *
     * @apiResourceAdditional success=true message="Cash sessions retrieved successfully."
     */
    public function index(ListCashSessionsRequest $request, AgencyScope $scope): JsonResponse
    {
        $query = CashSession::query()
            ->forAgencyScope($scope)
            ->with(['closures'])
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('cashier_id')) {
            $query->where('cashier_id', $request->integer('cashier_id'));
        }

        if ($request->filled('agency')) {
            $query->where('agency_id', $request->integer('agency'));
        }

        if ($request->filled('opened_from')) {
            $query->where('opened_at', '>=', $request->input('opened_from'));
        }

        if ($request->filled('opened_to')) {
            $query->where('opened_at', '<=', $request->input('opened_to').' 23:59:59');
        }

        return $this->successPaginated(
            resource: CashSessionResource::collection($query->paginate($request->integer('per_page', 15))),
            message: 'Cash sessions retrieved successfully.',
        );
    }

    /**
     * Current open cash session
     *
     * Returns `cash_session: null` when the authenticated cashier has no open session.
     *
     * @response 200 scenario="Open session" {"success":true,"message":"Current cash session retrieved successfully.","data":{"cash_session":{"id":1,"agency_id":1,"cashier_id":2,"status":"open","opening_amount":"100.00","expected_amount":"150.00","closing_amount":null,"difference":null,"opened_at":"2026-10-31T08:00:00+00:00","closed_at":null,"created_at":"2026-10-31T08:00:00+00:00","updated_at":"2026-10-31T08:00:00+00:00","movements":[],"closures":[]}}}
     * @response 200 scenario="No open session" {"success":true,"message":"Current cash session retrieved successfully.","data":{"cash_session":null}}
     */
    public function current(CashierAgencyResolver $resolver, AgencyScope $scope): JsonResponse
    {
        $session = $resolver->currentOpenSession();

        if ($session !== null && ! $scope->covers((int) $session->agency_id)) {
            $session = null;
        }

        if ($session !== null) {
            $session->load(['movements', 'closures']);
        }

        return $this->success([
            'cash_session' => $session !== null ? new CashSessionResource($session) : null,
        ], 'Current cash session retrieved successfully.');
    }

    /**
     * Show a cash session
     *
     * @urlParam session integer required Cash session id. Example: 1
     *
     * @apiResource Src\Finance\Cash\Resources\CashSessionResource
     *
     * @apiResourceModel Src\Finance\Cash\Models\CashSession
     */
    public function show(int $session, AgencyScope $scope): JsonResponse
    {
        $model = CashSession::query()
            ->with(['movements', 'closures'])
            ->forAgencyScope($scope)
            ->find($session);

        if ($model === null) {
            throw new BusinessRuleException(
                message: 'The cash session does not exist.',
                errorCode: 'CASH_SESSION_NOT_FOUND',
                statusCode: 404,
            );
        }

        return $this->success(new CashSessionResource($model), 'Cash session retrieved successfully.');
    }

    /**
     * Open a cash session
     *
     * The agency is inferred from the authenticated cashier; it is not accepted from the client.
     *
     * @bodyParam opening_amount number required Opening cash. Example: 100.00
     *
     * @apiResource Src\Finance\Cash\Resources\CashSessionResource
     *
     * @apiResourceModel Src\Finance\Cash\Models\CashSession
     */
    public function open(
        OpenCashSessionRequest $request,
        OpenCashSessionUseCase $useCase,
        AgencyScope $scope,
    ): JsonResponse {
        $session = $useCase->execute($request->validated(), $scope);

        return $this->success(new CashSessionResource($session), 'Cash session opened successfully.', 201);
    }

    /**
     * Close a cash session
     *
     * A zero difference closes immediately. Any difference (tolerance 0.00) keeps the
     * session open and returns a pending closure that a supervisor must approve.
     *
     * @urlParam session integer required Cash session id. Example: 1
     *
     * @bodyParam closing_amount number required Counted cash. Example: 250.00
     * @bodyParam count object[] optional Cash count detail. Example: [{"denomination":100,"quantity":2}]
     *
     * @response 200 scenario="Closed without difference" {"success":true,"message":"Cash session closed successfully.","data":{"cash_session":{"id":1,"agency_id":1,"cashier_id":2,"status":"closed","opening_amount":"100.00","expected_amount":"150.00","closing_amount":"150.00","difference":"0.00","opened_at":"2026-10-31T08:00:00+00:00","closed_at":"2026-10-31T18:00:00+00:00","created_at":"2026-10-31T08:00:00+00:00","updated_at":"2026-10-31T18:00:00+00:00","movements":[],"closures":[]},"closure":{"id":1,"cash_session_id":1,"status":"approved","expected_amount":"150.00","closing_amount":"150.00","difference":"0.00","requested_by":2,"approved_by":null,"requested_at":"2026-10-31T18:00:00+00:00","approved_at":"2026-10-31T18:00:00+00:00","rejected_at":null,"rejection_reason":null,"created_at":"2026-10-31T18:00:00+00:00","report_url":null},"requires_approval":false}}
     * @response 200 scenario="Pending approval" {"success":true,"message":"Cash session has a difference and waits for approval.","data":{"cash_session":{"id":1,"agency_id":1,"cashier_id":2,"status":"open","opening_amount":"100.00","expected_amount":"150.00","closing_amount":"145.00","difference":"-5.00","opened_at":"2026-10-31T08:00:00+00:00","closed_at":null,"created_at":"2026-10-31T08:00:00+00:00","updated_at":"2026-10-31T18:00:00+00:00","movements":[],"closures":[]},"closure":{"id":1,"cash_session_id":1,"status":"pending","expected_amount":"150.00","closing_amount":"145.00","difference":"-5.00","requested_by":2,"approved_by":null,"requested_at":"2026-10-31T18:00:00+00:00","approved_at":null,"rejected_at":null,"rejection_reason":null,"created_at":"2026-10-31T18:00:00+00:00","report_url":null},"requires_approval":true}}
     */
    public function close(
        int $session,
        CloseCashSessionRequest $request,
        CloseCashSessionUseCase $useCase,
        AgencyScope $scope,
    ): JsonResponse {
        $result = $useCase->execute($session, $request->validated(), $scope);

        return $this->success([
            'cash_session' => new CashSessionResource($result['session']),
            'closure' => new CashClosureResource($result['closure']),
            'requires_approval' => $result['requires_approval'],
        ], $result['requires_approval']
            ? 'Cash session has a difference and waits for approval.'
            : 'Cash session closed successfully.');
    }
}
