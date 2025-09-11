<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class Webhook extends Model
{
    use HasFactory;

    // Definirea tabelei explicit (opțional)
    protected $table = 'webhooks';

    // Coloanele care pot fi completate în masă
    protected $fillable = [
        'topic',
        'webhook_id',
        'product_id',
        'triggered_at',
        'payload',
    ];

    // Conversii automate de date
    protected $casts = [
        'triggered_at' => 'datetime', // Convertire automată în Carbon
        'payload' => 'array', // Laravel va interpreta JSON-ul ca array PHP
    ];

    // Getter pentru a formata `triggered_at` în ISO 8601
    public function getTriggeredAtAttribute($value)
    {
        return Carbon::parse($value)->toIso8601String();
    }

    // Setter pentru a salva corect timestamp-ul în baza de date
    public function setTriggeredAtAttribute($value)
    {
        $this->attributes['triggered_at'] = Carbon::parse($value);
    }
}
