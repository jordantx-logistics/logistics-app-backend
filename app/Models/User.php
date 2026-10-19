<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use Src\Organization\Models\Agency;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    /**
     * Guard con el que se comprueban los permisos de Spatie.
     *
     * Tiene que declararse: `Illuminate\Auth\Middleware\Authenticate` hace
     * `shouldUse('sanctum')` al validar `auth:sanctum`, y eso reescribe
     * `config('auth.defaults.guard')`. Spatie deduce el guard del permiso de esa
     * config, así que, sin fijarlo aquí, dentro de una petición buscaría los
     * permisos con guard `sanctum` (no existen: los nuestros son `web`) y
     * devolvería 403 a todo el mundo. Fijar el guard del modelo (el mecanismo
     * que el propio paquete documenta para varios guards) hace que la
     * comprobación sea la misma dentro y fuera de la petición.
     *
     * @var string
     */
    protected $guard_name = 'web';

    /**
     * Roles con ámbito nacional: operan sin filtro de agencia
     * (`spec/02-objetivo/04-autorizacion.md`).
     *
     * @var list<string>
     */
    private const NATIONAL_ROLES = ['admin', 'analyst'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Agencias a las que pertenece el usuario (ámbito de datos).
     *
     * @return BelongsToMany<Agency, $this>
     */
    public function agencies(): BelongsToMany
    {
        return $this->belongsToMany(Agency::class, 'agency_user')->withPivot('is_primary');
    }

    /**
     * Ids de las agencias del usuario, para pasar a un scope de consulta
     * (`Order::forAgencies($user->agencyIds())`).
     *
     * @return list<int>
     */
    public function agencyIds(): array
    {
        return $this->agencies()->pluck('agencies.id')->all();
    }

    /**
     * Un rol nacional (`admin`, `analyst`) ve todos los datos, sin filtro de
     * agencia. El resto queda acotado a `agencyIds()`.
     */
    public function hasNationalScope(): bool
    {
        return $this->hasAnyRole(self::NATIONAL_ROLES);
    }

    /**
     * ¿Es superadmin? Solo el rol `admin`.
     *
     * Se distingue de `hasNationalScope()` en que también bypassa las reglas
     * por registro de las policies (un `analyst` ve todo el país, pero no
     * administra): `AgencyPolicy::create` solo lo deja pasar a un superadmin.
     */
    public function isSuperAdmin(): bool
    {
        return $this->hasRole('admin');
    }
}
