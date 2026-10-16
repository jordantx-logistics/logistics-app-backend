<?php

namespace Src\Auth\UseCases;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

class RegisterUseCase
{
    public function __construct(
        private readonly LoginUseCase $loginUseCase
    ) {}

    /**
     * @return array{user: User, token: string, expires_in: int}
     */
    public function execute(array $data): array
    {
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        // Optional: Assign a default role
        // $user->assignRole('customer');

        return $this->loginUseCase->execute([
            'email' => $data['email'],
            'password' => $data['password'],
        ]);
    }
}
