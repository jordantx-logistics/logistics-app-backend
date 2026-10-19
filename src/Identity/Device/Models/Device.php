<?php

namespace Src\Identity\Device\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dispositivo autorizado a autenticarse con token (apps/PDA).
 *
 * `device_uuid` identifica el equipo físico: el mismo dispositivo reutiliza su
 * fila (único `[user_id, device_uuid]`) en vez de acumular duplicados.
 * `user_id → users` es FK intra-Identity (`users` es del scaffolding de Laravel,
 * no un contexto ajeno); no hay campos de agencia: el dispositivo es del usuario,
 * no de la agencia.
 */
class Device extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_REVOKED = 'revoked';

    /** @var list<string> */
    public const PLATFORMS = ['web', 'android', 'ios', 'pda'];

    protected $fillable = [
        'user_id',
        'device_name',
        'platform',
        'device_uuid',
        'push_token',
        'app_version',
        'status',
        'last_seen_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Nombre con el que se emite el token Sanctum de este dispositivo
     * (`device_name (platform)`, ver `IssueTokenUseCase`).
     *
     * Es lo que permite revocar el token asociado al revocar el dispositivo: la
     * tabla de Sanctum no guarda el vínculo con `devices`, así que ambos lados
     * componen el mismo nombre.
     */
    public function tokenName(): string
    {
        return sprintf('%s (%s)', $this->device_name, $this->platform);
    }
}
