<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmailSyncState extends Model
{
    use HasFactory;
     protected $fillable = ['mailbox', 'last_uid'];
}
