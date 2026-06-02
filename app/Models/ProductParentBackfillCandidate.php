<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductParentBackfillCandidate extends Model
{
    public const STATUS_ALREADY_SET = 'already_set';
    public const STATUS_MATCHED = 'matched';
    public const STATUS_UNMATCHED = 'unmatched';
    public const STATUS_AMBIGUOUS = 'ambiguous';

    protected $fillable = [
        'source_shop_id',
        'source_product_id',
        'source_product_gid',
        'source_title',
        'source_handle',
        'source_skus',
        'source_status',
        'source_image_count',
        'target_shop_id',
        'target_product_id',
        'target_product_gid',
        'target_title',
        'target_handle',
        'target_skus',
        'target_status',
        'target_image_count',
        'parentproduct_value',
        'match_status',
        'match_strategy',
        'notes',
        'last_scanned_at',
    ];

    protected $casts = [
        'source_skus' => 'array',
        'target_skus' => 'array',
        'notes' => 'array',
        'last_scanned_at' => 'datetime',
    ];

    public function sourceShop()
    {
        return $this->belongsTo(Shop::class, 'source_shop_id');
    }

    public function targetShop()
    {
        return $this->belongsTo(Shop::class, 'target_shop_id');
    }

    public function scopeCorrelated($query)
    {
        return $query->whereIn('match_status', [
            self::STATUS_ALREADY_SET,
            self::STATUS_MATCHED,
        ]);
    }

    public function scopeUncorrelated($query)
    {
        return $query->whereIn('match_status', [
            self::STATUS_UNMATCHED,
            self::STATUS_AMBIGUOUS,
        ]);
    }
}
