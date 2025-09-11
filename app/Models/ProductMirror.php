<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductMirror extends Model
{
    protected $fillable = [
        'source_shop_id','source_product_id','source_product_gid',
        'target_shop_id','target_product_id','target_product_gid',
        'meta','last_snapshot'
    ];

    protected $casts = [
        'meta'          => 'array',
        'last_snapshot' => 'array',
    ];

    public function variantMirrors()
    {
        return $this->hasMany(VariantMirror::class);
    }
}
