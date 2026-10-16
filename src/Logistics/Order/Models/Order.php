<?php

namespace Src\Logistics\Order\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Src\Customer\Models\Person;
use Src\Logistics\Payment\Models\Payment;
use Src\Organization\Models\Agency;
use Src\Shared\Traits\HasTicketIdentifiers;

class Order extends Model
{
    use HasFactory, HasTicketIdentifiers;

    protected $fillable = [
        'idempotency_key',
        'sender_id',
        'receiver_id',
        'origin_agency_id',
        'destination_agency_id',
        'description',
        'weight_kg',
        'volume_m3',
        'declared_value',
        'total_amount',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'weight_kg' => 'decimal:3',
            'volume_m3' => 'decimal:4',
            'declared_value' => 'decimal:2',
            'total_amount' => 'decimal:2',
        ];
    }

    public function getTicketPrefix(): string
    {
        return 'ORD';
    }

    // ── Relationships ────────────────────────────────────
    public function sender(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'sender_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'receiver_id');
    }

    public function originAgency(): BelongsTo
    {
        return $this->belongsTo(Agency::class, 'origin_agency_id');
    }

    public function destinationAgency(): BelongsTo
    {
        return $this->belongsTo(Agency::class, 'destination_agency_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
