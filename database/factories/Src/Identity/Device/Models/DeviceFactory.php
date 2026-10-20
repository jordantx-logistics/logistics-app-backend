<?php

namespace Database\Factories\Src\Identity\Device\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Src\Identity\Device\Models\Device;

/**
 * `user_id` es FK intra-Identity hacia `users`, así que por defecto la factory
 * crea el usuario.
 *
 * @extends Factory<Device>
 */
class DeviceFactory extends Factory
{
    protected $model = Device::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'device_name' => $this->faker->randomElement(['PDA-A1', 'Pixel 8', 'iPhone 15']),
            'platform' => $this->faker->randomElement(Device::PLATFORMS),
            'device_uuid' => (string) Str::uuid(),
            'push_token' => null,
            'app_version' => '1.0.0',
            'status' => Device::STATUS_ACTIVE,
            'last_seen_at' => now(),
            'revoked_at' => null,
        ];
    }
}
