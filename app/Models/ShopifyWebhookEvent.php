<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShopifyWebhookEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'webhook_id',
        'topic',
        'shop_domain',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];
}
