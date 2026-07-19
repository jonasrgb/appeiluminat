<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CatalogAuditFinding extends Model
{
    use HasFactory;

    public const TYPE_MISSING_IMAGE = 'missing_image';

    public const TYPE_DUPLICATE_SKU = 'duplicate_sku';

    protected $fillable = [
        'shop_id',
        'last_seen_run_id',
        'finding_type',
        'fingerprint',
        'product_gid',
        'product_legacy_id',
        'product_title',
        'product_handle',
        'product_status',
        'variant_gid',
        'variant_legacy_id',
        'variant_title',
        'sku',
        'normalized_sku',
        'shopify_admin_url',
        'last_seen_at',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
    ];

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function lastSeenRun()
    {
        return $this->belongsTo(CatalogAuditRun::class, 'last_seen_run_id');
    }
}
