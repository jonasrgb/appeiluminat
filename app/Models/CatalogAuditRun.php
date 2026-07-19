<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CatalogAuditRun extends Model
{
    use HasFactory;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'shop_id',
        'status',
        'started_at',
        'finished_at',
        'missing_image_count',
        'duplicate_sku_group_count',
        'duplicate_sku_row_count',
        'error_message',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function findings()
    {
        return $this->hasMany(CatalogAuditFinding::class, 'last_seen_run_id');
    }
}
