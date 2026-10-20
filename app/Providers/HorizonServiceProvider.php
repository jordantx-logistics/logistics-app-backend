<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Registra la gate que decide quién ve el panel fuera de local.
     *
     * En local Horizon deja pasar siempre (`Horizon::auth`); en el resto solo
     * entran los emails de `HORIZON_ALLOWED_EMAILS` (vacía = nadie). En
     * staging/producción la barrera de verdad es el borde (Cloudflare Access).
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null) {
            return in_array(optional($user)->email, config('horizon.allowed_emails', []), true);
        });
    }
}
