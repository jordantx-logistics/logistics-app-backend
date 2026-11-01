<?php

namespace Src\Shared\Contracts;

/**
 * Puerto de lectura de los parámetros de negocio editables.
 *
 * Flota y el motor de precios preguntan por aquí en vez de leer `config()`: el
 * admin puede cambiar el valor sin desplegar. El adaptador vive en
 * `Src\Settings\Adapters` y, sin valor guardado, responde con `config()`.
 */
interface SettingsReader
{
    public function get(string $key): mixed;
}
