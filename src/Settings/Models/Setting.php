<?php

namespace Src\Settings\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Valor de un parámetro de negocio que sustituye al de `config()`.
 */
class Setting extends Model
{
    protected $fillable = ['key', 'value', 'updated_by'];
}
