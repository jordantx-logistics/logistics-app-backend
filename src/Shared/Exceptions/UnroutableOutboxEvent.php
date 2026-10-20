<?php

namespace Src\Shared\Exceptions;

use RuntimeException;

/**
 * El evento no tiene consumidores registrados en `OutboxEventMap`.
 *
 * Es un hueco de configuración, no un fallo transitorio: reintentarlo con
 * backoff no arreglaría nada, así que el consumidor lo manda directo a `failed`
 * para que se vea (y se reprocese a mano cuando el contexto que lo consume
 * exista).
 */
class UnroutableOutboxEvent extends RuntimeException
{
    public static function forEventType(string $eventType): self
    {
        return new self("Ningún contexto consume el evento `{$eventType}`.");
    }
}
