<?php

namespace Src\Shared\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Archiva un mes antes de borrarlo. Es lo único que se interpone entre un
 * `DROP PARTITION` y una pérdida irreversible, así que no da nada por bueno sin
 * comprobarlo: vuelve a leer del disco de archivo lo que acaba de subir.
 */
class PartitionArchiver
{
    public function __construct(private readonly ReadWriteRouting $routing) {}

    /**
     * @return array{path: string, rows: int, sha256: string, bytes: int}
     *
     * @throws RuntimeException si el archivo no coincide con la partición
     */
    public function archive(string $table, string $partition): array
    {
        $disk = (string) config('partitions.archive_disk');
        $path = sprintf('%s/%s/%s.ndjson.gz', trim((string) config('partitions.archive_path'), '/'), $table, $partition);
        $temporary = tempnam(sys_get_temp_dir(), 'partition');

        try {
            // Un mes vencido ya no cambia: se exporta de la réplica para no cargar el
            // primary. El recuento que da la orden de borrar sí sale del primary.
            $this->routing->allowReplica();

            try {
                $exported = $this->export($table, $partition, $temporary);
            } finally {
                $this->routing->pinToPrimary();
            }

            $rows = (int) DB::selectOne("SELECT COUNT(*) AS total FROM `{$table}` PARTITION (`{$partition}`)")->total;

            if ($rows !== $exported) {
                throw new RuntimeException("{$table}.{$partition}: exported {$exported} rows, the primary has {$rows}.");
            }

            $sha256 = hash_file('sha256', $temporary);
            $bytes = (int) filesize($temporary);

            $stream = fopen($temporary, 'rb');
            Storage::disk($disk)->writeStream($path, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }

            $this->verify($disk, $path, $sha256, $rows);

            return ['path' => $path, 'rows' => $rows, 'sha256' => $sha256, 'bytes' => $bytes];
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function export(string $table, string $partition, string $file): int
    {
        $gzip = gzopen($file, 'wb6');
        $batch = max(1, (int) config('partitions.batch'));
        $lastId = 0;
        $count = 0;

        try {
            do {
                $rows = DB::select(
                    "SELECT * FROM `{$table}` PARTITION (`{$partition}`) WHERE id > ? ORDER BY id LIMIT {$batch}",
                    [$lastId],
                );

                foreach ($rows as $row) {
                    gzwrite($gzip, json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n");
                    $lastId = $row->id;
                    $count++;
                }
            } while (count($rows) === $batch);
        } finally {
            gzclose($gzip);
        }

        return $count;
    }

    /**
     * Baja el archivo otra vez y comprueba checksum y número de líneas.
     */
    private function verify(string $disk, string $path, string $sha256, int $rows): void
    {
        $copy = tempnam(sys_get_temp_dir(), 'partition-verify');

        try {
            $source = Storage::disk($disk)->readStream($path);

            if (! is_resource($source)) {
                throw new RuntimeException("Archive {$path} cannot be read back.");
            }

            $target = fopen($copy, 'wb');
            stream_copy_to_stream($source, $target);
            fclose($target);
            fclose($source);

            if (hash_file('sha256', $copy) !== $sha256) {
                throw new RuntimeException("Archive {$path} does not match its checksum.");
            }

            $gzip = gzopen($copy, 'rb');
            $lines = 0;

            while (gzgets($gzip) !== false) {
                $lines++;
            }

            gzclose($gzip);

            if ($lines !== $rows) {
                throw new RuntimeException("Archive {$path} has {$lines} rows, expected {$rows}.");
            }
        } finally {
            if (is_file($copy)) {
                unlink($copy);
            }
        }
    }
}
