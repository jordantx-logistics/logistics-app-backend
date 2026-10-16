<?php

namespace Src\Auth\UseCases;

use App\Models\User;
use Illuminate\Validation\ValidationException;

class LoginUseCase
{
    /**
     * @return array{user: User, token: string, expires_in: int}
     */
    public function execute(array $credentials): array
    {
        $token = auth('api')->attempt([
            'email' => $credentials['email'],
            'password' => $credentials['password'],
        ]);

        if (! $token) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        return [
            'user' => auth('api')->user(),
            'token' => $token,
            'expires_in' => auth('api')->factory()->getTTL() * 60,
        ];
    }
}
