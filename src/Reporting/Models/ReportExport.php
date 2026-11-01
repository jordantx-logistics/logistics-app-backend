<?php

namespace Src\Reporting\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Exportación solicitada por un usuario. Solo la ve quien la pidió y el archivo
 * se borra al vencer (`reports:purge-expired`).
 */
class ReportExport extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    public const FORMATS = ['csv', 'xlsx', 'pdf'];

    protected $fillable = [
        'user_id',
        'report',
        'format',
        'filters',
        'status',
        'path',
        'row_count',
        'error_code',
        'started_at',
        'completed_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
