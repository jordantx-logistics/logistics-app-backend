<?php

namespace Src\Finance\Cash\Jobs;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Storage;
use Src\Finance\Cash\Models\CashClosure;
use Src\Shared\Contracts\AgencyDirectory;
use Src\Shared\Services\ProcessedEvents;

/**
 * Genera el PDF de cierre de caja (cola `reports`).
 *
 * Se dispara con `cash.closed`; deja la ruta en `cash_closures.report_path` y
 * la descarga se sirve por un endpoint autenticado, nunca exponiendo el path.
 */
final class GenerateCashCloseReportJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $outboxEventId,
        public readonly array $payload,
    ) {
        $this->onQueue('reports');
    }

    public function handle(ProcessedEvents $processed, AgencyDirectory $agencies): void
    {
        $closure = CashClosure::query()->find((int) ($this->payload['cash_closure_id'] ?? 0));

        if ($closure === null) {
            return;
        }

        $processed->once($this->outboxEventId, self::class, function () use ($closure, $agencies): void {
            $session = $closure->cashSession;
            $agencyCode = $agencies->agencyCode((int) $session->agency_id) ?? 'AG'.$session->agency_id;
            $path = sprintf(
                '%s/%s/%s/%s/cash-session-%d.pdf',
                trim((string) config('finance.cash_report_path', 'finance/cash-reports'), '/'),
                $agencyCode,
                $session->closed_at?->format('Y') ?? now()->format('Y'),
                $session->closed_at?->format('m') ?? now()->format('m'),
                $session->id,
            );

            $rows = $session->movements()->get()->map(fn ($movement): string => sprintf(
                '<tr><td>%s</td><td>%s</td><td>S/ %s</td><td>%s</td></tr>',
                e((string) $movement->created_at?->format('Y-m-d H:i')),
                e((string) $movement->type),
                e((string) $movement->amount),
                e((string) ($movement->note ?? '')),
            ))->implode('');

            $html = '<h1>Cierre de caja</h1>'
                .'<p>'.e($agencyCode).' · Sesión #'.e((string) $session->id).'</p>'
                .'<table>'
                .'<tr><th>Apertura</th><td>S/ '.e((string) $session->opening_amount).'</td></tr>'
                .'<tr><th>Esperado</th><td>S/ '.e((string) $closure->expected_amount).'</td></tr>'
                .'<tr><th>Contado</th><td>S/ '.e((string) $closure->closing_amount).'</td></tr>'
                .'<tr><th>Diferencia</th><td>S/ '.e((string) $closure->difference).'</td></tr>'
                .'</table>'
                .'<table><tr><th>Fecha</th><th>Tipo</th><th>Importe</th><th>Nota</th></tr>'.$rows.'</table>'
                .'<p>Generado automáticamente.</p>';

            $pdf = Pdf::loadHTML(
                '<!doctype html><html lang="es"><head><meta charset="utf-8"><style>'
                .'body{font-family:DejaVu Sans,sans-serif;font-size:12px}'
                .'table{width:100%;border-collapse:collapse;margin:12px 0}'
                .'th,td{border:1px solid #ddd;padding:6px;text-align:left}'
                .'th{background:#f4f4f4}'
                .'</style></head><body>'.$html.'</body></html>',
            )->setPaper('a4')->output();

            Storage::disk((string) config('finance.disk', 'local'))->put($path, $pdf);
            $closure->update(['report_path' => $path]);
        });
    }
}
