<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Knuckles\Scribe\Scribe;
use Symfony\Component\HttpFoundation\Request;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
        $this->configurePasswordResetUrl();
        $this->configureScribeAuthentication();

        if (! config('authz.enforce')) {
            // El middleware de permisos está desactivado (rollback de Fase 1):
            // tiene que quedar en los logs, no pasar inadvertido.
            Log::warning('authz_permissions_disabled', [
                'env' => app()->environment(),
                'flag' => 'AUTHZ_ENFORCE=false',
            ]);
        }
    }

    /**
     * `throttle:auth` para los endpoints de credenciales: 10 intentos por
     * minuto por IP y 5 por email+IP (`02-objetivo/03-autenticacion.md`;
     * criterio 11 de la Fase 1: 429 al superarlo).
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('auth', function (HttpRequest $request) {
            $email = (string) $request->input('email');

            return [
                Limit::perMinute(10)->by($request->ip()),
                Limit::perMinute(5)->by(sprintf('%s|%s', $email, $request->ip())),
            ];
        });
    }

    /**
     * El enlace del correo de recuperación apunta a la SPA, no a una vista
     * del backend.
     */
    private function configurePasswordResetUrl(): void
    {
        ResetPassword::createUrlUsing(function (User $user, string $token): string {
            return sprintf(
                '%s/reset-password?token=%s&email=%s',
                rtrim((string) config('app.frontend_url'), '/'),
                $token,
                urlencode($user->getEmailForPasswordReset()),
            );
        });
    }

    /**
     * Scribe (`scribe:generate`) ejecuta las peticiones de ejemplo: en lugar de
     * guardar un token en el entorno, se emite uno de Sanctum al usuario de
     * Scribe. Solo en local/testing.
     */
    private function configureScribeAuthentication(): void
    {
        if (! app()->environment('local', 'testing')) {
            return;
        }

        /** @var Request $request */
        Scribe::beforeResponseCall(function (Request $request) {
            $user = User::where('email', env('SCRIBE_AUTH_EMAIL', 'admin@admin.pe'))->first();

            if ($user === null) {
                return;
            }

            // Un token por generación, no uno acumulado por petición.
            $user->tokens()->where('name', 'scribe')->delete();

            $token = $user->createToken('scribe')->plainTextToken;

            $request->headers->add(['Authorization' => 'Bearer '.$token]);
        });
    }
}
