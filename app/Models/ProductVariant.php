<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductVariant extends Model
{
    use HasFactory;

    protected $table = 'product_variants';

    protected $fillable = [
        'admin_graphql_api_id',
        'shopify_id',
        'product_id',
        'title',
        'sku',
        'price',
        'compare_at_price',
        'inventory_policy',
        'taxable',
        'option1',
        'option2',
        'option3',
        'inventory_item_id',
        'inventory_quantity',
    ];

    protected $casts = [
        'price' => 'float',
        'compare_at_price' => 'float',
        'taxable' => 'boolean',
        'inventory_quantity' => 'integer',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
