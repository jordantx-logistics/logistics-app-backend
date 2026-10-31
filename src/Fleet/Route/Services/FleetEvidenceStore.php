<?php

namespace Src\Fleet\Route\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Comprobantes y fotos de odómetro en el disco de Flota (`FLEET_DISK`). La base
 * guarda la ruta relativa; el archivo solo sale por endpoints autenticados.
 */
class FleetEvidenceStore
{
    public function store(UploadedFile $file, int $routeId, string $kind): string
    {
        $path = sprintf(
            '%s/%d/%s-%s.%s',
            (string) config('fleet.evidence_path', 'fleet'),
            $routeId,
            $kind,
            Str::uuid(),
            $file->extension(),
        );

        Storage::disk($this->disk())->put($path, $file->getContent());

        return $path;
    }

    public function delete(?string $path): void
    {
        if ($path !== null) {
            Storage::disk($this->disk())->delete($path);
        }
    }

    public function exists(?string $path): bool
    {
        return $path !== null && Storage::disk($this->disk())->exists($path);
    }

    public function download(string $path): StreamedResponse
    {
        return Storage::disk($this->disk())->download($path);
    }

    private function disk(): string
    {
        return (string) config('fleet.disk', 'local');
    }
}
