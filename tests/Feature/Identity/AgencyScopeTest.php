<?php

use Illuminate\Support\Facades\Route;
use Src\Organization\Models\Agency;
use Src\Shared\Scope\AgencyScope;

/*
|--------------------------------------------------------------------------
| Ámbito de agencia (Fase 2, paso 2)
|--------------------------------------------------------------------------
|
| El ámbito se resuelve una vez por petición en `ResolveAgencyScope` y llega a
| los casos de uso como `AgencyScope`; los modelos lo aplican con el trait
| `CanBeScopedByAgency`. Un rol nacional (`admin`, `analyst`) llega sin filtro,
| un usuario operativo con sus agencias y una petición sin sesión, vacío.
|
*/

it('acota las consultas al ámbito recibido', function () {
    $propia = Agency::factory()->create();
    Agency::factory()->create();

    expect(Agency::query()->forAgencyScope(AgencyScope::forAgencies([$propia->id]))->pluck('id')->all())
        ->toBe([$propia->id])
        ->and(Agency::query()->forAgencyScope(AgencyScope::unrestricted())->count())->toBe(2)
        ->and(Agency::query()->forAgencyScope(AgencyScope::none())->count())->toBe(0);
});

it('resuelve el ámbito del usuario en cada petición', function () {
    Route::middleware('api')->get('api/v1/temp-agency-scope', fn () => response()->json([
        'unrestricted' => app(AgencyScope::class)->unrestricted,
        'ids' => app(AgencyScope::class)->ids,
    ]));

    $propia = Agency::factory()->create();
    Agency::factory()->create();

    $supervisor = adminUser();
    $supervisor->removeRole('admin');
    $supervisor->assignRole('agency-supervisor');
    $supervisor->agencies()->attach($propia);

    $this->actingAs($supervisor)
        ->getJson('api/v1/temp-agency-scope')
        ->assertExactJson(['unrestricted' => false, 'ids' => [$propia->id]]);

    // Sin sesión el ámbito es vacío, nunca «todo».
    $this->app['auth']->forgetGuards();

    $this->getJson('api/v1/temp-agency-scope')
        ->assertExactJson(['unrestricted' => false, 'ids' => []]);

    $this->actingAs(adminUser())
        ->getJson('api/v1/temp-agency-scope')
        ->assertExactJson(['unrestricted' => true, 'ids' => []]);
});

it('distingue superadmin de rol nacional de lectura', function () {
    $admin = adminUser();

    expect($admin->isSuperAdmin())->toBeTrue()
        ->and($admin->hasNationalScope())->toBeTrue();

    $analyst = adminUser();
    $analyst->removeRole('admin');
    $analyst->assignRole('analyst');

    expect($analyst->isSuperAdmin())->toBeFalse()
        ->and($analyst->hasNationalScope())->toBeTrue();
});
