<?php

namespace Src\Reporting\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Src\Reporting\Models\ReportExport;
use Src\Reporting\Requests\StoreReportExportRequest;
use Src\Reporting\Resources\ReportExportResource;
use Src\Reporting\Services\ReportCatalog;
use Src\Reporting\UseCases\RequestReportExportUseCase;
use Src\Shared\Exceptions\BusinessRuleException;
use Src\Shared\Scope\AgencyScope;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @group Reports
 *
 * Exportaciones asíncronas de reportes operativos y financieros.
 */
class ReportExportController extends Controller
{
    /**
     * List available reports
     *
     * @response 200 scenario="Catalog" {"success": true, "message": "Reports retrieved successfully.", "data": [{"key": "orders", "title": "Orders", "columns": ["Ticket"], "statuses": ["pending"]}]}
     */
    public function catalog(ReportCatalog $catalog): JsonResponse
    {
        return $this->success($catalog->describe(), 'Reports retrieved successfully.');
    }

    /**
     * List my exports
     *
     * @queryParam per_page integer Rows per page (1..100). Example: 15
     *
     * @apiResourceCollection Src\Reporting\Resources\ReportExportResource
     *
     * @apiResourceModel Src\Reporting\Models\ReportExport paginate=15
     *
     * @apiResourceAdditional success=true message="Exports retrieved successfully."
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);

        return $this->successPaginated(
            resource: ReportExportResource::collection(
                ReportExport::query()
                    ->where('user_id', (int) auth()->id())
                    ->orderByDesc('id')
                    ->paginate((int) ($data['per_page'] ?? 15)),
            ),
            message: 'Exports retrieved successfully.',
        );
    }

    /**
     * Request an export
     *
     * The file is generated in the background: poll the export until it is
     * `ready` or `failed`.
     *
     * @apiResource status=202 Src\Reporting\Resources\ReportExportResource
     *
     * @apiResourceModel Src\Reporting\Models\ReportExport
     */
    public function store(StoreReportExportRequest $request, RequestReportExportUseCase $useCase, AgencyScope $scope): JsonResponse
    {
        $export = $useCase->execute($request->validated(), (int) auth()->id(), $scope);

        return $this->success(new ReportExportResource($export), 'Export requested successfully.', 202);
    }

    /**
     * Show an export
     *
     * @urlParam export integer required Export id. Example: 1
     *
     * @apiResource Src\Reporting\Resources\ReportExportResource
     *
     * @apiResourceModel Src\Reporting\Models\ReportExport
     */
    public function show(int $export): JsonResponse
    {
        return $this->success(new ReportExportResource($this->mine($export)), 'Export retrieved successfully.');
    }

    /**
     * Download an export
     *
     * @urlParam export integer required Export id. Example: 1
     *
     * @response 200 scenario="File" binary
     * @response 409 scenario="Not ready" {"success": false, "message": "The export is not ready.", "error_code": "REPORT_NOT_READY"}
     */
    public function download(int $export): StreamedResponse
    {
        $model = $this->mine($export);
        $disk = Storage::disk((string) config('reporting.disk'));

        if ($model->status !== ReportExport::STATUS_READY || $model->path === null || ! $disk->exists($model->path)) {
            throw new BusinessRuleException(
                message: 'The export is not ready.',
                errorCode: 'REPORT_NOT_READY',
                statusCode: 409,
            );
        }

        return $disk->download($model->path, "{$model->report}-{$model->id}.{$model->format}");
    }

    /**
     * Las exportaciones son de quien las pidió: la de otro responde igual que una
     * que no existe.
     */
    private function mine(int $id): ReportExport
    {
        $export = ReportExport::query()->where('user_id', (int) auth()->id())->find($id);

        if ($export === null) {
            throw new BusinessRuleException(
                message: 'The export does not exist.',
                errorCode: 'REPORT_NOT_FOUND',
                statusCode: 404,
            );
        }

        return $export;
    }
}
