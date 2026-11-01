<?php

namespace Src\Operations\Shipment\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmbarkItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'embark_id',
        'shipment_id',
        'ticket_code',
        'scan_id',
    ];

    /** @return BelongsTo<Embark, $this> */
    public function embark(): BelongsTo
    {
        return $this->belongsTo(Embark::class);
    }
}
