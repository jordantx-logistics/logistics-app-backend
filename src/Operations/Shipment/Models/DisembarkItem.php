<?php

namespace Src\Operations\Shipment\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisembarkItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'disembark_id',
        'shipment_id',
        'ticket_code',
        'scan_id',
    ];

    /** @return BelongsTo<Disembark, $this> */
    public function disembark(): BelongsTo
    {
        return $this->belongsTo(Disembark::class);
    }
}
