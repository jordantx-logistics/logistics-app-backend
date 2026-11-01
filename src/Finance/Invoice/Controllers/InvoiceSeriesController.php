<?php

namespace Src\Finance\Invoice\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Src\Finance\Invoice\Models\Invoice;
use Src\Finance\Invoice\Models\InvoiceSeries;
use Src\Finance\Invoice\Requests\InvoiceSeriesRequest;
use Src\Finance\Invoice\Resources\InvoiceSeriesResource;
use Src\Shared\Exceptions\BusinessRuleException;
use Src\Shared\Scope\AgencyScope;

/**
 * @group Finance - Invoice series
 *
 * Series y datos fiscales del emisor por agencia. Una serie activa por defecto
 * y tipo de comprobante; sin ella no se puede registrar un pago.
 */
class InvoiceSeriesController extends Controller
{
    /**
     * List invoice series
     *
     * @queryParam agency integer Filter by agency. Example: 1
     * @queryParam document_type string `boleta` or `factura`. Example: boleta
     *
     * @apiResourceCollection Src\Finance\Invoice\Resources\InvoiceSeriesResource
     *
     * @apiResourceModel Src\Finance\Invoice\Models\InvoiceSeries
     *
     * @apiResourceAdditional success=true message="Invoice series retrieved successfully."
     */
    public function index(InvoiceSeriesRequest $request, AgencyScope $scope): JsonResponse
    {
        $query = InvoiceSeries::query()->orderBy('agency_id')->orderBy('document_type');

        if (! $scope->unrestricted) {
            $query->whereIn('agency_id', $scope->ids);
        }

        if ($request->filled('agency')) {
            $query->where('agency_id', $request->integer('agency'));
        }

        if ($request->filled('document_type')) {
            $query->where('document_type', $request->input('document_type'));
        }

        return $this->success(
            InvoiceSeriesResource::collection($query->get()),
            'Invoice series retrieved successfully.',
        );
    }

    /**
     * Create an invoice series
     *
     * @bodyParam agency_id integer required Agency that issues. Example: 1
     * @bodyParam document_type string required `boleta` or `factura`. Example: boleta
     * @bodyParam series string required Four alphanumeric characters. Example: B001
     * @bodyParam issuer_ruc string required Issuer RUC (11 digits). Example: 20123456789
     * @bodyParam issuer_name string required Legal name. Example: Logistics Peru SAC
     * @bodyParam issuer_trade_name string optional Trade name. Example: Logistics
     * @bodyParam issuer_address string optional Fiscal address. Example: Av. Siempre Viva 123
     * @bodyParam issuer_ubigeo string optional Six-digit ubigeo. Example: 150101
     * @bodyParam establishment_code string optional SUNAT establishment code. Example: 0000
     * @bodyParam is_active boolean optional Defaults to true. Example: true
     * @bodyParam is_default boolean optional Defaults to false. Example: true
     *
     * @apiResource Src\Finance\Invoice\Resources\InvoiceSeriesResource
     *
     * @apiResourceModel Src\Finance\Invoice\Models\InvoiceSeries
     */
    public function store(InvoiceSeriesRequest $request, AgencyScope $scope): JsonResponse
    {
        $data = $request->validated();

        if (! $scope->covers((int) $data['agency_id'])) {
            throw new BusinessRuleException(
                message: 'The agency is outside your scope.',
                errorCode: 'CASH_AGENCY_SCOPE',
                statusCode: 403,
            );
        }

        $series = DB::transaction(function () use ($data): InvoiceSeries {
            $this->guardDuplicate($data);

            if ($data['is_default'] ?? false) {
                InvoiceSeries::query()
                    ->where('agency_id', $data['agency_id'])
                    ->where('document_type', $data['document_type'])
                    ->update(['is_default' => false]);
            }

            return InvoiceSeries::create([
                ...$data,
                'next_number' => 1,
                'is_active' => $data['is_active'] ?? true,
                'is_default' => $data['is_default'] ?? false,
            ]);
        });

        return $this->success(new InvoiceSeriesResource($series), 'Invoice series created successfully.', 201);
    }

    /**
     * Update an invoice series
     *
     * @urlParam series integer required Invoice series id. Example: 1
     *
     * @apiResource Src\Finance\Invoice\Resources\InvoiceSeriesResource
     *
     * @apiResourceModel Src\Finance\Invoice\Models\InvoiceSeries
     */
    public function update(int $series, InvoiceSeriesRequest $request, AgencyScope $scope): JsonResponse
    {
        $model = DB::transaction(function () use ($series, $request, $scope): InvoiceSeries {
            $model = InvoiceSeries::query()->lockForUpdate()->find($series);

            if ($model === null) {
                throw new BusinessRuleException(
                    message: 'The invoice series does not exist.',
                    errorCode: 'INVOICE_SERIES_NOT_FOUND',
                    statusCode: 404,
                );
            }

            if (! $scope->covers((int) $model->agency_id)) {
                throw new BusinessRuleException(
                    message: 'The agency is outside your scope.',
                    errorCode: 'CASH_AGENCY_SCOPE',
                    statusCode: 403,
                );
            }

            $data = $request->validated();

            if (isset($data['agency_id']) && (int) $data['agency_id'] !== (int) $model->agency_id
                && Invoice::query()->where('invoice_series_id', $model->id)->exists()) {
                throw new BusinessRuleException(
                    message: 'The series is already used by invoices.',
                    errorCode: 'INVOICE_SERIES_IN_USE',
                );
            }

            if (($data['is_default'] ?? false) === true) {
                InvoiceSeries::query()
                    ->where('agency_id', $data['agency_id'] ?? $model->agency_id)
                    ->where('document_type', $data['document_type'] ?? $model->document_type)
                    ->whereKeyNot($model->id)
                    ->update(['is_default' => false]);
            }

            $model->fill($data);
            $model->save();

            return $model->refresh();
        });

        return $this->success(new InvoiceSeriesResource($model), 'Invoice series updated successfully.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function guardDuplicate(array $data): void
    {
        $exists = InvoiceSeries::query()
            ->where('agency_id', $data['agency_id'])
            ->where('document_type', $data['document_type'])
            ->where('series', $data['series'])
            ->exists();

        if ($exists) {
            throw new BusinessRuleException(
                message: 'That series already exists for the agency and document type.',
                errorCode: 'INVOICE_SERIES_DUPLICATED',
                statusCode: 409,
            );
        }
    }
}
