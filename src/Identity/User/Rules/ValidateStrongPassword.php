<?php

namespace Src\Identity\User\Rules;

use Closure;
use Src\Shared\Exceptions\BusinessRuleException;

/**
 * Política de contraseña (`PASSWORD_TOO_WEAK`): mínimo 12 caracteres y variedad
 * (al menos una letra y un dígito), según el ejemplo de
 * `spec/04-modulos/identity/07-reglas.md`.
 */
class ValidateStrongPassword
{
    private const MIN_LENGTH = 12;

    public function handle(array $payload, Closure $next): mixed
    {
        $password = (string) ($payload['password'] ?? '');

        $hasLetter = preg_match('/[A-Za-z]/', $password) === 1;
        $hasDigit = preg_match('/\d/', $password) === 1;

        if (strlen($password) < self::MIN_LENGTH || ! $hasLetter || ! $hasDigit) {
            throw new BusinessRuleException(
                message: 'The password does not meet the security policy.',
                errorCode: 'PASSWORD_TOO_WEAK',
                statusCode: 422,
            );
        }

        return $next($payload);
    }
}
