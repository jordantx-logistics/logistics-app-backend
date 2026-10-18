<?php

namespace Src\Auth\UseCases;

use Illuminate\Support\Facades\Password;

/**
 * Envía el enlace de recuperación. La respuesta al cliente es siempre la
 * misma: no se revela si el email existe (`spec/02-objetivo/03-autenticacion.md`).
 */
class SendPasswordResetLinkUseCase
{
    public function execute(string $email): void
    {
        Password::broker()->sendResetLink(['email' => $email]);
    }
}
