<?php

namespace Src\Customer\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Src\Customer\Services\CustomerCodeGenerator;

/**
 * Cliente (raíz del agregado `Customer`).
 *
 * Puede actuar como remitente o destinatario de una orden. Se respalda en una
 * **persona** o una **empresa** (`person_id`/`company_id`, misma tabla de este
 * contexto, así que la FK es legítima); el documento y el contacto se copian
 * aquí porque un cliente puede cambiar de persona/empresa sin romper el
 * histórico de órdenes.
 *
 * `persons` y `companies` siguen existiendo como entidades de respaldo y se
 * pueden dar de alta por las rutas del MVP: el agregado se crea en el mismo
 * movimiento (`CreateCustomerUseCase`).
 */
class Customer extends Model
{
    use HasFactory;

    public const TYPE_PERSON = 'person';

    public const TYPE_COMPANY = 'company';

    /** Tipos de documento admitidos (`ValidateCustomerDocument`). */
    public const DOCUMENT_TYPES = ['DNI', 'RUC', 'CE', 'PASSPORT'];

    protected $fillable = [
        'customer_type',
        'person_id',
        'company_id',
        'display_name',
        'document_type',
        'document_number',
        'email',
        'phone',
        'address',
        'is_active',
        'credit_limit',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'credit_limit' => 'decimal:2',
        ];
    }

    /**
     * `code` lo asigna el generador en `creating`: el llamante no lo elige.
     * Va en `booted()` y no en un trait porque es propio de este agregado
     * (el formato `CLI-<año>-<secuencia>` no es el de los tickets, ADR-0009).
     */
    protected static function booted(): void
    {
        static::creating(function (Customer $customer): void {
            if (empty($customer->code)) {
                $customer->code = app(CustomerCodeGenerator::class)->generate();
            }
        });
    }

    /** Persona de respaldo (solo si `customer_type = person`). */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /** Empresa de respaldo (solo si `customer_type = company`). */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Solo clientes activos: el listado de alta filtra por esto.
     *
     * @param  Builder<Customer>  $query
     * @return Builder<Customer>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Búsqueda del alta: nombre, documento o email.
     *
     * @param  Builder<Customer>  $query
     * @return Builder<Customer>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $needle = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($term)).'%';

        return $query->where(function (Builder $query) use ($needle): void {
            $query->where('display_name', 'like', $needle)
                ->orWhere('document_number', 'like', $needle)
                ->orWhere('email', 'like', $needle);
        });
    }
}
