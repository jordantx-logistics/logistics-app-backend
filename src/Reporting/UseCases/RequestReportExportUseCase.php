<?php

namespace Src\Reporting\UseCases;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Src\Reporting\Models\ReportExport;
use Src\Reporting\Services\ReportCatalog;
use Src\Shared\Exceptions\BusinessRuleException;
use Src\Shared\Models\OutboxEvent;
use Src\Shared\Scope\AgencyScope;

/**
 * Registra una exportación y la encola. El ámbito se fija aquí: el job no conoce
 * al usuario, y si su ámbito cambia luego no altera lo ya solicitado.
 */
class RequestReportExportUseCase
{
    public function __construct(
        private readonly ReportCatalog $catalog,
    ) {}

    /**
     * @param  array{report: string, format: string, from: string, to: string, agency_id?: int|null, status?: string|null}  $data
     */
    public function execute(array $data, int $userId, AgencyScope $scope): ReportExport
    {
        $source = $this->catalog->find($data['report']);

        if ($source === null) {
            throw new BusinessRuleException(
                message: 'The report does not exist.',
                errorCode: 'REPORT_UNKNOWN',
            );
        }

        $status = $data['status'] ?? null;

        if ($status !== null && ! in_array($status, $source->statuses(), true)) {
            throw new BusinessRuleException(
                message: 'The report cannot be filtered by that status.',
                errorCode: 'REPORT_STATUS_INVALID',
            );
        }

        $this->assertRange($data['from'], $data['to']);

        $agencyIds = $this->agencyIds($data['agency_id'] ?? null, $scope);

        return DB::transaction(function () use ($data, $userId, $agencyIds, $status): ReportExport {
            $export = ReportExport::query()->create([
                'user_id' => $userId,
                'report' => $data['report'],
                'format' => $data['format'],
                'status' => ReportExport::STATUS_PENDING,
                'filters' => [
                    'from' => $data['from'],
                    'to' => $data['to'],
                    'agency_ids' => $agencyIds,
                    'status' => $status,
                ],
            ]);

            OutboxEvent::create([
                'event_type' => 'report.export-requested',
                'payload' => ['export_id' => $export->id],
            ]);

            return $export;
        });
    }

    private function assertRange(string $from, string $to): void
    {
        $days = CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to), false);

        if ($days > (int) config('reporting.max_range_days')) {
            throw new BusinessRuleException(
                message: 'The date range is too long.',
                errorCode: 'REPORT_RANGE_TOO_LONG',
            );
        }
    }

    /**
     * @return list<int>|null `null` es alcance nacional
     */
    private function agencyIds(?int $agencyId, AgencyScope $scope): ?array
    {
        if ($agencyId !== null) {
            if (! $scope->covers($agencyId)) {
                throw $this->outOfScope();
            }

            return [$agencyId];
        }

        if ($scope->unrestricted) {
            return null;
        }

        if ($scope->ids === []) {
            throw $this->outOfScope();
        }

        return $scope->ids;
    }

    private function outOfScope(): BusinessRuleException
    {
        return new BusinessRuleException(
            message: 'The agency is outside your scope.',
            errorCode: 'REPORT_AGENCY_SCOPE',
            statusCode: 403,
        );
    }
}
