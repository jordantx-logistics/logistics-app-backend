<?php

namespace Src\Shared\Contracts;

/**
 * Puerto hacia el contexto `Customer` (personas y empresas).
 *
 * El contexto `Logistics/Order` necesita saber si un `sender_id`/`receiver_id`
 * existe, pero no puede consultar `persons` directamente (ADR-0006 y
 * `spec/02-objetivo/01-contextos-delimitados.md`: sin modelos ajenos, sin JOIN
 * cruzado). El adaptador que sí conoce ese modelo vive en `Src\Customer\Adapters`
 * y se enlaza en el composition root (`app/Providers/DomainServiceProvider.php`).
 */
interface CustomerDirectory
{
    /**
     * ¿Existe una persona (cliente natural) con ese id?
     */
    public function personExists(int $id): bool;

    /**
     * Email de contacto de la persona, si lo tiene (en `persons` es opcional).
     * Lo usa el correo de confirmación de la orden.
     */
    public function personEmail(int $id): ?string;
}
