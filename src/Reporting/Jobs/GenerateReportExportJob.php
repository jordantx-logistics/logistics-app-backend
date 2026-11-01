<?php

namespace Src\Reporting\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Storage;
use Src\Reporting\Models\ReportExport;
use Src\Reporting\Services\ReportCatalog;
use Src\Reporting\Services\ReportFileWriter;
use Src\Shared\Contracts\Metrics;
use Src\Shared\DTOs\ReportFilters;
use Src\Shared\Exceptions\BusinessRuleException;
use Throwable;

/**
 * Genera el archivo de una exportación (cola `reports`). Escribe primero en un
 * temporal: un reintento nunca deja a medias lo que ya estaba en el disco.
 */
final class GenerateReportExportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 280;

    /**
     * @param  array{export_id?: int}  $payload
     */
    public function __construct(
        public readonly int $outboxEventId,
        public readonly array $payload,
    ) {
        $this->onQueue('reports');
    }

    public function handle(ReportCatalog $catalog, ReportFileWriter $writer, Metrics $metrics): void
    {
        $export = ReportExport::query()->find((int) ($this->payload['export_id'] ?? 0));

        if ($export === null || ! $this->claim($export)) {
            return;
        }

        $source = $catalog->find($export->report);

        if ($source === null) {
            $this->fail($export, 'REPORT_UNKNOWN', $metrics);

            return;
        }

        $temporary = tempnam(sys_get_temp_dir(), 'report');

        try {
            $count = $writer->write(
                $export->format,
                $source->title(),
                $source->columns(),
                $source->rows($this->filters($export)),
                $temporary,
            );

            $path = sprintf(
                '%s/%s/%d-%s.%s',
                trim((string) config('reporting.path'), '/'),
                now()->format('Y/m'),
                $export->id,
                $export->report,
                $export->format,
            );

            $stream = fopen($temporary, 'rb');
            Storage::disk((string) config('reporting.disk'))->writeStream($path, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }

            $export->update([
                'status' => ReportExport::STATUS_READY,
                'path' => $path,
                'row_count' => $count,
                'completed_at' => now(),
                'expires_at' => $this->expiry(),
            ]);

            $this->count($export, $metrics);
        } catch (BusinessRuleException $exception) {
            // Reintentar no cambiaría el resultado: se avisa de una vez.
            $this->fail($export, $exception->errorCode, $metrics);
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /**
     * Reclama la exportación con un UPDATE condicional. Aquí no sirve
     * `ProcessedEvents`: mantendría una transacción abierta mientras dura el archivo.
     * Una en `processing` solo se retoma si superó el tiempo del job (worker caído).
     */
    private function claim(ReportExport $export): bool
    {
        $claimed = ReportExport::query()
            ->whereKey($export->id)
            ->where(fn ($query) => $query
                ->where('status', ReportExport::STATUS_PENDING)
                ->orWhere(fn ($stale) => $stale
                    ->where('status', ReportExport::STATUS_PROCESSING)
                    ->where('started_at', '<', now()->subSeconds($this->timeout))))
            ->update(['status' => ReportExport::STATUS_PROCESSING, 'started_at' => now()]);

        $export->refresh();

        return $claimed === 1;
    }

    /**
     * Fallo agotados los reintentos: sin esto, la exportación quedaría `processing`
     * para siempre y la pantalla seguiría esperando.
     */
    public function failed(Throwable $exception): void
    {
        $export = ReportExport::query()->find((int) ($this->payload['export_id'] ?? 0));

        if ($export !== null) {
            $this->fail($export, 'REPORT_GENERATION_FAILED', app(Metrics::class));
        }
    }

    private function fail(ReportExport $export, string $errorCode, Metrics $metrics): void
    {
        $export->update([
            'status' => ReportExport::STATUS_FAILED,
            'error_code' => $errorCode,
            'completed_at' => now(),
            'expires_at' => $this->expiry(),
        ]);

        $this->count($export, $metrics);
    }

    private function count(ReportExport $export, Metrics $metrics): void
    {
        $metrics->increment(
            'report_exports_total',
            ['report' => $export->report, 'format' => $export->format, 'status' => $export->status],
            help: 'Report exports by final status',
        );
    }

    private function expiry(): CarbonImmutable
    {
        return CarbonImmutable::now()->addDays((int) config('reporting.retention_days'));
    }

    private function filters(ReportExport $export): ReportFilters
    {
        return new ReportFilters(
            from: CarbonImmutable::parse($export->filters['from'])->startOfDay(),
            to: CarbonImmutable::parse($export->filters['to'])->endOfDay(),
            agencyIds: $export->filters['agency_ids'] ?? null,
            status: $export->filters['status'] ?? null,
        );
    }
}
