<?php

namespace Src\System\Logging;

use Monolog\Formatter\JsonFormatter;
use Monolog\LogRecord;

/**
 * JSON en una línea con `context` y `extra` aplanados en la raíz.
 *
 * Así un colector de logs puede indexar `request_id`, `route` o `duration_ms`
 * sin tener que leer dentro de subobjetos. Las claves propias del registro
 * (`message`, `level`, `channel`, `datetime`) no se pueden sobrescribir.
 */
class FlatJsonFormatter extends JsonFormatter
{
    protected function normalizeRecord(LogRecord $record): array
    {
        $normalized = parent::normalizeRecord($record);

        $context = $normalized['context'] ?? [];
        $extra = $normalized['extra'] ?? [];

        unset($normalized['context'], $normalized['extra']);

        return [
            ...(is_array($context) ? $context : []),
            ...(is_array($extra) ? $extra : []),
            ...$normalized,
        ];
    }
}
