<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Shop extends Model
{
    use HasFactory;

    protected $fillable = ['name','domain','access_token','api_version','is_source','is_active','location_legacy_id'];

    protected $casts = [
        'access_token' => 'encrypted',
        'is_source'    => 'boolean',
        'is_active'    => 'boolean',
    ];
    public function locationGid(): ?string
    {
        return $this->location_legacy_id
            ? "gid://shopify/Location/{$this->location_legacy_id}"
            : null;
    }
    public function outgoingConnections() { return $this->hasMany(ShopConnection::class, 'source_shop_id'); }
    public function incomingConnections() { return $this->hasMany(ShopConnection::class, 'target_shop_id'); }
}
