<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VariantMirror extends Model
{
    protected $fillable = [
        'product_mirror_id',
        'source_variant_id','source_options_key',
        'target_variant_id','target_variant_gid',
        'variant_fingerprint','inventory_fingerprint',
        'last_snapshot'
    ];

    protected $casts = [
        'last_snapshot' => 'array',
    ];

    public function productMirror()
    {
        return $this->belongsTo(ProductMirror::class);
    }
}
