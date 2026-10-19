<?php

namespace Src\Shared\Scope;

/**
 * Ámbito de agencia de la petición (paso 2.6 de la Fase 2).
 *
 * Es el «qué puede ver este usuario» ya resuelto: el middleware
 * `Src\System\Middleware\ResolveAgencyScope` lo construye a partir del usuario
 * autenticado y lo deja en el contenedor, y los casos de uso lo piden por
 * constructor en lugar de volver a resolver el usuario. Un rol nacional
 * (`admin`, `analyst`) llega como `unrestricted`; un usuario operativo, con sus
 * agencias; y sin sesión, vacío (`none()`, que no ve nada).
 *
 * Vive en `Shared` porque lo usan varios contextos (`Organization`,
 * `Logistics/Order`, Identity) y `Shared` es el único namespace compartido
 * (ADR-0006).
 */
final class AgencyScope
{
    /**
     * @param  list<int>  $ids
     */
    public function __construct(
        public readonly bool $unrestricted,
        public readonly array $ids,
    ) {}

    /**
     * Ámbito vacío: no ve nada. Es el ámbito de una petición sin sesión y el de
     * un usuario operativo sin agencias (un error de configuración, no un
     * nacional).
     */
    public static function none(): self
    {
        return new self(unrestricted: false, ids: []);
    }

    /**
     * Ámbito acotado a esas agencias.
     *
     * @param  list<int>  $ids
     */
    public static function forAgencies(array $ids): self
    {
        return new self(unrestricted: false, ids: array_values($ids));
    }

    /**
     * Sin filtro de agencia: rol nacional (`admin`, `analyst`).
     */
    public static function unrestricted(): self
    {
        return new self(unrestricted: true, ids: []);
    }

    /**
     * ¿El ámbito alcanza esa agencia? Es la regla por registro de las policies
     * (`AgencyPolicy`).
     */
    public function covers(int $agencyId): bool
    {
        return $this->unrestricted || in_array($agencyId, $this->ids, true);
    }
}
