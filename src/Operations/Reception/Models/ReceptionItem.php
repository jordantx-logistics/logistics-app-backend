<?php

namespace Src\Operations\Reception\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReceptionItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'reception_id',
        'shipment_id',
        'ticket_code',
        'scan_id',
    ];

    /** @return BelongsTo<Reception, $this> */
    public function reception(): BelongsTo
    {
        return $this->belongsTo(Reception::class);
    }
}
