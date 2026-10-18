<?php

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
| Los traits compartidos (p. ej. RefreshDatabase) viven en Tests\TestCase para que
| apliquen también a las clases de test con estilo PHPUnit.
|
*/

pest()->extend(TestCase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Siembra el catálogo de permisos/roles (idempotente) y devuelve un usuario con
 * el rol `admin`, que los tiene todos. Es el usuario por defecto de los tests
 * que ejercitan una ruta de negocio: desde la Fase 1 toda ruta lleva `permission:`,
 * así que un usuario pelado recibiría 403.
 */
function adminUser(): User
{
    (new PermissionSeeder)->run();

    $user = User::factory()->create();
    $user->assignRole('admin');

    return $user;
}

/**
 * Cabecera `Authorization` con un token de Sanctum recién emitido (modo app).
 *
 * @return array<string, string>
 */
function tokenHeadersFor(User $user, string $device = 'test'): array
{
    return ['Authorization' => 'Bearer '.$user->createToken($device)->plainTextToken];
}
