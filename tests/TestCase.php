<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * Base para los tests que necesitan la aplicación arrancada.
 *
 * RefreshDatabase vive aquí (y no en tests/Pest.php) porque los traits
 * registrados con Pest::use() no se aplican a las clases de test con estilo
 * PHPUnit, solo a los tests escritos con funciones (`it`/`test`).
 */
abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;
}
