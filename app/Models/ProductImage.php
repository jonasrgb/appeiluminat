<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductImage extends Model
{
    use HasFactory;

    protected $table = 'product_images';

    protected $fillable = [
        'shopify_id',
        'product_id',
        'position',
        'alt',
        'width',
        'height',
        'src',
    ];

    protected $casts = [
        'width' => 'integer',
        'height' => 'integer',
        'position' => 'integer',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
