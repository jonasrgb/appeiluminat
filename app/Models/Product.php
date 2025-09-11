<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $table = 'products';

    protected $fillable = [
        'admin_graphql_api_id',
        'shopify_id',
        'title',
        'handle',
        'body_html',
        'product_type',
        'vendor',
        'status',
        'published_scope',
        'tags',
        'published_at',
        'shopify_created_at',
        'shopify_updated_at',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'shopify_created_at' => 'datetime',
        'shopify_updated_at' => 'datetime',
    ];

    // 🔗 Relații
    public function variants()
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function options()
    {
        return $this->hasMany(ProductOption::class);
    }

    public function images()
    {
        return $this->hasMany(ProductImage::class);
    }

    // 🔍 Verifică dacă produsul este activ
    public function isActive()
    {
        return strtolower($this->status) === 'active';
    }

    // 🔢 Număr total variante
    public function variantCount()
    {
        return $this->variants()->count();
    }

    // 📦 Verifică dacă are stoc total pe toate variantele
    public function inStock()
    {
        return $this->variants()->sum('inventory_quantity') > 0;
    }
}
