<?php

namespace Src\Operations\Delivery\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Src\Shared\Traits\CanBeScopedByAgency;

class Delivery extends Model
{
    use CanBeScopedByAgency, HasFactory;

    public const STATUS_ASSIGNED = 'assigned';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_FAILED = 'failed';

    public const STATUS_RETURNED = 'returned';

    protected $fillable = [
        'shipment_id',
        'ticket_code',
        'ticket_number',
        'agency_id',
        'courier_id',
        'status',
        'attempt',
        'delivered_at',
        'failure_reason',
        'recipient_name',
        'recipient_document',
    ];

    protected function casts(): array
    {
        return [
            'attempt' => 'integer',
            'delivered_at' => 'datetime',
        ];
    }

    /** @return HasOne<DeliveryEvidence, $this> */
    public function evidence(): HasOne
    {
        return $this->hasOne(DeliveryEvidence::class);
    }
}
