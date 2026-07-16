<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SourceProductDeletion extends Model
{
    protected $fillable = [
        'source_shop_id',
        'source_product_id',
        'webhook_event_id',
        'deleted_at',
    ];

    protected $casts = [
        'deleted_at' => 'datetime',
    ];

    public static function existsFor(int $sourceShopId, int $sourceProductId): bool
    {
        return static::query()
            ->where('source_shop_id', $sourceShopId)
            ->where('source_product_id', $sourceProductId)
            ->exists();
    }
}
