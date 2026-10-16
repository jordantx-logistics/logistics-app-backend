<?php

namespace App\Providers;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;
use Knuckles\Scribe\Scribe;
use Symfony\Component\HttpFoundation\Request;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * Implementa autenticación automática para Scribe response calls.
     * Esto permite que Postman obtenga tokens automáticamente sin intervención manual.
     */
    public function boot(): void
    {
        // Solo habilitar autenticación automática en desarrollo
        if (app()->environment('local', 'testing')) {
            Scribe::beforeResponseCall(function (Request $request) {
                // Obtener credenciales de variables de entorno o usar valores por defecto
                $email = env('SCRIBE_AUTH_EMAIL', 'admin@admin.pe');
                $password = env('SCRIBE_AUTH_PASSWORD', 'SecureAdmin2026!@#');

                // Intentar autenticación con el guard 'api'
                $token = Auth::guard('api')->attempt([
                    'email' => $email,
                    'password' => $password,
                ], true); // true = remember me (no logout concurrentes)

                // Agregar el token al header de autorización
                if ($token) {
                    $request->headers->add(['Authorization' => 'Bearer '.$token]);
                }
            });
        }
        //
    }
}
