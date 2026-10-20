<?php

namespace Src\Shared\Contracts;

/**
 * Puerto hacia el contexto `Customer` (agregado `customers`).
 *
 * El contexto `Logistics/Order` necesita saber si un `sender_id`/`receiver_id`
 * existe, pero no puede consultar `customers` directamente (ADR-0006 y
 * `spec/02-objetivo/01-contextos-delimitados.md`: sin modelos ajenos, sin JOIN
 * cruzado). El adaptador que sí conoce ese modelo vive en `Src\Customer\Adapters`
 * y se enlaza en el composition root (`app/Providers/DomainServiceProvider.php`).
 *
 * Desde la Fase 3 los ids de `orders.sender_id`/`receiver_id` son **clientes**
 * (no personas): el backfill de `customers` repunta las órdenes históricas.
 */
interface CustomerDirectory
{
    /**
     * ¿Existe un cliente con ese id?
     */
    public function customerExists(int $id): bool;

    /**
     * Email de contacto del cliente, si lo tiene (es opcional).
     * Lo usa el correo de confirmación de la orden.
     */
    public function customerEmail(int $id): ?string;

    /**
     * Nombre visible del cliente (persona o razón social).
     *
     * Lo usa la guía de remisión: el documento que viaja con el paquete no
     * puede mostrar ids. `null` si el cliente ya no existe.
     */
    public function customerDisplayName(int $id): ?string;
}
