<?php

namespace Src\Reporting\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Src\Reporting\Models\ReportExport;

/**
 * Borra las exportaciones vencidas, con su archivo.
 */
class PurgeExpiredReportExportsCommand extends Command
{
    protected $signature = 'reports:purge-expired';

    protected $description = 'Delete report exports past their retention';

    public function handle(): int
    {
        $disk = Storage::disk((string) config('reporting.disk'));

        ReportExport::query()
            ->where('expires_at', '<', now())
            ->lazyById(200)
            ->each(function (ReportExport $export) use ($disk): void {
                if ($export->path !== null) {
                    $disk->delete($export->path);
                }

                $export->delete();
            });

        return self::SUCCESS;
    }
}
