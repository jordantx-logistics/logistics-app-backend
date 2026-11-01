<?php

namespace Src\Finance\Invoice\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Src\Finance\Invoice\Models\Invoice;
use Src\Finance\Invoice\Requests\ListInvoicesRequest;
use Src\Finance\Invoice\Resources\InvoiceResource;
use Src\Finance\Invoice\UseCases\RetrySunatTransmissionUseCase;
use Src\Shared\Exceptions\BusinessRuleException;
use Src\Shared\Scope\AgencyScope;

/**
 * @group Finance - Invoices
 *
 * Comprobantes electrónicos, su estado SUNAT y sus descargas.
 */
class InvoiceController extends Controller
{
    /**
     * List invoices
     *
     * @queryParam status string One status or a comma-separated list. Example: accepted
     * @queryParam document_type string `boleta` or `factura`. Example: boleta
     * @queryParam order_id integer Filter by order. Example: 42
     * @queryParam ticket_number string Ticket number prefix. Example: LIM01-F001
     * @queryParam agency integer Filter by agency. Example: 1
     * @queryParam issued_from date Filter from this date. Example: 2026-10-01
     * @queryParam issued_to date Filter up to this date. Example: 2026-10-31
     * @queryParam per_page integer Rows per page (1..100). Example: 15
     *
     * @apiResourceCollection Src\Finance\Invoice\Resources\InvoiceResource
     *
     * @apiResourceModel Src\Finance\Invoice\Models\Invoice paginate=15
     *
     * @apiResourceAdditional success=true message="Invoices retrieved successfully."
     */
    public function index(ListInvoicesRequest $request, AgencyScope $scope): JsonResponse
    {
        $query = Invoice::query()
            ->forAgencyScope($scope)
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $statuses = array_values(array_filter(array_map('trim', explode(',', (string) $request->input('status')))));
            $query->whereIn('status', $statuses);
        }

        if ($request->filled('document_type')) {
            $query->where('document_type', $request->input('document_type'));
        }

        if ($request->filled('order_id')) {
            $query->where('order_id', $request->integer('order_id'));
        }

        if ($request->filled('ticket_number')) {
            $query->where('ticket_number', 'like', strtoupper((string) $request->input('ticket_number')).'%');
        }

        if ($request->filled('agency')) {
            $query->where('agency_id', $request->integer('agency'));
        }

        if ($request->filled('issued_from')) {
            $query->whereDate('issued_at', '>=', $request->input('issued_from'));
        }

        if ($request->filled('issued_to')) {
            $query->whereDate('issued_at', '<=', $request->input('issued_to'));
        }

        return $this->successPaginated(
            resource: InvoiceResource::collection($query->paginate($request->integer('per_page', 15))),
            message: 'Invoices retrieved successfully.',
        );
    }

    /**
     * Show an invoice
     *
     * @urlParam invoice integer required Invoice id. Example: 1
     *
     * @apiResource Src\Finance\Invoice\Resources\InvoiceResource
     *
     * @apiResourceModel Src\Finance\Invoice\Models\Invoice
     */
    public function show(int $invoice, AgencyScope $scope): JsonResponse
    {
        $model = Invoice::query()->forAgencyScope($scope)->find($invoice);

        if ($model === null) {
            throw new BusinessRuleException(
                message: 'The invoice does not exist.',
                errorCode: 'INVOICE_NOT_FOUND',
                statusCode: 404,
            );
        }

        return $this->success(new InvoiceResource($model), 'Invoice retrieved successfully.');
    }

    /**
     * Retry SUNAT transmission
     *
     * @urlParam invoice integer required Invoice id. Example: 1
     *
     * @apiResource Src\Finance\Invoice\Resources\InvoiceResource
     *
     * @apiResourceModel Src\Finance\Invoice\Models\Invoice
     */
    public function retry(
        int $invoice,
        RetrySunatTransmissionUseCase $useCase,
        AgencyScope $scope,
    ): JsonResponse {
        $model = $useCase->execute($invoice, $scope);

        return $this->success(new InvoiceResource($model), 'Invoice transmission retried successfully.');
    }

    /**
     * Download an invoice artifact
     *
     * @urlParam invoice integer required Invoice id. Example: 1
     * @urlParam type string required `xml`, `pdf` or `cdr`. Example: pdf
     */
    public function download(int $invoice, string $type, AgencyScope $scope): mixed
    {
        $model = Invoice::query()->forAgencyScope($scope)->find($invoice);

        if ($model === null) {
            throw new BusinessRuleException(
                message: 'The invoice does not exist.',
                errorCode: 'INVOICE_NOT_FOUND',
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
                errorCode: 'INVOICE_ARTIFACT_NOT_READY',
                statusCode: 409,
            );
        }

        return Storage::disk((string) config('finance.disk', 'local'))->download($path);
    }
}
