<?php

namespace Src\Finance\CreditNote\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Src\Finance\CreditNote\Models\CreditNote;
use Src\Finance\CreditNote\Requests\IssueCreditNoteRequest;
use Src\Finance\CreditNote\Requests\ListCreditNotesRequest;
use Src\Finance\CreditNote\Resources\CreditNoteResource;
use Src\Finance\CreditNote\UseCases\IssueCreditNoteUseCase;
use Src\Shared\Exceptions\BusinessRuleException;
use Src\Shared\Scope\AgencyScope;

/**
 * @group Finance - Credit notes
 *
 * Notas de crédito totales o parciales sobre comprobantes aceptados.
 */
class CreditNoteController extends Controller
{
    /**
     * List credit notes
     *
     * @queryParam status string One status or a comma-separated list. Example: accepted
     * @queryParam payment_id integer Filter by payment. Example: 1
     * @queryParam order_id integer Filter by order. Example: 42
     * @queryParam per_page integer Rows per page (1..100). Example: 15
     *
     * @apiResourceCollection Src\Finance\CreditNote\Resources\CreditNoteResource
     *
     * @apiResourceModel Src\Finance\CreditNote\Models\CreditNote paginate=15
     *
     * @apiResourceAdditional success=true message="Credit notes retrieved successfully."
     */
    public function index(ListCreditNotesRequest $request, AgencyScope $scope): JsonResponse
    {
        $query = CreditNote::query()
            ->forAgencyScope($scope)
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $statuses = array_values(array_filter(array_map('trim', explode(',', (string) $request->input('status')))));
            $query->whereIn('status', $statuses);
        }

        if ($request->filled('payment_id')) {
            $query->where('payment_id', $request->integer('payment_id'));
        }

        if ($request->filled('order_id')) {
            $query->where('order_id', $request->integer('order_id'));
        }

        return $this->successPaginated(
            resource: CreditNoteResource::collection($query->paginate($request->integer('per_page', 15))),
            message: 'Credit notes retrieved successfully.',
        );
    }

    /**
     * Issue a credit note
     *
     * @bodyParam payment_id integer required Payment to credit. Example: 1
     * @bodyParam amount number required Credit amount. Example: 25.90
     * @bodyParam reason string required SUNAT reason code (`01`, `02`, `03`, `06`, `07`). Example: 01
     * @bodyParam note string optional Detail. Example: Customer cancellation
     *
     * @apiResource Src\Finance\CreditNote\Resources\CreditNoteResource
     *
     * @apiResourceModel Src\Finance\CreditNote\Models\CreditNote
     */
    public function store(
        IssueCreditNoteRequest $request,
        IssueCreditNoteUseCase $useCase,
        AgencyScope $scope,
    ): JsonResponse {
        $note = $useCase->execute($request->validated(), $scope);

        return $this->success(new CreditNoteResource($note), 'Credit note issued successfully.', 201);
    }

    /**
     * Show a credit note
     *
     * @urlParam creditNote integer required Credit note id. Example: 1
     *
     * @apiResource Src\Finance\CreditNote\Resources\CreditNoteResource
     *
     * @apiResourceModel Src\Finance\CreditNote\Models\CreditNote
     */
    public function show(int $creditNote, AgencyScope $scope): JsonResponse
    {
        $model = CreditNote::query()->forAgencyScope($scope)->find($creditNote);

        if ($model === null) {
            throw new BusinessRuleException(
                message: 'The credit note does not exist.',
                errorCode: 'CREDIT_NOTE_NOT_FOUND',
                statusCode: 404,
            );
        }

        return $this->success(new CreditNoteResource($model), 'Credit note retrieved successfully.');
    }

    /**
     * Download a credit note artifact
     *
     * @urlParam creditNote integer required Credit note id. Example: 1
     * @urlParam type string required `xml`, `pdf` or `cdr`. Example: pdf
     */
    public function download(int $creditNote, string $type, AgencyScope $scope): mixed
    {
        $model = CreditNote::query()->forAgencyScope($scope)->find($creditNote);

        if ($model === null) {
            throw new BusinessRuleException(
                message: 'The credit note does not exist.',
                errorCode: 'CREDIT_NOTE_NOT_FOUND',
                statusCode: 404,
            );
        }

        $path = match ($type) {
            'xml' => $model->xml_path,
            'pdf' => $model->pdf_path,
            'cdr' => $model->cdr_path,
            default => null,
        };

        if ($path === null || ! Storage::disk((string) config('finance.disk', 'local'))->exists($path)) {
            throw new BusinessRuleException(
                message: 'The requested artifact is not available.',
                errorCode: 'CREDIT_NOTE_ARTIFACT_NOT_READY',
                statusCode: 409,
            );
        }

        return Storage::disk((string) config('finance.disk', 'local'))->download($path);
    }
}
