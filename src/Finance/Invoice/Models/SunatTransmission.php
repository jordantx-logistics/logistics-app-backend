<?php

namespace Src\Finance\Invoice\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bitácora de una transmisión (una fila por intento) a SUNAT/PSE.
 */
class SunatTransmission extends Model
{
    public const STATUS_SENT = 'sent';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    public $timestamps = false;

    protected $fillable = [
        'invoice_id',
        'credit_note_id',
        'attempt',
        'endpoint',
        'request_hash',
        'response_code',
        'response_message',
        'status',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'attempt' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
