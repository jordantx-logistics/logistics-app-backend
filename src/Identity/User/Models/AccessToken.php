<?php

namespace Src\Identity\User\Models;

use Illuminate\Database\Eloquent\Relations\Relation;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Token y usuario se leen siempre del primary: con la réplica, un token revocado
 * seguiría valiendo durante el lag y uno recién emitido daría 401.
 */
class AccessToken extends PersonalAccessToken
{
    /** Segundos entre dos escrituras de `last_used_at` de un mismo token. */
    private const LAST_USED_RESOLUTION = 60;

    protected $table = 'personal_access_tokens';

    /**
     * @param  string  $token
     * @return static|null
     */
    public static function findToken($token)
    {
        if (! str_contains($token, '|')) {
            $found = static::onWriteConnection()->where('token', hash('sha256', $token))->first();
        } else {
            [$id, $plain] = explode('|', $token, 2);
            $candidate = static::onWriteConnection()->find($id);
            $found = $candidate && hash_equals($candidate->token, hash('sha256', $plain)) ? $candidate : null;
        }

        if ($found !== null) {
            $class = Relation::getMorphedModel($found->tokenable_type) ?? $found->tokenable_type;
            $found->setRelation('tokenable', $class::onWriteConnection()->find($found->tokenable_id));
        }

        return $found;
    }

    /**
     * Sanctum guarda `last_used_at` en cada petición, también en las de lectura: eso es
     * una escritura en el primary por cada GET. Con la resolución de un minuto basta.
     *
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = [])
    {
        $previous = $this->getOriginal('last_used_at');

        if (
            $this->exists
            && array_keys($this->getDirty()) === ['last_used_at']
            && $previous !== null
            && $this->asDateTime($previous)->diffInSeconds(now()) < self::LAST_USED_RESOLUTION
        ) {
            return true;
        }

        return parent::save($options);
    }
}
