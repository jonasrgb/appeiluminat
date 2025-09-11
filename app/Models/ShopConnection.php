<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShopConnection extends Model
{
    use HasFactory;

    protected $fillable = ['source_shop_id','target_shop_id'];

    public function source() { return $this->belongsTo(Shop::class, 'source_shop_id'); }
    public function target() { return $this->belongsTo(Shop::class, 'target_shop_id'); }
}
