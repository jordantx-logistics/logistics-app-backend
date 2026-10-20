<?php

use Src\Logistics\Order\Models\Order;

/*
|--------------------------------------------------------------------------
| Volumen de órdenes (SLO de la Fase 3)
|--------------------------------------------------------------------------
|
| El comando existe para medir el listado con 100.000 órdenes en un entorno de
| verdad (`--explain` incluido). El test solo comprueba que siembra, que es
| idempotente y que el plan de la consulta se puede pedir.
|
*/

it('siembra órdenes de volumen sin duplicar al repetir', function () {
    $this->artisan('orders:seed-volume', ['--count' => 2000, '--chunk' => 500])
        ->assertSuccessful();

    expect(Order::query()->count())->toBe(2000);

    $this->artisan('orders:seed-volume', ['--count' => 2000, '--chunk' => 500])
        ->assertSuccessful();

    expect(Order::query()->count())->toBe(2000)
        ->and(Order::query()->distinct()->count('ticket_code'))->toBe(2000);
});

it('muestra el plan de la consulta del listado', function () {
    $this->artisan('orders:seed-volume', ['--count' => 100, '--explain' => true])
        ->expectsOutputToContain('EXPLAIN del listado')
        ->assertSuccessful();
});
