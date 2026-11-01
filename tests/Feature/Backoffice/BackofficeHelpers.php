<?php

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Str;
use Src\Logistics\Order\Models\Order;
use Src\Organization\Models\Agency;

function fleetLessUser(string $role, ?Agency $agency = null): User
{
    (new PermissionSeeder)->run();

    $user = User::factory()->create();
    $user->assignRole($role);

    if ($agency !== null) {
        $user->agencies()->attach($agency->id, ['is_primary' => true]);
    }

    return $user->refresh();
}

function backofficeAgency(string $code): Agency
{
    return Agency::factory()->create(['code' => $code, 'is_active' => true]);
}

function backofficeOrder(Agency $origin, Agency $destination, array $attributes = []): Order
{
    return Order::factory()->create(array_merge([
        'origin_agency_id' => $origin->id,
        'destination_agency_id' => $destination->id,
        'idempotency_key' => (string) Str::uuid(),
    ], $attributes));
}
